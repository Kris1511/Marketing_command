import React, { useEffect, useState, useCallback } from 'react';
import axiosInstance from '../api/axiosInstance';
import { useNavigate } from 'react-router-dom';
import { useWorkspace } from '../context/WorkspaceContext';
import {
  CheckCircle2,
  Search,
  Check,
  AlertTriangle,
  Plus,
  RefreshCw,
  Megaphone,
  Link2,
  Bell,
  ChevronLeft,
  ChevronRight,
  ShieldCheck,
  RotateCw
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

function formatRelativeTime(iso) {
  if (!iso) return '';
  if (typeof iso === 'string' && (iso.includes('ago') || iso.includes('Yesterday') || iso.includes('Now'))) return iso;
  const diff = Date.now() - new Date(iso).getTime();
  if (isNaN(diff)) return iso;
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins} min ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs} hr${hrs > 1 ? 's' : ''} ago`;
  const days = Math.floor(hrs / 24);
  return `${days} day${days > 1 ? 's' : ''} ago`;
}

export default function NotificationsPage() {
  const navigate = useNavigate();
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspace();

  // State
  const [notifications, setNotifications] = useState([]);
  const [pagination, setPagination] = useState({
    current_page: 1,
    per_page: 5,
    total: 0,
    last_page: 1,
    has_prev: false,
    has_next: false,
  });
  const [summary, setSummary] = useState({
    total_notifications: 0,
    unread_alerts: 0,
    active_workspaces: 0,
    system_status: 'Operational',
    counts: {
      all: 0,
      comments: 0,
      unread: 0,
      leads: 0,
      publishing: 0,
      system: 0,
    },
  });

  const [loading, setLoading] = useState(true);
  const [filterTab, setFilterTab] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [page, setPage] = useState(1);

  // Debounce search input
  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setPage(1); // reset to page 1 on new search
    }, 300);
    return () => clearTimeout(handler);
  }, [searchQuery]);

  // Reset page to 1 when workspace or category tab changes
  useEffect(() => {
    setPage(1);
  }, [selectedWorkspaceId, filterTab]);

  // Fetch real notifications with backend pagination
  const fetchNotifications = useCallback(() => {
    if (!selectedWorkspaceId) return;
    setLoading(true);

    const params = {
      workspace_id: selectedWorkspaceId,
      category: filterTab,
      page,
      per_page: 5,
    };
    if (debouncedSearch.trim()) {
      params.search = debouncedSearch.trim();
    }

    axiosInstance
      .get('/notifications', { params })
      .then((res) => {
        if (res.data.success) {
          const formatted = (res.data.data || []).map((n) => ({
            ...n,
            timeAgo: formatRelativeTime(n.created_at),
          }));
          setNotifications(formatted);
          if (res.data.pagination) {
            setPagination(res.data.pagination);
          }
          if (res.data.summary) {
            setSummary(res.data.summary);
          }
        }
      })
      .catch((err) => console.error('Error fetching notifications:', err))
      .finally(() => setLoading(false));
  }, [selectedWorkspaceId, filterTab, page, debouncedSearch]);

  useEffect(() => {
    fetchNotifications();
  }, [fetchNotifications]);

  // Mark all notifications for active workspace as read
  const handleMarkAllRead = () => {
    setNotifications((prev) => prev.map((n) => ({ ...n, is_read: true })));
    setSummary((prev) => ({
      ...prev,
      unread_alerts: 0,
      counts: { ...prev.counts, unread: 0 },
    }));

    axiosInstance
      .post('/notifications/mark-read', { workspace_id: selectedWorkspaceId })
      .then(() => {
        fetchNotifications();
      })
      .catch((err) => console.error('Error marking all as read:', err));
  };

  // Toggle read/unread for single notification
  const toggleReadStatus = (id) => {
    const target = notifications.find((n) => n.id === id);
    if (!target) return;

    const newRead = !target.is_read;

    // Optimistic UI update
    setNotifications((prev) =>
      prev.map((n) => (n.id === id ? { ...n, is_read: newRead } : n))
    );
    setSummary((prev) => {
      const delta = newRead ? -1 : 1;
      const newUnread = Math.max(0, (prev.unread_alerts || 0) + delta);
      return {
        ...prev,
        unread_alerts: newUnread,
        counts: {
          ...prev.counts,
          unread: Math.max(0, (prev.counts?.unread || 0) + delta),
        },
      };
    });

    axiosInstance
      .patch(`/notifications/${id}/read`)
      .catch((err) => {
        console.error('Error toggling read status:', err);
        fetchNotifications(); // Revert on failure
      });
  };

  const handleActionClick = (item) => {
    if (item.type === 'youtube_comment' && item.related_entity) {
      const parts = item.related_entity.split(':');
      if (parts.length >= 3) {
        const videoId = parts[2];
        if (videoId) {
          window.open(`https://www.youtube.com/watch?v=${videoId}`, '_blank');
          return;
        }
      }
    }

    const category = item.category;
    if (category === 'leads') navigate('/leads');
    else if (category === 'publishing') navigate('/publishing');
    else if (category === 'system') navigate('/integrations');
    else navigate('/reports');
  };

  const counts = summary.counts || {
    all: 0,
    comments: 0,
    unread: 0,
    leads: 0,
    publishing: 0,
    system: 0,
  };

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <h2>Notifications & activity log</h2>
          <p>
            Real-time channel alerts, system logs, lead updates, and integration warnings for{' '}
            <strong>{selectedWorkspace?.name || 'Selected Workspace'}</strong>.
          </p>
        </div>
        <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={fetchNotifications}
            disabled={loading}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}
          >
            <RotateCw size={13} className={loading ? 'spin' : ''} /> Refresh
          </button>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={handleMarkAllRead}
            disabled={summary.unread_alerts === 0 || loading}
          >
            Mark all as read
          </button>
        </div>
      </div>

      {/* 2. Stat Cards Grid (100% Real Backend Data) */}
      <div className="metric-grid">
        <div className="metric-card">
          <span className="metric-label">Total notifications</span>
          <div className="metric-value">{summary.total_notifications}</div>
          <div className="metric-foot">
            <span>All activity history</span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label">Unread alerts</span>
          <div className="metric-value">{summary.unread_alerts}</div>
          <div className="metric-foot">
            <span className={summary.unread_alerts > 0 ? 'trend-down' : 'trend-up'}>
              {summary.unread_alerts > 0 ? 'Action required' : 'All caught up'}
            </span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label">System Status</span>
          <div className="metric-value" style={{ fontSize: '18px', display: 'flex', alignItems: 'center', gap: '6px' }}>
            <ShieldCheck size={20} color="#10b981" /> {summary.system_status}
          </div>
          <div className="metric-foot">
            <span className="trend-up" style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
              <CheckCircle2 size={13} /> Active DB & Services
            </span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label">Active Workspaces</span>
          <div className="metric-value">{summary.active_workspaces}</div>
          <div className="metric-foot">
            <span>Across all channels</span>
          </div>
        </div>
      </div>

      {/* 3. Notification List Panel with 5-Record Pagination */}
      <section className="panel">
        <div className="panel-header" style={{ alignItems: 'center', flexWrap: 'wrap', gap: '12px' }}>
          <div className="panel-actions" style={{ gap: '8px', flexWrap: 'wrap' }}>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'all' ? 'active' : ''}`}
              onClick={() => setFilterTab('all')}
            >
              All ({counts.all || 0})
            </button>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'comments' ? 'active' : ''}`}
              onClick={() => setFilterTab('comments')}
            >
              Comments ({counts.comments || 0})
            </button>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'unread' ? 'active' : ''}`}
              onClick={() => setFilterTab('unread')}
            >
              Unread ({counts.unread || 0})
            </button>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'leads' ? 'active' : ''}`}
              onClick={() => setFilterTab('leads')}
            >
              Leads ({counts.leads || 0})
            </button>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'publishing' ? 'active' : ''}`}
              onClick={() => setFilterTab('publishing')}
            >
              Publishing ({counts.publishing || 0})
            </button>
            <button
              type="button"
              className={`platform-tab ${filterTab === 'system' ? 'active' : ''}`}
              onClick={() => setFilterTab('system')}
            >
              System Alerts ({counts.system || 0})
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

        <div className="activity-list mt-10" style={{ opacity: loading ? 0.6 : 1, transition: 'opacity 0.2s' }}>
          {loading && notifications.length === 0 ? (
            <p style={{ textAlign: 'center', padding: '32px', color: '#64748b' }}>Loading notifications...</p>
          ) : notifications.length === 0 ? (
            <p style={{ textAlign: 'center', padding: '32px', color: '#64748b' }}>
              No notifications found for this workspace matching your filter.
            </p>
          ) : (
            notifications.map((item) => (
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
                  <span style={{ color: '#475569', fontSize: '13px', marginTop: '4px', display: 'block', whiteSpace: 'pre-line', lineHeight: '1.45' }}>
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
                      onClick={() => handleActionClick(item)}
                    >
                      View
                    </button>
                    <button
                      type="button"
                      className="link-button"
                      style={{ fontSize: '12px', color: '#64748b' }}
                      onClick={() => toggleReadStatus(item.id)}
                    >
                      {item.is_read ? 'Mark unread' : 'Mark read'}
                    </button>
                  </div>
                </div>
              </div>
            ))
          )}
        </div>

        {/* 4. 5-Record Pagination Footer */}
        {pagination.total > 0 && (
          <div
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              padding: '16px 4px 4px',
              borderTop: '1px solid #f1f5f9',
              marginTop: '12px',
              flexWrap: 'wrap',
              gap: '12px',
            }}
          >
            <div style={{ fontSize: '12.5px', color: '#64748b' }}>
              Showing records <strong>{(page - 1) * pagination.per_page + (notifications.length > 0 ? 1 : 0)}</strong> to{' '}
              <strong>{Math.min(page * pagination.per_page, pagination.total)}</strong> of{' '}
              <strong>{pagination.total}</strong> notifications
            </div>

            <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1 || !pagination.has_prev || loading}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', padding: '5px 12px' }}
              >
                <ChevronLeft size={15} /> Previous
              </button>

              <span
                style={{
                  fontSize: '13px',
                  fontWeight: '600',
                  padding: '4px 10px',
                  background: '#f1f5f9',
                  borderRadius: '6px',
                  color: '#334155',
                }}
              >
                {page} / {pagination.last_page}
              </span>

              <button
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={() => setPage((p) => Math.min(pagination.last_page, p + 1))}
                disabled={page >= pagination.last_page || !pagination.has_next || loading}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', padding: '5px 12px' }}
              >
                Next <ChevronRight size={15} />
              </button>
            </div>
          </div>
        )}
      </section>
    </div>
  );
}
