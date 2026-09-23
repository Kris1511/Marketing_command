import React, { useState, useEffect, useCallback } from 'react';
import axiosInstance, { API_BASE_URL } from '../api/axiosInstance';
import {
  Users,
  UserPlus,
  Clock,
  Activity,
  RefreshCw,
  AlertCircle,
  ExternalLink,
  Compass,
  ArrowUpRight,
  BarChart3,
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

function formatNum(n) {
  if (n === null || n === undefined || n === '') return '—';
  const num = Number(n);
  if (isNaN(num)) return '—';
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
  return num.toLocaleString();
}

function formatDuration(seconds, formattedStr) {
  if (formattedStr) return formattedStr;
  if (seconds === null || seconds === undefined || isNaN(seconds)) return '0s';
  const sec = Math.floor(Number(seconds));
  if (sec <= 0) return '0s';
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (h > 0) return `${h}h ${m}m ${s}s`;
  if (m > 0) return `${m}m ${s}s`;
  return `${s}s`;
}

export default function GoogleAnalyticsDashboardSection({
  workspaceId,
  startDate,
  endDate,
  refreshTrigger,
}) {
  const [loading, setLoading] = useState(true);
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [refreshing, setRefreshing] = useState(false);

  const fetchMetrics = useCallback(
    async (isManual = false) => {
      if (!workspaceId) return;
      if (isManual) setRefreshing(true);
      else setLoading(true);
      setError('');

      try {
        const params = { workspace_id: workspaceId };
        if (startDate) params.start_date = startDate;
        if (endDate) params.end_date = endDate;

        const res = await axiosInstance.get('/google-analytics/metrics', { params });
        if (res.data?.success && res.data?.data) {
          setData(res.data.data);
        } else {
          setError(res.data?.message || 'Failed to fetch GA4 metrics.');
        }
      } catch (err) {
        setError(err.response?.data?.message || err.message || 'Error fetching Google Analytics data.');
      } finally {
        setLoading(false);
        setRefreshing(false);
      }
    },
    [workspaceId, startDate, endDate]
  );

  const handleConnect = () => {
    const wsId = workspaceId || 1;
    const width = 600;
    const height = 700;
    const left = window.screen.width / 2 - width / 2;
    const top = window.screen.height / 2 - height / 2;
    window.open(
      `${API_BASE_URL}/google-analytics/connect?workspace_id=${wsId}&reconnect=true&force=true`,
      'Connect Google Analytics',
      `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
    );
  };

  useEffect(() => {
    const handleOAuthResult = (event) => {
      if (event.data?.type === 'GOOGLE_ANALYTICS_OAUTH_RESULT') {
        if (event.data.success) {
          fetchMetrics(true);
        }
      }
    };
    window.addEventListener('message', handleOAuthResult);
    return () => window.removeEventListener('message', handleOAuthResult);
  }, [fetchMetrics]);

  useEffect(() => {
    fetchMetrics();
  }, [fetchMetrics, refreshTrigger]);

  const overview = data?.overview;
  const isAuthRequired = Boolean(data?.auth_required || overview?.auth_required);
  const sources = data?.sources || [];
  const trend = data?.trend;

  // Chart configuration
  const chartData = {
    labels: trend?.labels || [],
    datasets: [
      {
        label: 'Active Users',
        data: trend?.active_users || [],
        borderColor: '#f59e0b',
        backgroundColor: 'rgba(245, 158, 11, 0.1)',
        fill: true,
        tension: 0.35,
        borderWidth: 2,
        pointBackgroundColor: '#f59e0b',
        pointBorderColor: '#ffffff',
        pointBorderWidth: 1.5,
        pointRadius: (trend?.labels || []).length > 30 ? 0 : 3,
        pointHoverRadius: 5,
      },
      {
        label: 'Sessions',
        data: trend?.sessions || [],
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59, 130, 246, 0.05)',
        fill: true,
        tension: 0.35,
        borderWidth: 2,
        borderDash: [4, 4],
        pointBackgroundColor: '#3b82f6',
        pointBorderColor: '#ffffff',
        pointBorderWidth: 1.5,
        pointRadius: (trend?.labels || []).length > 30 ? 0 : 3,
        pointHoverRadius: 5,
      },
    ],
  };

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'top',
        align: 'end',
        labels: {
          boxWidth: 12,
          boxHeight: 12,
          usePointStyle: true,
          font: { size: 12, weight: '600' },
          color: '#475569',
        },
      },
      tooltip: {
        backgroundColor: '#1e293b',
        titleFont: { size: 12, weight: '700' },
        bodyFont: { size: 12 },
        padding: 10,
        cornerRadius: 8,
      },
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: { color: '#94a3b8', font: { size: 11 } },
      },
      y: {
        grid: { color: '#f1f5f9' },
        ticks: {
          color: '#94a3b8',
          font: { size: 11 },
          precision: 0,
        },
        beginAtZero: true,
      },
    },
  };

  const totalChannelSessions = sources.reduce((acc, s) => acc + (s.sessions || 0), 0) || 1;

  if (loading && !data) {
    return (
      <div style={{ padding: '40px', textAlign: 'center', background: '#fff', borderRadius: '12px', border: '1px solid #e2e8f0', marginBottom: '24px' }}>
        <RefreshCw size={24} className="spin" style={{ color: '#f59e0b', margin: '0 auto 12px' }} />
        <p style={{ color: '#64748b', fontSize: '13px', margin: 0 }}>Loading Google Analytics data...</p>
      </div>
    );
  }

  if (error && !data) {
    return (
      <div style={{ padding: '36px', textAlign: 'center', background: '#fff', borderRadius: '12px', border: '1px solid #fee2e2', marginBottom: '24px' }}>
        <AlertCircle size={32} color="#ef4444" style={{ margin: '0 auto 10px' }} />
        <h4 style={{ color: '#991b1b', margin: '0 0 6px', fontSize: '16px' }}>Unable to load Google Analytics data</h4>
        <p style={{ color: '#64748b', fontSize: '13px', margin: '0 0 16px' }}>{error}</p>
        <div style={{ display: 'flex', justifyContent: 'center', gap: '10px' }}>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={handleConnect}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', background: '#d97706', borderColor: '#d97706' }}
          >
            <ExternalLink size={13} /> Reconnect GA4
          </button>
          <button className="btn btn-secondary btn-sm" onClick={() => fetchMetrics(true)}>
            <RefreshCw size={13} style={{ marginRight: '6px' }} /> Try Again
          </button>
        </div>
      </div>
    );
  }

  return (
    <div style={{ marginBottom: '24px' }}>
      {/* 1. Header Banner */}
      <div
        style={{
          background: 'linear-gradient(135deg, #ffffff 0%, #fffbeb 100%)',
          border: '1px solid #fef3c7',
          borderRadius: '12px',
          padding: '16px 20px',
          marginBottom: '18px',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          flexWrap: 'wrap',
          gap: '14px',
          boxShadow: '0 1px 3px rgba(245, 158, 11, 0.06)',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <div
            style={{
              width: '40px',
              height: '40px',
              borderRadius: '10px',
              background: 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
              color: '#fff',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontWeight: '800',
              fontSize: '15px',
              boxShadow: '0 2px 6px rgba(245, 158, 11, 0.35)',
            }}
          >
            GA
          </div>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <h4 style={{ margin: 0, fontSize: '15px', color: '#1e293b', fontWeight: '700' }}>
                Google Analytics 4 (GA4)
              </h4>
              <span
                style={{
                  background: '#fef3c7',
                  color: '#92400e',
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '2px 8px',
                  borderRadius: '12px',
                  border: '1px solid #fde68a',
                }}
              >
                Property: {overview?.property_id || 'Active'}
              </span>
            </div>
            <p style={{ margin: '2px 0 0', fontSize: '12px', color: '#64748b' }}>
              Audience acquisition, session engagement, and content performance metrics
            </p>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
          {isAuthRequired ? (
            <div
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '6px',
                background: '#fef2f2',
                border: '1px solid #fecaca',
                padding: '4px 10px',
                borderRadius: '16px',
                fontSize: '11.5px',
                fontWeight: '600',
                color: '#b91c1c',
              }}
            >
              <span
                style={{
                  width: '7px',
                  height: '7px',
                  borderRadius: '50%',
                  background: '#ef4444',
                  boxShadow: '0 0 6px #ef4444',
                }}
              />
              Session Expired
            </div>
          ) : (
            <div
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '6px',
                background: '#ecfdf5',
                border: '1px solid #a7f3d0',
                padding: '4px 10px',
                borderRadius: '16px',
                fontSize: '11.5px',
                fontWeight: '600',
                color: '#047857',
              }}
            >
              <span
                style={{
                  width: '7px',
                  height: '7px',
                  borderRadius: '50%',
                  background: '#10b981',
                  boxShadow: '0 0 6px #10b981',
                }}
              />
              Live Data
            </div>
          )}

          {isAuthRequired && (
            <button
              type="button"
              className="btn btn-primary btn-sm"
              onClick={handleConnect}
              style={{ display: 'inline-flex', alignItems: 'center', gap: '5px', background: '#d97706', borderColor: '#d97706' }}
            >
              <ExternalLink size={13} />
              Reconnect GA4
            </button>
          )}

          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => fetchMetrics(true)}
            disabled={refreshing}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '5px' }}
          >
            <RefreshCw size={13} className={refreshing ? 'spin' : ''} />
            {refreshing ? 'Refreshing...' : 'Refresh'}
          </button>
        </div>
      </div>

      {/* Auth Required Alert Banner */}
      {isAuthRequired && (
        <div
          style={{
            background: '#fffbeb',
            border: '1px solid #fde68a',
            borderRadius: '10px',
            padding: '12px 16px',
            marginBottom: '18px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            flexWrap: 'wrap',
            gap: '12px',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <AlertCircle size={20} color="#d97706" />
            <div>
              <p style={{ margin: 0, fontSize: '13px', fontWeight: '600', color: '#92400e' }}>
                Google Analytics session expired
              </p>
              <p style={{ margin: 0, fontSize: '12px', color: '#b45309' }}>
                Google OAuth tokens expire periodically in test mode. Reconnect your account to display live GA4 metrics.
              </p>
            </div>
          </div>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={handleConnect}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', background: '#d97706', borderColor: '#d97706', padding: '6px 14px' }}
          >
            <ExternalLink size={14} /> Reconnect Google Analytics
          </button>
        </div>
      )}

      {/* 2. Core KPI Cards Grid (GA4 Reports Snapshot: Active Users, New Users, Average Engagement Time, Sessions) */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
          gap: '14px',
          marginBottom: '20px',
        }}
      >
        {/* Card 1: Active Users */}
        <div
          style={{
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            borderRadius: '12px',
            padding: '18px 16px',
            boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
            <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Active Users</span>
            <div style={{ width: '32px', height: '32px', borderRadius: '8px', background: '#eff6ff', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#2563eb' }}>
              <Users size={16} />
            </div>
          </div>
          <div style={{ fontSize: '26px', fontWeight: '800', color: '#0f172a' }}>
            {formatNum(overview?.active_users)}
          </div>
          <div style={{ fontSize: '11.5px', color: '#10b981', marginTop: '4px', fontWeight: '500' }}>
            Unique engaged visitors
          </div>
        </div>

        {/* Card 2: New Users */}
        <div
          style={{
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            borderRadius: '12px',
            padding: '18px 16px',
            boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
            <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>New Users</span>
            <div style={{ width: '32px', height: '32px', borderRadius: '8px', background: '#ecfdf5', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#059669' }}>
              <UserPlus size={16} />
            </div>
          </div>
          <div style={{ fontSize: '26px', fontWeight: '800', color: '#0f172a' }}>
            {formatNum(overview?.new_users)}
          </div>
          <div style={{ fontSize: '11.5px', color: '#64748b', marginTop: '4px' }}>
            First-time visitors
          </div>
        </div>

        {/* Card 3: Average Engagement Time */}
        <div
          style={{
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            borderRadius: '12px',
            padding: '18px 16px',
            boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
            <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Average Engagement Time</span>
            <div style={{ width: '32px', height: '32px', borderRadius: '8px', background: '#f5f3ff', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#7c3aed' }}>
              <Clock size={16} />
            </div>
          </div>
          <div style={{ fontSize: '26px', fontWeight: '800', color: '#0f172a' }}>
            {formatDuration(overview?.average_engagement_time, overview?.average_engagement_time_formatted)}
          </div>
          <div style={{ fontSize: '11.5px', color: '#64748b', marginTop: '4px' }}>
            Per active user
          </div>
        </div>

        {/* Card 4: Sessions */}
        <div
          style={{
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            borderRadius: '12px',
            padding: '18px 16px',
            boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
            <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Sessions</span>
            <div style={{ width: '32px', height: '32px', borderRadius: '8px', background: '#fffbeb', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#d97706' }}>
              <Activity size={16} />
            </div>
          </div>
          <div style={{ fontSize: '26px', fontWeight: '800', color: '#0f172a' }}>
            {formatNum(overview?.sessions)}
          </div>
          <div style={{ fontSize: '11.5px', color: '#64748b', marginTop: '4px' }}>
            Total browse interactions
          </div>
        </div>
      </div>

      {/* 3. Charts & Breakdown Section (2-Column Grid with optimized proportions) */}
      <style>{`
        .ga-trend-acquisition-grid {
          display: grid;
          grid-template-columns: minmax(0, 0.88fr) minmax(0, 1.22fr);
          gap: 20px;
          align-items: stretch;
        }
        @media (max-width: 1024px) {
          .ga-trend-acquisition-grid {
            grid-template-columns: 1fr;
          }
        }
      `}</style>
      <div className="ga-trend-acquisition-grid">
        {/* Left: Daily Traffic Trend Chart */}
        <div
          style={{
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            borderRadius: '12px',
            padding: '20px',
            boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
            display: 'flex',
            flexDirection: 'column',
            minWidth: 0,
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' }}>
            <div>
              <h4 style={{ margin: 0, fontSize: '15px', color: '#0f172a', fontWeight: '700' }}>
                Daily Traffic Trend
              </h4>
              <span style={{ fontSize: '12px', color: '#64748b' }}>
                Active users and total sessions over selected period
              </span>
            </div>
            <BarChart3 size={18} color="#94a3b8" />
          </div>

          <div style={{ flex: 1, minHeight: '300px', position: 'relative', width: '100%' }}>
            {trend?.labels && trend.labels.length > 0 ? (
              <Line data={chartData} options={chartOptions} />
            ) : (
              <div style={{ height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#94a3b8', fontSize: '13px' }}>
                No trend data available for this date range.
              </div>
            )}
          </div>
        </div>

        {/* Right: Top Traffic Acquisition Table */}
        <div
          style={{
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            borderRadius: '12px',
            padding: '20px',
            boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
            display: 'flex',
            flexDirection: 'column',
            minWidth: 0,
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' }}>
            <div>
              <h4 style={{ margin: 0, fontSize: '15px', color: '#0f172a', fontWeight: '700' }}>
                Top Traffic Acquisition
              </h4>
              <span style={{ fontSize: '12px', color: '#64748b' }}>
                Session volume, key events, and revenue by source / medium
              </span>
            </div>
            <Compass size={18} color="#94a3b8" />
          </div>

          <div
            style={{
              flex: 1,
              overflowY: 'auto',
              overflowX: 'hidden',
              maxHeight: '300px',
              minHeight: '220px',
              paddingRight: '2px',
            }}
          >
            <table
              style={{
                width: '100%',
                minWidth: '0',
                tableLayout: 'fixed',
                borderCollapse: 'collapse',
                fontSize: '12px',
                textAlign: 'left',
              }}
            >
              <thead>
                <tr style={{ borderBottom: '2px solid #f1f5f9', background: '#f8fafc', position: 'sticky', top: 0, zIndex: 2 }}>
                  <th
                    style={{
                      width: '40%',
                      padding: '9px 10px',
                      color: '#475569',
                      fontWeight: '700',
                      background: '#f8fafc',
                      borderRadius: '6px 0 0 6px',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    Session Source / Medium
                  </th>
                  <th
                    style={{
                      width: '18%',
                      padding: '9px 8px',
                      color: '#475569',
                      fontWeight: '700',
                      background: '#f8fafc',
                      textAlign: 'right',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    Sessions
                  </th>
                  <th
                    style={{
                      width: '18%',
                      padding: '9px 8px',
                      color: '#475569',
                      fontWeight: '700',
                      background: '#f8fafc',
                      textAlign: 'right',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    Key Events
                  </th>
                  <th
                    style={{
                      width: '24%',
                      padding: '9px 14px 9px 8px',
                      color: '#475569',
                      fontWeight: '700',
                      background: '#f8fafc',
                      textAlign: 'right',
                      borderRadius: '0 6px 6px 0',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    Total Revenue
                  </th>
                </tr>
              </thead>
              <tbody>
                {sources.length > 0 ? (
                  sources.map((src, i) => (
                    <tr
                      key={src.source_medium || src.channel || i}
                      style={{
                        borderBottom: '1px solid #f1f5f9',
                        transition: 'background 0.15s ease',
                      }}
                      onMouseEnter={(e) => (e.currentTarget.style.background = '#f8fafc')}
                      onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                    >
                      <td style={{ padding: '8px 10px', color: '#1e293b', fontWeight: '600' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '6px', minWidth: 0 }}>
                          <span style={{ fontSize: '11px', color: '#94a3b8', width: '18px', flexShrink: 0 }}>
                            {i + 1}.
                          </span>
                          <span
                            title={src.source_medium || src.channel || '(not set)'}
                            style={{
                              overflow: 'hidden',
                              textOverflow: 'ellipsis',
                              whiteSpace: 'nowrap',
                              display: 'block',
                            }}
                          >
                            {src.source_medium || src.channel || '(not set)'}
                          </span>
                        </div>
                      </td>
                      <td style={{ padding: '8px 8px', textAlign: 'right', fontWeight: '700', color: '#0f172a', whiteSpace: 'nowrap' }}>
                        {formatNum(src.sessions)}
                      </td>
                      <td style={{ padding: '8px 8px', textAlign: 'right', color: '#64748b', whiteSpace: 'nowrap' }}>
                        {formatNum(src.key_events ?? 0)}
                      </td>
                      <td
                        style={{
                          padding: '8px 14px 8px 8px',
                          textAlign: 'right',
                          color: '#0f172a',
                          fontWeight: '600',
                          whiteSpace: 'nowrap',
                        }}
                      >
                        {src.revenue_formatted || (src.total_revenue ? `₹${Number(src.total_revenue).toFixed(2)}` : '₹0.00')}
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={4} style={{ padding: '36px 12px', textAlign: 'center', color: '#94a3b8' }}>
                      No acquisition data available for this period.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          {sources.length > 0 && (
            <div style={{ marginTop: '10px', fontSize: '11.5px', color: '#94a3b8', textAlign: 'right' }}>
              Showing {sources.length} traffic acquisition sources
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
