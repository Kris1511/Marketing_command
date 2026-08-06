import React, { useEffect, useState } from 'react';
import axiosInstance from '../api/axiosInstance';
import { useNavigate } from 'react-router-dom';
import { useWorkspace } from '../context/WorkspaceContext';
import {
  Eye,
  Zap,
  Globe,
  Target,
  Check,
  Plus,
  RefreshCw,
  AlertTriangle
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

const renderActivityIcon = (icon) => {
  if (icon === '✓' || icon === 'check') return <Check size={16} />;
  if (icon === '+' || icon === 'plus') return <Plus size={16} />;
  if (icon === '↻' || icon === 'sync') return <RefreshCw size={16} />;
  if (icon === '!' || icon === 'alert') return <AlertTriangle size={16} />;
  return <Check size={16} />;
};

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
  funnel: { new_leads: 186, contacted: 142, qualified: 78, proposal_sent: 39, won: 23, conversion: '12.4%' },
  priorities: [
    { num: 1, title: 'Follow up new leads', sub: 'Due today', btn: 'Open', target: '/leads' },
    { num: 2, title: 'Approve scheduled content', sub: 'Social media campaign', btn: 'Review', target: '/publishing' }
  ],
  activities: [
    { icon: '✓', title: 'Campaign active', sub: 'Monitoring real-time performance', time: 'Just now', bg: '#dcfce7', color: '#16a34a' }
  ],
  trend: {
    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    reach: [12000, 19000, 15000, 22000, 28000, 24000, 31000],
    engagement: [800, 1400, 1100, 1800, 2200, 1900, 2600],
  }
};

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

  const clientName = selectedWorkspace?.name || metrics?.workspace_name || 'Aara Wellness';
  const clientData = CLIENT_OVERVIEW_DATA[clientName] || DEFAULT_ZERO_DATA;

  const totalReach = metrics?.total_reach || clientData.total_reach || '148,500';
  const engagementRate = metrics?.engagement_rate || clientData.engagement_rate || '5.6%';
  const websiteTraffic = metrics?.website_traffic || clientData.website_traffic || '18,420';
  const newLeads = metrics?.new_leads ?? clientData.new_leads ?? 342;
  const funnel = clientData.funnel || DEFAULT_ZERO_DATA.funnel;

  const chartData = {
    labels: metrics?.trend_data?.labels || clientData.trend.labels,
    datasets: [
      {
        label: 'Reach',
        data: metrics?.trend_data?.reach || clientData.trend.reach,
        borderColor: '#2457e6',
        backgroundColor: '#2457e6',
        tension: 0.35,
      },
      {
        label: 'Engagement',
        data: metrics?.trend_data?.engagement || clientData.trend.engagement,
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

  const activeChannels = metrics?.channels || (clientData.channels.length > 0 ? clientData.channels : defaultChannels);

  return (
    <div>
      {/* Welcome Banner */}
      <div className="welcome-panel">
        <div className="welcome-copy">
          <h2>
            {clientName ? `${clientName} Overview` : 'Welcome to Marketing Command'}
          </h2>
          <p>
            Currently viewing performance data, connected platforms, and CRM leads for <strong>{clientName}</strong>.
          </p>
        </div>
        <div className="welcome-actions">
          <button type="button" className="btn btn-outline-white" onClick={() => navigate('/reports')}>View report</button>
          <button type="button" className="btn btn-white" onClick={() => navigate('/publishing')} style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
            <Plus size={16} /> Create content
          </button>
        </div>
      </div>

      <div className="metric-grid" style={{ opacity: loading ? 0.6 : 1, transition: 'opacity 0.2s' }}>
        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Total reach</span>
            <div className="metric-icon"><Eye size={20} /></div>
          </div>
          <div className="metric-value">{totalReach}</div>
          <div className="metric-foot">
            <span className="trend-up">{metrics?.reach_change || clientData.reach_change}</span> vs previous period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Engagement</span>
            <div className="metric-icon"><Zap size={20} /></div>
          </div>
          <div className="metric-value">{engagementRate}</div>
          <div className="metric-foot">
            <span className="trend-up">{metrics?.engagement_change || clientData.engagement_change}</span> vs previous period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Website traffic</span>
            <div className="metric-icon"><Globe size={20} /></div>
          </div>
          <div className="metric-value">{websiteTraffic}</div>
          <div className="metric-foot">
            <span className="trend-up">{metrics?.traffic_change || clientData.traffic_change}</span> vs previous period
          </div>
        </div>

        <div className="metric-card">
          <div className="metric-top">
            <span className="metric-label">Leads generated</span>
            <div className="metric-icon"><Target size={20} /></div>
          </div>
          <div className="metric-value">{newLeads}</div>
          <div className="metric-foot">
            <span className="trend-up">{metrics?.leads_change || clientData.leads_change}</span> vs previous period
          </div>
        </div>
      </div>

      <div className="grid-2" style={{ opacity: loading ? 0.6 : 1, transition: 'opacity 0.2s' }}>
        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Performance trend</h3>
              <p>Reach and engagement for {clientName}</p>
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
              <p>Only channels enabled for {clientName}</p>
            </div>
            <button type="button" className="link-button" onClick={() => navigate('/integrations')}>Manage</button>
          </div>
          <div className="channel-list">
            {activeChannels.map((ch, idx) => (
              <div className="channel-row" key={idx}>
                <div className="channel-logo" style={ch.bg ? { background: ch.bg, color: ch.color, fontWeight: 800 } : {}}>
                  {ch.logo || ch.key || ch.name?.slice(0, 2).toUpperCase()}
                </div>
                <div>
                  <div className="channel-name">{ch.name}</div>
                  <div className="channel-meta">{ch.meta || ch.label || 'Connected'}</div>
                </div>
                <div className="channel-value">
                  <strong>{ch.value}</strong>
                  <small style={{ color: '#11875d' }}>{ch.change}</small>
                </div>
              </div>
            ))}
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
            <span className="pill success">{funnel.conversion || '12.4%'} conversion</span>
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
            {(clientData.priorities.length > 0 ? clientData.priorities : DEFAULT_ZERO_DATA.priorities).map((item) => (
              <div className="activity-item" key={item.title}>
                <div className="activity-icon" style={{ background: '#eaf0ff', color: '#2457e6' }}>{item.num}</div>
                <div className="activity-text">
                  <strong>{item.title}</strong>
                  <span>{item.sub}</span>
                </div>
                <button type="button" className="btn btn-sm btn-secondary" onClick={() => navigate(item.target)}>{item.btn}</button>
              </div>
            ))}
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
            {(clientData.activities.length > 0 ? clientData.activities : DEFAULT_ZERO_DATA.activities).map((act) => (
              <div className="activity-item" key={act.title}>
                <div className="activity-icon" style={{ background: act.bg, color: act.color }}>
                  {renderActivityIcon(act.icon)}
                </div>
                <div className="activity-text">
                  <strong>{act.title}</strong>
                  <span>{act.sub}</span>
                </div>
                <span className="activity-time">{act.time}</span>
              </div>
            ))}
          </div>
        </section>
      </div>
    </div>
  );
}
