import React, { useEffect, useState } from 'react';
import axios from 'axios';
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
  const [metrics, setMetrics] = useState(null);

  useEffect(() => {
    axios
      .get('/api/v1/dashboard/metrics')
      .then((res) => {
        if (res.data.success) {
          setMetrics(res.data.data);
        }
      })
      .catch((err) => console.error('Error loading metrics:', err));
  }, []);

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

  return (
    <div>
      <div className="welcome-panel">
        <div className="welcome-copy">
          <h2>Welcome to Marketing Command</h2>
          <p>Add a client workspace to begin connecting channels, tracking leads, and generating reports.</p>
        </div>
        <div className="welcome-actions">
          <button type="button" className="btn btn-outline-white">View report</button>
          <button type="button" className="btn btn-white">+ Create content</button>
        </div>
      </div>

      <div className="metric-grid">
        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Total Reach</span>
            <div className="metric-icon">👁</div>
          </div>
          <div className="metric-value">{metrics?.total_reach || '1.2M'}</div>
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
          <div className="metric-value">{metrics?.new_leads || 342}</div>
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

      <div className="grid-2">
        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Performance trend</h3>
              <p>Reach and engagement for the selected period</p>
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
              <p>Only channels enabled for this client</p>
            </div>
            <button type="button" className="link-button">Manage</button>
          </div>
          <div className="channel-list">
            <div className="channel-row">
              <div className="channel-logo">FB</div>
              <div>
                <div className="channel-name">Facebook Page</div>
                <div className="channel-meta">Connected • 1.2k Followers</div>
              </div>
              <div className="channel-value">
                <strong>45.2k</strong>
                <small>+12%</small>
              </div>
            </div>
            <div className="channel-row">
              <div className="channel-logo">IG</div>
              <div>
                <div className="channel-name">Instagram Profile</div>
                <div className="channel-meta">Connected • 8.4k Followers</div>
              </div>
              <div className="channel-value">
                <strong>89.1k</strong>
                <small>+24%</small>
              </div>
            </div>
            <div className="channel-row">
              <div className="channel-logo">GA</div>
              <div>
                <div className="channel-name">Google Analytics 4</div>
                <div className="channel-meta">Active Stream</div>
              </div>
              <div className="channel-value">
                <strong>12.4k</strong>
                <small>+8%</small>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
