import React, { useState, useEffect, useCallback } from 'react';
import axiosInstance, { API_BASE_URL } from '../api/axiosInstance';
import {
  Search,
  MousePointer,
  Eye,
  Percent,
  Hash,
  RefreshCw,
  AlertCircle,
  TrendingUp,
  Globe,
  ExternalLink
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

// Number formatter
function formatNum(n) {
  if (n === null || n === undefined) return '0';
  const num = Number(n);
  if (isNaN(num)) return '0';
  return num.toLocaleString();
}

export default function GoogleSearchConsoleDashboardSection({
  workspaceId,
  startDate,
  endDate,
  refreshTrigger,
}) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [refreshing, setRefreshing] = useState(false);

  const fetchMetrics = useCallback(async (isManualRefresh = false) => {
    if (!workspaceId) return;

    if (isManualRefresh) setRefreshing(true);
    else setLoading(true);

    setError(null);

    try {
      const params = { workspace_id: workspaceId };
      if (startDate) params.start_date = startDate;
      if (endDate) params.end_date = endDate;

      const res = await axiosInstance.get('/search-console/metrics', { params });

      if (res.data?.success && res.data?.data) {
        setData(res.data.data);
      } else {
        setError(res.data?.message || 'Failed to load Search Console metrics.');
      }
    } catch (err) {
      console.error('[GSC Dashboard Error]', err);
      setError(
        err.response?.data?.message ||
        'Unable to communicate with Google Search Console API. Ensure your property is verified.'
      );
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [workspaceId, startDate, endDate]);

  const handleConnect = () => {
    const wsId = workspaceId || 1;
    const width = 600;
    const height = 700;
    const left = window.screen.width / 2 - width / 2;
    const top = window.screen.height / 2 - height / 2;
    window.open(
      `${API_BASE_URL}/search-console/connect?workspace_id=${wsId}&reconnect=true&force=true`,
      'Connect Google Search Console',
      `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
    );
  };

  useEffect(() => {
    const handleOAuthResult = (event) => {
      if (event.data?.type === 'SEARCH_CONSOLE_OAUTH_RESULT') {
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

  const overview = data?.overview || {};
  const isAuthRequired = Boolean(data?.auth_required || overview?.auth_required);
  const queries = data?.queries || [];
  const trend = data?.trend || {};

  // Build Trend Chart Data
  const chartData = {
    labels: trend?.labels || [],
    datasets: [
      {
        label: 'Clicks',
        data: trend?.clicks || [],
        borderColor: '#0284c7',
        backgroundColor: 'rgba(2, 132, 199, 0.12)',
        borderWidth: 2.5,
        fill: true,
        tension: 0.35,
        pointRadius: (trend?.labels?.length || 0) > 30 ? 0 : 3,
        pointBackgroundColor: '#0284c7',
        pointHoverRadius: 6,
        yAxisID: 'y',
      },
      {
        label: 'Impressions',
        data: trend?.impressions || [],
        borderColor: '#818cf8',
        backgroundColor: 'rgba(129, 140, 248, 0.05)',
        borderWidth: 2,
        borderDash: [4, 4],
        fill: false,
        tension: 0.35,
        pointRadius: 0,
        pointHoverRadius: 5,
        yAxisID: 'y1',
      },
    ],
  };

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
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
          font: { size: 12, weight: '600' },
          color: '#475569',
        },
      },
      tooltip: {
        backgroundColor: '#0f172a',
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
        type: 'linear',
        display: true,
        position: 'left',
        grid: { color: '#f1f5f9' },
        ticks: { color: '#0284c7', font: { size: 11 }, precision: 0 },
        beginAtZero: true,
        title: { display: true, text: 'Clicks', color: '#0284c7', font: { size: 11, weight: '600' } },
      },
      y1: {
        type: 'linear',
        display: true,
        position: 'right',
        grid: { drawOnChartArea: false },
        ticks: { color: '#818cf8', font: { size: 11 }, precision: 0 },
        beginAtZero: true,
        title: { display: true, text: 'Impressions', color: '#818cf8', font: { size: 11, weight: '600' } },
      },
    },
  };

  if (loading && !data) {
    return (
      <div style={{ padding: '40px', textAlign: 'center', background: '#fff', borderRadius: '12px', border: '1px solid #e2e8f0', marginBottom: '24px' }}>
        <RefreshCw size={24} className="spin" style={{ color: '#0284c7', margin: '0 auto 12px' }} />
        <p style={{ color: '#64748b', fontSize: '13px', margin: 0 }}>Loading Google Search Console data...</p>
      </div>
    );
  }

  if (error && !data) {
    return (
      <div style={{ padding: '36px', textAlign: 'center', background: '#fff', borderRadius: '12px', border: '1px solid #fee2e2', marginBottom: '24px' }}>
        <AlertCircle size={32} color="#ef4444" style={{ margin: '0 auto 10px' }} />
        <h4 style={{ color: '#991b1b', margin: '0 0 6px', fontSize: '16px' }}>Unable to load Google Search Console data</h4>
        <p style={{ color: '#64748b', fontSize: '13px', margin: '0 0 16px' }}>{error}</p>
        <div style={{ display: 'flex', justifyContent: 'center', gap: '10px' }}>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={handleConnect}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', background: '#0284c7', borderColor: '#0284c7' }}
          >
            <ExternalLink size={13} /> Reconnect Search Console
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
          background: 'linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%)',
          border: '1px solid #e0f2fe',
          borderRadius: '12px',
          padding: '16px 20px',
          marginBottom: '18px',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          flexWrap: 'wrap',
          gap: '14px',
          boxShadow: '0 1px 3px rgba(2, 132, 199, 0.06)',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <div
            style={{
              width: '40px',
              height: '40px',
              borderRadius: '10px',
              background: 'linear-gradient(135deg, #0284c7 0%, #0369a1 100%)',
              color: '#fff',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontWeight: '800',
              fontSize: '15px',
              boxShadow: '0 2px 6px rgba(2, 132, 199, 0.35)',
            }}
          >
            SC
          </div>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <h4 style={{ margin: 0, fontSize: '15px', color: '#1e293b', fontWeight: '700' }}>
                Google Search Console
              </h4>
              <span
                style={{
                  background: '#e0f2fe',
                  color: '#0369a1',
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '2px 8px',
                  borderRadius: '12px',
                  border: '1px solid #bae6fd',
                }}
              >
                Site: {overview?.site_url || 'Active Property'}
              </span>
            </div>
            <p style={{ margin: '2px 0 0', fontSize: '12px', color: '#64748b' }}>
              Organic search performance, keyword impressions, CTR, and search rankings
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
              style={{ display: 'inline-flex', alignItems: 'center', gap: '5px', background: '#0284c7', borderColor: '#0284c7' }}
            >
              <ExternalLink size={13} />
              Reconnect Search Console
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
            <AlertCircle size={20} color="#0284c7" />
            <div>
              <p style={{ margin: 0, fontSize: '13px', fontWeight: '600', color: '#0369a1' }}>
                Google Search Console session expired
              </p>
              <p style={{ margin: 0, fontSize: '12px', color: '#075985' }}>
                Google OAuth tokens expire periodically in test mode. Reconnect your account to display live search console metrics.
              </p>
            </div>
          </div>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={handleConnect}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', background: '#0284c7', borderColor: '#0284c7', padding: '6px 14px' }}
          >
            <ExternalLink size={14} /> Reconnect Search Console
          </button>
        </div>
      )}

      {/* 2. Core KPI Cards Grid */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
          gap: '14px',
          marginBottom: '20px',
        }}
      >
        {/* Card 1: Total Clicks */}
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
            <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Total Clicks</span>
            <div style={{ width: '32px', height: '32px', borderRadius: '8px', background: '#e0f2fe', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#0284c7' }}>
              <MousePointer size={16} />
            </div>
          </div>
          <div style={{ fontSize: '26px', fontWeight: '800', color: '#0f172a' }}>
            {formatNum(overview?.clicks)}
          </div>
          <div style={{ fontSize: '11.5px', color: '#0284c7', marginTop: '4px', fontWeight: '500' }}>
            Search visitors referred
          </div>
        </div>

        {/* Card 2: Total Impressions */}
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
            <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Total Impressions</span>
            <div style={{ width: '32px', height: '32px', borderRadius: '8px', background: '#eef2ff', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#6366f1' }}>
              <Eye size={16} />
            </div>
          </div>
          <div style={{ fontSize: '26px', fontWeight: '800', color: '#0f172a' }}>
            {formatNum(overview?.impressions)}
          </div>
          <div style={{ fontSize: '11.5px', color: '#64748b', marginTop: '4px' }}>
            Times shown in Google Search
          </div>
        </div>

        {/* Card 3: Average CTR */}
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
            <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Average CTR</span>
            <div style={{ width: '32px', height: '32px', borderRadius: '8px', background: '#ecfdf5', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#059669' }}>
              <Percent size={16} />
            </div>
          </div>
          <div style={{ fontSize: '26px', fontWeight: '800', color: '#0f172a' }}>
            {overview?.ctr !== undefined ? `${overview.ctr}%` : '0%'}
          </div>
          <div style={{ fontSize: '11.5px', color: '#10b981', marginTop: '4px', fontWeight: '500' }}>
            Click-through rate
          </div>
        </div>

        {/* Card 4: Average Position */}
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
            <span style={{ fontSize: '12px', fontWeight: '600', color: '#64748b' }}>Average Position</span>
            <div style={{ width: '32px', height: '32px', borderRadius: '8px', background: '#fffbeb', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#d97706' }}>
              <Hash size={16} />
            </div>
          </div>
          <div style={{ fontSize: '26px', fontWeight: '800', color: '#0f172a' }}>
            {overview?.position !== undefined && overview.position > 0 ? overview.position : '—'}
          </div>
          <div style={{ fontSize: '11.5px', color: '#64748b', marginTop: '4px' }}>
            Mean search rank on Google
          </div>
        </div>
      </div>

      {/* 3. Charts & Breakdown Section (2-Column Grid with optimized proportions) */}
      <style>{`
        .gsc-trend-queries-grid {
          display: grid;
          grid-template-columns: minmax(0, 0.88fr) minmax(0, 1.22fr);
          gap: 20px;
          align-items: stretch;
        }
        @media (max-width: 1024px) {
          .gsc-trend-queries-grid {
            grid-template-columns: 1fr;
          }
        }
      `}</style>
      <div className="gsc-trend-queries-grid">
        {/* Left: Daily Search Performance Trend Chart */}
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
                Daily Search Performance
              </h4>
              <span style={{ fontSize: '12px', color: '#64748b' }}>
                Clicks and search impressions over selected period
              </span>
            </div>
            <TrendingUp size={18} color="#94a3b8" />
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

        {/* Right: Top Search Queries Table */}
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
                Top Search Queries
              </h4>
              <span style={{ fontSize: '12px', color: '#64748b' }}>
                Keywords and queries bringing searchers to your site
              </span>
            </div>
            <Search size={18} color="#94a3b8" />
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
                      width: '42%',
                      padding: '9px 10px',
                      color: '#475569',
                      fontWeight: '700',
                      background: '#f8fafc',
                      borderRadius: '6px 0 0 6px',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    Top Query / Keyword
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
                    Clicks
                  </th>
                  <th
                    style={{
                      width: '20%',
                      padding: '9px 8px',
                      color: '#475569',
                      fontWeight: '700',
                      background: '#f8fafc',
                      textAlign: 'right',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    Impressions
                  </th>
                  <th
                    style={{
                      width: '20%',
                      padding: '9px 14px 9px 8px',
                      color: '#475569',
                      fontWeight: '700',
                      background: '#f8fafc',
                      textAlign: 'right',
                      borderRadius: '0 6px 6px 0',
                      whiteSpace: 'nowrap',
                    }}
                  >
                    Position
                  </th>
                </tr>
              </thead>
              <tbody>
                {queries.length > 0 ? (
                  queries.map((q, i) => (
                    <tr
                      key={q.query || i}
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
                            title={q.query}
                            style={{
                              overflow: 'hidden',
                              textOverflow: 'ellipsis',
                              whiteSpace: 'nowrap',
                              display: 'block',
                            }}
                          >
                            {q.query}
                          </span>
                        </div>
                      </td>
                      <td style={{ padding: '8px 8px', textAlign: 'right', fontWeight: '700', color: '#0284c7', whiteSpace: 'nowrap' }}>
                        {formatNum(q.clicks)}
                      </td>
                      <td style={{ padding: '8px 8px', textAlign: 'right', color: '#475569', whiteSpace: 'nowrap' }}>
                        {formatNum(q.impressions)}
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
                        <span
                          style={{
                            background: q.position <= 3 ? '#dcfce7' : q.position <= 10 ? '#e0f2fe' : '#f1f5f9',
                            color: q.position <= 3 ? '#15803d' : q.position <= 10 ? '#0369a1' : '#475569',
                            padding: '2px 6px',
                            borderRadius: '4px',
                            fontSize: '11.5px',
                            fontWeight: '700',
                          }}
                        >
                          #{q.position}
                        </span>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={4} style={{ padding: '36px 12px', textAlign: 'center', color: '#94a3b8' }}>
                      No search query data available for this period.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          {queries.length > 0 && (
            <div style={{ marginTop: '10px', fontSize: '11.5px', color: '#94a3b8', textAlign: 'right' }}>
              Showing {queries.length} search queries
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
