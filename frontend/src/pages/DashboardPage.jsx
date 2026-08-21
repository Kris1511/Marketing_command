import React, { useEffect, useState, useCallback } from 'react';
import axiosInstance from '../api/axiosInstance';
import { useNavigate } from 'react-router-dom';
import { useWorkspace } from '../context/WorkspaceContext';
import { useAuth } from '../hooks/useAuth';
import {
  Eye, Zap, Globe, Users, Plus, RefreshCw, Share2, CheckCircle2,
  AlertCircle, Clock, TrendingUp, AlertTriangle, BarChart2,
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
  const navigate = useNavigate();
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspace();
  const { user } = useAuth();

  const [overview, setOverview] = useState(null);
  const [trend, setTrend] = useState(null);
  const [priorities, setPriorities] = useState([]);
  const [loading, setLoading] = useState(true);
  const [platformIndex, setPlatformIndex] = useState(0); // 0 = FB, 1 = IG, 2 = YT
  const getPresetDates = (days) => {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - (days - 1));
    return {
      start: start.toISOString().split('T')[0],
      end: end.toISOString().split('T')[0],
    };
  };

  const [trendDays, setTrendDays] = useState(() => {
    const saved = localStorage.getItem('dashboard_trend_days');
    if (saved) return saved === 'custom' ? 'custom' : Number(saved);
    return 30;
  });
  const [customStartDate, setCustomStartDate] = useState(() => {
    const saved = localStorage.getItem('dashboard_custom_start');
    if (saved) return saved;
    const initialDays = localStorage.getItem('dashboard_trend_days');
    const numDays = (initialDays && initialDays !== 'custom') ? Number(initialDays) : 30;
    const d = new Date();
    d.setDate(d.getDate() - (numDays - 1));
    return d.toISOString().split('T')[0];
  });
  const [customEndDate, setCustomEndDate] = useState(() => {
    const saved = localStorage.getItem('dashboard_custom_end');
    if (saved) return saved;
    return new Date().toISOString().split('T')[0];
  });
  const [error, setError] = useState('');

  const fetchAll = useCallback(async (forceRefresh = false) => {
    if (!selectedWorkspaceId) return;
    setLoading(true);
    setError('');
    try {
      const overviewParams = {
        workspace_id: selectedWorkspaceId,
        start_date: customStartDate,
        end_date: customEndDate,
      };
      if (typeof trendDays === 'number') {
        overviewParams.days = trendDays;
      }
      if (forceRefresh === true) {
        overviewParams.force_refresh = 1;
      }

      const trendParams = {
        workspace_id: selectedWorkspaceId,
        start_date: customStartDate,
        end_date: customEndDate,
      };
      if (typeof trendDays === 'number') {
        trendParams.days = trendDays;
      }

      const results = await Promise.allSettled([
        axiosInstance.get('/dashboard/overview', { params: overviewParams }),
        axiosInstance.get('/dashboard/trend', { params: trendParams }),
        axiosInstance.get('/dashboard/priorities', { params: { workspace_id: selectedWorkspaceId } }),
      ]);

      const [overviewRes, trendRes, priRes] = results;

      if (overviewRes.status === 'fulfilled' && overviewRes.value.data?.success) {
        setOverview(overviewRes.value.data.data);
      }
      if (trendRes.status === 'fulfilled' && trendRes.value.data?.success) {
        setTrend(trendRes.value.data);
      }
      if (priRes.status === 'fulfilled' && priRes.value.data?.success) {
        setPriorities(priRes.value.data.data || []);
      }

      // Only set general error if overview failed
      if (overviewRes.status === 'rejected') {
        console.error('Overview endpoint error:', overviewRes.reason);
        setError('Unable to load dashboard data. Please try again.');
      }
    } catch (err) {
      console.error('Dashboard load error:', err);
      setError('Unable to load dashboard data. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [selectedWorkspaceId, trendDays, customStartDate, customEndDate]);

  useEffect(() => { fetchAll(); }, [fetchAll]);

  // ── Chart Configuration ─────────────────────────────────────────────────────
  // Show chart if we have labels AND (a connected page OR non-zero data)
  const hasChartData = trend?.data?.labels?.length > 0 && (
    trend?.has_data ||
    (overview?.connected_page != null)
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
        <button className="btn btn-primary" onClick={fetchAll} style={{ marginTop: 16 }}>
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

      {/* Date Filter Bar — Aligned to top right */}
      <div style={{ display: 'flex', justifyContent: 'flex-end', alignItems: 'center', marginBottom: '10px', gap: '10px' }}>
        <select
          className="input"
          style={{ width: 'auto', padding: '6px 12px', fontSize: '13px', borderRadius: '6px', border: '1px solid #cbd5e1', background: '#ffffff', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' }}
          value={trendDays}
          onChange={e => {
            const val = e.target.value === 'custom' ? 'custom' : Number(e.target.value);
            setTrendDays(val);
            localStorage.setItem('dashboard_trend_days', val);
            if (val !== 'custom') {
              const { start, end } = getPresetDates(val);
              setCustomStartDate(start);
              setCustomEndDate(end);
              localStorage.setItem('dashboard_custom_start', start);
              localStorage.setItem('dashboard_custom_end', end);
            }
          }}
        >
          <option value={7}>Last 7 days</option>
          <option value={30}>Last 30 days</option>
          <option value={90}>Last 90 days</option>
          <option value="custom">Custom</option>
        </select>

        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', background: '#ffffff', padding: '4px 10px', borderRadius: '6px', border: '1px solid #cbd5e1', boxShadow: '0 1px 2px rgba(0,0,0,0.05)' }}>
          <span style={{ fontSize: '12px', color: '#475569', fontWeight: '600' }}>Start:</span>
          <input
            type="date"
            className="input"
            style={{ width: 'auto', padding: '3px 8px', fontSize: '12px' }}
            value={customStartDate}
            onChange={e => {
              const newStart = e.target.value;
              setCustomStartDate(newStart);
              setTrendDays('custom');
              localStorage.setItem('dashboard_trend_days', 'custom');
              localStorage.setItem('dashboard_custom_start', newStart);
              if (customEndDate && newStart > customEndDate) {
                setCustomEndDate(newStart);
                localStorage.setItem('dashboard_custom_end', newStart);
              }
            }}
          />
          <span style={{ fontSize: '12px', color: '#475569', fontWeight: '600' }}>End:</span>
          <input
            type="date"
            className="input"
            style={{ width: 'auto', padding: '3px 8px', fontSize: '12px' }}
            min={customStartDate}
            value={customEndDate}
            onChange={e => {
              const newEnd = e.target.value;
              if (newEnd >= customStartDate) {
                setCustomEndDate(newEnd);
                setTrendDays('custom');
                localStorage.setItem('dashboard_trend_days', 'custom');
                localStorage.setItem('dashboard_custom_end', newEnd);
              }
            }}
          />
        </div>
      </div>

      {/* Metrics Row — all real data */}
      <div className="metric-grid" style={{ opacity: loading ? 0.5 : 1, transition: 'opacity 0.2s', marginBottom: '18px' }}>
        {/* Total Reach */}
        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Total reach</span>
            <div className="metric-icon"><Eye size={20} /></div>
          </div>
          <div className="metric-value">
            {loading ? '...' : connectedPage ? formatNum(overview?.reach || 0) : <span style={{ fontSize: '14px', color: '#9ca3af' }}>No account</span>}
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
              ? <span className="trend-up">Meta & Instagram API</span>
              : <span style={{ color: '#9ca3af' }}>Connect Facebook / IG to view</span>}
          </div>
        </div>

        {/* Total Published Posts */}
        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Total Published Posts</span>
            <div className="metric-icon"><Share2 size={20} /></div>
          </div>
          <div className="metric-value">
            {loading ? '...' : formatNum(overview?.total_posts || 0)}
          </div>
          <div className="metric-foot">
            <span className="trend-up">+{overview?.published_today || 0}</span> published today
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

      {/* Meta Facebook, Instagram & YouTube Real Insights Summary Panel */}
      {connectedChannels.length > 0 && (
        <div className="panel mb-18" style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: '12px', padding: '16px 20px', marginBottom: '24px' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '16px' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
              <div style={{
                width: '36px',
                height: '36px',
                background: platformIndex === 0
                  ? 'linear-gradient(135deg, #1877f2, #0d6efd)'
                  : platformIndex === 1
                    ? 'linear-gradient(135deg, #f58529, #dd2a7b, #8134af)'
                    : 'linear-gradient(135deg, #ff0000, #cc0000)',
                color: '#fff',
                borderRadius: '50%',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontWeight: 'bold',
                fontSize: '14px'
              }}>
                {platformIndex === 0 ? 'FB' : platformIndex === 1 ? 'IG' : 'YT'}
              </div>
              <div>
                <strong style={{ fontSize: '14px', color: '#0f172a' }}>
                  {platformIndex === 2
                    ? (overview?.youtube_connection?.channel_name || connectedPage?.page_name || 'Social Channels')
                    : (connectedPage?.page_name || 'Social Channels')} — Platform Insights Breakdown
                </strong>
                <span style={{ fontSize: '11px', color: '#64748b', display: 'block' }}>
                  {platformIndex === 0 && 'Real Meta Graph API v23.0 metrics'}
                  {platformIndex === 1 && 'Real Instagram Graph API metrics'}
                  {platformIndex === 2 && 'Real YouTube Data API v3 metrics'}
                </span>
              </div>
            </div>

            {/* Platform Specific Breakdown (One platform at a time: FB -> IG -> YT) */}
            <div style={{ display: 'flex', alignItems: 'center' }}>
              {/* Facebook Metrics Card */}
              {platformIndex === 0 && (
                <div style={{ background: '#eff6ff', border: '1px solid #bfdbfe', borderRadius: '8px', padding: '8px 14px', display: 'flex', gap: '14px', alignItems: 'center' }}>
                  <span style={{ fontWeight: '700', fontSize: '12px', color: '#1d4ed8' }}>FB</span>
                  <div style={{ textAlign: 'center', minWidth: '45px' }}>
                    <span
                      style={{ fontSize: '10px', color: '#64748b', fontWeight: '600', display: 'block', cursor: 'help' }}
                      title={
                        (overview?.facebook_metrics?.views_supported === false)
                          ? 'Views not available for this date range'
                          : 'Facebook Views: video plays for video content (/{page}/videos?fields=views), or Page profile views (page_views_total) for photo/text pages. Source: Meta Graph API v23.0. Not impressions.'
                      }
                    >VIEWS</span>
                    {overview?.facebook_metrics?.views_supported === false ? (
                      <span style={{ fontSize: '10px', color: '#94a3b8', fontStyle: 'italic' }} title="No views data for this date range">N/A</span>
                    ) : (
                      <strong style={{ fontSize: '14px', color: '#0369a1' }}>{loading ? '...' : formatNum(overview?.facebook_metrics?.views ?? 0)}</strong>
                    )}
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
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Main Grid */}
      <div className="grid-main-side mb-18">
        {/* Performance Trend Chart */}
        <div className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Performance trend</h3>
              <p>Reach and engagement for the selected period</p>
            </div>
          </div>

          <div style={{ height: '260px', position: 'relative' }}>
            {loading ? (
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%', color: '#9ca3af' }}>
                Loading trend data...
              </div>
            ) : hasChartData ? (
              <Line data={chartData} options={chartOptions} />
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', height: '100%', color: '#9ca3af', gap: 8 }}>
                <BarChart2 size={32} />
                <p style={{ margin: 0, fontSize: '14px' }}>
                  {connectedPage
                    ? 'No trend data available for this period'
                    : 'Connect a Facebook account to view performance trend'}
                </p>
                {!connectedPage && (
                  <button type="button" className="btn btn-primary" style={{ marginTop: 8 }} onClick={() => navigate('/integrations')}>
                    Connect Account
                  </button>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Connected Channels Panel */}
        <div className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Connected channels</h3>
              <p>Only channels enabled for this client</p>
            </div>
            <button className="btn-link" onClick={() => navigate('/integrations')}>Manage</button>
          </div>

          <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {loading ? (
              <div style={{ color: '#9ca3af', fontSize: '13px', padding: '12px 0' }}>Loading channels...</div>
            ) : connectedChannels.length === 0 ? (
              <div style={{ padding: '20px', textAlign: 'center', background: '#f9fafb', borderRadius: '10px' }}>
                <p style={{ fontSize: '13px', color: '#6b7280', marginBottom: '12px' }}>
                  Connect an account to view data
                </p>
                <button type="button" className="btn btn-primary" onClick={() => navigate('/integrations')}>
                  Connect Social Accounts
                </button>
              </div>
            ) : (
              connectedChannels.map((ch, i) => (
                <div key={i} className="channel-item">
                  <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <div className="channel-logo" style={{
                      background: ch.name === 'Facebook' ? '#1877f2' : ch.name === 'YouTube' ? '#ff0000' : '#7b92c9',
                      color: '#fff'
                    }}>
                      {ch.code}
                    </div>
                    <div>
                      <h5 style={{ margin: 0, fontSize: '14px' }}>{ch.name}</h5>
                      <span style={{ fontSize: '11px', color: ch.status === 'connected' ? '#059669' : '#ef4444', display: 'flex', alignItems: 'center', gap: 4 }}>
                        {ch.status === 'connected' ? <CheckCircle2 size={11} /> : <AlertCircle size={11} />}
                        {ch.status === 'connected' ? 'Connected' : ch.status}
                      </span>
                    </div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div style={{ fontWeight: '700', fontSize: '14px' }}>
                      {ch.followers > 0 ? formatNum(ch.followers) : '—'}
                    </div>
                    <span style={{ fontSize: '11px', color: '#6b7280' }}>
                      {ch.last_sync ? timeAgo(ch.last_sync) : 'No data'}
                    </span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>

      {/* Lead Funnel + Today's Priorities + Recent Activity */}
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
    </div>
  );
}
