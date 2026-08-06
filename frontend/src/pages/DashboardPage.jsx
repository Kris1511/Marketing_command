import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
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
import { useWorkspace } from '../context/WorkspaceContext';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend
);

const CLIENT_OVERVIEW_DATA = {
  'Aara Wellness': {
    total_reach: '171,661',
    reach_change: '+14.2%',
    engagement_rate: '5.6%',
    engagement_change: '+0.6%',
    website_traffic: '18,420',
    traffic_change: '+9.2%',
    new_leads: 186,
    leads_change: '+28',
    channels: [
      { key: 'IG', name: 'Instagram', label: 'Connected', value: '82.4K', change: '+18.2%', color: '#E1306C', bg: '#FDE8EF' },
      { key: 'FB', name: 'Facebook Page', label: 'Connected', value: '46.1K', change: '+9.7%', color: '#1877F2', bg: '#E7F0FD' },
      { key: 'GA', name: 'Google Analytics 4', label: 'Connected', value: '18.4K', change: '+8.0%', color: '#E37400', bg: '#FEF3E2' },
    ],
    funnel: { new_leads: 186, contacted: 142, qualified: 78, proposal_sent: 39, won: 23, conversion: '12.4%' },
    priorities: [
      { num: 1, title: 'Follow up 3 leads', sub: 'Due before 5:00 PM', btn: 'Open', target: '/leads' },
      { num: 2, title: 'Approve tomorrow’s post', sub: 'Instagram • 10:30 AM', btn: 'Review', target: '/publishing' },
      { num: 3, title: 'Reconnect YouTube', sub: 'Connection expires on Aug 8', btn: 'Fix', target: '/integrations' },
    ],
    activities: [
      { icon: '✓', title: 'Instagram post published', sub: 'Aara Wellness • Morning yoga reel', time: '12 min', bg: '#dcfce7', color: '#16a34a' },
      { icon: '+', title: 'New lead received', sub: 'Facebook Lead Ads • Priyanka R', time: '26 min', bg: '#dbeafe', color: '#1d4ed8' },
      { icon: '↻', title: 'Google Analytics synced', sub: '18,420 website users updated', time: '1 hr', bg: '#e0f2fe', color: '#0369a1' },
      { icon: '!', title: 'YouTube token needs attention', sub: 'Aara Wellness • Reconnect before Aug 8', time: '2 hr', bg: '#fef3c7', color: '#b45309' },
    ],
    trend: {
      labels: ['Jul 6', 'Jul 10', 'Jul 14', 'Jul 18', 'Jul 22', 'Jul 26', 'Jul 30', 'Aug 3'],
      reach: [62000, 69000, 66000, 76000, 81000, 86000, 93000, 104000],
      engagement: [31000, 34000, 39000, 37000, 45000, 49000, 54000, 58000],
    }
  },
  'Fast Logistics': {
    total_reach: '84,200',
    reach_change: '+8.4%',
    engagement_rate: '6.1%',
    engagement_change: '+1.2%',
    website_traffic: '9,250',
    traffic_change: '+12.7%',
    new_leads: 104,
    leads_change: '+14',
    channels: [
      { key: 'FB', name: 'Facebook Page', label: 'Connected', value: '38.2K', change: '+7.4%', color: '#1877F2', bg: '#E7F0FD' },
      { key: 'YT', name: 'YouTube Channel', label: 'Connected', value: '31.6K', change: '+13.4%', color: '#FF0000', bg: '#FFE8E8' },
      { key: 'GA', name: 'Google Analytics 4', label: 'Connected', value: '9.25K', change: '+12.7%', color: '#E37400', bg: '#FEF3E2' },
    ],
    funnel: { new_leads: 104, contacted: 78, qualified: 36, proposal_sent: 18, won: 10, conversion: '9.6%' },
    priorities: [
      { num: 1, title: 'Follow up 2 cargo leads', sub: 'Due before 4:00 PM', btn: 'Open', target: '/leads' },
      { num: 2, title: 'Review YouTube explainer video', sub: 'YouTube • Scheduled Aug 8', btn: 'Review', target: '/publishing' },
    ],
    activities: [
      { icon: '+', title: 'New lead received', sub: 'Website Form • Arun Kumar', time: '18 min', bg: '#dbeafe', color: '#1d4ed8' },
      { icon: '↻', title: 'Google Analytics synced', sub: '9,250 website users updated', time: '45 min', bg: '#e0f2fe', color: '#0369a1' },
      { icon: '!', title: 'YouTube token needs attention', sub: 'Fast Logistics • Reconnect before Aug 8', time: '2 hr', bg: '#fef3c7', color: '#b45309' },
    ],
    trend: {
      labels: ['Jul 6', 'Jul 10', 'Jul 14', 'Jul 18', 'Jul 22', 'Jul 26', 'Jul 30', 'Aug 3'],
      reach: [40000, 45000, 48000, 52000, 60000, 68000, 75000, 84200],
      engagement: [18000, 21000, 24000, 28000, 32000, 35000, 38000, 41200],
    }
  }
};

const DEFAULT_ZERO_DATA = {
  total_reach: '0',
  reach_change: '+0%',
  engagement_rate: '0%',
  engagement_change: '+0%',
  website_traffic: '0',
  traffic_change: '+0%',
  new_leads: 0,
  leads_change: '+0',
  channels: [],
  funnel: { new_leads: 0, contacted: 0, qualified: 0, proposal_sent: 0, won: 0, conversion: '0%' },
  priorities: [],
  activities: [],
  trend: {
    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    reach: [0, 0, 0, 0, 0, 0, 0],
    engagement: [0, 0, 0, 0, 0, 0, 0],
  }
};

export default function DashboardPage() {
  const { activeWorkspace } = useWorkspace();
  const navigate = useNavigate();
  const [metrics, setMetrics] = useState(null);

  useEffect(() => {
    if (!activeWorkspace?.id) return;
    axios
      .get(`/api/v1/dashboard/metrics?workspace_id=${activeWorkspace.id}`)
      .then((res) => {
        if (res.data.success) {
          setMetrics(res.data.data);
        }
      })
      .catch((err) => console.error('Error loading metrics:', err));
  }, [activeWorkspace?.id]);

  const clientName = activeWorkspace?.name || 'Aara Wellness';
  const hasPresetData = !!CLIENT_OVERVIEW_DATA[clientName];
  const clientData = CLIENT_OVERVIEW_DATA[clientName] || DEFAULT_ZERO_DATA;

  const totalReach = hasPresetData ? clientData.total_reach : (metrics?.total_reach || '0');
  const engagementRate = hasPresetData ? clientData.engagement_rate : (metrics?.engagement_rate || '0%');
  const websiteTraffic = hasPresetData ? clientData.website_traffic : (metrics?.website_traffic || '0');
  const newLeads = hasPresetData ? clientData.new_leads : (metrics?.new_leads ?? 0);
  const funnel = clientData.funnel;

  const chartData = {
    labels: clientData.trend.labels,
    datasets: [
      {
        label: 'Reach',
        data: clientData.trend.reach,
        borderColor: '#2457e6',
        backgroundColor: '#2457e6',
        tension: 0.35,
      },
      {
        label: 'Engagement',
        data: clientData.trend.engagement,
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
          <h2>{clientName} Workspace 👋</h2>
          <p>
            Operations, channels, and performance updates for <strong>{clientName}</strong>.
          </p>
        </div>
        <div className="welcome-actions">
          <button type="button" className="btn btn-outline-white" onClick={() => navigate('/reports')}>
            View report
          </button>
          <button type="button" className="btn btn-white" onClick={() => navigate('/publishing')}>
            + Create content
          </button>
        </div>
      </div>

      {/* Top 4 Metrics Grid matching Screenshots */}
      <div className="metric-grid">
        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Total reach</span>
            <div className="metric-icon">👁</div>
          </div>
          <div className="metric-value">{totalReach}</div>
          <div className="metric-foot">
            <span className="trend-up">{clientData.reach_change}</span> vs previous period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Engagement</span>
            <div className="metric-icon">⚡</div>
          </div>
          <div className="metric-value">{engagementRate}</div>
          <div className="metric-foot">
            <span className="trend-up">{clientData.engagement_change}</span> vs previous period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Website traffic</span>
            <div className="metric-icon">🌐</div>
          </div>
          <div className="metric-value">{websiteTraffic}</div>
          <div className="metric-foot">
            <span className="trend-up">{clientData.traffic_change}</span> vs previous period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Leads generated</span>
            <div className="metric-icon">🎯</div>
          </div>
          <div className="metric-value">{newLeads}</div>
          <div className="metric-foot">
            <span className="trend-up">{clientData.leads_change}</span> vs previous period
          </div>
        </div>
      </div>

      {/* Grid 2: Performance Trend Chart & Connected Channels */}
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
            <button type="button" className="link-button" onClick={() => navigate('/integrations')}>
              Manage
            </button>
          </div>
          <div className="channel-list">
            {clientData.channels.length === 0 ? (
              <p style={{ color: '#94a3b8', fontSize: '0.85rem', padding: '1rem 0', margin: 0 }}>
                No channels connected yet. Go to API Connections to link social accounts.
              </p>
            ) : (
              clientData.channels.map((ch) => (
                <div className="channel-row" key={ch.name}>
                  <div className="channel-logo" style={{ background: ch.bg, color: ch.color, fontWeight: 800 }}>
                    {ch.key}
                  </div>
                  <div>
                    <div className="channel-name">{ch.name}</div>
                    <div className="channel-meta">
                      <span className="pill success" style={{ padding: '1px 6px', fontSize: '0.68rem' }}>
                        ● {ch.label}
                      </span>
                    </div>
                  </div>
                  <div className="channel-value">
                    <strong>{ch.value}</strong>
                    <small>{ch.change}</small>
                  </div>
                </div>
              ))
            )}
          </div>
        </section>
      </div>

      {/* Grid 3: Lead Funnel, Today's Priorities, Recent Activity */}
      <div className="grid-3">
        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Lead funnel</h3>
              <p>From enquiry to conversion</p>
            </div>
            <span className="pill success">{funnel.conversion} conversion</span>
          </div>
          <div className="funnel">
            <div className="funnel-row">
              <span>New Leads</span>
              <div className="progress"><span style={{ width: funnel.new_leads > 0 ? '100%' : '0%' }}></span></div>
              <strong>{funnel.new_leads}</strong>
            </div>
            <div className="funnel-row">
              <span>Contacted</span>
              <div className="progress"><span style={{ width: funnel.new_leads > 0 ? '76%' : '0%' }}></span></div>
              <strong>{funnel.contacted}</strong>
            </div>
            <div className="funnel-row">
              <span>Qualified</span>
              <div className="progress"><span style={{ width: funnel.new_leads > 0 ? '42%' : '0%' }}></span></div>
              <strong>{funnel.qualified}</strong>
            </div>
            <div className="funnel-row">
              <span>Proposal Sent</span>
              <div className="progress"><span style={{ width: funnel.new_leads > 0 ? '21%' : '0%' }}></span></div>
              <strong>{funnel.proposal_sent}</strong>
            </div>
            <div className="funnel-row">
              <span>Won</span>
              <div className="progress"><span style={{ width: funnel.new_leads > 0 ? '12%' : '0%' }}></span></div>
              <strong>{funnel.won}</strong>
            </div>
          </div>
        </section>

        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Today’s priorities</h3>
              <p>Recommended next actions</p>
            </div>
          </div>
          <div className="activity-list">
            {clientData.priorities.length === 0 ? (
              <p style={{ color: '#94a3b8', fontSize: '0.85rem', padding: '1rem 0', margin: 0 }}>
                No pending priorities for this workspace.
              </p>
            ) : (
              clientData.priorities.map((item) => (
                <div className="activity-item" key={item.title}>
                  <div className="activity-icon" style={{ background: '#eaf0ff', color: '#2457e6' }}>{item.num}</div>
                  <div className="activity-text">
                    <strong>{item.title}</strong>
                    <span>{item.sub}</span>
                  </div>
                  <button className="btn btn-sm btn-secondary" onClick={() => navigate(item.target)}>{item.btn}</button>
                </div>
              ))
            )}
          </div>
        </section>

        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Recent activity</h3>
              <p>Latest actions across the workspace</p>
            </div>
            <button type="button" className="link-button" onClick={() => navigate('/notifications')}>
              View all
            </button>
          </div>
          <div className="activity-list">
            {clientData.activities.length === 0 ? (
              <p style={{ color: '#94a3b8', fontSize: '0.85rem', padding: '1rem 0', margin: 0 }}>
                No recent activity recorded for this workspace yet.
              </p>
            ) : (
              clientData.activities.map((act) => (
                <div className="activity-item" key={act.title}>
                  <div className="activity-icon" style={{ background: act.bg, color: act.color }}>{act.icon}</div>
                  <div className="activity-text">
                    <strong>{act.title}</strong>
                    <span>{act.sub}</span>
                  </div>
                  <span className="activity-time">{act.time}</span>
                </div>
              ))
            )}
          </div>
        </section>
      </div>
    </div>
  );
}
