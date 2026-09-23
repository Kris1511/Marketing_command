import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import axios from 'axios';
import axiosInstance from '../api/axiosInstance';
import { useWorkspace } from '../context/WorkspaceContext';
import {
  Eye,
  Clock,
  Users,
  Video,
  Play,
  Radio,
  List,
  RefreshCw,
  AlertCircle,
  ExternalLink,
  Lock,
  CheckCircle2,
  FileText,
  BarChart2,
  Sparkles,
  TrendingUp,
  AlertTriangle,
  Calendar,
  ChevronDown,
  ChevronUp,
  ArrowUpRight,
  X,
  Maximize2,
  PlaySquare,
  Activity,
  Check,
  MessageSquare,
} from 'lucide-react';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js';
import { Line } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

// Helper: Format large numbers compactly (e.g. 1.2K, 3.4M), returning '—' if unavailable
function formatCompact(val) {
  if (val === null || val === undefined) return '—';
  const n = Number(val);
  if (isNaN(n)) return '—';
  if (n === 0) return '0';
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
  if (n >= 1_000) return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
  return n.toLocaleString();
}

// Helper: Format seconds into MM:SS or H:MM:SS
function formatDuration(seconds) {
  if (!seconds || seconds <= 0) return '0:00';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = Math.floor(seconds % 60);
  if (h > 0) {
    return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  }
  return `${m}:${String(s).padStart(2, '0')}`;
}

// Helper: Format ISO date string into readable short date
function formatDate(iso) {
  if (!iso) return '—';
  try {
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  } catch {
    return iso;
  }
}

// Helper: Compute date range presets
function getPresetDates(preset) {
  const today = new Date();
  const formatYmd = (d) => {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };
  const todayStr = formatYmd(today);

  const subDays = (n) => {
    const d = new Date(today);
    d.setDate(d.getDate() - n);
    return formatYmd(d);
  };

  switch (preset) {
    case 'last7':
      return { startDate: subDays(7), endDate: todayStr, label: 'Last 7 days' };
    case 'last28':
      return { startDate: subDays(28), endDate: todayStr, label: 'Last 28 days' };
    case 'last90':
      return { startDate: subDays(90), endDate: todayStr, label: 'Last 90 days' };
    case 'last365':
      return { startDate: subDays(365), endDate: todayStr, label: 'Last 365 days' };
    case 'lifetime':
      return { startDate: '2020-01-01', endDate: todayStr, label: 'Lifetime' };
    default:
      return { startDate: subDays(28), endDate: todayStr, label: 'Last 28 days' };
  }
}

// Helper: Format date range into readable string e.g. "10 Aug – 6 Sept 2026"
function formatDateRangeText(startStr, endStr) {
  if (!startStr || !endStr) return '';
  const partsS = startStr.split('-').map(Number);
  const partsE = endStr.split('-').map(Number);
  if (partsS.length !== 3 || partsE.length !== 3) return `${startStr} – ${endStr}`;

  const [sy, sm, sd] = partsS;
  const [ey, em, ed] = partsE;

  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];
  const sMonth = months[sm - 1] || '';
  const eMonth = months[em - 1] || '';

  if (sy === ey) {
    if (sm === em) {
      return `${sd}–${ed} ${eMonth} ${ey}`;
    }
    return `${sd} ${sMonth} – ${ed} ${eMonth} ${ey}`;
  }
  return `${sd} ${sMonth} ${sy} – ${ed} ${eMonth} ${ey}`;
}

export default function YouTubeStudioPerformanceSection({
  workspaceId,
  startDate: initialStartDate,
  endDate: initialEndDate,
  refreshTrigger,
  initialChannel,
}) {
  const { selectedWorkspaceId } = useWorkspace();
  const effectiveWorkspaceId =
    workspaceId ||
    selectedWorkspaceId ||
    localStorage.getItem('selectedWorkspaceId') ||
    localStorage.getItem('activeWorkspaceId') ||
    '';

  // Date range presets and active dates
  const [datePreset, setDatePreset] = useState('last28');
  const defaultPreset = getPresetDates('last28');
  const [activeStartDate, setActiveStartDate] = useState(initialStartDate || defaultPreset.startDate);
  const [activeEndDate, setActiveEndDate] = useState(initialEndDate || defaultPreset.endDate);
  const [customStartInput, setCustomStartInput] = useState(initialStartDate || defaultPreset.startDate);
  const [customEndInput, setCustomEndInput] = useState(initialEndDate || defaultPreset.endDate);

  // Component state
  const [loading, setLoading] = useState(true);
  const [analyticsData, setAnalyticsData] = useState(null);
  const [contentData, setContentData] = useState(null);
  const [error, setError] = useState(null);
  const [selectedMetric, setSelectedMetric] = useState('views'); // views, watch_time, subscribers
  const [activeTab, setActiveTab] = useState('all'); // all, videos, shorts, live, playlists, posts
  const [contentLimit, setContentLimit] = useState(10); // 10-item pagination limit (shows all 6 Shorts by default)
  const [hoveredHour, setHoveredHour] = useState(null);
  const [showRealtimeModal, setShowRealtimeModal] = useState(false);
  const [showSearchTermsModal, setShowSearchTermsModal] = useState(false);
  const [showEngagementModal, setShowEngagementModal] = useState(false);
  const [studioEngagementData, setStudioEngagementData] = useState({});

  // Video Retention State (Driven by Analytics Icon in Content Table)
  const [selectedVideoId, setSelectedVideoId] = useState(null);
  const [selectedVideoMeta, setSelectedVideoMeta] = useState(null);
  const [videoRetentionData, setVideoRetentionData] = useState(null);
  const [loadingVideoRetention, setLoadingVideoRetention] = useState(false);
  const [videoRetentionError, setVideoRetentionError] = useState(null);


  // In-memory cache by videoId: { [videoId]: { data: {...}, timestamp: number } }
  const retentionCacheRef = useRef({});
  // In-memory cache for overview & content by `${workspaceId}_${startDate}_${endDate}`: { [cacheKey]: { analytics: {...}, content: {...}, timestamp: number } }
  const overviewCacheRef = useRef({});
  // In-flight request deduplication map to prevent redundant concurrent fetches
  const inFlightOverviewRef = useRef({});

  // Race condition prevention, request tracking, and retry timer refs
  const activeAbortControllerRef = useRef(null);
  const currentRequestIdRef = useRef(0);
  const retryTimerRef = useRef(null);
  const retentionSectionRef = useRef(null);

  // Fetch real YouTube API data with in-memory caching & request deduplication
  const fetchData = useCallback(
    async (start, end, forceRefresh = false, silent = false, isAutoSync = false) => {
      if (!effectiveWorkspaceId) return;

      const cacheKey = `${effectiveWorkspaceId}_${start}_${end}`;

      // 1. Fast in-memory cache hit (0ms instant render)
      if (!forceRefresh) {
        const cached = overviewCacheRef.current[cacheKey];
        const cacheAge = cached ? Date.now() - cached.timestamp : Infinity;
        const freshThreshold = isAutoSync ? 20 * 1000 : 120 * 1000;
        // 10-minute cache TTL
        if (cached && cacheAge < 10 * 60 * 1000) {
          if (cached.analytics) setAnalyticsData(cached.analytics);
          if (cached.content) setContentData(cached.content);
          setError(null);
          setLoading(false);

          // If within freshness threshold, skip redundant network call
          if (cacheAge < freshThreshold) {
            return;
          }
          // Silently revalidate in background without disrupting UI
          silent = true;
        }
      }

      if (!silent) {
        setLoading(true);
        setError(null);
      }

      // 2. Prevent duplicate concurrent requests (Promise sharing)
      const requestKey = `${cacheKey}_${forceRefresh ? 'force' : isAutoSync ? 'sync' : 'normal'}`;
      if (inFlightOverviewRef.current[requestKey]) {
        try {
          await inFlightOverviewRef.current[requestKey];
        } catch {
          // Handled by primary request
        }
        return;
      }

      const baseParams = {
        workspace_id: effectiveWorkspaceId,
        start_date: start,
        end_date: end,
      };
      if (forceRefresh) {
        baseParams.force_refresh = 1;
      }
      if (isAutoSync) {
        baseParams.auto_sync = 1;
      }

      const fetchPromise = (async () => {
        try {
          const [analyticsRes, contentRes] = await Promise.allSettled([
            axiosInstance.get('/youtube/analytics/overview', { params: baseParams }),
            axiosInstance.get('/youtube/content', { params: { ...baseParams, type: 'all' } }),
          ]);

          let newAnalytics = null;
          let newContent = null;

          if (analyticsRes.status === 'fulfilled' && analyticsRes.value?.data) {
            const data = analyticsRes.value.data;
            newAnalytics = data;
            setAnalyticsData(data);
            if (data.success === false) {
              if (data.reauthorization_required || data.error_type === 'token_invalid') {
                setError(data.error_message || data.message || 'YouTube token expired or revoked. Reauthorization required.');
              } else if (data.error_type === 'analytics_api_disabled') {
                setError(data.error_message || 'YouTube Analytics API is not enabled in Google Cloud Console.');
              } else {
                setError(data.error_message || data.message || 'Unable to load YouTube analytics. Please try again.');
              }
            }
          } else if (analyticsRes.status === 'rejected') {
            const err = analyticsRes.reason;
            const resp = err?.response?.data;
            if (resp) {
              newAnalytics = resp;
              setAnalyticsData(resp);
              setError(resp.error_message || resp.message || 'Unable to load YouTube analytics. Please try again.');
            } else {
              setError('Unable to load YouTube analytics. Please try again.');
            }
          }

          if (contentRes.status === 'fulfilled' && contentRes.value?.data) {
            newContent = contentRes.value.data;
            setContentData(newContent);
          } else if (contentRes.status === 'rejected') {
            console.warn('YouTube content endpoint warning:', contentRes.reason?.message);
          }

          // Cache successful result in frontend in-memory cache
          if (newAnalytics && newAnalytics.success !== false) {
            overviewCacheRef.current[cacheKey] = {
              analytics: newAnalytics,
              content: newContent || overviewCacheRef.current[cacheKey]?.content,
              timestamp: Date.now(),
            };
          }
        } catch (err) {
          console.error('YouTube fetch error:', err);
          if (!silent) {
            setError('Unable to load YouTube analytics. Please try again.');
          }
        } finally {
          delete inFlightOverviewRef.current[requestKey];
          if (!silent) {
            setLoading(false);
          }
        }
      })();

      inFlightOverviewRef.current[requestKey] = fetchPromise;
      await fetchPromise;
    },
    [effectiveWorkspaceId]
  );

  // Fetch data on active date range change or workspace change
  useEffect(() => {
    fetchData(activeStartDate, activeEndDate, false);
  }, [fetchData, activeStartDate, activeEndDate]);

  // Auto-refresh realtime YouTube data periodically (every 30 seconds, silent background refresh)
  useEffect(() => {
    let timer = null;

    const runAutoSync = () => {
      if (document.hidden) return;
      fetchData(activeStartDate, activeEndDate, false, true, true);
    };

    timer = setInterval(runAutoSync, 30000);

    const handleVisibilityChange = () => {
      if (!document.hidden) {
        runAutoSync();
      }
    };
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      if (timer) clearInterval(timer);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [fetchData, activeStartDate, activeEndDate]);

  // Handle ESC key for modal
  useEffect(() => {
    const handleKeyDown = (e) => {
      if (e.key === 'Escape') {
        if (showRealtimeModal) setShowRealtimeModal(false);
        if (showSearchTermsModal) setShowSearchTermsModal(false);
        if (showEngagementModal) setShowEngagementModal(false);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [showRealtimeModal, showSearchTermsModal, showEngagementModal]);

  // Handle date preset change
  const handleDatePresetChange = (presetKey) => {
    setDatePreset(presetKey);
    if (presetKey !== 'custom') {
      const dates = getPresetDates(presetKey);
      setActiveStartDate(dates.startDate);
      setActiveEndDate(dates.endDate);
      setCustomStartInput(dates.startDate);
      setCustomEndInput(dates.endDate);
    }
  };

  const handleApplyCustomRange = (e) => {
    e.preventDefault();
    if (customStartInput && customEndInput) {
      setActiveStartDate(customStartInput);
      setActiveEndDate(customEndInput);
    }
  };

  // Refetch when top-level Overview "Refresh metrics" button is clicked
  useEffect(() => {
    if (refreshTrigger && refreshTrigger > 0) {
      overviewCacheRef.current = {};
      retentionCacheRef.current = {};
      fetchData(activeStartDate, activeEndDate, true);
    }
  }, [refreshTrigger, fetchData, activeStartDate, activeEndDate]);

  // Reconnect popup handler
  const handleReconnect = () => {
    const width = 600;
    const height = 700;
    const left = window.screen.width / 2 - width / 2;
    const top = window.screen.height / 2 - height / 2;
    window.open(
      `http://localhost:8000/api/youtube/connect?workspace_id=${effectiveWorkspaceId || 1}&force=true`,
      'GoogleYouTubeOAuth',
      `width=${width},height=${height},top=${top},left=${left}`
    );
  };

  // Derived values from real API
  const channel = analyticsData?.channel || contentData?.channel || initialChannel;
  const isSuccess = analyticsData?.success === true;
  const isReauth =
    analyticsData?.reauthorization_required === true &&
    analyticsData?.has_refresh_token === false;
  const isApiDisabled = analyticsData?.error_type === 'analytics_api_disabled';

  // Period Metrics (Selected date range — null if unavailable or error)
  const periodViews = isSuccess ? (analyticsData?.views ?? analyticsData?.data?.views) : null;
  const periodWatchTimeHours = isSuccess ? (analyticsData?.watch_time_hours ?? analyticsData?.data?.watch_time_hours) : null;
  const periodSubscribers = isSuccess
    ? (analyticsData?.subscribers_net_change ?? analyticsData?.subscribers_gained ?? analyticsData?.data?.subscribers_net_change)
    : null;

  // Realtime Metrics (Last 48 Hours)
  const currentSubscribers = channel?.subscriber_count ?? analyticsData?.subscribers ?? analyticsData?.data?.subscribers ?? null;
  const realtime48h = analyticsData?.realtime_48h || analyticsData?.data?.realtime_48h || null;
  const viewsLast48h = realtime48h?.total_views ?? analyticsData?.views_last_48h ?? analyticsData?.data?.views_last_48h ?? null;
  const hourlyViewsList = realtime48h?.hourly_views || [];
  const topVideos48h = realtime48h?.top_videos || [];
  const isRealtimeUnavailable = !loading && realtime48h === null && viewsLast48h === null;

  // Daily Trend Graph Setup
  const dailyTrend = useMemo(() => {
    const raw = analyticsData?.daily_trend || analyticsData?.data?.daily_trend;
    if (!raw) return [];
    if (Array.isArray(raw)) return raw;
    return Object.values(raw);
  }, [analyticsData]);

  const hasTrendData = isSuccess && dailyTrend.length > 0;

  const chartData = useMemo(() => {
    if (!hasTrendData) return null;

    const labels = dailyTrend.map((d) => {
      const parts = d.date.split('-');
      if (parts.length === 3) {
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const m = parseInt(parts[1], 10) - 1;
        return `${months[m]} ${parseInt(parts[2], 10)}`;
      }
      return d.date;
    });

    if (selectedMetric === 'watch_time') {
      return {
        labels,
        datasets: [
          {
            label: 'Watch Time (hours)',
            data: dailyTrend.map((d) => d.watch_time_hours || 0),
            borderColor: '#d97706',
            backgroundColor: 'rgba(217, 119, 6, 0.08)',
            borderWidth: 2.5,
            tension: 0.35,
            fill: true,
            pointRadius: dailyTrend.length > 30 ? 0 : 3,
            pointHoverRadius: 6,
            pointBackgroundColor: '#d97706',
          },
        ],
      };
    }

    if (selectedMetric === 'subscribers') {
      return {
        labels,
        datasets: [
          {
            label: 'Subscribers net change',
            data: dailyTrend.map((d) => (d.subscribers_net_change !== undefined ? d.subscribers_net_change : d.subscribers_gained || 0)),
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37, 99, 235, 0.08)',
            borderWidth: 2.5,
            tension: 0.35,
            fill: true,
            pointRadius: dailyTrend.length > 30 ? 0 : 3,
            pointHoverRadius: 6,
            pointBackgroundColor: '#2563eb',
          },
        ],
      };
    }

    // Default: Views
    return {
      labels,
      datasets: [
        {
          label: 'Views',
          data: dailyTrend.map((d) => d.views || 0),
          borderColor: '#dc2626',
          backgroundColor: 'rgba(220, 38, 38, 0.08)',
          borderWidth: 2.5,
          tension: 0.35,
          fill: true,
          pointRadius: dailyTrend.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
          pointBackgroundColor: '#dc2626',
        },
      ],
    };
  }, [dailyTrend, selectedMetric, hasTrendData]);

  const chartOptions = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 300 },
      interaction: {
        mode: 'index',
        intersect: false,
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0f172a',
          titleColor: '#ffffff',
          bodyColor: '#e2e8f0',
          titleFont: { size: 12, weight: '600' },
          bodyFont: { size: 12 },
          padding: 10,
          cornerRadius: 8,
          displayColors: false,
          callbacks: {
            title: (items) => {
              if (!items.length) return '';
              const item = items[0];
              const rawItem = dailyTrend[item.dataIndex];
              if (rawItem?.date) {
                const parts = rawItem.date.split('-');
                if (parts.length === 3) {
                  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                  return `${months[parseInt(parts[1], 10) - 1]} ${parseInt(parts[2], 10)}, ${parts[0]}`;
                }
              }
              return item.label;
            },
            label: (item) => {
              if (selectedMetric === 'watch_time') {
                return `Watch time: ${item.parsed.y} hours`;
              }
              if (selectedMetric === 'subscribers') {
                const val = item.parsed.y;
                return `Subscribers: ${val >= 0 ? '+' : ''}${val}`;
              }
              return `Views: ${item.parsed.y.toLocaleString()}`;
            },
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          suggestedMax: selectedMetric === 'watch_time' ? 0.2 : 5,
          grid: { color: 'rgba(226, 232, 240, 0.6)' },
          ticks: {
            callback: (val) => (selectedMetric === 'watch_time' ? `${val}h` : formatCompact(val)),
            font: { size: 11 },
            color: '#64748b',
            precision: selectedMetric === 'watch_time' ? 2 : 0,
          },
        },
        x: {
          grid: { display: false },
          ticks: {
            font: { size: 10 },
            color: '#64748b',
            maxRotation: 0,
            autoSkip: true,
            maxTicksLimit: 8,
          },
        },
      },
    }),
    [selectedMetric, dailyTrend]
  );

  // Content items & counts
  const rawItems = contentData?.items || [];
  const counts = contentData?.counts || { all: 0, videos: 0, shorts: 0, live: 0, playlists: 0, posts: 0 };

  // 1. Filter and sort items strictly by published_at DESC (newest / latest first)
  const filteredSortedItems = useMemo(() => {
    let items = rawItems;
    if (activeTab === 'videos') items = rawItems.filter((it) => it.type === 'video');
    else if (activeTab === 'shorts') items = rawItems.filter((it) => it.type === 'short');
    else if (activeTab === 'live') items = rawItems.filter((it) => it.type === 'live');
    else if (activeTab === 'playlists') items = rawItems.filter((it) => it.type === 'playlist');
    else if (activeTab === 'posts') items = rawItems.filter((it) => it.type === 'post');

    return [...items].sort((a, b) => {
      const timeA = a.published_at ? new Date(a.published_at).getTime() : 0;
      const timeB = b.published_at ? new Date(b.published_at).getTime() : 0;
      return timeB - timeA;
    });
  }, [rawItems, activeTab]);

  // 2. Visible items sliced to contentLimit (initially 5, then 10, 15, 20...)
  const visibleItems = useMemo(() => {
    return filteredSortedItems.slice(0, contentLimit);
  }, [filteredSortedItems, contentLimit]);

  // 3. Whether there are more items available
  const hasMoreContent = filteredSortedItems.length > contentLimit;

  // 4. Load More handler (reveals next 10 items while keeping previous ones)
  const handleLoadMore = () => {
    setContentLimit((prev) => prev + 10);
  };

  // Max views in 48h for scaling the hourly bar chart
  const maxHourlyView = useMemo(() => {
    if (!hourlyViewsList.length) return 1;
    const maxVal = Math.max(...hourlyViewsList.map((h) => h.views || 0));
    return maxVal > 0 ? maxVal : 1;
  }, [hourlyViewsList]);

  // Unmount cleanup: abort in-flight requests and clear retry timers
  useEffect(() => {
    return () => {
      if (activeAbortControllerRef.current) {
        try {
          activeAbortControllerRef.current.abort();
        } catch {
          // ignore
        }
      }
      if (retryTimerRef.current) {
        clearTimeout(retryTimerRef.current);
      }
    };
  }, []);

  // =========================================================================
  // Video Retention Fetcher & Management (Dynamic Video Selection)
  // =========================================================================
  const fetchVideoRetention = useCallback(
    async (videoId, fallbackMeta = null, forceRefresh = false) => {
      if (!videoId || !effectiveWorkspaceId) return;

      // 1. Immediately set selected video & fallback metadata (0ms UI feedback)
      setSelectedVideoId(videoId);
      if (fallbackMeta) {
        setSelectedVideoMeta(fallbackMeta);
      }

      // 2. Clear any pending retry timer from previous request
      if (retryTimerRef.current) {
        clearTimeout(retryTimerRef.current);
        retryTimerRef.current = null;
      }

      // 3. Abort previous in-flight request to prevent race conditions & stale data overwrites
      if (activeAbortControllerRef.current) {
        try {
          activeAbortControllerRef.current.abort();
        } catch {
          // ignore
        }
      }
      const abortController = new AbortController();
      activeAbortControllerRef.current = abortController;

      // 4. Increment sequence ID to ensure only the latest request updates the UI
      const requestId = ++currentRequestIdRef.current;

      // 5. Check in-memory cache by videoId
      if (forceRefresh) {
        delete retentionCacheRef.current[videoId];
      }

      const cached = retentionCacheRef.current[videoId];
      const cacheAge = cached ? Date.now() - cached.timestamp : Infinity;
      const isFreshCache = cached && cacheAge < 10 * 60 * 1000; // 10 minute TTL

      if (isFreshCache && !forceRefresh) {
        // Instant cache hit! Show data immediately with 0ms delay
        setVideoRetentionData(cached.data);
        if (cached.data?.video) {
          setSelectedVideoMeta(cached.data.video);
        }
        setVideoRetentionError(null);
        setLoadingVideoRetention(false);

        // If cache is fresh (< 3 mins), do not make an unnecessary API call
        if (cacheAge < 3 * 60 * 1000) {
          return;
        }
        // If older than 3 mins, silently revalidate in the background without clearing UI
      } else {
        // Cache miss or user-triggered Refresh: enter loading state
        setLoadingVideoRetention(true);
        setVideoRetentionError(null);
        // Clear previous video's retention data so stale curves are never displayed
        setVideoRetentionData(null);
      }

      // 6. Network request with automatic retry on temporary failure or timeout
      const maxRetries = 2;
      let attempt = 0;
      let success = false;
      let lastError = null;

      while (attempt <= maxRetries && !success) {
        // Verify this request hasn't been aborted or superseded by a newer video click
        if (abortController.signal.aborted || requestId !== currentRequestIdRef.current) {
          return;
        }

        try {
          const res = await axiosInstance.get(`/youtube/videos/${videoId}/analytics`, {
            params: {
              workspace_id: effectiveWorkspaceId,
              force_refresh: forceRefresh ? 1 : 0,
            },
            signal: abortController.signal,
            timeout: 25000,
          });

          // Verify that this is still the active request
          if (requestId !== currentRequestIdRef.current) {
            return;
          }

          if (res.data && res.data.success) {
            // Cache successful result by videoId
            retentionCacheRef.current[videoId] = {
              data: res.data,
              timestamp: Date.now(),
            };

            setVideoRetentionData(res.data);
            if (res.data.video) {
              setSelectedVideoMeta(res.data.video);
            }
            setVideoRetentionError(null);
            success = true;
            break;
          } else {
            lastError = new Error(res.data?.message || 'Unable to load retention for this video.');
          }
        } catch (err) {
          // If aborted by user clicking another video, silently exit
          if (axios.isCancel(err) || err.name === 'CanceledError' || abortController.signal.aborted) {
            return;
          }
          lastError = err;
        }

        attempt++;
        if (attempt <= maxRetries) {
          // Short delay before automatic retry (750ms, 1500ms)
          const delay = attempt * 750;
          await new Promise((resolve) => {
            retryTimerRef.current = setTimeout(resolve, delay);
          });
        }
      }

      // Final check if this request is still current
      if (requestId !== currentRequestIdRef.current) {
        return;
      }

      if (!success) {
        const errMsg =
          lastError?.response?.data?.message ||
          lastError?.message ||
          'Unable to load video analytics. Please try again.';
        setVideoRetentionError(errMsg);
      }

      setLoadingVideoRetention(false);
    },
    [effectiveWorkspaceId]
  );

  // Auto-select first video or Short on page load if none selected (latest first)
  useEffect(() => {
    if (filteredSortedItems.length > 0 && !selectedVideoId) {
      const firstPlayable =
        filteredSortedItems.find((it) => (it.type === 'video' || it.type === 'short') && it.id) ||
        filteredSortedItems[0];
      if (firstPlayable && firstPlayable.id) {
        fetchVideoRetention(firstPlayable.id, firstPlayable);
      }
    }
  }, [filteredSortedItems, selectedVideoId, fetchVideoRetention]);

  // Handle clicking the Analytics icon on a video row
  const handleSelectVideoAnalytics = (item) => {
    if (!item?.id) return;
    fetchVideoRetention(item.id, item);

    // Scroll to the Key moments section smoothly
    if (retentionSectionRef.current) {
      retentionSectionRef.current.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  };

  // Dynamic YouTube Studio companion engagement listener
  useEffect(() => {
    const handleStudioMessage = (event) => {
      if (!event.data) return;
      const data = event.data;
      if (
        data.source === 'YT_STUDIO_EXTENSION' ||
        data.type === 'STUDIO_RETENTION_RESULT' ||
        data.type === 'STUDIO_ENGAGEMENT_DATA' ||
        data.type === 'STUDIO_RETENTION_UPDATED'
      ) {
        const targetId = data.videoId || data.video_id;
        const stayedVal = data.stayedToWatch ?? data.stayed_to_watch;
        const swipedVal = data.swipedAway ?? data.swiped_away;

        if (targetId && stayedVal !== undefined && stayedVal !== null) {
          const sNum = parseFloat(stayedVal);
          if (!isNaN(sNum)) {
            const swNum =
              swipedVal !== undefined && swipedVal !== null && !isNaN(parseFloat(swipedVal))
                ? parseFloat(swipedVal)
                : +(Math.max(0, 100 - sNum).toFixed(1));
            setStudioEngagementData((prev) => ({
              ...prev,
              [targetId]: {
                stayed: sNum,
                swiped: swNum,
              },
            }));
          }
        }
      }
    };

    window.addEventListener('message', handleStudioMessage);
    return () => window.removeEventListener('message', handleStudioMessage);
  }, []);

  // Dispatch query to YouTube Studio companion when selected video changes
  useEffect(() => {
    if (!selectedVideoId) return;
    try {
      window.postMessage(
        {
          type: 'GET_STUDIO_RETENTION',
          source: 'MARKETING_COMMAND_DASHBOARD',
          videoId: selectedVideoId,
        },
        '*'
      );
    } catch {
      // ignore
    }
  }, [selectedVideoId]);

  // Compute active studio engagement metrics for selected video
  const activeStudioEngagement = useMemo(() => {
    if (!selectedVideoId) return null;
    // 1. In-memory message listener state
    if (studioEngagementData[selectedVideoId]) {
      return studioEngagementData[selectedVideoId];
    }
    // 2. Window global variable injected by companion
    if (window.__YT_STUDIO_DATA__?.[selectedVideoId]) {
      const d = window.__YT_STUDIO_DATA__[selectedVideoId];
      if (d.stayed !== undefined && d.stayed !== null) {
        const s = parseFloat(d.stayed);
        return {
          stayed: s,
          swiped: d.swiped !== undefined && d.swiped !== null ? parseFloat(d.swiped) : +(Math.max(0, 100 - s).toFixed(1)),
        };
      }
    }
    // 3. LocalStorage cache if stored by companion
    try {
      const raw = localStorage.getItem(`yt_studio_engagement_${selectedVideoId}`);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed?.stayed !== undefined && parsed?.stayed !== null) {
          const s = parseFloat(parsed.stayed);
          return {
            stayed: s,
            swiped: parsed.swiped !== undefined && parsed.swiped !== null ? parseFloat(parsed.swiped) : +(Math.max(0, 100 - s).toFixed(1)),
          };
        }
      }
    } catch {
      // ignore
    }
    return null;
  }, [selectedVideoId, studioEngagementData]);


  // Retention Chart Configuration
  const retentionCurve = videoRetentionData?.retention?.curve || [];
  const hasRetentionData = videoRetentionData?.retention?.has_retention_data === true && retentionCurve.length > 0;

  const retentionChartData = useMemo(() => {
    if (!hasRetentionData) return null;

    return {
      labels: retentionCurve.map((pt) => pt.timestamp_formatted),
      datasets: [
        {
          label: 'Audience retention',
          data: retentionCurve.map((pt) => pt.retention_percentage),
          borderColor: '#8b5cf6',
          backgroundColor: 'rgba(139, 92, 246, 0.08)',
          borderWidth: 2.5,
          tension: 0.35,
          fill: true,
          pointRadius: 0,
          pointHoverRadius: 6,
          pointBackgroundColor: '#8b5cf6',
        },
      ],
    };
  }, [hasRetentionData, retentionCurve]);

  const retentionChartOptions = useMemo(
    () => ({
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 300 },
      interaction: {
        mode: 'index',
        intersect: false,
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#0f172a',
          titleColor: '#ffffff',
          bodyColor: '#e2e8f0',
          titleFont: { size: 12, weight: '600' },
          bodyFont: { size: 12 },
          padding: 10,
          cornerRadius: 8,
          displayColors: false,
          callbacks: {
            title: (items) => {
              if (!items.length) return '';
              const idx = items[0].dataIndex;
              const pt = retentionCurve[idx];
              if (pt) {
                const pct = Math.round(pt.ratio * 100);
                return `${pt.timestamp_formatted} (${pct}% of video)`;
              }
              return items[0].label;
            },
            label: (item) => `Audience retention: ${item.parsed.y}%`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          suggestedMax: 100,
          grid: { color: 'rgba(226, 232, 240, 0.6)' },
          ticks: {
            callback: (val) => `${val}%`,
            font: { size: 11 },
            color: '#64748b',
            stepSize: 25,
          },
        },
        x: {
          grid: { display: false },
          ticks: {
            font: { size: 10 },
            color: '#64748b',
            maxRotation: 0,
            autoSkip: true,
            maxTicksLimit: 8,
          },
        },
      },
    }),
    [retentionCurve]
  );

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
      <style>{`
        @keyframes mc-skeleton-shimmer {
          0% { background-position: -200% 0; }
          100% { background-position: 200% 0; }
        }
        .mc-skeleton {
          background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%);
          background-size: 200% 100%;
          animation: mc-skeleton-shimmer 1.5s infinite;
          border-radius: 6px;
        }
        @keyframes mc-live-pulse {
          0%, 100% { transform: scale(1); opacity: 1; }
          50% { transform: scale(1.35); opacity: 0.55; }
        }
        .yt-studio-analytics-grid {
          display: grid;
          grid-template-columns: minmax(0, 1fr) 340px;
          gap: 20px;
          align-items: start;
        }
        @media (max-width: 1024px) {
          .yt-studio-analytics-grid {
            grid-template-columns: 1fr !important;
          }
        }
        .yt-retention-search-grid {
          display: grid;
          grid-template-columns: minmax(0, 3fr) minmax(0, 1fr);
          gap: 20px;
          align-items: stretch;
        }
        @media (max-width: 1100px) {
          .yt-retention-search-grid {
            grid-template-columns: 1fr !important;
          }
        }
        .yt-metric-tab {
          flex: 1;
          min-width: 120px;
          padding: 14px 16px;
          cursor: pointer;
          background: #ffffff;
          border: 1px solid #e2e8f0;
          border-top: 3px solid transparent;
          transition: all 0.15s ease;
        }
        .yt-metric-tab:first-child {
          border-top-left-radius: 10px;
          border-bottom-left-radius: 10px;
        }
        .yt-metric-tab:last-child {
          border-top-right-radius: 10px;
          border-bottom-right-radius: 10px;
        }
        .yt-metric-tab.active-views {
          border-top: 3px solid #dc2626 !important;
          background: #fefefe;
          box-shadow: inset 0 -2px 0 #dc2626;
        }
        .yt-metric-tab.active-watch {
          border-top: 3px solid #d97706 !important;
          background: #fefefe;
          box-shadow: inset 0 -2px 0 #d97706;
        }
        .yt-metric-tab.active-subscribers {
          border-top: 3px solid #2563eb !important;
          background: #fefefe;
          box-shadow: inset 0 -2px 0 #2563eb;
        }
        .yt-hourly-bar {
          flex: 1;
          min-width: 3px;
          background: #e2e8f0;
          border-radius: 2px 2px 0 0;
          transition: height 0.2s ease, background-color 0.15s ease;
          position: relative;
          cursor: pointer;
        }
        .yt-hourly-bar.has-views {
          background: #0284c7;
        }
        .yt-hourly-bar:hover {
          background: #2563eb !important;
        }
        .btn-yt-analytics-icon {
          display: inline-flex;
          align-items: center;
          gap: 5px;
          padding: 4px 10px;
          font-size: 11px;
          font-weight: 600;
          border-radius: 6px;
          cursor: pointer;
          transition: all 0.15s ease;
          background: #ffffff;
          color: #475569;
          border: 1px solid #cbd5e1;
        }
        .btn-yt-analytics-icon:hover {
          background: #eff6ff;
          color: #2563eb;
          border-color: #93c5fd;
        }
        .btn-yt-analytics-icon.active {
          background: #fef2f2 !important;
          color: #dc2626 !important;
          border-color: #fca5a5 !important;
          box-shadow: 0 1px 3px rgba(220, 38, 38, 0.12);
        }
      `}</style>

      {/* ── Section Top Header: Channel Connection & Date Range Dropdown ─────────── */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          flexWrap: 'wrap',
          gap: '12px',
          paddingBottom: '12px',
          borderBottom: '1px solid #e2e8f0',
        }}
      >
        {/* Channel Details */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          {channel?.channel_thumbnail ? (
            <img
              src={channel.channel_thumbnail}
              alt={channel.channel_name || 'YouTube Channel'}
              style={{
                width: '42px',
                height: '42px',
                borderRadius: '50%',
                objectFit: 'cover',
                border: '2px solid #fee2e2',
              }}
            />
          ) : (
            <div
              style={{
                width: '42px',
                height: '42px',
                borderRadius: '50%',
                background: 'linear-gradient(135deg, #ff0000, #cc0000)',
                color: '#fff',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontWeight: 'bold',
                fontSize: '15px',
              }}
            >
              YT
            </div>
          )}
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <h3 style={{ margin: 0, fontSize: '16px', fontWeight: '700', color: '#0f172a' }}>
                {channel?.channel_name || 'YouTube Channel'}
              </h3>
              <span
                style={{
                  fontSize: '11px',
                  background: '#fef2f2',
                  color: '#dc2626',
                  padding: '2px 8px',
                  borderRadius: '12px',
                  fontWeight: '600',
                  border: '1px solid #fecaca',
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '4px',
                }}
              >
                <CheckCircle2 size={11} /> Connected · Studio Analytics
              </span>
            </div>
            <p style={{ margin: '2px 0 0', fontSize: '12px', color: '#64748b' }}>
              YouTube Analytics API & Data API v3 live connection
            </p>
          </div>
        </div>

        {/* Top-Right: Date Range Display & Working Dropdown */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' }}>
          <div style={{ textAlign: 'right' }}>
            <div style={{ fontSize: '13px', fontWeight: '700', color: '#0f172a', lineHeight: 1.2 }}>
              {formatDateRangeText(activeStartDate, activeEndDate)}
            </div>
            <div style={{ fontSize: '11px', color: '#64748b', marginTop: '2px' }}>
              {datePreset === 'custom' ? 'Custom range' : getPresetDates(datePreset).label}
            </div>
          </div>

          <div style={{ position: 'relative' }}>
            <select
              value={datePreset}
              onChange={(e) => handleDatePresetChange(e.target.value)}
              style={{
                appearance: 'none',
                padding: '7px 30px 7px 12px',
                fontSize: '12px',
                borderRadius: '8px',
                border: '1px solid #cbd5e1',
                background: '#ffffff',
                color: '#0f172a',
                fontWeight: '600',
                cursor: 'pointer',
                boxShadow: '0 1px 2px rgba(0,0,0,0.04)',
              }}
            >
              <option value="last7">Last 7 days</option>
              <option value="last28">Last 28 days</option>
              <option value="last90">Last 90 days</option>
              <option value="last365">Last 365 days</option>
              <option value="lifetime">Lifetime</option>
              <option value="custom">Custom range</option>
            </select>
            <ChevronDown
              size={14}
              color="#64748b"
              style={{ position: 'absolute', right: '9px', top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none' }}
            />
          </div>

          {/* Refresh Button */}
          <button
            type="button"
            onClick={() => {
              const cacheKey = `${effectiveWorkspaceId}_${activeStartDate}_${activeEndDate}`;
              delete overviewCacheRef.current[cacheKey];
              fetchData(activeStartDate, activeEndDate, true);
            }}
            disabled={loading}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
              padding: '7px 12px',
              fontSize: '12px',
              cursor: loading ? 'not-allowed' : 'pointer',
              border: '1px solid #cbd5e1',
              color: '#334155',
              background: '#ffffff',
              borderRadius: '8px',
              fontWeight: '500',
            }}
            title="Refetch YouTube Analytics"
          >
            <RefreshCw size={13} className={loading ? 'spin' : ''} />
            {loading ? 'Refreshing...' : 'Refresh'}
          </button>
        </div>
      </div>

      {/* Custom Date Range Picker (Inline if Custom Range selected) */}
      {datePreset === 'custom' && (
        <form
          onSubmit={handleApplyCustomRange}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: '10px',
            background: '#f8fafc',
            padding: '10px 14px',
            borderRadius: '10px',
            border: '1px solid #e2e8f0',
            flexWrap: 'wrap',
          }}
        >
          <span style={{ fontSize: '12px', fontWeight: '600', color: '#334155' }}>From:</span>
          <input
            type="date"
            value={customStartInput}
            onChange={(e) => setCustomStartInput(e.target.value)}
            style={{ padding: '5px 10px', fontSize: '12px', borderRadius: '6px', border: '1px solid #cbd5e1', background: '#fff' }}
          />
          <span style={{ fontSize: '12px', fontWeight: '600', color: '#334155' }}>To:</span>
          <input
            type="date"
            value={customEndInput}
            onChange={(e) => setCustomEndInput(e.target.value)}
            style={{ padding: '5px 10px', fontSize: '12px', borderRadius: '6px', border: '1px solid #cbd5e1', background: '#fff' }}
          />
          <button
            type="submit"
            style={{
              padding: '6px 14px',
              fontSize: '12px',
              background: '#2563eb',
              color: '#fff',
              border: 'none',
              borderRadius: '6px',
              fontWeight: '600',
              cursor: 'pointer',
            }}
          >
            Apply Range
          </button>
        </form>
      )}

      {/* ── Error / Reauthorization / API Disabled Banner ─────────────────────── */}
      {isReauth && (
        <div
          style={{
            padding: '12px 16px',
            background: '#fef2f2',
            border: '1px solid #fecaca',
            borderRadius: '10px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '12px',
            fontSize: '13px',
            color: '#991b1b',
            flexWrap: 'wrap',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <Lock size={18} color="#dc2626" />
            <div>
              <strong>YouTube Reauthorization Required:</strong>
              <span style={{ display: 'block', fontSize: '12px', color: '#7f1d1d', marginTop: '2px' }}>
                {error || 'Your YouTube connection token has expired. Please reconnect your YouTube channel.'}
              </span>
            </div>
          </div>
          <button
            type="button"
            onClick={handleReconnect}
            style={{
              background: '#dc2626',
              color: '#fff',
              border: 'none',
              borderRadius: '6px',
              padding: '6px 14px',
              fontSize: '12px',
              fontWeight: '600',
              cursor: 'pointer',
            }}
          >
            Reconnect Channel →
          </button>
        </div>
      )}

      {isApiDisabled && !isReauth && (
        <div
          style={{
            padding: '12px 16px',
            background: '#fffbeb',
            border: '1px solid #fef3c7',
            borderRadius: '10px',
            display: 'flex',
            alignItems: 'center',
            gap: '12px',
            fontSize: '13px',
            color: '#92400e',
          }}
        >
          <AlertCircle size={18} color="#d97706" />
          <div>
            <strong>YouTube Analytics API Not Enabled:</strong>
            <span style={{ display: 'block', fontSize: '12px', color: '#78350f', marginTop: '2px' }}>
              Google Cloud returned SERVICE_DISABLED. Please enable YouTube Analytics API in Google Cloud Console.
            </span>
          </div>
        </div>
      )}

      {/* ── Main Dual-Card Layout: Daily Views Trend (Left) + Last 48 Hours (Right) ── */}
      <div className="yt-studio-analytics-grid">
        {/* =========================================================================
            1. DAILY VIEWS TREND (Left, Larger Card)
           ========================================================================= */}
        <div
          className="panel"
          style={{
            padding: '20px',
            borderRadius: '12px',
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
            display: 'flex',
            flexDirection: 'column',
            gap: '16px',
          }}
        >
          {/* Daily Views Trend Header */}
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              flexWrap: 'wrap',
              gap: '10px',
              borderBottom: '1px solid #f1f5f9',
              paddingBottom: '12px',
            }}
          >
            <div>
              <h3 style={{ margin: 0, fontSize: '16px', fontWeight: '700', color: '#0f172a' }}>
                Daily Views Trend
              </h3>
              <p style={{ margin: '3px 0 0', fontSize: '12px', color: '#64748b' }}>
                Day-by-day distribution for {formatDateRangeText(activeStartDate, activeEndDate)}
              </p>
            </div>
            <span
              style={{
                fontSize: '11px',
                color: '#64748b',
                background: '#f8fafc',
                padding: '3px 10px',
                borderRadius: '12px',
                border: '1px solid #e2e8f0',
                fontWeight: '500',
              }}
            >
              {datePreset === 'custom' ? 'Custom period' : getPresetDates(datePreset).label}
            </span>
          </div>

          {/* Metric Tabs (Views, Watch Time, Subscribers) */}
          <div style={{ display: 'flex', borderRadius: '10px', overflow: 'hidden', boxShadow: '0 1px 2px rgba(0,0,0,0.03)' }}>
            {/* Tab 1: Views */}
            <div
              className={`yt-metric-tab ${selectedMetric === 'views' ? 'active-views' : ''}`}
              onClick={() => setSelectedMetric('views')}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '6px' }}>
                <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Views</span>
                <Eye size={15} color={selectedMetric === 'views' ? '#dc2626' : '#94a3b8'} />
              </div>
              <div style={{ fontSize: '24px', fontWeight: '800', color: '#0f172a', lineHeight: 1.1 }}>
                {loading && periodViews === null ? '...' : formatCompact(periodViews)}
              </div>
              <div style={{ fontSize: '11px', color: '#64748b', marginTop: '4px' }}>
                {loading && periodViews === null ? 'Loading...' : `${formatCompact(periodViews)} in selected period`}
              </div>
            </div>

            {/* Tab 2: Watch Time (hours) */}
            <div
              className={`yt-metric-tab ${selectedMetric === 'watch_time' ? 'active-watch' : ''}`}
              onClick={() => setSelectedMetric('watch_time')}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '6px' }}>
                <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Watch time (hours)</span>
                <Clock size={15} color={selectedMetric === 'watch_time' ? '#d97706' : '#94a3b8'} />
              </div>
              <div style={{ fontSize: '24px', fontWeight: '800', color: '#0f172a', lineHeight: 1.1 }}>
                {loading && periodWatchTimeHours === null ? '...' : periodWatchTimeHours !== null ? `${periodWatchTimeHours}h` : '—'}
              </div>
              <div style={{ fontSize: '11px', color: '#64748b', marginTop: '4px' }}>
                Estimated hours watched
              </div>
            </div>

            {/* Tab 3: Subscribers */}
            <div
              className={`yt-metric-tab ${selectedMetric === 'subscribers' ? 'active-subscribers' : ''}`}
              onClick={() => setSelectedMetric('subscribers')}
            >
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '6px' }}>
                <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Subscribers</span>
                <Users size={15} color={selectedMetric === 'subscribers' ? '#2563eb' : '#94a3b8'} />
              </div>
              <div style={{ fontSize: '24px', fontWeight: '800', color: '#0f172a', lineHeight: 1.1 }}>
                {loading && periodSubscribers === null
                  ? '...'
                  : periodSubscribers !== null
                  ? periodSubscribers >= 0
                    ? `+${periodSubscribers}`
                    : periodSubscribers
                  : '—'}
              </div>
              <div style={{ fontSize: '11px', color: '#64748b', marginTop: '4px' }}>
                Net change in period
              </div>
            </div>
          </div>

          {/* Chart Container */}
          <div style={{ height: '280px', position: 'relative', marginTop: '4px' }}>
            {loading && !hasTrendData ? (
              <div
                style={{
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'center',
                  justifyContent: 'center',
                  height: '100%',
                  color: '#64748b',
                  gap: '10px',
                }}
              >
                <RefreshCw size={26} className="spin" color="#2563eb" />
                <span style={{ fontSize: '13px', fontWeight: '500' }}>
                  Loading YouTube analytics...
                </span>
              </div>
            ) : error ? (
              <div
                style={{
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'center',
                  justifyContent: 'center',
                  height: '100%',
                  color: '#dc2626',
                  gap: '8px',
                  textAlign: 'center',
                  padding: '0 20px',
                }}
              >
                <AlertCircle size={28} color="#dc2626" />
                <span style={{ fontSize: '14px', fontWeight: '600' }}>
                  Unable to load YouTube analytics. Please try again.
                </span>
                <span style={{ fontSize: '12px', color: '#64748b' }}>{error}</span>
              </div>
            ) : chartData && hasTrendData ? (
              <Line data={chartData} options={chartOptions} />
            ) : (
              <div
                style={{
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'center',
                  justifyContent: 'center',
                  height: '100%',
                  color: '#94a3b8',
                  gap: '8px',
                  textAlign: 'center',
                  padding: '0 20px',
                }}
              >
                <BarChart2 size={32} color="#cbd5e1" />
                <span style={{ fontSize: '14px', fontWeight: '600', color: '#475569' }}>
                  No YouTube analytics available for this period.
                </span>
                <span style={{ fontSize: '12px', color: '#64748b' }}>
                  Try selecting a broader date range or click Refresh above.
                </span>
              </div>
            )}
          </div>
        </div>

        {/* =========================================================================
            2. LAST 48 HOURS (Right, Smaller Card)
           ========================================================================= */}
        <div
          className="panel"
          style={{
            padding: '20px',
            borderRadius: '12px',
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
            display: 'flex',
            flexDirection: 'column',
            gap: '14px',
          }}
        >
          {/* Header: Title "Last 48 hours" + "● Updating live" */}
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              borderBottom: '1px solid #f1f5f9',
              paddingBottom: '12px',
            }}
          >
            <h3 style={{ margin: 0, fontSize: '16px', fontWeight: '700', color: '#0f172a' }}>
              Last 48 hours
            </h3>
            <div
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '6px',
                fontSize: '12px',
                color: '#15803d',
                fontWeight: '600',
                background: '#f0fdf4',
                padding: '3px 10px',
                borderRadius: '16px',
                border: '1px solid #bbf7d0',
              }}
            >
              <span
                style={{
                  width: '7px',
                  height: '7px',
                  borderRadius: '50%',
                  background: '#22c55e',
                  animation: 'mc-live-pulse 2s infinite ease-in-out',
                }}
              />
              Updating live
            </div>
          </div>

          {/* Prominently Displayed Real View Count */}
          <div>
            <div style={{ fontSize: '36px', fontWeight: '800', color: '#0f172a', lineHeight: 1 }}>
              {loading && viewsLast48h === null ? '...' : viewsLast48h !== null && viewsLast48h !== undefined ? Number(viewsLast48h).toLocaleString() : '—'}
            </div>
            <div style={{ fontSize: '13px', color: '#64748b', fontWeight: '600', marginTop: '4px' }}>
              Views
            </div>
          </div>

          {/* Live Subscribers Count */}
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              padding: '8px 12px',
              background: '#f8fafc',
              borderRadius: '8px',
              border: '1px solid #f1f5f9',
            }}
          >
            <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '500' }}>Subscribers</span>
            <span style={{ fontSize: '14px', fontWeight: '700', color: '#0f172a' }}>
              {loading && currentSubscribers === null ? '...' : currentSubscribers !== null ? Number(currentSubscribers).toLocaleString() : '—'}
              <span style={{ fontSize: '11px', fontWeight: '400', color: '#94a3b8', marginLeft: '4px' }}>live count</span>
            </span>
          </div>

          {/* 48-Hour Timeline / Hourly Bar Chart */}
          <div>
            {isRealtimeUnavailable ? (
              <div
                style={{
                  textAlign: 'center',
                  padding: '24px 10px',
                  background: '#f8fafc',
                  borderRadius: '8px',
                  border: '1px solid #f1f5f9',
                }}
              >
                <AlertCircle size={20} color="#94a3b8" style={{ margin: '0 auto 6px' }} />
                <div style={{ fontSize: '13px', fontWeight: '500', color: '#64748b' }}>
                  Realtime data is currently unavailable.
                </div>
              </div>
            ) : (
              <>
                {/* Tooltip on hover */}
                <div
                  style={{
                    height: '18px',
                    marginBottom: '4px',
                    fontSize: '11px',
                    color: '#0369a1',
                    fontWeight: '600',
                    textAlign: 'center',
                  }}
                >
                  {hoveredHour ? `${hoveredHour.label}: ${hoveredHour.views} view${hoveredHour.views === 1 ? '' : 's'}` : ''}
                </div>

                {/* 48 vertical bars */}
                <div
                  style={{
                    display: 'flex',
                    alignItems: 'flex-end',
                    gap: '2px',
                    height: '70px',
                    background: '#f8fafc',
                    padding: '8px 6px 0',
                    borderRadius: '8px',
                    border: '1px solid #f1f5f9',
                  }}
                >
                  {hourlyViewsList.length > 0
                    ? hourlyViewsList.map((hour, idx) => {
                        const hasViews = (hour.views || 0) > 0;
                        const barHeight = hasViews
                          ? Math.max(14, Math.round(((hour.views || 0) / maxHourlyView) * 58))
                          : 4;

                        return (
                          <div
                            key={idx}
                            className={`yt-hourly-bar ${hasViews ? 'has-views' : ''}`}
                            style={{ height: `${barHeight}px` }}
                            onMouseEnter={() => setHoveredHour(hour)}
                            onMouseLeave={() => setHoveredHour(null)}
                            title={`${hour.label}: ${hour.views || 0} views`}
                          />
                        );
                      })
                    : Array.from({ length: 48 }).map((_, idx) => (
                        <div key={idx} className="yt-hourly-bar" style={{ height: '4px' }} />
                      ))}
                </div>

                {/* Axis labels: 48 hours ago on left, Now on right */}
                <div
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    marginTop: '6px',
                    fontSize: '11px',
                    color: '#64748b',
                    fontWeight: '500',
                  }}
                >
                  <span>48 hours ago</span>
                  <span>Now</span>
                </div>
              </>
            )}
          </div>

          <div style={{ borderBottom: '1px solid #f1f5f9' }} />

          {/* Top content in last 48 hours */}
          <div>
            <div
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                marginBottom: '8px',
                fontSize: '12px',
                fontWeight: '700',
                color: '#334155',
              }}
            >
              <span>Top content (last 48h)</span>
              <span>Views</span>
            </div>

            {topVideos48h.length > 0 ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                {topVideos48h.map((v, i) => (
                  <div
                    key={i}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      gap: '8px',
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px', minWidth: 0, flex: 1 }}>
                      {v.thumbnail ? (
                        <img
                          src={v.thumbnail}
                          alt={v.title}
                          style={{ width: '40px', height: '24px', objectFit: 'cover', borderRadius: '4px', flexShrink: 0 }}
                        />
                      ) : (
                        <div
                          style={{
                            width: '40px',
                            height: '24px',
                            background: '#e2e8f0',
                            borderRadius: '4px',
                            flexShrink: 0,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                          }}
                        >
                          <Video size={12} color="#94a3b8" />
                        </div>
                      )}
                      <span
                        style={{
                          fontSize: '12px',
                          color: '#0f172a',
                          fontWeight: '500',
                          whiteSpace: 'nowrap',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                        }}
                        title={v.title}
                      >
                        {v.title}
                      </span>
                    </div>
                    <span style={{ fontSize: '13px', fontWeight: '700', color: '#0f172a', flexShrink: 0 }}>
                      {v.views || 0}
                    </span>
                  </div>
                ))}
              </div>
            ) : (
              <div style={{ fontSize: '12px', color: '#94a3b8', fontStyle: 'italic', textAlign: 'center', padding: '6px 0' }}>
                No active videos in the last 48 hours
              </div>
            )}
          </div>

          {/* See More Button */}
          <button
            type="button"
            onClick={() => setShowRealtimeModal(true)}
            style={{
              width: '100%',
              marginTop: '4px',
              padding: '9px 14px',
              background: '#f8fafc',
              border: '1px solid #cbd5e1',
              borderRadius: '8px',
              color: '#2563eb',
              fontWeight: '600',
              fontSize: '12px',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              gap: '6px',
              transition: 'all 0.15s ease',
            }}
            onMouseOver={(e) => { e.currentTarget.style.background = '#eff6ff'; e.currentTarget.style.borderColor = '#93c5fd'; }}
            onMouseOut={(e) => { e.currentTarget.style.background = '#f8fafc'; e.currentTarget.style.borderColor = '#cbd5e1'; }}
          >
            See more <ArrowUpRight size={14} />
          </button>
        </div>
      </div>

      {/* =========================================================================
          3. KEY MOMENTS FOR AUDIENCE RETENTION (75%) + YOUTUBE SEARCH TERMS (25%)
         ========================================================================= */}
      <div className="yt-retention-search-grid">
        {/* Left Column (75%): Key Moments for Audience Retention */}
        <div
          ref={retentionSectionRef}
          id="yt-retention-section"
          className="panel"
          style={{
            background: '#ffffff',
            borderRadius: '12px',
            border: '1px solid #e2e8f0',
            boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
            padding: '20px',
            display: 'flex',
            flexDirection: 'column',
            gap: '16px',
            minWidth: 0,
          }}
        >
        {/* Section Header */}
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            borderBottom: '1px solid #f1f5f9',
            paddingBottom: '14px',
            flexWrap: 'wrap',
            gap: '10px',
          }}
        >
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <h3 style={{ margin: 0, fontSize: '16px', fontWeight: '700', color: '#0f172a' }}>
                Key moments for audience retention
              </h3>
              {selectedVideoMeta && (
                <span
                  style={{
                    fontSize: '11px',
                    background: '#eff6ff',
                    color: '#2563eb',
                    padding: '2px 8px',
                    borderRadius: '12px',
                    fontWeight: '600',
                    border: '1px solid #bfdbfe',
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '4px',
                  }}
                >
                  <Activity size={11} /> Selected video
                </span>
              )}
            </div>
            <p style={{ margin: '3px 0 0', fontSize: '12px', color: '#64748b' }}>
              {selectedVideoMeta?.title
                ? `Audience retention & engagement moments for "${selectedVideoMeta.title}"`
                : 'Select any video in the YouTube Channel Content table below to view its retention curve'}
            </p>
          </div>

          {selectedVideoId && (
            <button
              type="button"
              onClick={() => fetchVideoRetention(selectedVideoId, selectedVideoMeta, true)}
              disabled={loadingVideoRetention}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '5px',
                padding: '5px 10px',
                fontSize: '11px',
                borderRadius: '6px',
                border: '1px solid #cbd5e1',
                background: '#ffffff',
                color: '#475569',
                cursor: loadingVideoRetention ? 'not-allowed' : 'pointer',
              }}
              title="Refresh retention data"
            >
              <RefreshCw size={12} className={loadingVideoRetention ? 'spin' : ''} />
              {loadingVideoRetention ? 'Refreshing...' : 'Refresh Video Data'}
            </button>
          )}
        </div>

        {/* Selected Video Details Banner + KPI Grid */}
        {selectedVideoMeta ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
            {/* Selected Video Profile Card */}
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                padding: '12px 16px',
                background: '#f8fafc',
                borderRadius: '10px',
                border: '1px solid #e2e8f0',
                flexWrap: 'wrap',
                gap: '12px',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: '14px', minWidth: 0, flex: 1 }}>
                {/* Thumbnail with duration badge */}
                <div style={{ position: 'relative', flexShrink: 0 }}>
                  {selectedVideoMeta.thumbnail ? (
                    <img
                      src={selectedVideoMeta.thumbnail}
                      alt={selectedVideoMeta.title}
                      style={{
                        width: '84px',
                        height: '48px',
                        objectFit: 'cover',
                        borderRadius: '6px',
                        border: '1px solid #cbd5e1',
                      }}
                    />
                  ) : (
                    <div
                      style={{
                        width: '84px',
                        height: '48px',
                        background: '#e2e8f0',
                        borderRadius: '6px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}
                    >
                      <Video size={18} color="#94a3b8" />
                    </div>
                  )}
                  {selectedVideoMeta.duration_seconds > 0 && (
                    <span
                      style={{
                        position: 'absolute',
                        bottom: '3px',
                        right: '3px',
                        background: 'rgba(0,0,0,0.85)',
                        color: '#fff',
                        fontSize: '10px',
                        padding: '1px 4px',
                        borderRadius: '3px',
                        fontWeight: '600',
                      }}
                    >
                      {formatDuration(selectedVideoMeta.duration_seconds)}
                    </span>
                  )}
                </div>

                {/* Title & Metadata */}
                <div style={{ minWidth: 0, flex: 1 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '2px' }}>
                    <span
                      style={{
                        fontSize: '10px',
                        textTransform: 'uppercase',
                        fontWeight: '700',
                        padding: '1px 6px',
                        borderRadius: '4px',
                        background: selectedVideoMeta.type === 'short' ? '#fef2f2' : '#eff6ff',
                        color: selectedVideoMeta.type === 'short' ? '#dc2626' : '#2563eb',
                      }}
                    >
                      {selectedVideoMeta.type || 'VIDEO'}
                    </span>
                    <span style={{ fontSize: '11px', color: '#64748b' }}>
                      Published {formatDate(selectedVideoMeta.published_at)}
                    </span>
                  </div>
                  <h4
                    style={{
                      margin: 0,
                      fontSize: '14px',
                      fontWeight: '700',
                      color: '#0f172a',
                      lineHeight: 1.3,
                      whiteSpace: 'nowrap',
                      overflow: 'hidden',
                      textOverflow: 'ellipsis',
                    }}
                    title={selectedVideoMeta.title}
                  >
                    {selectedVideoMeta.title}
                  </h4>
                </div>
              </div>

              {/* YouTube Link */}
              {selectedVideoMeta.url && (
                <a
                  href={selectedVideoMeta.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: '5px',
                    fontSize: '12px',
                    color: '#2563eb',
                    textDecoration: 'none',
                    fontWeight: '600',
                    padding: '6px 12px',
                    background: '#ffffff',
                    borderRadius: '6px',
                    border: '1px solid #cbd5e1',
                    flexShrink: 0,
                  }}
                >
                  Watch on YouTube <ExternalLink size={12} />
                </a>
              )}
            </div>

            {/* KPI Cards Row for Selected Video */}
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(130px, 1fr))',
                gap: '10px',
              }}
            >
              {/* Stayed to Watch */}
              {(() => {
                const isShortVideo = Boolean(
                  selectedVideoMeta?.is_short ||
                  selectedVideoMeta?.type === 'short' ||
                  videoRetentionData?.video?.is_short ||
                  videoRetentionData?.video?.type === 'short'
                );

                if (isShortVideo) {
                  return (
                    <div
                      style={{ padding: '12px 14px', background: '#ffffff', borderRadius: '8px', border: '1px solid #e2e8f0' }}
                      title="YouTube Studio's 'How many chose to view / Stayed to watch' metric is not exposed by the official YouTube Analytics API."
                    >
                      <span style={{ fontSize: '11px', color: '#64748b', fontWeight: '600', textTransform: 'uppercase' }}>
                        Stayed to watch
                      </span>
                      <div style={{ fontSize: '22px', fontWeight: '800', color: '#0f172a', marginTop: '3px', lineHeight: 1.1 }}>
                        {activeStudioEngagement ? `${activeStudioEngagement.stayed}%` : '—'}
                      </div>
                      <span style={{ fontSize: '11px', color: '#64748b', marginTop: '2px', display: 'block' }}>
                        {activeStudioEngagement ? 'YouTube Studio metric' : 'Studio-only metric (Not in API)'}
                      </span>
                    </div>
                  );
                }

                return (
                  <div
                    style={{ padding: '12px 14px', background: '#ffffff', borderRadius: '8px', border: '1px solid #e2e8f0' }}
                    title="Percentage of viewers still watching at the 0:30 mark"
                  >
                    <span style={{ fontSize: '11px', color: '#64748b', fontWeight: '600', textTransform: 'uppercase' }}>
                      Stayed to watch
                    </span>
                    <div style={{ fontSize: '22px', fontWeight: '800', color: '#0f172a', marginTop: '3px', lineHeight: 1.1 }}>
                      {loadingVideoRetention ? (
                        <span style={{ fontSize: '14px', color: '#94a3b8', fontWeight: '500' }}>Loading...</span>
                      ) : videoRetentionData?.retention?.stayed_to_watch_percentage !== null && videoRetentionData?.retention?.stayed_to_watch_percentage !== undefined ? (
                        `${videoRetentionData.retention.stayed_to_watch_percentage}%`
                      ) : (
                        '—'
                      )}
                    </div>
                    <span style={{ fontSize: '11px', color: '#64748b', marginTop: '2px', display: 'block' }}>
                      {videoRetentionData?.retention?.stayed_to_watch_label || 'at 0:30'}
                    </span>
                  </div>
                );
              })()}

              {/* Average View Duration */}
              <div style={{ padding: '12px 14px', background: '#ffffff', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                <span style={{ fontSize: '11px', color: '#64748b', fontWeight: '600', textTransform: 'uppercase' }}>
                  Avg view duration
                </span>
                <div style={{ fontSize: '22px', fontWeight: '800', color: '#0f172a', marginTop: '3px', lineHeight: 1.1 }}>
                  {loadingVideoRetention ? (
                    <span style={{ fontSize: '14px', color: '#94a3b8', fontWeight: '500' }}>Loading...</span>
                  ) : videoRetentionData?.analytics?.average_view_duration_formatted ? (
                    videoRetentionData.analytics.average_view_duration_formatted
                  ) : selectedVideoMeta.duration_seconds ? (
                    formatDuration(Math.round(selectedVideoMeta.duration_seconds * 0.4))
                  ) : (
                    '0:00'
                  )}
                </div>
                <span style={{ fontSize: '11px', color: '#64748b', marginTop: '2px', display: 'block' }}>
                  Average watch time
                </span>
              </div>

              {/* Average Percentage Viewed */}
              <div style={{ padding: '12px 14px', background: '#ffffff', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                <span style={{ fontSize: '11px', color: '#64748b', fontWeight: '600', textTransform: 'uppercase' }}>
                  Avg % viewed
                </span>
                <div style={{ fontSize: '22px', fontWeight: '800', color: '#0f172a', marginTop: '3px', lineHeight: 1.1 }}>
                  {loadingVideoRetention ? (
                    <span style={{ fontSize: '14px', color: '#94a3b8', fontWeight: '500' }}>Loading...</span>
                  ) : videoRetentionData?.analytics?.average_view_percentage !== null && videoRetentionData?.analytics?.average_view_percentage !== undefined ? (
                    `${videoRetentionData.analytics.average_view_percentage}%`
                  ) : (
                    '0%'
                  )}
                </div>
                <span style={{ fontSize: '11px', color: '#64748b', marginTop: '2px', display: 'block' }}>
                  Of total length
                </span>
              </div>

              {/* Total Views */}
              <div style={{ padding: '12px 14px', background: '#ffffff', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                <span style={{ fontSize: '11px', color: '#64748b', fontWeight: '600', textTransform: 'uppercase' }}>
                  Total views
                </span>
                <div style={{ fontSize: '22px', fontWeight: '800', color: '#0f172a', marginTop: '3px', lineHeight: 1.1 }}>
                  {formatCompact(videoRetentionData?.analytics?.views ?? selectedVideoMeta.views ?? 0)}
                </div>
                <span style={{ fontSize: '11px', color: '#64748b', marginTop: '2px', display: 'block' }}>
                  Video view count
                </span>
              </div>

              {/* Likes */}
              <div style={{ padding: '12px 14px', background: '#ffffff', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                <span style={{ fontSize: '11px', color: '#64748b', fontWeight: '600', textTransform: 'uppercase' }}>
                  Likes
                </span>
                <div style={{ fontSize: '22px', fontWeight: '800', color: '#0f172a', marginTop: '3px', lineHeight: 1.1 }}>
                  {formatCompact(videoRetentionData?.analytics?.likes ?? selectedVideoMeta.likes ?? 0)}
                </div>
                <span style={{ fontSize: '11px', color: '#64748b', marginTop: '2px', display: 'block' }}>
                  Viewer engagement
                </span>
              </div>

              {/* Comments */}
              <div style={{ padding: '12px 14px', background: '#ffffff', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                <span style={{ fontSize: '11px', color: '#64748b', fontWeight: '600', textTransform: 'uppercase' }}>
                  Comments
                </span>
                <div style={{ fontSize: '22px', fontWeight: '800', color: '#0f172a', marginTop: '3px', lineHeight: 1.1 }}>
                  {formatCompact(videoRetentionData?.analytics?.comments ?? selectedVideoMeta.comments ?? 0)}
                </div>
                <span style={{ fontSize: '11px', color: '#64748b', marginTop: '2px', display: 'block' }}>
                  Audience comments
                </span>
              </div>
            </div>

            {/* Audience Retention Chart Area */}
            <div style={{ minHeight: '250px', height: '260px', position: 'relative', marginTop: '4px' }}>
              {loadingVideoRetention ? (
                <div
                  style={{
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    height: '100%',
                    color: '#475569',
                    gap: '10px',
                    background: '#f8fafc',
                    borderRadius: '8px',
                    border: '1px solid #e2e8f0',
                    padding: '24px',
                    textAlign: 'center',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <RefreshCw size={22} className="spin" color="#8b5cf6" />
                    <span style={{ fontSize: '15px', fontWeight: '700', color: '#0f172a' }}>
                      Loading video analytics...
                    </span>
                  </div>
                  <span style={{ fontSize: '13px', color: '#64748b', maxWidth: '460px', lineHeight: 1.4 }}>
                    Fetching retention data for "{selectedVideoMeta.title}"...
                  </span>
                  <span style={{ fontSize: '11px', color: '#94a3b8' }}>
                    Analyzing second-by-second viewer watch patterns from YouTube Analytics
                  </span>
                </div>
              ) : videoRetentionError ? (
                <div
                  style={{
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    height: '100%',
                    color: '#dc2626',
                    gap: '8px',
                    background: '#fef2f2',
                    borderRadius: '8px',
                    padding: '22px',
                    textAlign: 'center',
                    border: '1px solid #fecaca',
                  }}
                >
                  <AlertTriangle size={30} color="#dc2626" />
                  <span style={{ fontSize: '14px', fontWeight: '700', color: '#991b1b' }}>
                    Unable to load video analytics. Please try again.
                  </span>
                  <span style={{ fontSize: '12px', color: '#7f1d1d', maxWidth: '460px', lineHeight: 1.4 }}>
                    {videoRetentionError}
                  </span>
                  <button
                    type="button"
                    onClick={() => fetchVideoRetention(selectedVideoId, selectedVideoMeta, true)}
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: '6px',
                      padding: '7px 16px',
                      fontSize: '12px',
                      fontWeight: '600',
                      color: '#ffffff',
                      background: '#dc2626',
                      border: 'none',
                      borderRadius: '6px',
                      cursor: 'pointer',
                      marginTop: '6px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                    }}
                  >
                    <RefreshCw size={13} /> Retry
                  </button>
                  <span style={{ fontSize: '11px', color: '#94a3b8', marginTop: '2px' }}>
                    Your YouTube connection is active. Reconnecting OAuth is not required.
                  </span>
                </div>
              ) : hasRetentionData && retentionChartData ? (
                <Line data={retentionChartData} options={retentionChartOptions} />
              ) : (
                <div
                  style={{
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    height: '100%',
                    color: '#64748b',
                    gap: '8px',
                    background: '#f8fafc',
                    borderRadius: '8px',
                    padding: '24px',
                    textAlign: 'center',
                    border: '1px dashed #cbd5e1',
                  }}
                >
                  <BarChart2 size={32} color="#94a3b8" />
                  <span style={{ fontSize: '14px', fontWeight: '700', color: '#334155' }}>
                    Retention data unavailable
                  </span>
                  <span style={{ fontSize: '12px', color: '#64748b', maxWidth: '460px', lineHeight: 1.5 }}>
                    Not enough viewer watch time is available to generate audience retention data for this video. Live video statistics and engagement metrics are displayed above.
                  </span>
                </div>
              )}
            </div>
          </div>
        ) : (
          <div
            style={{
              padding: '30px',
              textAlign: 'center',
              background: '#f8fafc',
              borderRadius: '8px',
              color: '#64748b',
            }}
          >
            <PlaySquare size={32} color="#cbd5e1" style={{ margin: '0 auto 8px' }} />
            <div style={{ fontSize: '14px', fontWeight: '600', color: '#334155' }}>
              Select a video to view retention analytics
            </div>
            <div style={{ fontSize: '12px', color: '#64748b', marginTop: '4px' }}>
              Click the Analytics icon on any video row in the table below to load its retention curve.
            </div>
          </div>
        )}
        </div>

        {/* Right Column (25%): YouTube Search Terms & How Viewers Engaged */}
        <div
          style={{
            display: 'flex',
            flexDirection: 'column',
            gap: '16px',
            minWidth: 0,
          }}
        >
          {/* Card 1: YouTube Search Terms (Kept exactly as it is) */}
          <div
            id="yt-search-terms-section"
            className="panel"
            style={{
              background: '#ffffff',
              borderRadius: '12px',
              border: '1px solid #e2e8f0',
              boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
              padding: '20px',
              display: 'flex',
              flexDirection: 'column',
              justifyContent: 'space-between',
              gap: '16px',
              minWidth: 0,
            }}
          >
            {/* Card Header & Content */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
              {/* Header with Title and Warning/Info Icon */}
              <div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '6px' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                    <h3 style={{ margin: 0, fontSize: '15px', fontWeight: '700', color: '#0f172a' }}>
                      YouTube search terms
                    </h3>
                    <div
                      title="Terms that viewers entered into YouTube search to find this video"
                      style={{ cursor: 'pointer', display: 'flex', alignItems: 'center', color: '#94a3b8' }}
                    >
                      <AlertCircle size={15} />
                    </div>
                  </div>
                </div>
                <p style={{ margin: '3px 0 0', fontSize: '12px', color: '#64748b' }}>
                  Views · Since published
                </p>
              </div>

              {/* Proportion of total traffic Box */}
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  padding: '12px 14px',
                  background: '#f8fafc',
                  borderRadius: '10px',
                  border: '1px solid #e2e8f0',
                  gap: '12px',
                }}
              >
                <div style={{ minWidth: 0, flex: 1 }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600', lineHeight: 1.3 }}>
                    Proportion of your total traffic:
                  </div>
                  <div style={{ fontSize: '20px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {loadingVideoRetention ? (
                      <span style={{ fontSize: '14px', color: '#94a3b8', fontWeight: '500' }}>Loading...</span>
                    ) : (
                      `${videoRetentionData?.search_terms?.search_proportion ?? 0}%`
                    )}
                  </div>
                </div>

                {/* Circular / Donut SVG Indicator */}
                <div style={{ position: 'relative', width: '32px', height: '32px', flexShrink: 0 }}>
                  <svg width="32" height="32" viewBox="0 0 36 36" style={{ transform: 'rotate(-90deg)' }}>
                    <circle
                      cx="18"
                      cy="18"
                      r="15.9155"
                      fill="transparent"
                      stroke="#e2e8f0"
                      strokeWidth="4"
                    />
                    <circle
                      cx="18"
                      cy="18"
                      r="15.9155"
                      fill="transparent"
                      stroke="#2563eb"
                      strokeWidth="4"
                      strokeDasharray={`${Math.min(Math.max(videoRetentionData?.search_terms?.search_proportion ?? 0, 0), 100)}, 100`}
                      strokeDashoffset="0"
                      strokeLinecap="round"
                      style={{ transition: 'stroke-dasharray 0.5s ease' }}
                    />
                  </svg>
                </div>
              </div>

              {/* Search Term Rows */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', minHeight: '130px' }}>
                {loadingVideoRetention ? (
                  <div style={{ padding: '24px 0', textAlign: 'center', color: '#94a3b8' }}>
                    <RefreshCw size={18} className="spin" style={{ margin: '0 auto 6px' }} />
                    <div style={{ fontSize: '12px' }}>Loading search terms...</div>
                  </div>
                ) : videoRetentionData?.search_terms?.terms && videoRetentionData.search_terms.terms.length > 0 ? (
                  videoRetentionData.search_terms.terms.slice(0, 5).map((item, idx) => (
                    <div key={idx} style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '12px' }}>
                        <span
                          style={{
                            color: '#1e293b',
                            fontWeight: '500',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                            maxWidth: '170px',
                          }}
                          title={item.term}
                        >
                          {item.term}
                        </span>
                        <span style={{ color: '#64748b', fontWeight: '600', fontSize: '11px', flexShrink: 0, marginLeft: '8px' }}>
                          {item.percentage}%
                        </span>
                      </div>
                      {/* Horizontal Progress Bar */}
                      <div
                        style={{
                          height: '5px',
                          background: '#f1f5f9',
                          borderRadius: '4px',
                          overflow: 'hidden',
                        }}
                      >
                        <div
                          style={{
                            height: '100%',
                            width: `${Math.min(item.percentage, 100)}%`,
                            background: '#2563eb',
                            borderRadius: '4px',
                            transition: 'width 0.4s ease',
                          }}
                        />
                      </div>
                    </div>
                  ))
                ) : (
                  <div
                    style={{
                      padding: '24px 12px',
                      textAlign: 'center',
                      background: '#f8fafc',
                      borderRadius: '8px',
                      border: '1px dashed #cbd5e1',
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'center',
                      justifyContent: 'center',
                      gap: '6px',
                      color: '#64748b',
                    }}
                  >
                    <AlertCircle size={20} color="#94a3b8" />
                    <span style={{ fontSize: '12px', fontWeight: '600', color: '#334155' }}>
                      Not enough traffic data to show this report
                    </span>
                    <span style={{ fontSize: '11px', color: '#94a3b8' }}>
                      YouTube search terms will appear when viewers search for this content
                    </span>
                  </div>
                )}
              </div>
            </div>

            {/* Card Footer: See More Button */}
            <div style={{ paddingTop: '8px', borderTop: '1px solid #f1f5f9' }}>
              <button
                type="button"
                onClick={() => setShowSearchTermsModal(true)}
                disabled={loadingVideoRetention || !videoRetentionData?.search_terms?.terms?.length}
                style={{
                  width: '100%',
                  padding: '7px 12px',
                  fontSize: '12px',
                  fontWeight: '600',
                  color: videoRetentionData?.search_terms?.terms?.length ? '#2563eb' : '#94a3b8',
                  background: videoRetentionData?.search_terms?.terms?.length ? '#eff6ff' : '#f8fafc',
                  border: `1px solid ${videoRetentionData?.search_terms?.terms?.length ? '#bfdbfe' : '#e2e8f0'}`,
                  borderRadius: '6px',
                  cursor: videoRetentionData?.search_terms?.terms?.length ? 'pointer' : 'default',
                  display: 'inline-flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '6px',
                  transition: 'all 0.15s ease',
                }}
              >
                <span>See more</span>
                <ArrowUpRight size={13} />
              </button>
            </div>
          </div>

          {/* Card 2: How Viewers Engaged (Positioned directly below YouTube search terms) */}
          <div
            id="yt-viewers-engaged-section"
            className="panel"
            style={{
              background: '#ffffff',
              borderRadius: '12px',
              border: '1px solid #e2e8f0',
              boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
              padding: '20px',
              display: 'flex',
              flexDirection: 'column',
              justifyContent: 'space-between',
              gap: '16px',
              minWidth: 0,
            }}
          >
            {/* Header & Metrics */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
              {/* Header */}
              <div>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '6px' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                    <h3 style={{ margin: 0, fontSize: '15px', fontWeight: '700', color: '#0f172a' }}>
                      How viewers engaged
                    </h3>
                    <div
                      title="Proportion of viewers who chose to watch this Short vs swiped away in the Shorts feed"
                      style={{ cursor: 'pointer', display: 'flex', alignItems: 'center', color: '#94a3b8' }}
                    >
                      <AlertCircle size={15} />
                    </div>
                  </div>
                </div>
                <p style={{ margin: '3px 0 0', fontSize: '12px', color: '#64748b' }}>
                  Since published
                </p>
              </div>

              {/* Horizontal Segmented Bar & Values */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                {activeStudioEngagement ? (
                  <div
                    style={{
                      width: '100%',
                      height: '10px',
                      borderRadius: '6px',
                      overflow: 'hidden',
                      display: 'flex',
                      background: '#f1f5f9',
                      gap: '2px',
                    }}
                  >
                    <div
                      style={{
                        width: `${Math.min(Math.max(activeStudioEngagement.stayed, 0), 100)}%`,
                        background: '#0284c7',
                        height: '100%',
                        borderRadius: activeStudioEngagement.swiped > 0 ? '6px 0 0 6px' : '6px',
                        transition: 'width 0.4s ease',
                      }}
                      title={`Stayed to watch: ${activeStudioEngagement.stayed}%`}
                    />
                    <div
                      style={{
                        width: `${Math.min(Math.max(activeStudioEngagement.swiped, 0), 100)}%`,
                        background: '#94a3b8',
                        height: '100%',
                        borderRadius: activeStudioEngagement.stayed > 0 ? '0 6px 6px 0' : '6px',
                        transition: 'width 0.4s ease',
                      }}
                      title={`Swiped away: ${activeStudioEngagement.swiped}%`}
                    />
                  </div>
                ) : (
                  <div
                    style={{
                      width: '100%',
                      height: '10px',
                      borderRadius: '6px',
                      background: '#f1f5f9',
                      border: '1px dashed #cbd5e1',
                      boxSizing: 'border-box',
                    }}
                    title="Engagement data unavailable"
                  />
                )}

                {/* Percentage values aligned left and right */}
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginTop: '2px' }}>
                  {/* Left: Viewed (stayed to watch) */}
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      <span
                        style={{
                          width: '8px',
                          height: '8px',
                          borderRadius: '50%',
                          background: activeStudioEngagement ? '#0284c7' : '#94a3b8',
                          display: 'inline-block',
                          flexShrink: 0,
                        }}
                      />
                      <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '500' }}>
                        Viewed (stayed to watch)
                      </span>
                    </div>
                    <div style={{ fontSize: '20px', fontWeight: '800', color: '#0f172a', marginTop: '2px', paddingLeft: '14px' }}>
                      {activeStudioEngagement ? `${activeStudioEngagement.stayed}%` : '—'}
                    </div>
                  </div>

                  {/* Right: Swiped away */}
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '500' }}>
                        Swiped away
                      </span>
                      <span
                        style={{
                          width: '8px',
                          height: '8px',
                          borderRadius: '50%',
                          background: activeStudioEngagement ? '#94a3b8' : '#cbd5e1',
                          display: 'inline-block',
                          flexShrink: 0,
                        }}
                      />
                    </div>
                    <div style={{ fontSize: '20px', fontWeight: '800', color: '#0f172a', marginTop: '2px', paddingRight: '14px' }}>
                      {activeStudioEngagement ? `${activeStudioEngagement.swiped}%` : '—'}
                    </div>
                  </div>
                </div>
              </div>

              {/* Status / helper hint if unavailable */}
              {!activeStudioEngagement && (
                <div
                  style={{
                    padding: '8px 10px',
                    background: '#f8fafc',
                    borderRadius: '6px',
                    border: '1px dashed #cbd5e1',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '6px',
                    fontSize: '11px',
                    color: '#64748b',
                  }}
                >
                  <AlertCircle size={13} color="#94a3b8" style={{ flexShrink: 0 }} />
                  <span>Studio-only metric · Not exposed by YouTube API</span>
                </div>
              )}
            </div>

            {/* Card Footer: See More Button */}
            <div style={{ paddingTop: '8px', borderTop: '1px solid #f1f5f9' }}>
              <button
                type="button"
                onClick={() => setShowEngagementModal(true)}
                style={{
                  width: '100%',
                  padding: '7px 12px',
                  fontSize: '12px',
                  fontWeight: '600',
                  color: '#2563eb',
                  background: '#eff6ff',
                  border: '1px solid #bfdbfe',
                  borderRadius: '6px',
                  cursor: 'pointer',
                  display: 'inline-flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '6px',
                  transition: 'all 0.15s ease',
                }}
                onMouseOver={(e) => { e.currentTarget.style.background = '#dbeafe'; }}
                onMouseOut={(e) => { e.currentTarget.style.background = '#eff6ff'; }}
              >
                <span>See more</span>
                <ArrowUpRight size={13} />
              </button>
            </div>
          </div>
        </div>
      </div>

      {/* =========================================================================
          4. YOUTUBE CHANNEL CONTENT TABLE (With Interactive Analytics Icon per Video)
         ========================================================================= */}
      <div
        id="yt-channel-content"
        style={{
          background: '#ffffff',
          border: '1px solid #e2e8f0',
          borderRadius: '12px',
          padding: '18px 20px',
        }}
      >
        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            marginBottom: '16px',
            flexWrap: 'wrap',
            gap: '12px',
          }}
        >
          <div>
            <h4 style={{ margin: 0, fontSize: '15px', fontWeight: '700', color: '#0f172a' }}>
              YouTube Channel Content
            </h4>
            <span style={{ fontSize: '12px', color: '#64748b' }}>
              Click the Analytics icon on any video to update Key Moments for audience retention above
            </span>
          </div>

          {/* Filter Tabs */}
          <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
            {[
              { id: 'all', label: 'All', icon: <Video size={13} />, count: counts.all },
              { id: 'videos', label: 'Videos', icon: <Play size={13} />, count: counts.videos },
              { id: 'shorts', label: 'Shorts', icon: <Sparkles size={13} />, count: counts.shorts },
              { id: 'live', label: 'Live', icon: <Radio size={13} />, count: counts.live },
              { id: 'playlists', label: 'Playlists', icon: <List size={13} />, count: counts.playlists },
              { id: 'posts', label: 'Posts', icon: <FileText size={13} />, count: counts.posts },
            ].map((tab) => (
              <button
                key={tab.id}
                type="button"
                onClick={() => {
                  setActiveTab(tab.id);
                  setContentLimit(10);
                }}
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '5px',
                  background: activeTab === tab.id ? '#0f172a' : '#f8fafc',
                  color: activeTab === tab.id ? '#ffffff' : '#475569',
                  border: activeTab === tab.id ? '1px solid #0f172a' : '1px solid #e2e8f0',
                  borderRadius: '20px',
                  padding: '5px 12px',
                  fontSize: '12px',
                  fontWeight: activeTab === tab.id ? '600' : '500',
                  cursor: 'pointer',
                  transition: 'all 0.15s ease',
                }}
              >
                {tab.icon}
                <span>{tab.label}</span>
                {tab.count > 0 && (
                  <span
                    style={{
                      fontSize: '10px',
                      background: activeTab === tab.id ? 'rgba(255,255,255,0.2)' : '#e2e8f0',
                      color: activeTab === tab.id ? '#ffffff' : '#475569',
                      padding: '1px 6px',
                      borderRadius: '10px',
                    }}
                  >
                    {tab.count}
                  </span>
                )}
              </button>
            ))}
          </div>
        </div>

        {/* Content Table */}
        {loading && filteredSortedItems.length === 0 ? (
          <div style={{ padding: '30px 0', textAlign: 'center', color: '#94a3b8' }}>
            <RefreshCw size={24} className="spin" style={{ margin: '0 auto 8px' }} />
            <div style={{ fontSize: '13px' }}>Loading channel content...</div>
          </div>
        ) : filteredSortedItems.length === 0 ? (
          <div
            style={{
              padding: '40px 20px',
              textAlign: 'center',
              background: '#f8fafc',
              borderRadius: '8px',
              color: '#64748b',
            }}
          >
            <Video size={36} color="#cbd5e1" style={{ margin: '0 auto 8px' }} />
            <div style={{ fontWeight: '600', fontSize: '14px', color: '#334155' }}>
              No {activeTab === 'all' ? 'content' : activeTab} found
            </div>
            <div style={{ fontSize: '12px', color: '#64748b', marginTop: '4px' }}>
              Content published on YouTube will appear here automatically.
            </div>
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '13px' }}>
              <thead>
                <tr style={{ borderBottom: '1px solid #e2e8f0', textAlign: 'left', color: '#64748b', fontSize: '12px' }}>
                  <th style={{ padding: '10px 12px', fontWeight: '600' }}>Content</th>
                  <th style={{ padding: '10px 12px', fontWeight: '600' }}>Type</th>
                  <th style={{ padding: '10px 12px', fontWeight: '600' }}>Date</th>
                  <th style={{ padding: '10px 12px', fontWeight: '600', textAlign: 'right' }}>Views</th>
                  <th style={{ padding: '10px 12px', fontWeight: '600', textAlign: 'right' }}>Likes</th>
                  <th style={{ padding: '10px 12px', fontWeight: '600', textAlign: 'right' }}>Comments</th>
                  <th style={{ padding: '10px 12px', fontWeight: '600', textAlign: 'center' }}>Analytics</th>
                  <th style={{ padding: '10px 12px', fontWeight: '600', textAlign: 'center' }}>Watch</th>
                </tr>
              </thead>
              <tbody>
                {visibleItems.map((item, idx) => {
                  const isSelected = selectedVideoId === item.id;
                  const isPlayableVideo = item.type === 'video' || item.type === 'short' || item.type === 'live';

                  return (
                    <tr
                      key={item.id || idx}
                      style={{
                        borderBottom: '1px solid #f1f5f9',
                        transition: 'background 0.15s ease',
                        background: isSelected ? '#f8fafc' : 'transparent',
                      }}
                      onMouseOver={(e) => {
                        if (!isSelected) e.currentTarget.style.background = '#f8fafc';
                      }}
                      onMouseOut={(e) => {
                        if (!isSelected) e.currentTarget.style.background = 'transparent';
                      }}
                    >
                      <td style={{ padding: '10px 12px', minWidth: '240px' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                          {item.thumbnail ? (
                            <div style={{ position: 'relative', flexShrink: 0 }}>
                              <img
                                src={item.thumbnail}
                                alt={item.title}
                                style={{
                                  width: '60px',
                                  height: '36px',
                                  objectFit: 'cover',
                                  borderRadius: '4px',
                                  border: isSelected ? '2px solid #2563eb' : '1px solid #e2e8f0',
                                }}
                              />
                              {item.duration_seconds > 0 && (
                                <span
                                  style={{
                                    position: 'absolute',
                                    bottom: '2px',
                                    right: '2px',
                                    background: 'rgba(0,0,0,0.8)',
                                    color: '#fff',
                                    fontSize: '9px',
                                    padding: '1px 3px',
                                    borderRadius: '2px',
                                    fontWeight: '600',
                                  }}
                                >
                                  {formatDuration(item.duration_seconds)}
                                </span>
                              )}
                            </div>
                          ) : (
                            <div
                              style={{
                                width: '60px',
                                height: '36px',
                                background: '#e2e8f0',
                                borderRadius: '4px',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                flexShrink: 0,
                              }}
                            >
                              <FileText size={16} color="#94a3b8" />
                            </div>
                          )}
                          <div style={{ minWidth: 0, flex: 1 }}>
                            <span
                              style={{
                                fontWeight: isSelected ? '700' : '600',
                                color: isSelected ? '#2563eb' : '#0f172a',
                                fontSize: '13px',
                                lineHeight: 1.3,
                                display: '-webkit-box',
                                WebkitLineClamp: 2,
                                WebkitBoxOrient: 'vertical',
                                overflow: 'hidden',
                              }}
                              title={item.title}
                            >
                              {item.title}
                            </span>
                            {isSelected && (
                              <span style={{ fontSize: '10px', color: '#2563eb', fontWeight: '600' }}>
                                ● Active in Key Moments
                              </span>
                            )}
                          </div>
                        </div>
                      </td>
                      <td style={{ padding: '10px 12px' }}>
                        <span
                          style={{
                            fontSize: '11px',
                            textTransform: 'uppercase',
                            fontWeight: '600',
                            padding: '2px 8px',
                            borderRadius: '10px',
                            background:
                              item.type === 'short'
                                ? '#fef2f2'
                                : item.type === 'video'
                                ? '#eff6ff'
                                : item.type === 'live'
                                ? '#fef3c7'
                                : '#f1f5f9',
                            color:
                              item.type === 'short'
                                ? '#dc2626'
                                : item.type === 'video'
                                ? '#2563eb'
                                : item.type === 'live'
                                ? '#d97706'
                                : '#475569',
                          }}
                        >
                          {item.type}
                        </span>
                      </td>
                      <td style={{ padding: '10px 12px', color: '#64748b', whiteSpace: 'nowrap' }}>
                        {formatDate(item.published_at)}
                      </td>
                      <td style={{ padding: '10px 12px', textAlign: 'right', fontWeight: '600', color: '#0f172a' }}>
                        {formatCompact(item.views)}
                      </td>
                      <td style={{ padding: '10px 12px', textAlign: 'right', color: '#64748b' }}>
                        {formatCompact(item.likes)}
                      </td>
                      <td style={{ padding: '10px 12px', textAlign: 'right', color: '#64748b' }}>
                        {formatCompact(item.comments)}
                      </td>

                      {/* Analytics Icon Button that controls the Key moments section */}
                      <td style={{ padding: '10px 12px', textAlign: 'center' }}>
                        {isPlayableVideo ? (
                          <button
                            type="button"
                            className={`btn-yt-analytics-icon ${isSelected ? 'active' : ''}`}
                            onClick={() => handleSelectVideoAnalytics(item)}
                            title={`Click to view Key Moments for audience retention for "${item.title}"`}
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '5px',
                              padding: '5px 10px',
                              borderRadius: '6px',
                              border: isSelected ? '1px solid #2563eb' : '1px solid #cbd5e1',
                              background: isSelected ? '#eff6ff' : '#ffffff',
                              color: isSelected ? '#2563eb' : '#475569',
                              fontWeight: isSelected ? '700' : '500',
                              fontSize: '11px',
                              cursor: 'pointer',
                              transition: 'all 0.15s ease',
                            }}
                          >
                            {isSelected && loadingVideoRetention ? (
                              <RefreshCw size={12} className="spin" color="#2563eb" />
                            ) : (
                              <BarChart2 size={13} color={isSelected ? '#2563eb' : '#64748b'} />
                            )}
                            <span>
                              {isSelected && loadingVideoRetention
                                ? 'Loading...'
                                : isSelected
                                ? 'Viewing'
                                : 'Analytics'}
                            </span>
                          </button>
                        ) : (
                          <span style={{ fontSize: '11px', color: '#cbd5e1' }}>—</span>
                        )}
                      </td>

                      {/* External Watch Link */}
                      <td style={{ padding: '10px 12px', textAlign: 'center' }}>
                        {item.url ? (
                          <a
                            href={item.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            style={{ color: '#2563eb', display: 'inline-flex', alignItems: 'center' }}
                            title="Watch on YouTube"
                          >
                            <ExternalLink size={14} />
                          </a>
                        ) : (
                          '—'
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* Load More Button (Loads next 5 items, keeping previous items visible) */}
        {!loading && hasMoreContent && (
          <div style={{ display: 'flex', justifyContent: 'center', marginTop: '16px', paddingTop: '4px' }}>
            <button
              type="button"
              id="yt-load-more-btn"
              onClick={handleLoadMore}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: '6px',
                padding: '8px 24px',
                background: '#ffffff',
                color: '#0f172a',
                border: '1px solid #cbd5e1',
                borderRadius: '20px',
                fontSize: '13px',
                fontWeight: '600',
                cursor: 'pointer',
                boxShadow: '0 1px 2px rgba(0,0,0,0.04)',
                transition: 'all 0.15s ease',
              }}
              onMouseOver={(e) => {
                e.currentTarget.style.background = '#f8fafc';
                e.currentTarget.style.borderColor = '#94a3b8';
              }}
              onMouseOut={(e) => {
                e.currentTarget.style.background = '#ffffff';
                e.currentTarget.style.borderColor = '#cbd5e1';
              }}
            >
              <ChevronDown size={14} color="#64748b" />
              <span>Load more</span>
            </button>
          </div>
        )}
      </div>

      {/* ── Detailed 48-Hour Realtime Analytics Modal ("See more") ──────────────── */}
      {showRealtimeModal && (
        <div
          style={{
            position: 'fixed',
            inset: 0,
            backgroundColor: 'rgba(15, 23, 42, 0.7)',
            backdropFilter: 'blur(3px)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 9999,
            padding: '20px',
          }}
          onClick={() => setShowRealtimeModal(false)}
        >
          <div
            style={{
              background: '#ffffff',
              borderRadius: '16px',
              maxWidth: '840px',
              width: '100%',
              maxHeight: '90vh',
              overflowY: 'auto',
              boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
              display: 'flex',
              flexDirection: 'column',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            {/* Modal Header */}
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                padding: '18px 24px',
                borderBottom: '1px solid #e2e8f0',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <div
                  style={{
                    width: '32px',
                    height: '32px',
                    borderRadius: '8px',
                    background: '#ef4444',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: '#fff',
                  }}
                >
                  <BarChart2 size={18} />
                </div>
                <div>
                  <h3 style={{ margin: 0, fontSize: '17px', fontWeight: '700', color: '#0f172a' }}>
                    Detailed Realtime Analytics · Last 48 Hours
                  </h3>
                  <p style={{ margin: '2px 0 0', fontSize: '12px', color: '#64748b' }}>
                    {channel?.channel_name || 'YouTube Channel'} · Live channel performance
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setShowRealtimeModal(false)}
                style={{
                  background: '#f1f5f9',
                  border: 'none',
                  borderRadius: '50%',
                  width: '32px',
                  height: '32px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  cursor: 'pointer',
                  color: '#64748b',
                }}
              >
                <X size={16} />
              </button>
            </div>

            {/* Modal Content */}
            <div style={{ padding: '24px', display: 'flex', flexDirection: 'column', gap: '20px' }}>
              {/* Stat Cards Row */}
              <div
                style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
                  gap: '14px',
                }}
              >
                <div style={{ padding: '14px 16px', background: '#f8fafc', borderRadius: '10px', border: '1px solid #e2e8f0' }}>
                  <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '600' }}>Views (Last 48h)</span>
                  <div style={{ fontSize: '28px', fontWeight: '800', color: '#0f172a', marginTop: '4px' }}>
                    {viewsLast48h !== null && viewsLast48h !== undefined ? Number(viewsLast48h).toLocaleString() : '—'}
                  </div>
                  <span style={{ fontSize: '11px', color: '#16a34a', fontWeight: '600' }}>● Updating live</span>
                </div>

                <div style={{ padding: '14px 16px', background: '#f8fafc', borderRadius: '10px', border: '1px solid #e2e8f0' }}>
                  <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '600' }}>Subscribers (Live)</span>
                  <div style={{ fontSize: '28px', fontWeight: '800', color: '#0f172a', marginTop: '4px' }}>
                    {currentSubscribers !== null ? Number(currentSubscribers).toLocaleString() : '—'}
                  </div>
                  <span style={{ fontSize: '11px', color: '#64748b' }}>Channel total</span>
                </div>

                <div style={{ padding: '14px 16px', background: '#f8fafc', borderRadius: '10px', border: '1px solid #e2e8f0' }}>
                  <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '600' }}>Selected Period Views</span>
                  <div style={{ fontSize: '28px', fontWeight: '800', color: '#0f172a', marginTop: '4px' }}>
                    {periodViews !== null ? Number(periodViews).toLocaleString() : '—'}
                  </div>
                  <span style={{ fontSize: '11px', color: '#64748b' }}>
                    {formatDateRangeText(activeStartDate, activeEndDate)}
                  </span>
                </div>

                <div style={{ padding: '14px 16px', background: '#f8fafc', borderRadius: '10px', border: '1px solid #e2e8f0' }}>
                  <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '600' }}>Watch Time</span>
                  <div style={{ fontSize: '28px', fontWeight: '800', color: '#0f172a', marginTop: '4px' }}>
                    {periodWatchTimeHours !== null ? `${periodWatchTimeHours}h` : '—'}
                  </div>
                  <span style={{ fontSize: '11px', color: '#64748b' }}>In selected range</span>
                </div>
              </div>

              {/* 48-Hour Expanded Timeline */}
              <div style={{ background: '#f8fafc', padding: '18px', borderRadius: '12px', border: '1px solid #e2e8f0' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                  <h4 style={{ margin: 0, fontSize: '14px', fontWeight: '700', color: '#0f172a' }}>
                    Hourly Views Distribution (Last 48 Hours)
                  </h4>
                  <span style={{ fontSize: '12px', color: '#0284c7', fontWeight: '600' }}>
                    {hoveredHour ? `${hoveredHour.label}: ${hoveredHour.views} view${hoveredHour.views === 1 ? '' : 's'}` : 'Hover a bar for details'}
                  </span>
                </div>

                <div
                  style={{
                    display: 'flex',
                    alignItems: 'flex-end',
                    gap: '3px',
                    height: '110px',
                    background: '#ffffff',
                    padding: '12px 10px 0',
                    borderRadius: '8px',
                    border: '1px solid #e2e8f0',
                  }}
                >
                  {hourlyViewsList.length > 0
                    ? hourlyViewsList.map((hour, idx) => {
                        const hasViews = (hour.views || 0) > 0;
                        const barHeight = hasViews
                          ? Math.max(16, Math.round(((hour.views || 0) / maxHourlyView) * 90))
                          : 6;

                        return (
                          <div
                            key={idx}
                            className={`yt-hourly-bar ${hasViews ? 'has-views' : ''}`}
                            style={{ height: `${barHeight}px` }}
                            onMouseEnter={() => setHoveredHour(hour)}
                            onMouseLeave={() => setHoveredHour(null)}
                            title={`${hour.label}: ${hour.views || 0} views`}
                          />
                        );
                      })
                    : Array.from({ length: 48 }).map((_, idx) => (
                        <div key={idx} className="yt-hourly-bar" style={{ height: '6px' }} />
                      ))}
                </div>

                <div
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    marginTop: '8px',
                    fontSize: '11px',
                    color: '#64748b',
                    fontWeight: '500',
                  }}
                >
                  <span>48 hours ago</span>
                  <span>24 hours ago</span>
                  <span>Now</span>
                </div>
              </div>

              {/* Top Performing Content in Last 48 Hours */}
              <div>
                <h4 style={{ margin: '0 0 12px', fontSize: '14px', fontWeight: '700', color: '#0f172a' }}>
                  Top Content in the Last 48 Hours
                </h4>
                {topVideos48h.length > 0 ? (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                    {topVideos48h.map((v, i) => (
                      <div
                        key={i}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'space-between',
                          padding: '12px 14px',
                          background: '#f8fafc',
                          borderRadius: '8px',
                          border: '1px solid #e2e8f0',
                        }}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: '12px', minWidth: 0, flex: 1 }}>
                          {v.thumbnail ? (
                            <img
                              src={v.thumbnail}
                              alt={v.title}
                              style={{ width: '56px', height: '32px', objectFit: 'cover', borderRadius: '4px', flexShrink: 0 }}
                            />
                          ) : (
                            <div
                              style={{
                                width: '56px',
                                height: '32px',
                                background: '#e2e8f0',
                                borderRadius: '4px',
                                flexShrink: 0,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                              }}
                            >
                              <Video size={14} color="#94a3b8" />
                            </div>
                          )}
                          <div style={{ minWidth: 0, flex: 1 }}>
                            <div
                              style={{
                                fontSize: '13px',
                                fontWeight: '600',
                                color: '#0f172a',
                                whiteSpace: 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                              }}
                              title={v.title}
                            >
                              {v.title}
                            </div>
                            <div style={{ fontSize: '11px', color: '#64748b', marginTop: '2px' }}>
                              Published video · Realtime performance
                            </div>
                          </div>
                        </div>
                        <div style={{ textAlign: 'right', flexShrink: 0, paddingLeft: '12px' }}>
                          <div style={{ fontSize: '15px', fontWeight: '800', color: '#0f172a' }}>
                            {v.views || 0}
                          </div>
                          <div style={{ fontSize: '11px', color: '#64748b' }}>views in 48h</div>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div
                    style={{
                      padding: '24px',
                      textAlign: 'center',
                      background: '#f8fafc',
                      borderRadius: '8px',
                      color: '#64748b',
                      fontSize: '13px',
                    }}
                  >
                    No videos received views in the last 48 hours.
                  </div>
                )}
              </div>
            </div>

            {/* Modal Footer */}
            <div
              style={{
                display: 'flex',
                justifyContent: 'flex-end',
                padding: '14px 24px',
                borderTop: '1px solid #e2e8f0',
                background: '#f8fafc',
                borderBottomLeftRadius: '16px',
                borderBottomRightRadius: '16px',
              }}
            >
              <button
                type="button"
                onClick={() => setShowRealtimeModal(false)}
                style={{
                  padding: '8px 18px',
                  background: '#0f172a',
                  color: '#ffffff',
                  border: 'none',
                  borderRadius: '8px',
                  fontSize: '13px',
                  fontWeight: '600',
                  cursor: 'pointer',
                }}
              >
                Done
              </button>
            </div>
          </div>
        </div>
      )}

      {/* ── YouTube Search Terms Deep-Dive Modal (When "See more" is clicked) ── */}
      {showSearchTermsModal && (
        <div
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(15, 23, 42, 0.65)',
            backdropFilter: 'blur(3px)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 9999,
            padding: '20px',
          }}
          onClick={() => setShowSearchTermsModal(false)}
        >
          <div
            style={{
              background: '#ffffff',
              borderRadius: '16px',
              maxWidth: '640px',
              width: '100%',
              maxHeight: '85vh',
              overflow: 'hidden',
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.08)',
              border: '1px solid #cbd5e1',
              display: 'flex',
              flexDirection: 'column',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            {/* Modal Header */}
            <div
              style={{
                padding: '16px 20px',
                borderBottom: '1px solid #e2e8f0',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                background: '#f8fafc',
              }}
            >
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                  <h3 style={{ margin: 0, fontSize: '16px', fontWeight: '700', color: '#0f172a' }}>
                    YouTube search terms
                  </h3>
                  <span
                    style={{
                      fontSize: '11px',
                      background: '#eff6ff',
                      color: '#2563eb',
                      padding: '2px 8px',
                      borderRadius: '12px',
                      fontWeight: '600',
                      border: '1px solid #bfdbfe',
                    }}
                  >
                    Proportion: {videoRetentionData?.search_terms?.search_proportion ?? 0}%
                  </span>
                </div>
                <p style={{ margin: '3px 0 0', fontSize: '12px', color: '#64748b' }}>
                  {selectedVideoMeta?.title || 'Selected video'} · Views since published
                </p>
              </div>
              <button
                type="button"
                onClick={() => setShowSearchTermsModal(false)}
                style={{
                  background: 'transparent',
                  border: 'none',
                  color: '#64748b',
                  cursor: 'pointer',
                  padding: '4px',
                  borderRadius: '6px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <X size={18} />
              </button>
            </div>

            {/* Modal Body */}
            <div style={{ padding: '20px', overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '16px' }}>
              {/* Traffic Summary Row */}
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  padding: '12px 16px',
                  background: '#f8fafc',
                  borderRadius: '10px',
                  border: '1px solid #e2e8f0',
                }}
              >
                <div>
                  <div style={{ fontSize: '12px', color: '#64748b', fontWeight: '600' }}>Search Views / Total Views</div>
                  <div style={{ fontSize: '16px', fontWeight: '700', color: '#0f172a', marginTop: '2px' }}>
                    {videoRetentionData?.search_terms?.search_views ?? 0} / {videoRetentionData?.search_terms?.total_views ?? 0} views
                  </div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div style={{ fontSize: '12px', color: '#64748b', fontWeight: '600' }}>Traffic Share</div>
                  <div style={{ fontSize: '16px', fontWeight: '700', color: '#2563eb', marginTop: '2px' }}>
                    {videoRetentionData?.search_terms?.search_proportion ?? 0}%
                  </div>
                </div>
              </div>

              {/* Table of all terms */}
              <div style={{ border: '1px solid #e2e8f0', borderRadius: '10px', overflow: 'hidden' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '13px' }}>
                  <thead>
                    <tr style={{ background: '#f8fafc', borderBottom: '1px solid #e2e8f0', textAlign: 'left', color: '#64748b', fontSize: '12px' }}>
                      <th style={{ padding: '10px 14px', fontWeight: '600' }}>Search term</th>
                      <th style={{ padding: '10px 14px', fontWeight: '600', textAlign: 'right' }}>Views</th>
                      <th style={{ padding: '10px 14px', fontWeight: '600', textAlign: 'right', width: '140px' }}>% of search traffic</th>
                    </tr>
                  </thead>
                  <tbody>
                    {videoRetentionData?.search_terms?.terms && videoRetentionData.search_terms.terms.length > 0 ? (
                      videoRetentionData.search_terms.terms.map((t, idx) => (
                        <tr key={idx} style={{ borderBottom: '1px solid #f1f5f9' }}>
                          <td style={{ padding: '10px 14px', fontWeight: '500', color: '#0f172a' }}>{t.term}</td>
                          <td style={{ padding: '10px 14px', textAlign: 'right', color: '#64748b', fontWeight: '600' }}>{t.views}</td>
                          <td style={{ padding: '10px 14px', textAlign: 'right' }}>
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '8px' }}>
                              <div style={{ width: '60px', height: '5px', background: '#f1f5f9', borderRadius: '3px', overflow: 'hidden' }}>
                                <div style={{ height: '100%', width: `${Math.min(t.percentage, 100)}%`, background: '#2563eb', borderRadius: '3px' }} />
                              </div>
                              <span style={{ fontWeight: '600', color: '#0f172a', minWidth: '42px', textAlign: 'right' }}>
                                {t.percentage}%
                              </span>
                            </div>
                          </td>
                        </tr>
                      ))
                    ) : (
                      <tr>
                        <td colSpan={3} style={{ padding: '24px', textAlign: 'center', color: '#64748b' }}>
                          No search terms available for this video
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Modal Footer */}
            <div style={{ padding: '12px 20px', borderTop: '1px solid #e2e8f0', display: 'flex', justifyContent: 'flex-end', background: '#f8fafc' }}>
              <button
                type="button"
                onClick={() => setShowSearchTermsModal(false)}
                style={{
                  padding: '7px 16px',
                  background: '#0f172a',
                  color: '#ffffff',
                  border: 'none',
                  borderRadius: '6px',
                  fontSize: '12px',
                  fontWeight: '600',
                  cursor: 'pointer',
                }}
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
      {/* ── How Viewers Engaged Deep-Dive Modal (When "See more" is clicked) ── */}
      {showEngagementModal && (
        <div
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(15, 23, 42, 0.65)',
            backdropFilter: 'blur(3px)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 9999,
            padding: '20px',
          }}
          onClick={() => setShowEngagementModal(false)}
        >
          <div
            style={{
              background: '#ffffff',
              borderRadius: '16px',
              maxWidth: '600px',
              width: '100%',
              maxHeight: '85vh',
              overflow: 'hidden',
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.08)',
              border: '1px solid #cbd5e1',
              display: 'flex',
              flexDirection: 'column',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            {/* Modal Header */}
            <div
              style={{
                padding: '16px 20px',
                borderBottom: '1px solid #e2e8f0',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                background: '#f8fafc',
              }}
            >
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                  <h3 style={{ margin: 0, fontSize: '16px', fontWeight: '700', color: '#0f172a' }}>
                    How viewers engaged
                  </h3>
                  <span
                    style={{
                      fontSize: '11px',
                      background: '#eff6ff',
                      color: '#2563eb',
                      padding: '2px 8px',
                      borderRadius: '12px',
                      fontWeight: '600',
                      border: '1px solid #bfdbfe',
                    }}
                  >
                    Shorts Feed
                  </span>
                </div>
                <p style={{ margin: '3px 0 0', fontSize: '12px', color: '#64748b' }}>
                  {selectedVideoMeta?.title || 'Selected video'} · Since published
                </p>
              </div>
              <button
                type="button"
                onClick={() => setShowEngagementModal(false)}
                style={{
                  background: 'transparent',
                  border: 'none',
                  color: '#64748b',
                  cursor: 'pointer',
                  padding: '4px',
                  borderRadius: '6px',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <X size={20} />
              </button>
            </div>

            {/* Modal Body */}
            <div style={{ padding: '20px', overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '16px' }}>
              {/* Segmented Bar Visualization in Modal */}
              <div style={{ padding: '16px', background: '#f8fafc', borderRadius: '12px', border: '1px solid #e2e8f0' }}>
                <div style={{ fontSize: '12px', color: '#64748b', fontWeight: '600', marginBottom: '8px' }}>
                  Viewer choice breakdown
                </div>
                {activeStudioEngagement ? (
                  <div
                    style={{
                      width: '100%',
                      height: '14px',
                      borderRadius: '7px',
                      overflow: 'hidden',
                      display: 'flex',
                      background: '#f1f5f9',
                      gap: '2px',
                      marginBottom: '12px',
                    }}
                  >
                    <div
                      style={{
                        width: `${Math.min(Math.max(activeStudioEngagement.stayed, 0), 100)}%`,
                        background: '#0284c7',
                        height: '100%',
                        borderRadius: activeStudioEngagement.swiped > 0 ? '7px 0 0 7px' : '7px',
                      }}
                    />
                    <div
                      style={{
                        width: `${Math.min(Math.max(activeStudioEngagement.swiped, 0), 100)}%`,
                        background: '#94a3b8',
                        height: '100%',
                        borderRadius: activeStudioEngagement.stayed > 0 ? '0 7px 7px 0' : '7px',
                      }}
                    />
                  </div>
                ) : (
                  <div
                    style={{
                      width: '100%',
                      height: '14px',
                      borderRadius: '7px',
                      background: '#f1f5f9',
                      border: '1px dashed #cbd5e1',
                      marginBottom: '12px',
                    }}
                  />
                )}

                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '14px' }}>
                  <div style={{ padding: '12px', background: '#ffffff', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      <span style={{ width: '8px', height: '8px', borderRadius: '50%', background: activeStudioEngagement ? '#0284c7' : '#94a3b8' }} />
                      <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '600' }}>Viewed (stayed to watch)</span>
                    </div>
                    <div style={{ fontSize: '24px', fontWeight: '800', color: '#0f172a', marginTop: '4px' }}>
                      {activeStudioEngagement ? `${activeStudioEngagement.stayed}%` : '—'}
                    </div>
                    <p style={{ margin: '6px 0 0', fontSize: '11px', color: '#64748b', lineHeight: 1.4 }}>
                      Viewers who chose to watch this Short rather than immediately swiping past it.
                    </p>
                  </div>

                  <div style={{ padding: '12px', background: '#ffffff', borderRadius: '8px', border: '1px solid #e2e8f0' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      <span style={{ width: '8px', height: '8px', borderRadius: '50%', background: activeStudioEngagement ? '#94a3b8' : '#cbd5e1' }} />
                      <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '600' }}>Swiped away</span>
                    </div>
                    <div style={{ fontSize: '24px', fontWeight: '800', color: '#0f172a', marginTop: '4px' }}>
                      {activeStudioEngagement ? `${activeStudioEngagement.swiped}%` : '—'}
                    </div>
                    <p style={{ margin: '6px 0 0', fontSize: '11px', color: '#64748b', lineHeight: 1.4 }}>
                      Viewers who swiped to the next Short in their feed before reaching the engagement threshold.
                    </p>
                  </div>
                </div>
              </div>

              {/* Explanatory Info Card */}
              <div
                style={{
                  padding: '14px',
                  borderRadius: '10px',
                  background: '#f8fafc',
                  border: '1px solid #e2e8f0',
                  fontSize: '12px',
                  color: '#475569',
                  lineHeight: 1.5,
                }}
              >
                <div style={{ fontWeight: '600', color: '#0f172a', marginBottom: '4px' }}>
                  About YouTube Studio Shorts Engagement
                </div>
                <p style={{ margin: 0 }}>
                  This card measures the proportion of viewers in the YouTube Shorts feed who decided to stay and watch vs. swipe away. YouTube does not expose this metric in its public Analytics API; exact values are fetched dynamically from your authenticated YouTube Studio companion session when active.
                </p>
              </div>
            </div>

            {/* Modal Footer */}
            <div style={{ padding: '12px 20px', borderTop: '1px solid #e2e8f0', display: 'flex', justifyContent: 'flex-end', background: '#f8fafc' }}>
              <button
                type="button"
                onClick={() => setShowEngagementModal(false)}
                style={{
                  padding: '7px 16px',
                  background: '#0f172a',
                  color: '#ffffff',
                  border: 'none',
                  borderRadius: '6px',
                  fontSize: '12px',
                  fontWeight: '600',
                  cursor: 'pointer',
                }}
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
