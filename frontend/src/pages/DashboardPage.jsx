import React, { useEffect, useState } from 'react';
import axiosInstance from '../api/axiosInstance';
import { useNavigate } from 'react-router-dom';
import { useWorkspace } from '../context/WorkspaceContext';
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
      .catch((err) => console.error('Error loading metrics:', err))
      .finally(() => setLoading(false));
  }, [selectedWorkspaceId]);

  const chartData = {
    labels: metrics?.trend_data?.labels || ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    datasets: [
      {
        label: 'Reach',
        data: metrics?.trend_data?.reach || [12000, 19000, 15000, 22000, 28000, 24000, 31000],
        borderColor: '#2457e6',
        backgroundColor: '#2457e6',
        tension: 0.35,
      },
      {
        label: 'Engagement',
        data: metrics?.trend_data?.engagement || [800, 1400, 1100, 1800, 2200, 1900, 2600],
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

  const defaultChannels = [
    { name: 'Facebook Page', meta: 'Connected • 1.2k Followers', value: '45.2k', change: '+12%', logo: 'FB' },
    { name: 'Instagram Profile', meta: 'Connected • 8.4k Followers', value: '89.1k', change: '+24%', logo: 'IG' },
    { name: 'Google Analytics 4', meta: 'Active Stream', value: '12.4k', change: '+8%', logo: 'GA' },
  ];

  const activeChannels = metrics?.channels || defaultChannels;

  return (
    <div>
      <div className="welcome-panel">
        <div className="welcome-copy">
          <h2>
            {metrics?.workspace_name ? `${metrics.workspace_name} Overview` : 'Welcome to Marketing Command'}
          </h2>
          <p>
            {selectedWorkspace
              ? `Currently viewing performance data, connected platforms, and CRM leads for ${selectedWorkspace.name}.`
              : 'Add or select a client workspace to begin connecting channels, tracking leads, and generating reports.'}
          </p>
        </div>
        <div className="welcome-actions">
          <button type="button" className="btn btn-outline-white" onClick={() => navigate('/reports')}>View report</button>
          <button type="button" className="btn btn-white" onClick={() => navigate('/publishing')}>+ Create content</button>
        </div>
      </div>

      <div className="metric-grid" style={{ opacity: loading ? 0.6 : 1, transition: 'opacity 0.2s' }}>
        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Total Reach</span>
            <div className="metric-icon">👁</div>
          </div>
          <div className="metric-value">{metrics?.total_reach || '148,500'}</div>
          <div className="metric-foot">
            <span className="trend-up">{metrics?.reach_change || '+14.2%'}</span> vs last period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Engagement Rate</span>
            <div className="metric-icon">⚡</div>
          </div>
          <div className="metric-value">{metrics?.engagement_rate || '4.8%'}</div>
          <div className="metric-foot">
            <span className="trend-up">{metrics?.engagement_change || '+0.6%'}</span> vs last period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">New Leads</span>
            <div className="metric-icon">🎯</div>
          </div>
          <div className="metric-value">{metrics?.new_leads ?? 342}</div>
          <div className="metric-foot">
            <span className="trend-up">{metrics?.leads_change || '+28'}</span> this week
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Monthly Revenue</span>
            <div className="metric-icon">💰</div>
          </div>
          <div className="metric-value">{metrics?.monthly_revenue || '$84,500'}</div>
          <div className="metric-foot">
            <span className="trend-up">{metrics?.revenue_change || '+18.5%'}</span> growth
          </div>
        </div>
      </div>

      <div className="grid-2" style={{ opacity: loading ? 0.6 : 1, transition: 'opacity 0.2s' }}>
        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Performance trend</h3>
              <p>Reach and engagement for {metrics?.workspace_name || 'selected workspace'}</p>
            </div>
            <div className="panel-actions">
              <select className="select" style={{ width: '145px' }} aria-label="Performance date range">
                <option value="30">Last 30 days</option>
                <option value="7">Last 7 days</option>
                <option value="90">Last 90 days</option>
              </select>
            </div>
          </div>
          <div className="chart-legend">
            <span className="legend-item"><span className="legend-line"></span>Reach</span>
            <span className="legend-item"><span className="legend-line secondary"></span>Engagement</span>
          </div>
          <div className="chart-wrap">
            <Line data={chartData} options={chartOptions} />
          </div>
        </section>

        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Connected channels</h3>
              <p>Only channels enabled for {metrics?.workspace_name || 'this client'}</p>
            </div>
            <button type="button" className="link-button" onClick={() => navigate('/integrations')}>Manage</button>
          </div>
          <div className="channel-list">
            {activeChannels.map((ch, idx) => (
              <div className="channel-row" key={idx}>
                <div className="channel-logo">{ch.logo}</div>
                <div>
                  <div className="channel-name">{ch.name}</div>
                  <div className="channel-meta">{ch.meta}</div>
                </div>
                <div className="channel-value">
                  <strong>{ch.value}</strong>
                  <small>{ch.change}</small>
                </div>
              </div>
            ))}
          </div>
        </section>
      </div>
    </div>
  );
}
