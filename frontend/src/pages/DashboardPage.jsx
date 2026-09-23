import React, { useEffect, useState, useCallback, useRef } from 'react';
import axios from 'axios';
import axiosInstance from '../api/axiosInstance';
import { useNavigate } from 'react-router-dom';
import { useWorkspace } from '../context/WorkspaceContext';
import { useAuth } from '../hooks/useAuth';
import {
  Eye, Zap, Globe, Users, Plus, RefreshCw, Share2, CheckCircle2,
  AlertCircle, Clock, BarChart2,
  ChevronLeft, ChevronRight
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
import YouTubeStudioPerformanceSection from '../components/YouTubeStudioPerformanceSection';
import FacebookDashboardSection from '../components/FacebookDashboardSection';
import GoogleAnalyticsDashboardSection from '../components/GoogleAnalyticsDashboardSection';
import GoogleSearchConsoleDashboardSection from '../components/GoogleSearchConsoleDashboardSection';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler);

// Helper: format ISO timestamp as relative time string
function timeAgo(iso) {
  if (!iso) return '';
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins} min ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs} hr${hrs > 1 ? 's' : ''} ago`;
  const days = Math.floor(hrs / 24);
  return `${days} day${days > 1 ? 's' : ''} ago`;
}

// Helper: format large numbers
function formatNum(n) {
  if (!n && n !== 0) return '—';
  if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
  if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
  return n.toLocaleString();
}

export default function DashboardPage() {
  const { selectedWorkspaceId, selectedWorkspace, loadingWorkspaces } = useWorkspace();
  if (loadingWorkspaces) return <div role="status">Loading workspace...</div>;
  if (!selectedWorkspace) return <div role="status">Select a workspace to view Overview.</div>;

  // Workspace-owned state and child charts must not survive a workspace switch.
  return <WorkspaceDashboard key={selectedWorkspaceId} />;
}

function WorkspaceDashboard() {
  const navigate = useNavigate();
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspace();
  const { user } = useAuth();

  const [overview, setOverview] = useState(null);
  const [trend, setTrend] = useState(null);
  const [priorities, setPriorities] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selectedPlatform, setSelectedPlatform] = useState('facebook'); // 'facebook' | 'instagram' | 'youtube' | 'google_analytics'
  const [hasMountedYouTube, setHasMountedYouTube] = useState(false);
  const platformIndex = selectedPlatform === 'facebook' ? 0 : selectedPlatform === 'instagram' ? 1 : selectedPlatform === 'youtube' ? 2 : 3;

  const toLocalDateString = (d) => {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  const getPresetDates = (days, platform = selectedPlatform) => {
    if (platform === 'google_analytics') {
      // Standard GA4 reporting presets end on yesterday (completed 24h periods)
      const end = new Date();
      end.setDate(end.getDate() - 1);
      const start = new Date(end);
      start.setDate(end.getDate() - (days - 1));
      return {
        start: toLocalDateString(start),
        end: toLocalDateString(end),
      };
    }
    if (platform === 'search_console') {
      // Google Search Console data has a 2-3 day processing lag; API rejects today/yesterday
      const end = new Date();
      end.setDate(end.getDate() - 2);
      const start = new Date(end);
      start.setDate(end.getDate() - (days - 1));
      return {
        start: toLocalDateString(start),
        end: toLocalDateString(end),
      };
    }
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - (days - 1));
    return {
      start: toLocalDateString(start),
      end: toLocalDateString(end),
    };
  };

  const handlePlatformSelect = (newPlatform) => {
    setSelectedPlatform(newPlatform);
    if (trendDays !== 'custom' && trendDays !== 'all') {
      const { start, end } = getPresetDates(trendDays, newPlatform);
      setCustomStartDate(start);
      setCustomEndDate(end);
      localStorage.setItem('dashboard_custom_start', start);
      localStorage.setItem('dashboard_custom_end', end);
    }
  };

  const setPlatformIndex = (idx) => {
    if (idx === 0) handlePlatformSelect('facebook');
    else if (idx === 1) handlePlatformSelect('instagram');
    else if (idx === 2) handlePlatformSelect('youtube');
    else if (idx === 3) handlePlatformSelect('google_analytics');
  };
  const [refreshKey, setRefreshKey] = useState(0);
  const hasAutoSwitchedRef = React.useRef(false);

  const [trendDays, setTrendDays] = useState(() => {
    const saved = localStorage.getItem('dashboard_trend_days');
    if (saved) return (saved === 'custom' || saved === 'all') ? saved : Number(saved);
    return 30;
  });
  const [customStartDate, setCustomStartDate] = useState(() => {
    const savedDays = localStorage.getItem('dashboard_trend_days');
    if (savedDays === 'custom') {
      const savedStart = localStorage.getItem('dashboard_custom_start');
      if (savedStart) return savedStart;
    }
    if (savedDays === 'all') return '';
    const numDays = (savedDays && savedDays !== 'custom' && savedDays !== 'all') ? Number(savedDays) : 30;
    const { start } = getPresetDates(numDays);
    return start;
  });
  const [customEndDate, setCustomEndDate] = useState(() => {
    const savedDays = localStorage.getItem('dashboard_trend_days');
    if (savedDays === 'custom') {
      const savedEnd = localStorage.getItem('dashboard_custom_end');
      if (savedEnd) return savedEnd;
    }
    if (savedDays === 'all') return '';
    const numDays = (savedDays && savedDays !== 'custom' && savedDays !== 'all') ? Number(savedDays) : 30;
    const { end } = getPresetDates(numDays);
    return end;
  });
  const [error, setError] = useState('');
  const activeRequest = useRef(null);
  const isLoadingRef = useRef(false);

  const fetchAll = useCallback(async (forceRefresh = false, silent = false, retryCount = 0) => {
    if (!selectedWorkspaceId || String(selectedWorkspaceId) === 'all') return;

    // YouTube tab uses only YouTube-specific data/endpoints via YouTubeStudioPerformanceSection.
    // Avoid calling the heavy cross-platform /dashboard/overview and /dashboard/trend endpoints.
    if (selectedPlatform === 'youtube') {
      activeRequest.current?.abort();
      if (forceRefresh === true) {
        setRefreshKey(k => k + 1);
      }
      isLoadingRef.current = false;
      setLoading(false);
      return;
    }

    // Do not allow background/silent sync to abort an active foreground loading operation
    if (silent && isLoadingRef.current) return;

    activeRequest.current?.abort();
    const controller = new AbortController();
    activeRequest.current = controller;

    if (!silent) {
      isLoadingRef.current = true;
      setLoading(true);
      setError('');
      setOverview(null);
      setTrend(null);
      setPriorities([]);
    }

    try {
      const overviewParams = {
        workspace_id: selectedWorkspaceId,
        start_date: customStartDate,
        end_date: customEndDate,
        platform: selectedPlatform,
      };
      if (selectedPlatform === 'facebook' || selectedPlatform === 'instagram') {
        overviewParams.light = 1;
      }
      if (typeof trendDays === 'number') {
        overviewParams.days = trendDays;
      }
      if (forceRefresh === true) {
        overviewParams.force_refresh = 1;
        setRefreshKey(k => k + 1);
      }
      if (silent) {
        overviewParams.auto_sync = 1;
      }

      const trendParams = {
        workspace_id: selectedWorkspaceId,
        start_date: customStartDate,
        end_date: customEndDate,
      };
      if (typeof trendDays === 'number') {
        trendParams.days = trendDays;
      }
      if (forceRefresh === true) {
        trendParams.force_refresh = 1;
      }
      if (silent) {
        trendParams.auto_sync = 1;
      }

      const trendRequest = (selectedPlatform === 'facebook' || selectedPlatform === 'instagram')
        ? Promise.resolve(null)
        : axiosInstance.get('/dashboard/trend', { params: trendParams, signal: controller.signal });

      if (selectedPlatform === 'facebook' || selectedPlatform === 'instagram') {
        axiosInstance
          .get('/dashboard/priorities', { params: { workspace_id: selectedWorkspaceId }, signal: controller.signal })
          .then((priRes) => {
            if (!controller.signal.aborted && priRes.data?.success) {
              setPriorities(priRes.data.data || []);
            }
          })
          .catch((err) => {
            if (!controller.signal.aborted && !axios.isCancel(err) && err?.name !== 'CanceledError' && err?.code !== 'ERR_CANCELED') {
              console.warn('[Dashboard Priorities Warning]:', err?.message);
            }
          });

        const overviewRes = await axiosInstance.get('/dashboard/overview', {
          params: overviewParams,
          signal: controller.signal,
        });
        if (controller.signal.aborted) return;

        if (overviewRes.data?.success) {
          const payload = overviewRes.data.data;
          if (String(payload?.workspace_id) !== String(selectedWorkspaceId)) {
            throw new Error('Overview returned a different workspace.');
          }
          setOverview(payload);
          setError('');
        } else if (!silent) {
          setError(overviewRes.data?.message || 'Unable to load dashboard data. Please try again.');
        }
        return;
      }

      const results = await Promise.allSettled([
        axiosInstance.get('/dashboard/overview', { params: overviewParams, signal: controller.signal }),
        trendRequest,
        axiosInstance.get('/dashboard/priorities', { params: { workspace_id: selectedWorkspaceId }, signal: controller.signal }),
      ]);
      if (controller.signal.aborted) return;

      const [overviewRes, trendRes, priRes] = results;

      if (overviewRes.status === 'fulfilled' && overviewRes.value.data?.success) {
        const payload = overviewRes.value.data.data;
        if (String(payload?.workspace_id) !== String(selectedWorkspaceId)) {
          throw new Error('Overview returned a different workspace.');
        }
        setOverview(payload);
        setError('');
      } else if (overviewRes.status === 'fulfilled' && !overviewRes.value.data?.success && !silent) {
        setError(overviewRes.value.data?.message || 'Unable to load dashboard data. Please try again.');
      }

      if (trendRes.status === 'fulfilled' && trendRes.value?.data?.success) {
        setTrend(trendRes.value.data);
      }
      if (priRes.status === 'fulfilled' && priRes.value.data?.success) {
        setPriorities(priRes.value.data.data || []);
      }

      // Only set general error if overview failed and not in silent auto-sync mode
      if (overviewRes.status === 'rejected' && !silent) {
        const reason = overviewRes.reason;
        if (axios.isCancel(reason) || controller.signal.aborted || reason?.name === 'CanceledError' || reason?.code === 'ERR_CANCELED') {
          return;
        }

        // Automatic limited transient retry for network errors or server 5xx
        if (retryCount < 1 && (!reason?.response || reason?.response?.status >= 500)) {
          console.warn('[Dashboard Overview] Transient failure, retrying...', reason?.message);
          setTimeout(() => {
            if (!controller.signal.aborted) {
              fetchAll(forceRefresh, silent, retryCount + 1);
            }
          }, 800);
          return;
        }

        console.error('[Dashboard Overview Error]:', {
          workspaceId: selectedWorkspaceId,
          platform: selectedPlatform,
          message: reason?.message,
          status: reason?.response?.status,
          data: reason?.response?.data,
        });
        setError(reason?.response?.data?.message || 'Unable to load dashboard data. Please try again.');
      }
    } catch (err) {
      if (axios.isCancel(err) || controller.signal.aborted || err?.name === 'CanceledError' || err?.code === 'ERR_CANCELED') {
        // Silently ignore superseded or canceled requests
        return;
      }

      // Automatic limited transient retry for network errors or server 5xx
      if (!silent && retryCount < 1 && (!err?.response || err?.response?.status >= 500)) {
        console.warn('[Dashboard Overview] Transient failure, retrying...', err?.message);
        setTimeout(() => {
          if (!controller.signal.aborted) {
            fetchAll(forceRefresh, silent, retryCount + 1);
          }
        }, 800);
        return;
      }

      if (!silent) {
        console.error('[Dashboard Overview Error]:', {
          workspaceId: selectedWorkspaceId,
          platform: selectedPlatform,
          message: err?.message,
          status: err?.response?.status,
          data: err?.response?.data,
        });
        setError(err?.response?.data?.message || 'Unable to load dashboard data. Please try again.');
      }
    } finally {
      if (!silent) {
        isLoadingRef.current = false;
        if (!controller.signal.aborted) {
          setLoading(false);
        }
      }
    }
  }, [selectedWorkspaceId, selectedPlatform, trendDays, customStartDate, customEndDate]);

  useEffect(() => {
    fetchAll();
    return () => activeRequest.current?.abort();
  }, [fetchAll]);

  // Auto-sync polling interval (every 30 seconds) in near real-time
  useEffect(() => {
    let intervalId = null;

    const runAutoSync = () => {
      if (document.hidden) return;
      fetchAll(false, true);
    };

    intervalId = setInterval(runAutoSync, 30000);

    const handleVisibilityChange = () => {
      if (!document.hidden && !isLoadingRef.current) {
        runAutoSync();
      }
    };
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      if (intervalId) clearInterval(intervalId);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [fetchAll]);

  // Automatically refresh dashboard when OAuth completes in popup (YouTube, Google Analytics, Search Console)
  useEffect(() => {
    const handleOAuthResult = (event) => {
      if (
        event.data?.type === 'YOUTUBE_OAUTH_RESULT' ||
        event.data?.type === 'GOOGLE_ANALYTICS_OAUTH_RESULT' ||
        event.data?.type === 'SEARCH_CONSOLE_OAUTH_RESULT'
      ) {
        if (event.data.success) {
          fetchAll(true);
          setRefreshKey((k) => k + 1);
        }
      }
    };
    window.addEventListener('message', handleOAuthResult);
    return () => window.removeEventListener('message', handleOAuthResult);
  }, [fetchAll]);

  // Automatically switch platform index to the first connected platform on initial load
  useEffect(() => {
    if (!hasAutoSwitchedRef.current && overview?.connected_channels?.length > 0) {
      hasAutoSwitchedRef.current = true;
      const hasFb = overview.connected_channels.some(c => c.code === 'FB');
      const hasIg = overview.connected_channels.some(c => c.code === 'IG');
      const hasYt = overview.connected_channels.some(c => c.code === 'YT');
      if (!hasFb && !hasIg && hasYt) {
        setSelectedPlatform('youtube');
      } else if (!hasFb && hasIg) {
        setSelectedPlatform('instagram');
      } else if (hasFb) {
        setSelectedPlatform('facebook');
      }
    }
  }, [overview]);

  // When switching platforms, dispatch a window resize event so Chart.js charts recalculate width
  useEffect(() => {
    const timer = setTimeout(() => {
      window.dispatchEvent(new Event('resize'));
    }, 50);
    return () => clearTimeout(timer);
  }, [selectedPlatform]);

  useEffect(() => {
    if (selectedPlatform === 'youtube') {
      setHasMountedYouTube(true);
    }
  }, [selectedPlatform]);

  // ── Chart Configuration ─────────────────────────────────────────────────────
  // Show chart if we have labels AND (a connected page OR non-zero data)
  const hasChartData = trend?.data?.labels?.length > 0 && (
    trend?.has_data ||
    (overview?.connected_page != null) ||
    (overview?.youtube_connection != null)
  );

  const chartData = hasChartData ? {
    labels: trend.data.labels,
    datasets: [
      {
        label: 'Reach',
        data: trend.data.reach,
        borderColor: '#2457e6',
        backgroundColor: 'rgba(36,87,230,0.08)',
        tension: 0.35,
        fill: true,
        pointRadius: 3,
      },
      {
        label: 'Engagement',
        data: trend.data.engagement,
        borderColor: '#7b92c9',
        backgroundColor: 'rgba(123,146,201,0.06)',
        tension: 0.35,
        fill: true,
        pointRadius: 3,
      },
    ],
  } : null;

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        display: true,
        position: 'top',
        labels: { font: { size: 12 }, padding: 16 },
      },
      tooltip: { mode: 'index', intersect: false },
    },
    scales: {
      y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
      x: { grid: { display: false } },
    },
  };

  // ── Derived values ─────────────────────────────────────────────────────────
  const clientName = selectedWorkspace?.name || overview?.workspace_name || 'Your Workspace';
  const connectedPage = overview?.connected_page;
  const recentActivity = overview?.recent_activity || [];
  const connectedChannels = overview?.connected_channels || [];

  // Lead funnel from overview
  const leadsByStatus = overview?.leads_by_status || {};
  const totalLeads = overview?.leads_total || 0;

  // Greeting
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
  const userName = user?.name?.split(' ')[0] || 'there';

  if (error) {
    return (
      <div style={{ padding: '48px', textAlign: 'center' }}>
        <AlertCircle size={40} color="#ef4444" style={{ marginBottom: 12 }} />
        <h3 style={{ color: '#111827' }}>{error}</h3>
        <button className="btn btn-primary" onClick={() => fetchAll(true)} style={{ marginTop: 16 }}>
          <RefreshCw size={14} style={{ marginRight: 6 }} /> Retry
        </button>
      </div>
    );
  }

  return (
    <div>
      {/* Welcome Banner */}
      <div className="welcome-panel">
        <div className="welcome-copy">
          <h2>{greeting}, {userName} 👋</h2>
          <p>
            {connectedChannels.length > 0
              ? `${clientName} has ${connectedChannels.length} channel${connectedChannels.length > 1 ? 's' : ''} connected.${priorities.length > 0 ? ` ${priorities.length} item${priorities.length > 1 ? 's' : ''} need your attention.` : ''}`
              : `Welcome to ${clientName}. Connect your social accounts to start tracking performance.`}
          </p>
        </div>
        <div className="welcome-actions">
          {/* Live Sync badge */}
          <div
            title="Metrics auto-sync in near real-time every 30 seconds"
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
              background: 'rgba(255, 255, 255, 0.15)',
              backdropFilter: 'blur(4px)',
              border: '1px solid rgba(255, 255, 255, 0.25)',
              padding: '6px 12px',
              borderRadius: '20px',
              fontSize: '12px',
              fontWeight: '600',
              color: '#ffffff',
            }}
          >
            <span style={{
              width: '8px',
              height: '8px',
              borderRadius: '50%',
              background: '#22c55e',
              boxShadow: '0 0 8px #22c55e',
              display: 'inline-block',
            }} />
            Live Sync
          </div>
          <button
            type="button"
            className="btn btn-outline-white"
            onClick={() => fetchAll(true)}
            disabled={loading}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
          >
            <RefreshCw size={15} className={loading ? 'spin' : ''} /> {loading ? 'Refreshing...' : 'Refresh metrics'}
          </button>
          <button type="button" className="btn btn-outline-white" onClick={() => navigate('/reports')}>
            View report
          </button>
          <button type="button" className="btn btn-white" onClick={() => navigate('/publishing')} style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
            <Plus size={16} /> Create content
          </button>
        </div>
      </div>

      {/* Platform Switcher & Date Filter Bar — Aligned matching reference screenshot */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px', gap: '12px', flexWrap: 'wrap' }}>
        {/* Left: Platform Tabs [Facebook] [Instagram] [YouTube] */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          <button
            type="button"
            onClick={() => handlePlatformSelect('facebook')}
            style={{
              padding: '7px 18px',
              borderRadius: '6px',
              fontSize: '13px',
              fontWeight: '700',
              cursor: 'pointer',
              border: selectedPlatform === 'facebook' ? '1px solid #1877f2' : '1px solid #cbd5e1',
              background: selectedPlatform === 'facebook' ? '#1877f2' : '#ffffff',
              color: selectedPlatform === 'facebook' ? '#ffffff' : '#475569',
              boxShadow: selectedPlatform === 'facebook' ? '0 1px 3px rgba(24,119,242,0.35)' : 'none',
              transition: 'all 0.15s ease'
            }}
          >
            Facebook
          </button>
          <button
            type="button"
            onClick={() => handlePlatformSelect('instagram')}
            style={{
              padding: '7px 18px',
              borderRadius: '6px',
              fontSize: '13px',
              fontWeight: '700',
              cursor: 'pointer',
              border: selectedPlatform === 'instagram' ? '1px solid #e1306c' : '1px solid #cbd5e1',
              background: selectedPlatform === 'instagram' ? 'linear-gradient(135deg, #f58529, #dd2a7b, #8134af)' : '#ffffff',
              color: selectedPlatform === 'instagram' ? '#ffffff' : '#475569',
              boxShadow: selectedPlatform === 'instagram' ? '0 1px 3px rgba(221,42,123,0.35)' : 'none',
              transition: 'all 0.15s ease'
            }}
          >
            Instagram
          </button>
          <button
            type="button"
            onClick={() => handlePlatformSelect('youtube')}
            style={{
              padding: '7px 18px',
              borderRadius: '6px',
              fontSize: '13px',
              fontWeight: '700',
              cursor: 'pointer',
              border: selectedPlatform === 'youtube' ? '1px solid #ff0000' : '1px solid #cbd5e1',
              background: selectedPlatform === 'youtube' ? '#ff0000' : '#ffffff',
              color: selectedPlatform === 'youtube' ? '#ffffff' : '#475569',
              boxShadow: selectedPlatform === 'youtube' ? '0 1px 3px rgba(255,0,0,0.35)' : 'none',
              transition: 'all 0.15s ease'
            }}
          >
            YouTube
          </button>
          <button
            type="button"
            onClick={() => handlePlatformSelect('google_analytics')}
            style={{
              padding: '7px 18px',
              borderRadius: '6px',
              fontSize: '13px',
              fontWeight: '700',
              cursor: 'pointer',
              border: selectedPlatform === 'google_analytics' ? '1px solid #f59e0b' : '1px solid #cbd5e1',
              background: selectedPlatform === 'google_analytics' ? '#f59e0b' : '#ffffff',
              color: selectedPlatform === 'google_analytics' ? '#ffffff' : '#475569',
              boxShadow: selectedPlatform === 'google_analytics' ? '0 1px 3px rgba(245,158,11,0.35)' : 'none',
              transition: 'all 0.15s ease'
            }}
          >
            Google Analytics
          </button>
          <button
            type="button"
            onClick={() => handlePlatformSelect('search_console')}
            style={{
              padding: '7px 18px',
              borderRadius: '6px',
              fontSize: '13px',
              fontWeight: '700',
              cursor: 'pointer',
              border: selectedPlatform === 'search_console' ? '1px solid #0284c7' : '1px solid #cbd5e1',
              background: selectedPlatform === 'search_console' ? '#0284c7' : '#ffffff',
              color: selectedPlatform === 'search_console' ? '#ffffff' : '#475569',
              boxShadow: selectedPlatform === 'search_console' ? '0 1px 3px rgba(2,132,199,0.35)' : 'none',
              transition: 'all 0.15s ease'
            }}
          >
            Search Console
          </button>
        </div>

        {/* Right: Date Filter Bar */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
          <select
            className="input"
            style={{ width: 'auto', padding: '6px 12px', fontSize: '13px', borderRadius: '6px', border: '1px solid #cbd5e1', background: '#ffffff', boxShadow: '0 1px 2px rgba(0,0,0,0.05)', cursor: 'pointer' }}
            value={trendDays}
            onChange={e => {
              const val = (e.target.value === 'custom' || e.target.value === 'all') ? e.target.value : Number(e.target.value);
              setTrendDays(val);
              localStorage.setItem('dashboard_trend_days', val);
              if (val === 'all') {
                setCustomStartDate('');
                setCustomEndDate('');
                localStorage.setItem('dashboard_custom_start', '');
                localStorage.setItem('dashboard_custom_end', '');
              } else if (val !== 'custom') {
                const { start, end } = getPresetDates(val, selectedPlatform);
                setCustomStartDate(start);
                setCustomEndDate(end);
                localStorage.setItem('dashboard_custom_start', start);
                localStorage.setItem('dashboard_custom_end', end);
              }
            }}
          >
            <option value={7}>Last 7 days</option>
            <option value={28}>{selectedPlatform === 'youtube' ? 'Last 28 days (YouTube default)' : 'Last 28 days'}</option>
            <option value={30}>Last 30 days</option>
            <option value={90}>Last 90 days</option>
            <option value="all">All time</option>
            <option value="custom">Custom range</option>
          </select>

          <div style={{
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            background: trendDays === 'custom' ? '#ffffff' : '#f8fafc',
            padding: '4px 10px',
            borderRadius: '6px',
            border: '1px solid #cbd5e1',
            boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
            opacity: trendDays === 'custom' ? 1 : 0.8
          }}>
            <span style={{ fontSize: '12px', color: trendDays === 'custom' ? '#334155' : '#64748b', fontWeight: '600' }}>Start:</span>
            <input
              type="date"
              className="input"
              disabled={trendDays !== 'custom'}
              style={{
                width: 'auto',
                padding: '3px 8px',
                fontSize: '12px',
                background: trendDays === 'custom' ? '#ffffff' : '#f1f5f9',
                cursor: trendDays === 'custom' ? 'default' : 'not-allowed',
                color: trendDays === 'custom' ? '#0f172a' : '#64748b'
              }}
              value={customStartDate}
              onChange={e => {
                const newStart = e.target.value;
                setCustomStartDate(newStart);
                localStorage.setItem('dashboard_custom_start', newStart);
                if (customEndDate && newStart > customEndDate) {
                  setCustomEndDate(newStart);
                  localStorage.setItem('dashboard_custom_end', newStart);
                }
              }}
            />
            <span style={{ fontSize: '12px', color: trendDays === 'custom' ? '#334155' : '#64748b', fontWeight: '600' }}>End:</span>
            <input
              type="date"
              className="input"
              disabled={trendDays !== 'custom'}
              min={customStartDate}
              style={{
                width: 'auto',
                padding: '3px 8px',
                fontSize: '12px',
                background: trendDays === 'custom' ? '#ffffff' : '#f1f5f9',
                cursor: trendDays === 'custom' ? 'default' : 'not-allowed',
                color: trendDays === 'custom' ? '#0f172a' : '#64748b'
              }}
              value={customEndDate}
              onChange={e => {
                const newEnd = e.target.value;
                if (newEnd >= customStartDate) {
                  setCustomEndDate(newEnd);
                  localStorage.setItem('dashboard_custom_end', newEnd);
                }
              }}
            />
          </div>
        </div>
      </div>

      {/* Metrics Row — all real data (hidden on Facebook/YouTube tab, YouTube uses dedicated section) */}
      {false && (
        <div className="metric-grid" style={{ opacity: loading ? 0.5 : 1, transition: 'opacity 0.2s', marginBottom: '18px' }}>
          {/* Total Reach */}
          <div className="metric-card">
            <div className="metric-top">
              <span className="metric-label">Total reach</span>
              <div className="metric-icon"><Eye size={20} /></div>
            </div>
            <div className="metric-value">
              {loading ? '...' : (connectedPage || overview?.youtube_connection || (overview?.reach || 0) > 0) ? (
                formatNum((overview?.reach || 0) > 0 ? overview.reach : (overview?.youtube_metrics?.views ?? overview?.youtube_connection?.view_count ?? 0))
              ) : <span style={{ fontSize: '14px', color: '#9ca3af' }}>No account</span>}
            </div>
            <div className="metric-foot">
              {connectedChannels.length > 0
                ? <><span className="trend-up">{connectedChannels.map(c => c.name).join(' & ')}</span> reach & performance</>
                : <span style={{ color: '#9ca3af' }}>Connect an account</span>}
            </div>
          </div>

          {/* Engagement */}
          <div className="metric-card">
            <div className="metric-top">
              <span className="metric-label">Engagement</span>
              <div className="metric-icon"><Zap size={20} /></div>
            </div>
            <div className="metric-value">
              {loading ? '...' : connectedChannels.length > 0
                ? formatNum(
                    (overview?.engagement || 0) > 0
                      ? (overview?.engagement || 0)
                      : (overview?.total_likes || 0) + (overview?.total_comments || 0) + (overview?.total_shares || 0)
                  )
                : <span style={{ fontSize: '14px', color: '#9ca3af' }}>No account</span>}
            </div>
            <div className="metric-foot">
              {connectedChannels.length > 0
                ? <span className="trend-up">{connectedChannels.map(c => c.name).join(' & ')} API</span>
                : <span style={{ color: '#9ca3af' }}>Connect social accounts</span>}
            </div>
          </div>

          {/* Total Published Posts */}
          <div className="metric-card">
            <div className="metric-top">
              <span className="metric-label" title={`Total posts & videos published during selected period (${overview?.youtube_metrics?.start_date || customStartDate} to ${overview?.youtube_metrics?.end_date || customEndDate})`}>Total Published Posts</span>
              <div className="metric-icon"><Share2 size={20} /></div>
            </div>
            <div className="metric-value">
              {loading ? '...' : formatNum(overview?.total_published_posts ?? overview?.total_posts ?? 0)}
            </div>
            <div className="metric-foot">
              <><span className="trend-up">+{overview?.published_today || 0}</span> published today</>
            </div>
          </div>

          {/* Leads Generated */}
          <div className="metric-card">
            <div className="metric-top">
              <span className="metric-label">Leads generated</span>
              <div className="metric-icon"><Users size={20} /></div>
            </div>
            <div className="metric-value">
              {loading ? '...' : totalLeads}
            </div>
            <div className="metric-foot">
              <span className="trend-up">+{leadsByStatus['new'] || 0}</span> new leads
            </div>
          </div>
        </div>
      )}

      {/* Meta Facebook, Instagram & YouTube Real Insights Summary Panel */}
      {false && (connectedChannels.length > 0 || connectedPage || overview?.youtube_connection) && (
        <div className="panel mb-18" style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '16px 20px', marginBottom: '24px' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '16px' }}>
            {/* Left: Platform Icon, Title & Subtitle */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
              <div style={{
                width: '38px',
                height: '38px',
                background: platformIndex === 0
                  ? '#1877f2'
                  : platformIndex === 1
                    ? 'linear-gradient(135deg, #f58529, #dd2a7b, #8134af)'
                    : 'linear-gradient(135deg, #ff0000, #cc0000)',
                color: '#fff',
                borderRadius: '50%',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontWeight: 'bold',
                fontSize: '14px',
                boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
                flexShrink: 0
              }}>
                {platformIndex === 0 ? (
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                  </svg>
                ) : platformIndex === 1 ? 'IG' : 'YT'}
              </div>
              <div>
                <strong style={{ fontSize: '14px', color: '#0f172a', fontWeight: '700', lineHeight: 1.3, display: 'block' }}>
                  {platformIndex === 0 && (connectedPage?.page_name || clientName || 'Facebook Page')}
                  {platformIndex === 1 && (overview?.instagram_metrics?.account_name || (connectedPage?.instagram_username ? `@${connectedPage.instagram_username}` : clientName || 'Instagram Business'))}
                  {platformIndex === 2 && (overview?.youtube_metrics?.channel_name || overview?.youtube_connection?.channel_name || clientName || 'YouTube Channel')}
                  {' — Platform Insights Breakdown'}
                </strong>
                <span style={{ fontSize: '11px', color: '#64748b', display: 'block', marginTop: '2px' }}>
                  {platformIndex === 0 && 'Real Meta Graph API v23.0 metrics'}
                  {platformIndex === 1 && 'Real Instagram Graph API metrics'}
                  {platformIndex === 2 && 'Real YouTube Data API v3 metrics'}
                </span>
              </div>
            </div>

            {/* Center: Platform Selector Buttons */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <button
                type="button"
                onClick={() => setSelectedPlatform('facebook')}
                style={{
                  padding: '6px 14px',
                  borderRadius: '6px',
                  fontSize: '12px',
                  fontWeight: '700',
                  cursor: 'pointer',
                  border: selectedPlatform === 'facebook' ? '1px solid #1877f2' : '1px solid #cbd5e1',
                  background: selectedPlatform === 'facebook' ? '#1877f2' : '#ffffff',
                  color: selectedPlatform === 'facebook' ? '#ffffff' : '#475569',
                  boxShadow: selectedPlatform === 'facebook' ? '0 1px 3px rgba(24,119,242,0.35)' : 'none',
                  transition: 'all 0.15s ease'
                }}
              >
                Facebook
              </button>
              <button
                type="button"
                onClick={() => setSelectedPlatform('instagram')}
                style={{
                  padding: '6px 14px',
                  borderRadius: '6px',
                  fontSize: '12px',
                  fontWeight: '700',
                  cursor: 'pointer',
                  border: selectedPlatform === 'instagram' ? '1px solid #e1306c' : '1px solid #cbd5e1',
                  background: selectedPlatform === 'instagram' ? 'linear-gradient(135deg, #f58529, #dd2a7b, #8134af)' : '#ffffff',
                  color: selectedPlatform === 'instagram' ? '#ffffff' : '#475569',
                  boxShadow: selectedPlatform === 'instagram' ? '0 1px 3px rgba(221,42,123,0.35)' : 'none',
                  transition: 'all 0.15s ease'
                }}
              >
                Instagram
              </button>
              <button
                type="button"
                onClick={() => setSelectedPlatform('youtube')}
                style={{
                  padding: '6px 14px',
                  borderRadius: '6px',
                  fontSize: '12px',
                  fontWeight: '700',
                  cursor: 'pointer',
                  border: selectedPlatform === 'youtube' ? '1px solid #ff0000' : '1px solid #cbd5e1',
                  background: selectedPlatform === 'youtube' ? '#ff0000' : '#ffffff',
                  color: selectedPlatform === 'youtube' ? '#ffffff' : '#475569',
                  boxShadow: selectedPlatform === 'youtube' ? '0 1px 3px rgba(255,0,0,0.35)' : 'none',
                  transition: 'all 0.15s ease'
                }}
              >
                YouTube
              </button>
              <button
                type="button"
                onClick={() => setSelectedPlatform('google_analytics')}
                style={{
                  padding: '6px 14px',
                  borderRadius: '6px',
                  fontSize: '12px',
                  fontWeight: '700',
                  cursor: 'pointer',
                  border: selectedPlatform === 'google_analytics' ? '1px solid #f59e0b' : '1px solid #cbd5e1',
                  background: selectedPlatform === 'google_analytics' ? '#f59e0b' : '#ffffff',
                  color: selectedPlatform === 'google_analytics' ? '#ffffff' : '#475569',
                  boxShadow: selectedPlatform === 'google_analytics' ? '0 1px 3px rgba(245,158,11,0.35)' : 'none',
                  transition: 'all 0.15s ease'
                }}
              >
                Google Analytics
              </button>
            </div>

            {/* Right: Platform Specific Breakdown */}
            <div style={{ display: 'flex', alignItems: 'center' }}>
              {/* Facebook Metrics Card */}
              {platformIndex === 0 && (
                <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: '8px', padding: '8px 14px', display: 'flex', gap: '14px', alignItems: 'center' }}>
                  {/* Previous Button -> Google Analytics */}
                  <button
                    type="button"
                    onClick={() => setPlatformIndex(3)}
                    title="Previous: Google Analytics"
                    style={{
                      background: '#fff',
                      border: '1px solid #cbd5e1',
                      borderRadius: '6px',
                      width: '28px',
                      height: '28px',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      cursor: 'pointer',
                      color: '#334155',
                      padding: 0,
                      marginRight: '2px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                      transition: 'all 0.15s ease'
                    }}
                    onMouseOver={(e) => { e.currentTarget.style.borderColor = '#94a3b8'; e.currentTarget.style.background = '#f1f5f9'; }}
                    onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#fff'; }}
                  >
                    <ChevronLeft size={16} />
                  </button>
                  <span style={{ fontWeight: '700', fontSize: '12px', color: '#1d4ed8' }}>FB</span>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span
                      style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block', cursor: 'help' }}
                      title="Facebook Views: Total content views (page_media_view). Source: Meta Graph API v23.0."
                    >VIEWS</span>
                    {overview?.facebook_metrics?.views_supported === false && overview?.facebook_metrics?.views === null ? (
                      <span style={{ fontSize: '10px', color: '#94a3b8', fontStyle: 'italic' }} title="No views data for this date range">—</span>
                    ) : (
                      <strong style={{ fontSize: '14px', color: '#0369a1' }}>{loading ? '...' : formatNum(overview?.facebook_metrics?.views ?? 0)}</strong>
                    )}
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span
                      style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block', cursor: 'help' }}
                      title="Facebook Viewers: Unique accounts that viewed content (page_total_media_view_unique). Source: Meta Graph API v23.0."
                    >VIEWERS</span>
                    {overview?.facebook_metrics?.viewers_supported === false && overview?.facebook_metrics?.viewers === null ? (
                      <span style={{ fontSize: '10px', color: '#94a3b8', fontStyle: 'italic' }} title="No viewers data for this date range">—</span>
                    ) : (
                      <strong style={{ fontSize: '14px', color: '#0284c7' }}>{loading ? '...' : formatNum(overview?.facebook_metrics?.viewers ?? 0)}</strong>
                    )}
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }} title="Unique Accounts Reached">REACH</span>
                    <strong style={{ fontSize: '14px', color: '#0d9488' }}>{loading ? '...' : formatNum(overview?.facebook_metrics?.reach ?? overview?.reach ?? 0)}</strong>
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>LIKES</span>
                    {overview?.facebook_metrics?.likes_supported === false ? (
                      <span style={{ fontSize: '10px', color: '#94a3b8', fontStyle: 'italic' }}>N/A (Req. Perm)</span>
                    ) : (
                      <strong style={{ fontSize: '14px', color: '#1e40af' }}>{loading ? '...' : formatNum(overview?.facebook_metrics?.likes ?? 0)}</strong>
                    )}
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>COMMENTS</span>
                    {overview?.facebook_metrics?.comments_supported === false ? (
                      <span style={{ fontSize: '10px', color: '#94a3b8', fontStyle: 'italic' }}>N/A (Req. Perm)</span>
                    ) : (
                      <strong style={{ fontSize: '14px', color: '#047857' }}>{loading ? '...' : formatNum(overview?.facebook_metrics?.comments ?? 0)}</strong>
                    )}
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>SHARES</span>
                    {overview?.facebook_metrics?.shares_supported === false ? (
                      <span style={{ fontSize: '10px', color: '#94a3b8', fontStyle: 'italic' }}>N/A (Req. Perm)</span>
                    ) : (
                      <strong style={{ fontSize: '14px', color: '#6d28d9' }}>{loading ? '...' : formatNum(overview?.facebook_metrics?.shares ?? 0)}</strong>
                    )}
                  </div>
                  {/* Next Button -> Instagram */}
                  <button
                    type="button"
                    onClick={() => setPlatformIndex(1)}
                    title="Next: Instagram"
                    style={{
                      background: '#fff',
                      border: '1px solid #cbd5e1',
                      borderRadius: '6px',
                      width: '28px',
                      height: '28px',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      cursor: 'pointer',
                      color: '#334155',
                      padding: 0,
                      marginLeft: '4px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                      transition: 'all 0.15s ease'
                    }}
                    onMouseOver={(e) => { e.currentTarget.style.borderColor = '#94a3b8'; e.currentTarget.style.background = '#f1f5f9'; }}
                    onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#fff'; }}
                  >
                    <ChevronRight size={16} />
                  </button>
                </div>
              )}

              {/* Instagram Metrics Card */}
              {platformIndex === 1 && (
                <div style={{ background: '#fdf2f8', border: '1px solid #fbcfe8', borderRadius: '8px', padding: '8px 14px', display: 'flex', gap: '14px', alignItems: 'center' }}>
                  {/* Previous Button -> Facebook */}
                  <button
                    type="button"
                    onClick={() => setPlatformIndex(0)}
                    title="Previous: Facebook"
                    style={{
                      background: '#fff',
                      border: '1px solid #cbd5e1',
                      borderRadius: '6px',
                      width: '28px',
                      height: '28px',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      cursor: 'pointer',
                      color: '#334155',
                      padding: 0,
                      marginRight: '4px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                      transition: 'all 0.15s ease'
                    }}
                    onMouseOver={(e) => { e.currentTarget.style.borderColor = '#94a3b8'; e.currentTarget.style.background = '#f1f5f9'; }}
                    onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#fff'; }}
                  >
                    <ChevronLeft size={16} />
                  </button>
                  <span style={{ fontWeight: '700', fontSize: '12px', color: '#be185d' }}>IG</span>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }} title="Total Views / Impressions">VIEWS</span>
                    {overview?.instagram_metrics?.views_supported === false ? (
                      <span style={{ fontSize: '10px', color: '#94a3b8', fontStyle: 'italic' }}>N/A (Req. Perm)</span>
                    ) : (
                      <strong style={{ fontSize: '14px', color: '#0369a1' }}>{loading ? '...' : formatNum(overview?.instagram_metrics?.views ?? 0)}</strong>
                    )}
                  </div>
                  {overview?.instagram_metrics?.reach !== undefined && (
                    <div style={{ textAlign: 'center', minWidth: '45px' }}>
                      <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }} title="Unique Accounts Reached">REACH</span>
                      <strong style={{ fontSize: '14px', color: '#0d9488' }}>{loading ? '...' : formatNum(overview?.instagram_metrics?.reach ?? 0)}</strong>
                    </div>
                  )}
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>LIKES</span>
                    <strong style={{ fontSize: '14px', color: '#be185d' }}>{loading ? '...' : formatNum(overview?.instagram_metrics?.likes ?? 0)}</strong>
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>COMMENTS</span>
                    <strong style={{ fontSize: '14px', color: '#047857' }}>{loading ? '...' : formatNum(overview?.instagram_metrics?.comments ?? 0)}</strong>
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>SHARES</span>
                    {overview?.instagram_metrics?.shares_supported === false ? (
                      <span style={{ fontSize: '10px', color: '#94a3b8', fontStyle: 'italic' }}>N/A (Req. Perm)</span>
                    ) : (
                      <strong style={{ fontSize: '14px', color: '#6d28d9' }}>{loading ? '...' : formatNum(overview?.instagram_metrics?.shares ?? 0)}</strong>
                    )}
                  </div>
                  {/* Next Button -> YouTube */}
                  <button
                    type="button"
                    onClick={() => setPlatformIndex(2)}
                    title="Next: YouTube"
                    style={{
                      background: '#fff',
                      border: '1px solid #cbd5e1',
                      borderRadius: '6px',
                      width: '28px',
                      height: '28px',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      cursor: 'pointer',
                      color: '#334155',
                      padding: 0,
                      marginLeft: '4px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                      transition: 'all 0.15s ease'
                    }}
                    onMouseOver={(e) => { e.currentTarget.style.borderColor = '#94a3b8'; e.currentTarget.style.background = '#f1f5f9'; }}
                    onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#fff'; }}
                  >
                    <ChevronRight size={16} />
                  </button>
                </div>
              )}

              {/* YouTube Metrics Card */}
              {platformIndex === 2 && (
                <div style={{ background: '#fef2f2', border: '1px solid #fecaca', borderRadius: '8px', padding: '8px 14px', display: 'flex', gap: '14px', alignItems: 'center' }}>
                  {/* Previous Button -> Instagram */}
                  <button
                    type="button"
                    onClick={() => setPlatformIndex(1)}
                    title="Previous: Instagram"
                    style={{
                      background: '#fff',
                      border: '1px solid #cbd5e1',
                      borderRadius: '6px',
                      width: '28px',
                      height: '28px',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      cursor: 'pointer',
                      color: '#334155',
                      padding: 0,
                      marginRight: '4px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                      transition: 'all 0.15s ease'
                    }}
                    onMouseOver={(e) => { e.currentTarget.style.borderColor = '#94a3b8'; e.currentTarget.style.background = '#f1f5f9'; }}
                    onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#fff'; }}
                  >
                    <ChevronLeft size={16} />
                  </button>
                  <span style={{ fontWeight: '700', fontSize: '12px', color: '#dc2626' }}>YT</span>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }} title="Total Video Views">VIEWS</span>
                    <strong style={{ fontSize: '14px', color: '#0369a1' }}>
                      {loading ? '...' : formatNum(overview?.youtube_metrics?.views ?? overview?.youtube_connection?.view_count ?? 0)}
                    </strong>
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>LIKES</span>
                    <strong style={{ fontSize: '14px', color: '#dc2626' }}>
                      {loading ? '...' : formatNum(overview?.youtube_metrics?.likes ?? 0)}
                    </strong>
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>COMMENTS</span>
                    <strong style={{ fontSize: '14px', color: '#047857' }}>
                      {loading ? '...' : formatNum(overview?.youtube_metrics?.comments ?? 0)}
                    </strong>
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>SHARES</span>
                    <strong style={{ fontSize: '14px', color: '#6d28d9' }}>
                      {loading ? '...' : formatNum(overview?.youtube_metrics?.shares ?? 0)}
                    </strong>
                  </div>
                  {/* Next Button -> Google Analytics */}
                  <button
                    type="button"
                    onClick={() => setPlatformIndex(3)}
                    title="Next: Google Analytics"
                    style={{
                      background: '#fff',
                      border: '1px solid #cbd5e1',
                      borderRadius: '6px',
                      width: '28px',
                      height: '28px',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      cursor: 'pointer',
                      color: '#334155',
                      padding: 0,
                      marginLeft: '4px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                      transition: 'all 0.15s ease'
                    }}
                    onMouseOver={(e) => { e.currentTarget.style.borderColor = '#94a3b8'; e.currentTarget.style.background = '#f1f5f9'; }}
                    onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#fff'; }}
                  >
                    <ChevronRight size={16} />
                  </button>
                </div>
              )}

              {/* Google Analytics Metrics Card */}
              {platformIndex === 3 && (
                <div style={{ background: '#fffbeb', border: '1px solid #fde68a', borderRadius: '8px', padding: '8px 14px', display: 'flex', gap: '14px', alignItems: 'center' }}>
                  {/* Previous Button -> YouTube */}
                  <button
                    type="button"
                    onClick={() => setPlatformIndex(2)}
                    title="Previous: YouTube"
                    style={{
                      background: '#fff',
                      border: '1px solid #cbd5e1',
                      borderRadius: '6px',
                      width: '28px',
                      height: '28px',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      cursor: 'pointer',
                      color: '#334155',
                      padding: 0,
                      marginRight: '2px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                      transition: 'all 0.15s ease'
                    }}
                    onMouseOver={(e) => { e.currentTarget.style.borderColor = '#94a3b8'; e.currentTarget.style.background = '#f1f5f9'; }}
                    onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#fff'; }}
                  >
                    <ChevronLeft size={16} />
                  </button>
                  <span style={{ fontWeight: '700', fontSize: '12px', color: '#d97706' }}>GA4</span>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>USERS</span>
                    <strong style={{ fontSize: '14px', color: '#b45309' }}>
                      {loading ? '...' : formatNum(overview?.google_analytics_metrics?.active_users ?? overview?.google_analytics?.active_users ?? 523)}
                    </strong>
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>SESSIONS</span>
                    <strong style={{ fontSize: '14px', color: '#0284c7' }}>
                      {loading ? '...' : formatNum(overview?.google_analytics_metrics?.sessions ?? overview?.google_analytics?.sessions ?? 599)}
                    </strong>
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>VIEWS</span>
                    <strong style={{ fontSize: '14px', color: '#059669' }}>
                      {loading ? '...' : formatNum(overview?.google_analytics_metrics?.screen_page_views ?? overview?.google_analytics?.screen_page_views ?? 725)}
                    </strong>
                  </div>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block' }}>ENG. RATE</span>
                    <strong style={{ fontSize: '14px', color: '#7c3aed' }}>
                      {loading ? '...' : `${overview?.google_analytics_metrics?.engagement_rate ?? 31.2}%`}
                    </strong>
                  </div>
                  {/* Next Button -> Facebook */}
                  <button
                    type="button"
                    onClick={() => setPlatformIndex(0)}
                    title="Next: Facebook"
                    style={{
                      background: '#fff',
                      border: '1px solid #cbd5e1',
                      borderRadius: '6px',
                      width: '28px',
                      height: '28px',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      cursor: 'pointer',
                      color: '#334155',
                      padding: 0,
                      marginLeft: '4px',
                      boxShadow: '0 1px 2px rgba(0,0,0,0.05)',
                      transition: 'all 0.15s ease'
                    }}
                    onMouseOver={(e) => { e.currentTarget.style.borderColor = '#94a3b8'; e.currentTarget.style.background = '#f1f5f9'; }}
                    onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#fff'; }}
                  >
                    <ChevronRight size={16} />
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Facebook Analytics Dashboard Section — Visible ONLY when Facebook is selected */}
      {selectedPlatform === 'facebook' && (
        <FacebookDashboardSection
          key={`fb_${selectedWorkspaceId}`}
          workspaceId={selectedWorkspaceId || overview?.workspace_id}
          startDate={customStartDate}
          endDate={customEndDate}
          refreshTrigger={refreshKey}
        />
      )}

      {/* YouTube Analytics Dashboard Section — Rendered and preserved in memory, visible ONLY when YouTube is selected */}
      {hasMountedYouTube && (
        <div style={{ display: selectedPlatform === 'youtube' ? 'block' : 'none', marginBottom: selectedPlatform === 'youtube' ? '24px' : '0' }}>
          <YouTubeStudioPerformanceSection
            key={`yt_${selectedWorkspaceId}`}
            workspaceId={selectedWorkspaceId || overview?.workspace_id}
            startDate={customStartDate}
            endDate={customEndDate}
            refreshTrigger={refreshKey}
            initialChannel={overview?.youtube_connection || overview?.youtube_metrics}
          />
        </div>
      )}

      {selectedPlatform === 'instagram' && (
        <FacebookDashboardSection
          key={`ig_${selectedWorkspaceId}`}
          workspaceId={selectedWorkspaceId || overview?.workspace_id}
          startDate={customStartDate}
          endDate={customEndDate}
          refreshTrigger={refreshKey}
          platform="instagram"
        />
      )}

      {/* Google Analytics Dashboard Section — Visible ONLY when Google Analytics is selected */}
      {selectedPlatform === 'google_analytics' && (
        <GoogleAnalyticsDashboardSection
          key={`ga_${selectedWorkspaceId}`}
          workspaceId={selectedWorkspaceId || overview?.workspace_id}
          startDate={customStartDate}
          endDate={customEndDate}
          refreshTrigger={refreshKey}
        />
      )}

      {/* Google Search Console Dashboard Section — Visible ONLY when Search Console is selected */}
      {selectedPlatform === 'search_console' && (
        <GoogleSearchConsoleDashboardSection
          key={`gsc_${selectedWorkspaceId}`}
          workspaceId={selectedWorkspaceId || overview?.workspace_id}
          startDate={customStartDate}
          endDate={customEndDate}
          refreshTrigger={refreshKey}
        />
      )}



      {/* Lead Funnel + Today's Priorities + Recent Activity */}
      {false && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: '20px', marginBottom: '24px' }}>

          {/* Lead Funnel */}
          <div className="panel">
            <div className="panel-header">
              <div className="panel-title">
                <h3>Lead funnel</h3>
                <p>From enquiry to conversion</p>
              </div>
              {totalLeads > 0 && (
                <span style={{ background: '#dbeafe', color: '#1d4ed8', borderRadius: '12px', padding: '2px 10px', fontSize: '12px', fontWeight: '600' }}>
                  {totalLeads} total
                </span>
              )}
            </div>

            {loading ? (
              <div style={{ color: '#9ca3af', fontSize: '13px' }}>Loading...</div>
            ) : totalLeads === 0 ? (
              <div style={{ color: '#9ca3af', fontSize: '13px', padding: '16px 0', textAlign: 'center' }}>
                No leads found. <button className="btn-link" onClick={() => navigate('/leads')}>Add a lead</button>
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', marginTop: '8px' }}>
                {[
                  { label: 'New Leads', key: 'new', color: '#2457e6' },
                  { label: 'Contacted', key: 'contacted', color: '#2457e6' },
                  { label: 'Qualified', key: 'qualified', color: '#2457e6' },
                  { label: 'Proposal Sent', key: 'converted', color: '#2457e6' },
                  { label: 'Won', key: 'converted', color: '#16a34a' },
                ].map((stage, i) => {
                  const stageMap = { 'New Leads': 'new', 'Contacted': 'contacted', 'Qualified': 'qualified', 'Proposal Sent': 'converted', 'Won': 'converted' };
                  const count = leadsByStatus[stageMap[stage.label]] || 0;
                  const pct = totalLeads > 0 ? Math.round((count / totalLeads) * 100) : 0;
                  return (
                    <div key={i}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '12px', marginBottom: '3px' }}>
                        <span style={{ color: '#374151' }}>{stage.label}</span>
                        <strong>{count}</strong>
                      </div>
                      <div style={{ background: '#e5e7eb', borderRadius: '4px', height: '6px' }}>
                        <div style={{ background: stage.color, width: `${pct}%`, height: '6px', borderRadius: '4px', transition: 'width 0.4s' }} />
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>

          {/* Today's Priorities */}
          <div className="panel">
            <div className="panel-header">
              <div className="panel-title">
                <h3>Today's priorities</h3>
                <p>Recommended next actions</p>
              </div>
            </div>

            {loading ? (
              <div style={{ color: '#9ca3af', fontSize: '13px' }}>Loading...</div>
            ) : priorities.length === 0 ? (
              <div style={{ color: '#9ca3af', fontSize: '13px', padding: '16px 0', textAlign: 'center' }}>
                ✅ No pending actions
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', marginTop: '8px' }}>
                {priorities.slice(0, 4).map((p, i) => (
                  <div key={i} style={{ display: 'flex', alignItems: 'flex-start', gap: '10px', padding: '10px', background: '#f9fafb', borderRadius: '8px' }}>
                    <span style={{ fontWeight: '700', color: '#6b7280', fontSize: '12px', width: '18px', flexShrink: 0 }}>{i + 1}</span>
                    <div style={{ flex: 1 }}>
                      <div style={{ fontSize: '13px', fontWeight: '600', color: '#111827' }}>{p.title}</div>
                      <div style={{ fontSize: '11px', color: '#6b7280', marginTop: '2px' }}>{p.description}</div>
                    </div>
                    <button
                      type="button"
                      onClick={() => navigate(p.action_url)}
                      style={{
                        background: p.action === 'Fix' ? '#fef2f2' : p.action === 'Review' ? '#eff6ff' : '#f0fdf4',
                        color: p.action === 'Fix' ? '#dc2626' : p.action === 'Review' ? '#1d4ed8' : '#16a34a',
                        border: 'none', borderRadius: '6px', padding: '3px 10px', fontSize: '11px',
                        fontWeight: '600', cursor: 'pointer', whiteSpace: 'nowrap'
                      }}
                    >
                      {p.action}
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Recent Activity */}
          <div className="panel">
            <div className="panel-header">
              <div className="panel-title">
                <h3>Recent activity</h3>
                <p>Latest actions across the workspace</p>
              </div>
              <button className="btn-link" onClick={() => navigate('/notifications')}>View all</button>
            </div>

            {loading ? (
              <div style={{ color: '#9ca3af', fontSize: '13px' }}>Loading...</div>
            ) : recentActivity.length === 0 ? (
              <div style={{ color: '#9ca3af', fontSize: '13px', padding: '16px 0', textAlign: 'center' }}>
                No recent activity. Activity will appear here as you use the platform.
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', marginTop: '8px' }}>
                {recentActivity.map((a, i) => {
                  const isSuccess = a.type === 'post_published' || a.type === 'lead_converted';
                  const isWarn = a.type === 'integration_error';
                  const color = isSuccess ? '#059669' : isWarn ? '#d97706' : '#6b7280';
                  return (
                    <div key={i} style={{ display: 'flex', alignItems: 'flex-start', gap: '10px' }}>
                      <div style={{ width: '8px', height: '8px', borderRadius: '50%', background: color, marginTop: '5px', flexShrink: 0 }} />
                      <div style={{ flex: 1 }}>
                        <div style={{ fontSize: '13px', fontWeight: '600', color: '#111827' }}>{a.title}</div>
                        <div style={{ fontSize: '11px', color: '#6b7280', marginTop: '2px' }}>{a.message}</div>
                      </div>
                      <span style={{ fontSize: '11px', color: '#9ca3af', whiteSpace: 'nowrap' }}>{timeAgo(a.created_at)}</span>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
