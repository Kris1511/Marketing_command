import React, { useEffect, useState } from 'react';
import axiosInstance from '../api/axiosInstance';
import { useNavigate } from 'react-router-dom';
import { useWorkspace } from '../context/WorkspaceContext';
import YouTubeAnalyticsSection from '../components/YouTubeAnalyticsSection';
import {
  Eye,
  Zap,
  Globe,
  Plus,
  RefreshCw,
  Share2,
  FileText,
  Clock,
  CheckCircle2,
  AlertCircle
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
} from 'chart.js';
import { Line } from 'react-chartjs-2';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend
);

export default function DashboardPage() {
  const navigate = useNavigate();
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspace();
  const [metrics, setMetrics] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    axiosInstance
      .get('/dashboard/metrics', {
        params: { workspace_id: selectedWorkspaceId },
      })
      .then((res) => {
        if (res.data.success) {
          setMetrics(res.data.data);
        }
      })
      .catch((err) => console.error('Error loading dashboard metrics:', err))
      .finally(() => setLoading(false));
  }, [selectedWorkspaceId]);

  const clientName = selectedWorkspace?.name || metrics?.workspace_name || 'Redmind Technologies';
  const connectedPage = metrics?.connected_page;
  const recentPosts = metrics?.recent_posts || [];

  const chartData = {
    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    datasets: [
      {
        label: 'Reach',
        data: [14200, 19500, 15800, 22400, 28100, 24500, 31200],
        borderColor: '#2457e6',
        backgroundColor: '#2457e6',
        tension: 0.35,
      },
      {
        label: 'Engagement',
        data: [1200, 1850, 1400, 2100, 2600, 2200, 2900],
        borderColor: '#7b92c9',
        backgroundColor: '#7b92c9',
        tension: 0.35,
      },
    ],
  };

  const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
    },
  };

  return (
    <div>
      {/* Welcome Banner */}
      <div className="welcome-panel">
        <div className="welcome-copy">
          <h2>{clientName} Operations Dashboard</h2>
          <p>
            Connected Meta Facebook Pages, publishing history, and performance metrics for <strong>{clientName}</strong>.
          </p>
        </div>
        <div className="welcome-actions">
          <button type="button" className="btn btn-outline-white" onClick={() => navigate('/reports')}>
            View Analytics Report
          </button>
          <button type="button" className="btn btn-white" onClick={() => navigate('/publishing')} style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
            <Plus size={16} /> Create Content
          </button>
        </div>
      </div>

      {/* Metrics Row */}
      <div className="metric-grid" style={{ opacity: loading ? 0.6 : 1, transition: 'opacity 0.2s', marginBottom: '24px' }}>
        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Total Posts</span>
            <div className="metric-icon"><FileText size={20} /></div>
          </div>
          <div className="metric-value">{metrics?.total_posts ?? 0}</div>
          <div className="metric-foot">
            <span className="trend-up">+{metrics?.posts_this_month ?? 0}</span> this month
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Published Today</span>
            <div className="metric-icon"><CheckCircle2 size={20} color="#16a34a" /></div>
          </div>
          <div className="metric-value">{metrics?.published_today ?? 0}</div>
          <div className="metric-foot">
            <span>Real-time status</span>
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Scheduled Posts</span>
            <div className="metric-icon"><Clock size={20} color="#d97706" /></div>
          </div>
          <div className="metric-value">{metrics?.scheduled_count ?? 0}</div>
          <div className="metric-foot">
            <span>Pending queue</span>
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Facebook Followers</span>
            <div className="metric-icon"><Share2 size={20} color="#1877f2" /></div>
          </div>
          <div className="metric-value">{metrics?.followers_count ? metrics.followers_count.toLocaleString() : (connectedPage ? 'Active' : '0')}</div>
          <div className="metric-foot">
            <span className="trend-up">Meta Graph API</span>
          </div>
        </div>
      </div>

      {/* YouTube Analytics Overview Section (Views, Likes, Comments, Shares) */}
      <YouTubeAnalyticsSection />

      {/* Main Grid Section */}
      <div className="grid-main-side mb-18">
        {/* Performance Trend Chart */}
        <div className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Performance Trend</h3>
              <p>Reach and engagement for {clientName}</p>
            </div>
            <select className="input" style={{ width: 'auto', padding: '6px 12px', fontSize: '13px' }}>
              <option>Last 30 days</option>
              <option>Last 7 days</option>
            </select>
          </div>

          <div style={{ height: '260px', position: 'relative' }}>
            <Line data={chartData} options={chartOptions} />
          </div>
        </div>

        {/* Connected Channels Panel */}
        <div className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Connected Channels</h3>
              <p>Active Meta Pages & YouTube Channels</p>
            </div>
            <button className="btn-link" onClick={() => navigate('/integrations')}>Manage</button>
          </div>

          <div className="channel-list" style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
            {metrics?.youtube_connection && (
              <div className="channel-item" style={{ border: '1px solid #fee2e2', background: '#fef2f2', padding: '12px', borderRadius: '10px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                  {metrics.youtube_connection.channel_thumbnail ? (
                    <img
                      src={metrics.youtube_connection.channel_thumbnail}
                      alt={metrics.youtube_connection.channel_name}
                      style={{ width: '40px', height: '40px', borderRadius: '50%', objectFit: 'cover' }}
                    />
                  ) : (
                    <div className="channel-logo" style={{ background: '#ff0000', color: '#fff' }}>YT</div>
                  )}
                  <div>
                    <h5 style={{ margin: 0, fontSize: '14.5px' }}>{metrics.youtube_connection.channel_name}</h5>
                    <span style={{ fontSize: '12px', color: '#059669', display: 'flex', alignItems: 'center', gap: '4px' }}>
                      <CheckCircle2 size={13} /> YouTube Data API v3
                    </span>
                  </div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div style={{ fontWeight: '700', fontSize: '14px', color: '#991b1b' }}>
                    {metrics.youtube_connection.subscriber_count ? metrics.youtube_connection.subscriber_count.toLocaleString() : 0}
                  </div>
                  <span style={{ fontSize: '11px', color: '#6b7280' }}>Subscribers</span>
                </div>
              </div>
            )}

            {connectedPage ? (
              <div className="channel-item">
                <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                  {connectedPage.profile_picture_url ? (
                    <img
                      src={connectedPage.profile_picture_url}
                      alt={connectedPage.page_name}
                      style={{ width: '40px', height: '40px', borderRadius: '50%', objectFit: 'cover' }}
                    />
                  ) : (
                    <div className="channel-logo" style={{ background: '#1877f2', color: '#fff' }}>f</div>
                  )}
                  <div>
                    <h5 style={{ margin: 0, fontSize: '14.5px' }}>{connectedPage.page_name}</h5>
                    <span style={{ fontSize: '12px', color: '#059669', display: 'flex', alignItems: 'center', gap: '4px' }}>
                      <CheckCircle2 size={13} /> Token Active • ID: {connectedPage.page_id}
                    </span>
                  </div>
                </div>
                <div style={{ textAlign: 'right' }}>
                  <div style={{ fontWeight: '700', fontSize: '14px' }}>
                    {connectedPage.followers_count ? connectedPage.followers_count.toLocaleString() : 'Connected'}
                  </div>
                  <span style={{ fontSize: '11px', color: '#6b7280' }}>Followers</span>
                </div>
              </div>
            ) : !metrics?.youtube_connection && (
              <div style={{ padding: '20px', textAlign: 'center', background: '#f9fafb', borderRadius: '10px' }}>
                <p style={{ fontSize: '13px', color: '#6b7280', marginBottom: '12px' }}>No social channels connected for this workspace.</p>
                <button type="button" className="btn btn-primary" onClick={() => navigate('/integrations')}>
                  Connect Social Accounts
                </button>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Recent Posts Feed */}
      <div className="panel">
        <div className="panel-header">
          <div className="panel-title">
            <h3>Recent Publishing History</h3>
            <p>Latest Facebook posts created in this workspace</p>
          </div>
          <button className="btn-link" onClick={() => navigate('/publishing')}>View All Posts</button>
        </div>

        {recentPosts.length === 0 ? (
          <div style={{ padding: '24px', textAlign: 'center', color: '#6b7280' }}>
            No recent posts found for this workspace. Click "Create Content" above to publish your first post!
          </div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '12px', marginTop: '12px' }}>
            {recentPosts.map((post) => (
              <div key={post.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 16px', background: '#f9fafb', borderRadius: '8px', border: '1px solid #e5e7eb' }}>
                <div>
                  <strong style={{ fontSize: '14px', display: 'block', color: '#111827' }}>
                    {post.content ? (post.content.length > 70 ? post.content.substring(0, 70) + '...' : post.content) : 'Untitled Post'}
                  </strong>
                  <span style={{ fontSize: '11.5px', color: '#6b7280' }}>
                    Type: {post.post_type} • Created: {new Date(post.created_at).toLocaleString()}
                  </span>
                </div>
                <div>
                  <span className={`pill ${post.status === 'published' ? 'success' : post.status === 'scheduled' ? 'warning' : 'neutral'}`}>
                    {post.status.toUpperCase()}
                  </span>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
