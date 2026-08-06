import React, { useEffect, useState } from 'react';
import axiosInstance from '../api/axiosInstance';
import { useNavigate } from 'react-router-dom';
import {
  CheckCircle2,
  Search,
  Check,
  AlertTriangle,
  Plus,
  RefreshCw,
  Megaphone,
  Link2,
  Bell
} from 'lucide-react';

const renderNotificationIcon = (iconStr, statusType, category) => {
  if (iconStr === '✓' || statusType === 'success') return <Check size={18} />;
  if (iconStr === '!' || statusType === 'warning') return <AlertTriangle size={18} />;
  if (iconStr === '+') return <Plus size={18} />;
  if (iconStr === '↻') return <RefreshCw size={18} />;
  if (iconStr === '📢' || category === 'publishing') return <Megaphone size={18} />;
  if (iconStr === '🔗' || category === 'system') return <Link2 size={18} />;
  return <Bell size={18} />;
};

export default function NotificationsPage() {
  const navigate = useNavigate();
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filterTab, setFilterTab] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');

  useEffect(() => {
    axiosInstance
      .get('/notifications')
      .then((res) => {
        if (res.data.success) {
          setNotifications(res.data.data);
        }
      })
      .catch((err) => console.error('Error fetching notifications:', err))
      .finally(() => setLoading(false));
  }, []);

  const handleMarkAllRead = () => {
    setNotifications((prev) => prev.map((n) => ({ ...n, is_read: true })));
    axiosInstance.post('/notifications/mark-read').catch(() => {});
  };

  const toggleReadStatus = (id) => {
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, is_read: !n.is_read } : n))
    );
  };

  const handleActionClick = (category) => {
    if (category === 'leads') navigate('/leads');
    else if (category === 'publishing') navigate('/publishing');
    else if (category === 'system') navigate('/integrations');
    else navigate('/reports');
  };

  // Filtering
  const filtered = notifications.filter((n) => {
    const matchesSearch =
      n.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
      n.message.toLowerCase().includes(searchQuery.toLowerCase()) ||
      n.workspace.toLowerCase().includes(searchQuery.toLowerCase());

    if (filterTab === 'unread') return matchesSearch && !n.is_read;
    if (filterTab === 'leads') return matchesSearch && n.category === 'leads';
    if (filterTab === 'publishing') return matchesSearch && n.category === 'publishing';
    if (filterTab === 'system') return matchesSearch && n.category === 'system';
    return matchesSearch;
  });

  const unreadCount = notifications.filter((n) => !n.is_read).length;

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <h2>Notifications & activity log</h2>
          <p>Real-time channel alerts, system logs, lead updates, and integration warnings.</p>
        </div>
        <div style={{ display: 'flex', gap: '10px' }}>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={handleMarkAllRead}
            disabled={unreadCount === 0}
          >
            Mark all as read
          </button>
        </div>
      </div>

      {/* 2. Stat Cards Grid */}
      <div className="metric-grid">
        <div className="metric-card">
          <span className="metric-label">Total notifications</span>
          <div className="metric-value">{notifications.length}</div>
          <div className="metric-foot">
            <span>All activity history</span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label">Unread alerts</span>
          <div className="metric-value">{unreadCount}</div>
          <div className="metric-foot">
            <span className={unreadCount > 0 ? 'trend-down' : 'trend-up'}>
              {unreadCount > 0 ? 'Action required' : 'All caught up'}
            </span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label">System Status</span>
          <div className="metric-value">99.2%</div>
          <div className="metric-foot">
            <span className="trend-up" style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
              <CheckCircle2 size={13} /> Healthy WAMP sync
            </span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label">Active Workspaces</span>
          <div className="metric-value">4</div>
          <div className="metric-foot">
            <span>Across all channels</span>
          </div>
        </div>
      </div>

      {/* 3. Notification List Panel */}
      <section className="panel">
        <div className="panel-header" style={{ alignItems: 'center' }}>
          <div className="panel-actions" style={{ gap: '8px' }}>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'all' ? 'active' : ''}`}
              onClick={() => setFilterTab('all')}
            >
              All ({notifications.length})
            </button>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'unread' ? 'active' : ''}`}
              onClick={() => setFilterTab('unread')}
            >
              Unread ({unreadCount})
            </button>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'leads' ? 'active' : ''}`}
              onClick={() => setFilterTab('leads')}
            >
              Leads
            </button>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'publishing' ? 'active' : ''}`}
              onClick={() => setFilterTab('publishing')}
            >
              Publishing
            </button>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'system' ? 'active' : ''}`}
              onClick={() => setFilterTab('system')}
            >
              System Alerts
            </button>
          </div>

          <div className="search-box">
            <span style={{ display: 'inline-flex', alignItems: 'center' }}><Search size={16} /></span>
            <input
              type="text"
              placeholder="Search notifications..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
          </div>
        </div>

        <div className="activity-list mt-10">
          {loading ? (
            <p style={{ textAlign: 'center', padding: '24px' }}>Loading notifications...</p>
          ) : filtered.length === 0 ? (
            <p style={{ textAlign: 'center', padding: '24px', color: '#64748b' }}>
              No notifications found matching your filter.
            </p>
          ) : (
            filtered.map((item) => (
              <div
                key={item.id}
                className="activity-item"
                style={{
                  gridTemplateColumns: '42px minmax(0,1fr) auto',
                  background: item.is_read ? 'transparent' : '#f8fafc',
                  padding: '14px 12px',
                  borderRadius: '12px',
                  marginBottom: '8px',
                  border: item.is_read ? '1px solid transparent' : '1px solid #e2e8f0',
                }}
              >
                <div
                  className="activity-icon"
                  style={{
                    background:
                      item.status_type === 'success'
                        ? '#e7f7f0'
                        : item.status_type === 'warning'
                        ? '#fff4db'
                        : item.status_type === 'info'
                        ? '#e8f6fb'
                        : '#eaf0ff',
                    color:
                      item.status_type === 'success'
                        ? '#11875d'
                        : item.status_type === 'warning'
                        ? '#b66a00'
                        : item.status_type === 'info'
                        ? '#0477a8'
                        : '#2457e6',
                    width: '40px',
                    height: '40px',
                    fontSize: '16px',
                  }}
                >
                  {renderNotificationIcon(item.icon, item.status_type, item.category)}
                </div>

                <div className="activity-text">
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <strong>{item.title}</strong>
                    <span className="pill" style={{ fontSize: '10px', padding: '2px 7px' }}>
                      {item.workspace}
                    </span>
                    {!item.is_read && (
                      <span
                        style={{
                          width: '7px',
                          height: '7px',
                          borderRadius: '50%',
                          background: '#2457e6',
                          display: 'inline-block',
                        }}
                      ></span>
                    )}
                  </div>
                  <span style={{ color: '#475569', fontSize: '13px', marginTop: '4px' }}>
                    {item.message}
                  </span>
                </div>

                <div style={{ textAlign: 'right', display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: '6px' }}>
                  <span className="activity-time">{item.created_at}</span>
                  <div style={{ display: 'flex', gap: '8px' }}>
                    <button
                      type="button"
                      className="link-button"
                      style={{ fontSize: '12px' }}
                      onClick={() => handleActionClick(item.category)}
                    >
                      View
                    </button>
                    <button
                      type="button"
                      className="link-button"
                      style={{ fontSize: '12px', color: '#64748b' }}
                      onClick={() => toggleReadStatus(item.id)}
                    >
                      {item.is_read ? 'Unread' : 'Mark read'}
                    </button>
                  </div>
                </div>
              </div>
            ))
          )}
        </div>
      </section>
    </div>
  );
}
