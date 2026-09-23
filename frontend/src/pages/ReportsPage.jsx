import React, { useState, useEffect, useMemo, useCallback } from 'react';
import axiosInstance from '../api/axiosInstance';
import { useWorkspace } from '../context/WorkspaceContext';
import {
  TrendingUp,
  TrendingDown,
  Heart,
  Eye,
  UserPlus,
  Share2,
  Calendar,
  Download,
  Printer,
  AlertCircle,
  AlertTriangle,
  RotateCw,
  ExternalLink,
  Users,
  MousePointer,
  Video,
  BarChart3,
  Search,
  Globe,
  CheckCircle2,
  Layers,
  ArrowUpRight,
  ArrowDownRight,
  Filter,
  Clock,
  MessageSquare,
  ThumbsUp,
  Sparkles,
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

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
);

// Format large numbers cleanly (e.g. 12,450 or 1.2M)
function formatNumber(num) {
  if (num === 'N/A') return 'N/A';
  if (num === null || num === undefined) return '—';
  const n = Number(num);
  if (isNaN(n)) return '—';
  return n.toLocaleString();
}

function formatCompact(num) {
  if (num === null || num === undefined) return '—';
  const n = Number(num);
  if (isNaN(n)) return '—';
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
  if (n >= 1_000) return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
  return n.toLocaleString();
}

function formatDate(isoStr) {
  if (!isoStr) return '—';
  try {
    const d = new Date(isoStr);
    if (isNaN(d.getTime())) return isoStr;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  } catch {
    return isoStr;
  }
}

// Compute percentage change between current and previous period
function computePercentChange(current, previous) {
  if (previous === null || previous === undefined || Number(previous) === 0) return null;
  const curr = Number(current || 0);
  const prev = Number(previous);
  const diff = curr - prev;
  return Math.round((diff / prev) * 100);
}

export default function ReportsPage() {
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspace();

  // Filters
  const [platform, setPlatform] = useState('all');
  const [period, setPeriod] = useState('30');
  const [customStart, setCustomStart] = useState(() => {
    const d = new Date(Date.now() - 30 * 86400000);
    return d.toISOString().split('T')[0];
  });
  const [customEnd, setCustomEnd] = useState(() => {
    return new Date().toISOString().split('T')[0];
  });
  const [appliedCustomDates, setAppliedCustomDates] = useState({
    start: '',
    end: '',
  });

  // Top content platform filter tab
  const [contentPlatformFilter, setContentPlatformFilter] = useState('all');

  // State
  const [analytics, setAnalytics] = useState(null);
  const [connectedPlatforms, setConnectedPlatforms] = useState([]);
  const [loading, setLoading] = useState(true);
  const [errorMsg, setErrorMsg] = useState('');

  // 1. Fetch available connected integrations for workspace
  useEffect(() => {
    if (!selectedWorkspaceId) return;

    axiosInstance
      .get('/integrations/status', { params: { workspace_id: selectedWorkspaceId } })
      .then((res) => {
        if (res.data.success && Array.isArray(res.data.data)) {
          const connected = res.data.data.filter((item) => item.status === 'connected');
          setConnectedPlatforms(connected);

          // If currently selected platform is not connected in this workspace, reset to 'all'
          const keys = connected.map((c) => c.key);
          if (platform !== 'all' && !keys.includes(platform)) {
            setPlatform('all');
          }
        }
      })
      .catch((err) => console.error('Error fetching integrations status:', err));
  }, [selectedWorkspaceId]);

  // 2. Fetch real analytics data
  const fetchAnalytics = useCallback(() => {
    if (!selectedWorkspaceId) return;
    setLoading(true);
    setErrorMsg('');

    const params = {
      workspace_id: selectedWorkspaceId,
      platform,
      period,
    };

    if (period === 'custom') {
      const s = appliedCustomDates.start || customStart;
      const e = appliedCustomDates.end || customEnd;
      if (s && e) {
        params.start_date = s;
        params.end_date = e;
      }
    }

    axiosInstance
      .get('/reports/analytics', { params })
      .then((res) => {
        if (res.data.success) {
          setAnalytics(res.data.data);
        } else {
          setErrorMsg(res.data.message || 'Failed to load reports data.');
        }
      })
      .catch((err) => {
        console.error('Error fetching analytics:', err);
        setErrorMsg(err.response?.data?.message || 'Error connecting to analytics services.');
      })
      .finally(() => setLoading(false));
  }, [selectedWorkspaceId, platform, period, appliedCustomDates, customStart, customEnd]);

  // Trigger on workspace, period, platform, or custom dates change
  useEffect(() => {
    fetchAnalytics();
  }, [selectedWorkspaceId, period, platform, appliedCustomDates]);

  const handlePeriodChange = (newPeriod) => {
    setPeriod(newPeriod);
    if (newPeriod === 'custom') {
      setAppliedCustomDates({
        start: customStart,
        end: customEnd,
      });
    }
  };

  const handleApplyCustomDates = (e) => {
    e.preventDefault();
    if (!customStart || !customEnd) {
      alert('Please select both Start Date and End Date.');
      return;
    }
    if (new Date(customStart) > new Date(customEnd)) {
      alert('Start Date cannot be after End Date.');
      return;
    }
    setAppliedCustomDates({
      start: customStart,
      end: customEnd,
    });
  };

  // Date range label for export & display
  const dateRangeLabel = useMemo(() => {
    if (!analytics) return '';
    return `${analytics.start_date} to ${analytics.end_date} (${analytics.days_count} day${analytics.days_count === 1 ? '' : 's'})`;
  }, [analytics]);

  // Summary & Previous period metrics
  const summary = analytics?.summary;
  const prevSummary = analytics?.previous_summary;

  const reachChange = useMemo(() => {
    return computePercentChange(summary?.total_reach, prevSummary?.total_reach);
  }, [summary, prevSummary]);

  const engagementChange = useMemo(() => {
    return computePercentChange(summary?.total_engagement, prevSummary?.total_engagement);
  }, [summary, prevSummary]);

  const clicksChange = useMemo(() => {
    return computePercentChange(summary?.total_clicks, prevSummary?.total_clicks);
  }, [summary, prevSummary]);

  // 3. Dynamic Chart Construction based on selected platform & real trend data
  const chartConfig = useMemo(() => {
    if (!analytics || !analytics.trend || !analytics.trend.labels || analytics.trend.labels.length === 0) {
      return null;
    }

    const trend = analytics.trend;
    const labels = trend.labels;
    let datasets = [];

    if (platform === 'all') {
      // Summed reach across connected platforms
      const totalReachByDay = labels.map((_, i) => {
        return (
          (trend.fb_reach?.[i] || 0) +
          (trend.ig_reach?.[i] || 0) +
          (trend.yt_views?.[i] || 0) +
          (trend.ga_pageviews?.[i] || 0) +
          (trend.gsc_impressions?.[i] || 0)
        );
      });

      const totalEngageByDay = labels.map((_, i) => {
        return (
          (trend.fb_engagement?.[i] || 0) +
          (trend.ig_engagement?.[i] || 0) +
          (trend.yt_engagement?.[i] || 0)
        );
      });

      const totalClicksByDay = labels.map((_, i) => {
        return (trend.gsc_clicks?.[i] || 0) + (trend.ga_sessions?.[i] || 0);
      });

      datasets = [
        {
          label: 'Total Reach / Impressions',
          data: totalReachByDay,
          borderColor: '#1877f2',
          backgroundColor: 'rgba(24, 119, 242, 0.08)',
          fill: true,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
        {
          label: 'Total Engagements',
          data: totalEngageByDay,
          borderColor: '#10b981',
          backgroundColor: 'rgba(16, 185, 129, 0.08)',
          fill: false,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
        {
          label: 'Clicks & Sessions',
          data: totalClicksByDay,
          borderColor: '#f59e0b',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
      ];
    } else if (platform === 'facebook') {
      datasets = [
        {
          label: 'Page Impressions / Reach',
          data: trend.fb_reach || [],
          borderColor: '#1877f2',
          backgroundColor: 'rgba(24, 119, 242, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
        {
          label: 'Post Engagements',
          data: trend.fb_engagement || [],
          borderColor: '#10b981',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
      ];
    } else if (platform === 'instagram') {
      datasets = [
        {
          label: 'Instagram Reach',
          data: trend.ig_reach || [],
          borderColor: '#e1306c',
          backgroundColor: 'rgba(225, 48, 108, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
        {
          label: 'Total Interactions',
          data: trend.ig_engagement || [],
          borderColor: '#8b5cf6',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
      ];
    } else if (platform === 'youtube') {
      datasets = [
        {
          label: 'Channel Views',
          data: trend.yt_views || [],
          borderColor: '#dc2626',
          backgroundColor: 'rgba(220, 38, 38, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
        {
          label: 'Engagement (Likes + Comments)',
          data: trend.yt_engagement || [],
          borderColor: '#10b981',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
      ];
    } else if (platform === 'google_analytics') {
      datasets = [
        {
          label: 'Screen Pageviews',
          data: trend.ga_pageviews || [],
          borderColor: '#f59e0b',
          backgroundColor: 'rgba(245, 158, 11, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
        {
          label: 'Web Sessions',
          data: trend.ga_sessions || [],
          borderColor: '#10b981',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
      ];
    } else if (platform === 'search_console') {
      datasets = [
        {
          label: 'Search Impressions',
          data: trend.gsc_impressions || [],
          borderColor: '#0284c7',
          backgroundColor: 'rgba(2, 132, 199, 0.12)',
          fill: true,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
        {
          label: 'Organic Clicks',
          data: trend.gsc_clicks || [],
          borderColor: '#16a34a',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.35,
          pointRadius: labels.length > 30 ? 0 : 3,
          pointHoverRadius: 6,
        },
      ];
    }

    return {
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 300 },
        interaction: {
          mode: 'index',
          intersect: false,
        },
        plugins: {
          legend: {
            position: 'top',
            align: 'end',
            labels: {
              boxWidth: 12,
              boxHeight: 12,
              usePointStyle: true,
              pointStyle: 'circle',
              font: { size: 12, weight: '600', family: 'system-ui, sans-serif' },
              color: '#475569',
              padding: 16,
            },
          },
          tooltip: {
            backgroundColor: '#0f172a',
            titleColor: '#ffffff',
            bodyColor: '#e2e8f0',
            titleFont: { size: 12, weight: '700' },
            bodyFont: { size: 12 },
            padding: 12,
            cornerRadius: 8,
            boxPadding: 4,
            callbacks: {
              label: (item) => {
                return ` ${item.dataset.label}: ${Number(item.parsed.y).toLocaleString()}`;
              },
            },
          },
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              font: { size: 11 },
              color: '#64748b',
              maxRotation: 0,
              autoSkip: true,
              maxTicksLimit: 12,
            },
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(226, 232, 240, 0.8)' },
            ticks: {
              callback: (val) => formatCompact(val),
              font: { size: 11 },
              color: '#64748b',
              padding: 8,
            },
          },
        },
      },
    };
  }, [analytics, platform]);

  // 4. Filtered Top Content
  const filteredContent = useMemo(() => {
    const items = analytics?.top_content || [];
    const activeFilter = platform !== 'all' ? platform : contentPlatformFilter;
    if (activeFilter === 'all') return items;
    return items.filter((item) => item.platform === activeFilter);
  }, [analytics, platform, contentPlatformFilter]);

  // 5. Download Comprehensive CSV Handler
  const handleDownloadCsv = () => {
    if (!analytics) return;

    const rows = [
      ['Marketing Command - Reports & Analytics Export'],
      ['Client / Workspace', selectedWorkspace?.name || 'Workspace'],
      ['Date Range', `${analytics.start_date} to ${analytics.end_date}`],
      ['Days Count', analytics.days_count],
      ['Platform Filter', platform.toUpperCase()],
      ['Generated At', new Date().toLocaleString()],
      [''],
      ['=== 1. EXECUTIVE KPI SUMMARY ==='],
      ['Metric', 'Current Value', 'Previous Period Value'],
      ['Total Reach / Impressions', summary?.total_reach || 0, prevSummary?.total_reach || 0],
      ['Total Engagements', summary?.total_engagement || 0, prevSummary?.total_engagement || 0],
      ['Total Audience (Followers / Subs)', summary?.total_audience || 0, prevSummary?.total_audience || 0],
      ['Total Clicks & Sessions', summary?.total_clicks || 0, prevSummary?.total_clicks || 0],
      [''],
      ['=== 2. PLATFORM BREAKDOWN ==='],
      ['Platform', 'Connected Status', 'Account / Entity', 'Primary Metric 1', 'Primary Metric 2', 'Primary Metric 3', 'Followers/Audience'],
    ];

    if (analytics.facebook?.connected) {
      rows.push([
        'Facebook',
        'Connected',
        analytics.facebook.page_name || 'Facebook Page',
        `Impressions: ${analytics.facebook.impressions}`,
        `Engagement: ${analytics.facebook.engagement}`,
        `Reactions/Likes: ${analytics.facebook.reactions || analytics.facebook.likes}`,
        `Followers: ${analytics.facebook.followers}`,
      ]);
    }

    if (analytics.instagram?.connected) {
      rows.push([
        'Instagram',
        'Connected',
        analytics.instagram.account_name || 'Instagram Business',
        `Reach: ${analytics.instagram.reach}`,
        `Interactions: ${analytics.instagram.interactions}`,
        `Video Views: ${analytics.instagram.views}`,
        `Followers: ${analytics.instagram.followers}`,
      ]);
    }

    if (analytics.youtube?.connected) {
      rows.push([
        'YouTube',
        'Connected',
        analytics.youtube.channel_name || 'YouTube Channel',
        `Views: ${analytics.youtube.views}`,
        `Likes: ${analytics.youtube.likes}`,
        `Videos: ${analytics.youtube.video_count}`,
        `Subscribers: ${analytics.youtube.subscribers}`,
      ]);
    }

    if (analytics.google_analytics?.connected) {
      rows.push([
        'Google Analytics (GA4)',
        'Connected',
        'Active Property Stream',
        `Active Users: ${analytics.google_analytics.active_users}`,
        `Sessions: ${analytics.google_analytics.sessions}`,
        `Pageviews: ${analytics.google_analytics.screen_page_views}`,
        `Engagement Rate: ${analytics.google_analytics.engagement_rate}%`,
      ]);
    }

    if (analytics.search_console?.connected) {
      rows.push([
        'Google Search Console',
        'Connected',
        'Verified Site URL',
        `Search Clicks: ${analytics.search_console.clicks}`,
        `Impressions: ${analytics.search_console.impressions}`,
        `Avg CTR: ${analytics.search_console.ctr}%`,
        `Avg Position: ${analytics.search_console.position}`,
      ]);
    }

    if (filteredContent.length > 0) {
      rows.push(['']);
      rows.push(['=== 3. TOP PERFORMING CONTENT ===']);
      rows.push(['Platform', 'Title / Caption', 'Date Published', 'Reach / Views', 'Likes', 'Comments', 'Shares', 'Total Engagement', 'URL']);
      filteredContent.forEach((c) => {
        rows.push([
          c.platform.toUpperCase(),
          `"${(c.title || '').replace(/"/g, '""').replace(/\n/g, ' ')}"`,
          formatDate(c.date),
          c.reach !== null && c.reach !== undefined ? c.reach : 'N/A',
          c.likes || 0,
          c.comments || 0,
          c.shares || 0,
          c.engagement || 0,
          c.url || '',
        ]);
      });
    }

    const csvContent = 'data:text/csv;charset=utf-8,' + rows.map((e) => e.join(',')).join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    const sanitizedName = (selectedWorkspace?.name || 'workspace').replace(/[^a-z0-9]/gi, '_').toLowerCase();
    link.setAttribute('download', `reports_${sanitizedName}_${analytics.start_date}_to_${analytics.end_date}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  const hasAnyConnections = connectedPlatforms.length > 0;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '22px' }}>
      {/* Print-specific style */}
      <style>{`
        @media print {
          body { background: #ffffff !important; color: #000000 !important; font-size: 11pt !important; }
          .no-print, header, aside, nav, .sidebar { display: none !important; }
          .panel, .metric-card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; page-break-inside: avoid; }
          .print-full-width { width: 100% !important; margin: 0 !important; padding: 0 !important; }
        }
      `}</style>

      {/* ── 1. Executive Header ──────────────────────────────────────────────── */}
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          flexWrap: 'wrap',
          gap: '16px',
          background: 'linear-gradient(135deg, #1e293b 0%, #0f172a 100%)',
          padding: '24px 28px',
          borderRadius: '16px',
          color: '#ffffff',
          boxShadow: '0 4px 20px rgba(15, 23, 42, 0.15)',
        }}
      >
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '6px' }}>
            <span
              style={{
                fontSize: '11px',
                fontWeight: '700',
                letterSpacing: '0.06em',
                textTransform: 'uppercase',
                background: 'rgba(255, 255, 255, 0.15)',
                padding: '3px 9px',
                borderRadius: '6px',
                color: '#93c5fd',
              }}
            >
              Executive Dashboard
            </span>
            <span style={{ fontSize: '13px', color: '#94a3b8' }}>•</span>
            <span style={{ fontSize: '13px', color: '#cbd5e1', fontWeight: '500' }}>
              {selectedWorkspace?.name || 'Selected Workspace'}
            </span>
          </div>
          <h1 style={{ fontSize: '26px', fontWeight: '800', margin: 0, letterSpacing: '-0.02em', color: '#ffffff' }}>
            Reports & Analytics
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: '13px', color: '#94a3b8' }}>
            Cross-platform multi-channel performance, engagement trends & content intelligence.
          </p>
        </div>

        {/* Action Buttons */}
        <div className="no-print" style={{ display: 'flex', gap: '10px', flexWrap: 'wrap', alignItems: 'center' }}>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={fetchAnalytics}
            disabled={loading}
            style={{
              background: 'rgba(255, 255, 255, 0.1)',
              borderColor: 'rgba(255, 255, 255, 0.2)',
              color: '#ffffff',
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
              padding: '8px 14px',
              fontSize: '13px',
              fontWeight: '600',
            }}
          >
            <RotateCw size={14} className={loading ? 'spin' : ''} />
            {loading ? 'Refreshing...' : 'Refresh Data'}
          </button>

          <button
            type="button"
            className="btn btn-secondary"
            onClick={handleDownloadCsv}
            disabled={loading || !analytics}
            style={{
              background: 'rgba(255, 255, 255, 0.1)',
              borderColor: 'rgba(255, 255, 255, 0.2)',
              color: '#ffffff',
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
              padding: '8px 14px',
              fontSize: '13px',
              fontWeight: '600',
            }}
          >
            <Download size={14} /> Download CSV
          </button>

          <button
            type="button"
            className="btn btn-primary"
            onClick={() => window.print()}
            style={{
              background: '#2563eb',
              borderColor: '#1d4ed8',
              color: '#ffffff',
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
              padding: '8px 16px',
              fontSize: '13px',
              fontWeight: '600',
              boxShadow: '0 2px 6px rgba(37, 99, 235, 0.4)',
            }}
          >
            <Printer size={14} /> Print Report
          </button>
        </div>
      </div>

      {/* Error Alert */}
      {errorMsg && (
        <div
          style={{
            background: '#fef2f2',
            border: '1px solid #fecaca',
            color: '#dc2626',
            padding: '12px 18px',
            borderRadius: '12px',
            fontSize: '13.5px',
            fontWeight: '600',
            display: 'flex',
            alignItems: 'center',
            gap: '10px',
          }}
        >
          <AlertCircle size={18} />
          <span>{errorMsg}</span>
        </div>
      )}

      {/* Disconnected Workspace Banner */}
      {!loading && !hasAnyConnections && (
        <div
          style={{
            background: '#fffbeb',
            border: '1px solid #fde68a',
            borderRadius: '12px',
            padding: '16px 20px',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            gap: '16px',
            flexWrap: 'wrap',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            <AlertTriangle size={24} color="#d97706" />
            <div>
              <h4 style={{ margin: '0 0 2px', fontSize: '14px', color: '#92400e', fontWeight: '700' }}>
                No active integrations connected for "{selectedWorkspace?.name || 'this workspace'}"
              </h4>
              <p style={{ margin: 0, fontSize: '12.5px', color: '#b45309' }}>
                Connect your Facebook, Instagram, YouTube, Google Analytics or Search Console accounts in Integrations to view real metrics.
              </p>
            </div>
          </div>
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => (window.location.href = '/integrations')}
            style={{ fontSize: '12px', padding: '7px 16px', display: 'inline-flex', alignItems: 'center', gap: '6px' }}
          >
            Go to Integrations <ExternalLink size={14} />
          </button>
        </div>
      )}

      {/* ── 2. Unified Filter Control Bar ───────────────────────────────────── */}
      <div
        className="panel no-print"
        style={{
          background: '#ffffff',
          borderRadius: '14px',
          border: '1px solid #e2e8f0',
          padding: '16px 20px',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          flexWrap: 'wrap',
          gap: '16px',
          boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
        }}
      >
        {/* Left: Platform Filter Pills */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px', flexWrap: 'wrap' }}>
          <span style={{ fontSize: '12px', fontWeight: '700', color: '#64748b', marginRight: '4px', textTransform: 'uppercase' }}>
            Platform:
          </span>

          {[
            { key: 'all', label: 'All Platforms', color: '#2563eb' },
            { key: 'facebook', label: 'Facebook', color: '#1877f2' },
            { key: 'instagram', label: 'Instagram', color: '#e1306c' },
            { key: 'youtube', label: 'YouTube', color: '#dc2626' },
            { key: 'google_analytics', label: 'Google Analytics', color: '#f59e0b' },
            { key: 'search_console', label: 'Search Console', color: '#0284c7' },
          ].map((item) => {
            const isSelected = platform === item.key;
            return (
              <button
                key={item.key}
                type="button"
                onClick={() => setPlatform(item.key)}
                style={{
                  padding: '6px 14px',
                  borderRadius: '20px',
                  fontSize: '12.5px',
                  fontWeight: '600',
                  cursor: 'pointer',
                  border: isSelected ? `1.5px solid ${item.color}` : '1px solid #cbd5e1',
                  background: isSelected ? item.color : '#ffffff',
                  color: isSelected ? '#ffffff' : '#475569',
                  boxShadow: isSelected ? `0 2px 8px ${item.color}35` : 'none',
                  transition: 'all 0.15s ease',
                }}
              >
                {item.label}
              </button>
            );
          })}
        </div>

        {/* Right: Date Period Selector & Custom Pickers */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
            <Calendar size={15} color="#64748b" />
            <select
              className="select"
              value={period}
              onChange={(e) => handlePeriodChange(e.target.value)}
              style={{
                width: 'auto',
                padding: '6px 12px',
                fontSize: '13px',
                fontWeight: '600',
                borderRadius: '8px',
                border: '1px solid #cbd5e1',
                background: '#ffffff',
                cursor: 'pointer',
              }}
            >
              <option value="today">Today</option>
              <option value="7">Last 7 days</option>
              <option value="30">Last 30 days</option>
              <option value="90">Last 90 days</option>
              <option value="custom">Custom date range</option>
            </select>
          </div>

          {/* Inline Custom Range Form */}
          {period === 'custom' && (
            <form
              onSubmit={handleApplyCustomDates}
              style={{ display: 'flex', gap: '8px', alignItems: 'center', flexWrap: 'wrap' }}
            >
              <input
                type="date"
                className="input"
                value={customStart}
                max={customEnd || new Date().toISOString().split('T')[0]}
                onChange={(e) => setCustomStart(e.target.value)}
                style={{ width: '135px', padding: '5px 8px', fontSize: '12px' }}
                required
              />
              <span style={{ fontSize: '12px', color: '#64748b' }}>to</span>
              <input
                type="date"
                className="input"
                value={customEnd}
                min={customStart}
                max={new Date().toISOString().split('T')[0]}
                onChange={(e) => setCustomEnd(e.target.value)}
                style={{ width: '135px', padding: '5px 8px', fontSize: '12px' }}
                required
              />
              <button
                type="submit"
                className="btn btn-primary btn-sm"
                style={{ padding: '6px 12px', fontSize: '12px', borderRadius: '6px' }}
              >
                Apply
              </button>
            </form>
          )}

          {dateRangeLabel && (
            <span
              style={{
                fontSize: '12px',
                color: '#0f172a',
                background: '#f1f5f9',
                padding: '5px 12px',
                borderRadius: '8px',
                fontWeight: '600',
              }}
            >
              {dateRangeLabel}
            </span>
          )}
        </div>
      </div>

      {/* ── 3. KPI Cards ────────────────────────────────────────────────────── */}
      <div
        className="metric-grid"
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))',
          gap: '16px',
          opacity: loading ? 0.6 : 1,
          transition: 'opacity 0.2s',
        }}
      >
        {/* Card 1: Reach / Impressions */}
        <div className="metric-card" style={{ background: '#ffffff', borderRadius: '14px', border: '1px solid #e2e8f0', padding: '20px' }}>
          <div className="metric-top" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' }}>
            <span style={{ fontSize: '13px', fontWeight: '600', color: '#64748b' }}>
              {platform === 'search_console' ? 'Search Impressions' : platform === 'youtube' ? 'Channel Views' : 'Total Reach / Impressions'}
            </span>
            <div
              style={{
                width: '36px',
                height: '36px',
                borderRadius: '10px',
                background: '#eff6ff',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Eye size={19} color="#1877f2" />
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: '800', color: '#0f172a', lineHeight: 1.1, marginBottom: '6px' }}>
            {loading ? '...' : (summary?.total_reach != null ? formatNumber(summary.total_reach) : 'No data available for this period.')}
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: '#64748b' }}>
            {reachChange !== null ? (
              <span
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  fontWeight: '700',
                  color: reachChange >= 0 ? '#16a34a' : '#dc2626',
                  background: reachChange >= 0 ? '#f0fdf4' : '#fef2f2',
                  padding: '1px 6px',
                  borderRadius: '4px',
                }}
              >
                {reachChange >= 0 ? <ArrowUpRight size={13} /> : <ArrowDownRight size={13} />}
                {reachChange >= 0 ? `+${reachChange}%` : `${reachChange}%`}
              </span>
            ) : null}
            <span>{reachChange !== null ? 'vs previous period' : 'Real connected metrics'}</span>
          </div>
        </div>

        {/* Card 2: Total Engagements */}
        <div className="metric-card" style={{ background: '#ffffff', borderRadius: '14px', border: '1px solid #e2e8f0', padding: '20px' }}>
          <div className="metric-top" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' }}>
            <span style={{ fontSize: '13px', fontWeight: '600', color: '#64748b' }}>
              {platform === 'google_analytics' ? 'GA4 Conversions' : 'Total Engagements'}
            </span>
            <div
              style={{
                width: '36px',
                height: '36px',
                borderRadius: '10px',
                background: '#f0fdf4',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Heart size={19} color="#10b981" />
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: '800', color: '#0f172a', lineHeight: 1.1, marginBottom: '6px' }}>
            {loading ? '...' : (summary?.total_engagement != null ? formatNumber(summary.total_engagement) : 'No data available for this period.')}
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: '#64748b' }}>
            {engagementChange !== null ? (
              <span
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  fontWeight: '700',
                  color: engagementChange >= 0 ? '#16a34a' : '#dc2626',
                  background: engagementChange >= 0 ? '#f0fdf4' : '#fef2f2',
                  padding: '1px 6px',
                  borderRadius: '4px',
                }}
              >
                {engagementChange >= 0 ? <ArrowUpRight size={13} /> : <ArrowDownRight size={13} />}
                {engagementChange >= 0 ? `+${engagementChange}%` : `${engagementChange}%`}
              </span>
            ) : null}
            <span>{engagementChange !== null ? 'vs previous period' : 'Likes, comments & interactions'}</span>
          </div>
        </div>

        {/* Card 3: Total Audience */}
        <div className="metric-card" style={{ background: '#ffffff', borderRadius: '14px', border: '1px solid #e2e8f0', padding: '20px' }}>
          <div className="metric-top" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' }}>
            <span style={{ fontSize: '13px', fontWeight: '600', color: '#64748b' }}>
              {platform === 'youtube' ? 'Subscribers' : 'Total Audience'}
            </span>
            <div
              style={{
                width: '36px',
                height: '36px',
                borderRadius: '10px',
                background: '#faf5ff',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Users size={19} color="#8b5cf6" />
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: '800', color: '#0f172a', lineHeight: 1.1, marginBottom: '6px' }}>
            {loading ? '...' : (summary?.total_audience != null ? formatNumber(summary.total_audience) : 'No data available for this period.')}
          </div>
          <div style={{ fontSize: '12px', color: '#64748b' }}>
            Followers & subscribers base
          </div>
        </div>

        {/* Card 4: Clicks & Traffic */}
        <div className="metric-card" style={{ background: '#ffffff', borderRadius: '14px', border: '1px solid #e2e8f0', padding: '20px' }}>
          <div className="metric-top" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px' }}>
            <span style={{ fontSize: '13px', fontWeight: '600', color: '#64748b' }}>
              Clicks & Web Traffic
            </span>
            <div
              style={{
                width: '36px',
                height: '36px',
                borderRadius: '10px',
                background: '#fffbeb',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <MousePointer size={19} color="#f59e0b" />
            </div>
          </div>
          <div style={{ fontSize: '28px', fontWeight: '800', color: '#0f172a', lineHeight: 1.1, marginBottom: '6px' }}>
            {loading ? '...' : (summary?.total_clicks != null ? formatNumber(summary.total_clicks) : 'No data available for this period.')}
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: '#64748b' }}>
            {clicksChange !== null ? (
              <span
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  fontWeight: '700',
                  color: clicksChange >= 0 ? '#16a34a' : '#dc2626',
                  background: clicksChange >= 0 ? '#f0fdf4' : '#fef2f2',
                  padding: '1px 6px',
                  borderRadius: '4px',
                }}
              >
                {clicksChange >= 0 ? <ArrowUpRight size={13} /> : <ArrowDownRight size={13} />}
                {clicksChange >= 0 ? `+${clicksChange}%` : `${clicksChange}%`}
              </span>
            ) : null}
            <span>Organic search clicks & web sessions</span>
          </div>
        </div>
      </div>

      {/* ── 4. Performance Trend Line Chart ─────────────────────────────────── */}
      <div
        className="panel"
        style={{
          background: '#ffffff',
          borderRadius: '14px',
          border: '1px solid #e2e8f0',
          padding: '24px',
          boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
        }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: '12px', marginBottom: '20px' }}>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <TrendingUp size={18} color="#2563eb" />
              <h3 style={{ margin: 0, fontSize: '17px', fontWeight: '700', color: '#0f172a' }}>
                Performance Trend
              </h3>
            </div>
            <p style={{ margin: '3px 0 0', fontSize: '13px', color: '#64748b' }}>
              {platform === 'all'
                ? 'Aggregated daily performance across connected marketing channels'
                : `${platform.toUpperCase()} date-based metric trends`}
            </p>
          </div>
          <span
            style={{
              fontSize: '12px',
              fontWeight: '600',
              color: '#475569',
              background: '#f8fafc',
              border: '1px solid #e2e8f0',
              padding: '4px 12px',
              borderRadius: '20px',
            }}
          >
            {dateRangeLabel || 'Selected Range'}
          </span>
        </div>

        <div style={{ height: '340px', position: 'relative' }}>
          {loading ? (
            <div style={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8' }}>
              Loading performance trend...
            </div>
          ) : chartConfig ? (
            <Line data={chartConfig.data} options={chartConfig.options} />
          ) : (
            <div
              style={{
                height: '100%',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                color: '#64748b',
                fontSize: '13.5px',
              }}
            >
              No data available for this period.
            </div>
          )}
        </div>
      </div>

      {/* ── 5. Platform Breakdown ───────────────────────────────────────────── */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: '14px' }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <Layers size={18} color="#0f172a" />
            <h3 style={{ margin: 0, fontSize: '18px', fontWeight: '700', color: '#0f172a' }}>
              Platform Breakdown
            </h3>
          </div>
          <p style={{ margin: '3px 0 0', fontSize: '13px', color: '#64748b' }}>
            Comprehensive performance and audience metrics for each connected service.
          </p>
        </div>

        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))',
            gap: '16px',
          }}
        >
          {/* Card: Facebook */}
          <div
            style={{
              background: '#ffffff',
              borderRadius: '14px',
              border: platform === 'facebook' ? '2px solid #1877f2' : '1px solid #e2e8f0',
              padding: '20px',
              boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <div
                  style={{
                    width: '32px',
                    height: '32px',
                    borderRadius: '8px',
                    background: '#1877f2',
                    color: '#ffffff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontWeight: '800',
                    fontSize: '16px',
                  }}
                >
                  f
                </div>
                <div>
                  <h4 style={{ margin: 0, fontSize: '14px', fontWeight: '700', color: '#0f172a' }}>
                    Facebook Page
                  </h4>
                  <span style={{ fontSize: '12px', color: '#64748b' }}>
                    {analytics?.facebook?.page_name || (analytics?.facebook?.connected ? 'Page Connected' : 'Not Connected')}
                  </span>
                </div>
              </div>
              <span
                style={{
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '3px 8px',
                  borderRadius: '12px',
                  background: analytics?.facebook?.connected ? '#f0fdf4' : '#fef2f2',
                  color: analytics?.facebook?.connected ? '#16a34a' : '#dc2626',
                }}
              >
                {analytics?.facebook?.connected ? 'Connected' : 'Offline'}
              </span>
            </div>

            {analytics?.facebook?.connected ? (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Impressions</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.facebook.impressions)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Engagements</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.facebook.engagement)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Reactions / Likes</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.facebook.reactions || analytics.facebook.likes)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Followers</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.facebook.followers)}
                  </div>
                </div>
              </div>
            ) : (
              <div style={{ padding: '16px 0', textAlign: 'center', fontSize: '13px', color: '#94a3b8' }}>
                No data available for this period.
              </div>
            )}
          </div>

          {/* Card: Instagram */}
          <div
            style={{
              background: '#ffffff',
              borderRadius: '14px',
              border: platform === 'instagram' ? '2px solid #e1306c' : '1px solid #e2e8f0',
              padding: '20px',
              boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <div
                  style={{
                    width: '32px',
                    height: '32px',
                    borderRadius: '8px',
                    background: 'linear-gradient(135deg, #f58529, #dd2a7b, #8134af)',
                    color: '#ffffff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontWeight: '800',
                  }}
                >
                  <Share2 size={16} />
                </div>
                <div>
                  <h4 style={{ margin: 0, fontSize: '14px', fontWeight: '700', color: '#0f172a' }}>
                    Instagram Business
                  </h4>
                  <span style={{ fontSize: '12px', color: '#64748b' }}>
                    {analytics?.instagram?.account_name || (analytics?.instagram?.connected ? 'Account Connected' : 'Not Connected')}
                  </span>
                </div>
              </div>
              <span
                style={{
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '3px 8px',
                  borderRadius: '12px',
                  background: analytics?.instagram?.connected ? '#f0fdf4' : '#fef2f2',
                  color: analytics?.instagram?.connected ? '#16a34a' : '#dc2626',
                }}
              >
                {analytics?.instagram?.connected ? 'Connected' : 'Offline'}
              </span>
            </div>

            {analytics?.instagram?.connected ? (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Total Reach</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.instagram.reach)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Interactions</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.instagram.interactions)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Video Views</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.instagram.views)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Followers</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.instagram.followers)}
                  </div>
                </div>
              </div>
            ) : (
              <div style={{ padding: '16px 0', textAlign: 'center', fontSize: '13px', color: '#94a3b8' }}>
                No data available for this period.
              </div>
            )}
          </div>

          {/* Card: YouTube */}
          <div
            style={{
              background: '#ffffff',
              borderRadius: '14px',
              border: platform === 'youtube' ? '2px solid #dc2626' : '1px solid #e2e8f0',
              padding: '20px',
              boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <div
                  style={{
                    width: '32px',
                    height: '32px',
                    borderRadius: '8px',
                    background: '#dc2626',
                    color: '#ffffff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <Video size={16} />
                </div>
                <div>
                  <h4 style={{ margin: 0, fontSize: '14px', fontWeight: '700', color: '#0f172a' }}>
                    YouTube Studio
                  </h4>
                  <span style={{ fontSize: '12px', color: '#64748b' }}>
                    {analytics?.youtube?.channel_name || (analytics?.youtube?.connected ? 'Channel Connected' : 'Not Connected')}
                  </span>
                </div>
              </div>
              <span
                style={{
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '3px 8px',
                  borderRadius: '12px',
                  background: analytics?.youtube?.connected ? '#f0fdf4' : '#fef2f2',
                  color: analytics?.youtube?.connected ? '#16a34a' : '#dc2626',
                }}
              >
                {analytics?.youtube?.connected ? 'Connected' : 'Offline'}
              </span>
            </div>

            {analytics?.youtube?.connected ? (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Total Views</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.youtube.views)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Subscribers</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.youtube.subscribers)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Uploaded Videos</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.youtube.video_count)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Video Likes</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.youtube.likes)}
                  </div>
                </div>
              </div>
            ) : (
              <div style={{ padding: '16px 0', textAlign: 'center', fontSize: '13px', color: '#94a3b8' }}>
                No data available for this period.
              </div>
            )}
          </div>

          {/* Card: Google Analytics (GA4) */}
          <div
            style={{
              background: '#ffffff',
              borderRadius: '14px',
              border: platform === 'google_analytics' ? '2px solid #f59e0b' : '1px solid #e2e8f0',
              padding: '20px',
              boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <div
                  style={{
                    width: '32px',
                    height: '32px',
                    borderRadius: '8px',
                    background: '#f59e0b',
                    color: '#ffffff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <BarChart3 size={16} />
                </div>
                <div>
                  <h4 style={{ margin: 0, fontSize: '14px', fontWeight: '700', color: '#0f172a' }}>
                    Google Analytics 4
                  </h4>
                  <span style={{ fontSize: '12px', color: '#64748b' }}>
                    {analytics?.google_analytics?.connected ? 'Active GA4 Stream' : 'Not Connected'}
                  </span>
                </div>
              </div>
              <span
                style={{
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '3px 8px',
                  borderRadius: '12px',
                  background: analytics?.google_analytics?.connected ? '#f0fdf4' : '#fef2f2',
                  color: analytics?.google_analytics?.connected ? '#16a34a' : '#dc2626',
                }}
              >
                {analytics?.google_analytics?.connected ? 'Connected' : 'Offline'}
              </span>
            </div>

            {analytics?.google_analytics?.connected ? (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Active Users</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.google_analytics.active_users)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Web Sessions</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.google_analytics.sessions)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Pageviews</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.google_analytics.screen_page_views)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Engagement Rate</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {analytics.google_analytics.engagement_rate != null ? `${analytics.google_analytics.engagement_rate}%` : '0%'}
                  </div>
                </div>
              </div>
            ) : (
              <div style={{ padding: '16px 0', textAlign: 'center', fontSize: '13px', color: '#94a3b8' }}>
                No data available for this period.
              </div>
            )}
          </div>

          {/* Card: Google Search Console */}
          <div
            style={{
              background: '#ffffff',
              borderRadius: '14px',
              border: platform === 'search_console' ? '2px solid #0284c7' : '1px solid #e2e8f0',
              padding: '20px',
              boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <div
                  style={{
                    width: '32px',
                    height: '32px',
                    borderRadius: '8px',
                    background: '#0284c7',
                    color: '#ffffff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  <Search size={16} />
                </div>
                <div>
                  <h4 style={{ margin: 0, fontSize: '14px', fontWeight: '700', color: '#0f172a' }}>
                    Google Search Console
                  </h4>
                  <span style={{ fontSize: '12px', color: '#64748b' }}>
                    {analytics?.search_console?.connected ? 'Organic Search Stream' : 'Not Connected'}
                  </span>
                </div>
              </div>
              <span
                style={{
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '3px 8px',
                  borderRadius: '12px',
                  background: analytics?.search_console?.connected ? '#f0fdf4' : '#fef2f2',
                  color: analytics?.search_console?.connected ? '#16a34a' : '#dc2626',
                }}
              >
                {analytics?.search_console?.connected ? 'Connected' : 'Offline'}
              </span>
            </div>

            {analytics?.search_console?.connected ? (
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Organic Clicks</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.search_console.clicks)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Impressions</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {formatNumber(analytics.search_console.impressions)}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Average CTR</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {analytics.search_console.ctr != null ? `${analytics.search_console.ctr}%` : '0%'}
                  </div>
                </div>
                <div style={{ background: '#f8fafc', padding: '10px 12px', borderRadius: '8px' }}>
                  <div style={{ fontSize: '11px', color: '#64748b', fontWeight: '600' }}>Average Position</div>
                  <div style={{ fontSize: '16px', fontWeight: '800', color: '#0f172a', marginTop: '2px' }}>
                    {analytics.search_console.position != null ? analytics.search_console.position : '0'}
                  </div>
                </div>
              </div>
            ) : (
              <div style={{ padding: '16px 0', textAlign: 'center', fontSize: '13px', color: '#94a3b8' }}>
                No data available for this period.
              </div>
            )}
          </div>
        </div>
      </div>

      {/* ── 6. Detailed Performance Table ───────────────────────────────────── */}
      <div
        className="panel"
        style={{
          background: '#ffffff',
          borderRadius: '14px',
          border: '1px solid #e2e8f0',
          padding: '24px',
          boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
        }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap', gap: '10px' }}>
          <div>
            <h3 style={{ margin: 0, fontSize: '17px', fontWeight: '700', color: '#0f172a' }}>
              Detailed Performance Metrics
            </h3>
            <p style={{ margin: '3px 0 0', fontSize: '13px', color: '#64748b' }}>
              Side-by-side granular breakdown across each channel for the active reporting period.
            </p>
          </div>
          <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '500' }}>
            Period: <strong>{dateRangeLabel}</strong>
          </span>
        </div>

        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', fontSize: '13px' }}>
            <thead>
              <tr style={{ background: '#f8fafc', borderBottom: '2px solid #e2e8f0' }}>
                <th style={{ padding: '12px 14px', fontWeight: '700', color: '#475569' }}>Platform</th>
                <th style={{ padding: '12px 14px', fontWeight: '700', color: '#475569' }}>Reach / Views</th>
                <th style={{ padding: '12px 14px', fontWeight: '700', color: '#475569' }}>Engagement</th>
                <th style={{ padding: '12px 14px', fontWeight: '700', color: '#475569' }}>Likes</th>
                <th style={{ padding: '12px 14px', fontWeight: '700', color: '#475569' }}>Comments</th>
                <th style={{ padding: '12px 14px', fontWeight: '700', color: '#475569' }}>Shares</th>
                <th style={{ padding: '12px 14px', fontWeight: '700', color: '#475569' }}>Followers / Subs</th>
                <th style={{ padding: '12px 14px', fontWeight: '700', color: '#475569' }}>Clicks</th>
                <th style={{ padding: '12px 14px', fontWeight: '700', color: '#475569' }}>Period</th>
              </tr>
            </thead>
            <tbody>
              {/* Facebook Row */}
              {(platform === 'all' || platform === 'facebook') && (
                <tr style={{ borderBottom: '1px solid #f1f5f9' }}>
                  <td style={{ padding: '14px', fontWeight: '600', color: '#1877f2', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <div style={{ width: '8px', height: '8px', borderRadius: '50%', background: '#1877f2' }} />
                    Facebook
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.facebook?.connected ? formatNumber(analytics.facebook.impressions) : '—'}
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600', color: '#10b981' }}>
                    {analytics?.facebook?.connected ? formatNumber(analytics.facebook.engagement) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.facebook?.connected ? formatNumber(analytics.facebook.likes) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.facebook?.connected ? formatNumber(analytics.facebook.comments) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.facebook?.connected ? '—' : '—'}
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.facebook?.connected ? formatNumber(analytics.facebook.followers) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px', color: '#64748b', fontSize: '12px' }}>
                    {analytics?.days_count ? `${analytics.days_count} days` : '—'}
                  </td>
                </tr>
              )}

              {/* Instagram Row */}
              {(platform === 'all' || platform === 'instagram') && (
                <tr style={{ borderBottom: '1px solid #f1f5f9' }}>
                  <td style={{ padding: '14px', fontWeight: '600', color: '#e1306c', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <div style={{ width: '8px', height: '8px', borderRadius: '50%', background: '#e1306c' }} />
                    Instagram
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.instagram?.connected ? formatNumber(analytics.instagram.reach) : '—'}
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600', color: '#10b981' }}>
                    {analytics?.instagram?.connected ? formatNumber(analytics.instagram.interactions) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.instagram?.connected ? formatNumber(analytics.instagram.likes) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.instagram?.connected ? formatNumber(analytics.instagram.comments) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.instagram?.connected ? formatNumber(analytics.instagram.shares) : '—'}
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.instagram?.connected ? formatNumber(analytics.instagram.followers) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px', color: '#64748b', fontSize: '12px' }}>
                    {analytics?.days_count ? `${analytics.days_count} days` : '—'}
                  </td>
                </tr>
              )}

              {/* YouTube Row */}
              {(platform === 'all' || platform === 'youtube') && (
                <tr style={{ borderBottom: '1px solid #f1f5f9' }}>
                  <td style={{ padding: '14px', fontWeight: '600', color: '#dc2626', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <div style={{ width: '8px', height: '8px', borderRadius: '50%', background: '#dc2626' }} />
                    YouTube
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.youtube?.connected ? formatNumber(analytics.youtube.views) : '—'}
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600', color: '#10b981' }}>
                    {analytics?.youtube?.connected ? formatNumber((analytics.youtube.likes || 0) + (analytics.youtube.comments || 0)) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.youtube?.connected ? formatNumber(analytics.youtube.likes) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.youtube?.connected ? formatNumber(analytics.youtube.comments) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.youtube?.connected ? formatNumber(analytics.youtube.shares) : '—'}
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.youtube?.connected ? formatNumber(analytics.youtube.subscribers) : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px', color: '#64748b', fontSize: '12px' }}>
                    {analytics?.days_count ? `${analytics.days_count} days` : '—'}
                  </td>
                </tr>
              )}

              {/* GA4 Row */}
              {(platform === 'all' || platform === 'google_analytics') && (
                <tr style={{ borderBottom: '1px solid #f1f5f9' }}>
                  <td style={{ padding: '14px', fontWeight: '600', color: '#f59e0b', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <div style={{ width: '8px', height: '8px', borderRadius: '50%', background: '#f59e0b' }} />
                    Google Analytics (GA4)
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.google_analytics?.connected ? formatNumber(analytics.google_analytics.screen_page_views) : '—'}
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.google_analytics?.connected ? `${analytics.google_analytics.engagement_rate}% rate` : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px' }}>
                    {analytics?.google_analytics?.connected ? `${formatNumber(analytics.google_analytics.active_users)} users` : '—'}
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.google_analytics?.connected ? formatNumber(analytics.google_analytics.sessions) : '—'}
                  </td>
                  <td style={{ padding: '14px', color: '#64748b', fontSize: '12px' }}>
                    {analytics?.days_count ? `${analytics.days_count} days` : '—'}
                  </td>
                </tr>
              )}

              {/* Search Console Row */}
              {(platform === 'all' || platform === 'search_console') && (
                <tr style={{ borderBottom: '1px solid #f1f5f9' }}>
                  <td style={{ padding: '14px', fontWeight: '600', color: '#0284c7', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <div style={{ width: '8px', height: '8px', borderRadius: '50%', background: '#0284c7' }} />
                    Search Console
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.search_console?.connected ? formatNumber(analytics.search_console.impressions) : '—'}
                  </td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.search_console?.connected ? `${analytics.search_console.ctr}% CTR` : '—'}
                  </td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px' }}>—</td>
                  <td style={{ padding: '14px', fontWeight: '600' }}>
                    {analytics?.search_console?.connected ? formatNumber(analytics.search_console.clicks) : '—'}
                  </td>
                  <td style={{ padding: '14px', color: '#64748b', fontSize: '12px' }}>
                    {analytics?.days_count ? `${analytics.days_count} days` : '—'}
                  </td>
                </tr>
              )}

              {/* Total Summary Row */}
              {platform === 'all' && (
                <tr style={{ background: '#f8fafc', fontWeight: '700', borderTop: '2px solid #cbd5e1' }}>
                  <td style={{ padding: '14px', color: '#0f172a' }}>Total Combined</td>
                  <td style={{ padding: '14px', color: '#1877f2' }}>{formatNumber(summary?.total_reach)}</td>
                  <td style={{ padding: '14px', color: '#10b981' }}>{formatNumber(summary?.total_engagement)}</td>
                  <td style={{ padding: '14px' }}>
                    {formatNumber((analytics?.facebook?.likes || 0) + (analytics?.instagram?.likes || 0) + (analytics?.youtube?.likes || 0))}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {formatNumber((analytics?.facebook?.comments || 0) + (analytics?.instagram?.comments || 0) + (analytics?.youtube?.comments || 0))}
                  </td>
                  <td style={{ padding: '14px' }}>
                    {formatNumber((analytics?.instagram?.shares || 0) + (analytics?.youtube?.shares || 0))}
                  </td>
                  <td style={{ padding: '14px', color: '#8b5cf6' }}>{formatNumber(summary?.total_audience)}</td>
                  <td style={{ padding: '14px', color: '#f59e0b' }}>{formatNumber(summary?.total_clicks)}</td>
                  <td style={{ padding: '14px', color: '#64748b', fontSize: '12px' }}>
                    {analytics?.days_count ? `${analytics.days_count} days` : '—'}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* ── 7. Top Performing Content ───────────────────────────────────────── */}
      <div
        className="panel"
        style={{
          background: '#ffffff',
          borderRadius: '14px',
          border: '1px solid #e2e8f0',
          padding: '24px',
          boxShadow: '0 1px 3px rgba(0,0,0,0.03)',
        }}
      >
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '18px', flexWrap: 'wrap', gap: '12px' }}>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <Sparkles size={18} color="#e1306c" />
              <h3 style={{ margin: 0, fontSize: '17px', fontWeight: '700', color: '#0f172a' }}>
                Top Performing Content
              </h3>
            </div>
            <p style={{ margin: '3px 0 0', fontSize: '13px', color: '#64748b' }}>
              Highest performing posts and videos by total interactions and reach during this period.
            </p>
          </div>

          {/* Sub-filter tabs for content when platform is 'all' */}
          {platform === 'all' && (
            <div className="no-print" style={{ display: 'flex', gap: '6px' }}>
              {['all', 'facebook', 'instagram', 'youtube'].map((pKey) => {
                const isAct = contentPlatformFilter === pKey;
                return (
                  <button
                    key={pKey}
                    type="button"
                    onClick={() => setContentPlatformFilter(pKey)}
                    style={{
                      padding: '4px 12px',
                      borderRadius: '16px',
                      fontSize: '11.5px',
                      fontWeight: '600',
                      cursor: 'pointer',
                      border: isAct ? '1px solid #2563eb' : '1px solid #cbd5e1',
                      background: isAct ? '#2563eb' : '#ffffff',
                      color: isAct ? '#ffffff' : '#64748b',
                    }}
                  >
                    {pKey.charAt(0).toUpperCase() + pKey.slice(1)}
                  </button>
                );
              })}
            </div>
          )}
        </div>

        {filteredContent.length > 0 ? (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '13px' }}>
              <thead>
                <tr style={{ background: '#f8fafc', borderBottom: '2px solid #e2e8f0', textAlign: 'left' }}>
                  <th style={{ padding: '10px 14px', fontWeight: '700', color: '#475569', width: '110px' }}>Platform</th>
                  <th style={{ padding: '10px 14px', fontWeight: '700', color: '#475569' }}>Content Preview</th>
                  <th style={{ padding: '10px 14px', fontWeight: '700', color: '#475569', width: '110px' }}>Date</th>
                  <th style={{ padding: '10px 14px', fontWeight: '700', color: '#475569', textAlign: 'right' }}>Reach / Views</th>
                  <th style={{ padding: '10px 14px', fontWeight: '700', color: '#475569', textAlign: 'right' }}>Likes</th>
                  <th style={{ padding: '10px 14px', fontWeight: '700', color: '#475569', textAlign: 'right' }}>Comments</th>
                  <th style={{ padding: '10px 14px', fontWeight: '700', color: '#475569', textAlign: 'right' }}>Shares</th>
                  <th style={{ padding: '10px 14px', fontWeight: '700', color: '#475569', textAlign: 'right' }}>Engagement</th>
                  <th style={{ padding: '10px 14px', fontWeight: '700', color: '#475569', textAlign: 'center', width: '60px' }}>Link</th>
                </tr>
              </thead>
              <tbody>
                {filteredContent.slice(0, 15).map((item, idx) => {
                  const platColor =
                    item.platform === 'facebook'
                      ? '#1877f2'
                      : item.platform === 'instagram'
                      ? '#e1306c'
                      : '#dc2626';

                  return (
                    <tr key={item.id || idx} style={{ borderBottom: '1px solid #f1f5f9' }}>
                      <td style={{ padding: '12px 14px' }}>
                        <span
                          style={{
                            fontSize: '11px',
                            fontWeight: '700',
                            textTransform: 'uppercase',
                            color: platColor,
                            background: `${platColor}12`,
                            padding: '3px 8px',
                            borderRadius: '6px',
                            display: 'inline-block',
                          }}
                        >
                          {item.platform}
                        </span>
                      </td>

                      <td style={{ padding: '12px 14px', maxWidth: '320px' }}>
                        <span
                          style={{
                            display: '-webkit-box',
                            WebkitLineClamp: 2,
                            WebkitBoxOrient: 'vertical',
                            overflow: 'hidden',
                            lineHeight: 1.4,
                            color: '#1e293b',
                            fontWeight: '500',
                          }}
                          title={item.title}
                        >
                          {item.title}
                        </span>
                      </td>

                      <td style={{ padding: '12px 14px', color: '#64748b', fontSize: '12px', whiteSpace: 'nowrap' }}>
                        {formatDate(item.date)}
                      </td>

                      <td style={{ padding: '12px 14px', textAlign: 'right', fontWeight: '600' }}>
                        {formatNumber(item.reach)}
                      </td>

                      <td style={{ padding: '12px 14px', textAlign: 'right', color: '#475569' }}>
                        {formatNumber(item.likes)}
                      </td>

                      <td style={{ padding: '12px 14px', textAlign: 'right', color: '#475569' }}>
                        {formatNumber(item.comments)}
                      </td>

                      <td style={{ padding: '12px 14px', textAlign: 'right', color: '#475569' }}>
                        {formatNumber(item.shares)}
                      </td>

                      <td style={{ padding: '12px 14px', textAlign: 'right' }}>
                        <span
                          style={{
                            fontWeight: '700',
                            color: '#10b981',
                            background: '#f0fdf4',
                            padding: '3px 8px',
                            borderRadius: '6px',
                          }}
                        >
                          {formatNumber(item.engagement)}
                        </span>
                      </td>

                      <td style={{ padding: '12px 14px', textAlign: 'center' }}>
                        {item.url ? (
                          <a
                            href={item.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            style={{ color: '#2563eb', display: 'inline-flex', alignItems: 'center' }}
                            title="View published post"
                          >
                            <ExternalLink size={14} />
                          </a>
                        ) : (
                          <span style={{ color: '#cbd5e1' }}>—</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        ) : (
          <div style={{ padding: '30px 0', textAlign: 'center', color: '#94a3b8', fontSize: '13.5px' }}>
            No content published during this period.
          </div>
        )}
      </div>
    </div>
  );
}
