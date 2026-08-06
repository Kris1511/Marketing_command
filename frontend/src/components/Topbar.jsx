import React from 'react';
import { useNavigate } from 'react-router-dom';
import { useWorkspace } from '../context/WorkspaceContext';
import { Menu, RefreshCw, Bell } from 'lucide-react';

export default function Topbar({ onToggleMobileMenu, title = 'Overview', subtitle = 'All important updates in one place' }) {
  const navigate = useNavigate();
  const { workspaces, selectedWorkspaceId, setSelectedWorkspaceId } = useWorkspace();

  return (
    <header className="topbar">
      <div className="topbar-left">
        <button
          type="button"
          className="mobile-menu"
          onClick={onToggleMobileMenu}
          aria-label="Open menu"
        >
          <Menu size={20} />
        </button>
        <div className="page-heading">
          <strong>{title}</strong>
          <small>{subtitle}</small>
        </div>
      </div>
      <div className="topbar-right">
        <div className="top-control">
          <label htmlFor="clientSelector">Current client workspace</label>
          <select
            id="clientSelector"
            aria-label="Select client"
            value={selectedWorkspaceId || ''}
            onChange={(e) => setSelectedWorkspaceId(e.target.value)}
          >
            <option value="all">All Workspaces (Combined)</option>
            {workspaces.map((ws) => (
              <option key={ws.id} value={ws.id}>
                {ws.name}
              </option>
            ))}
          </select>
        </div>
        <button type="button" className="icon-btn" title="Sync connected platforms">
          <RefreshCw size={18} />
        </button>
        <button
          type="button"
          className="icon-btn"
          title="Notifications"
          onClick={() => navigate('/notifications')}
        >
          <Bell size={18} />
          <span className="notification-dot"></span>
        </button>
        <div className="avatar" title="Digital Marketing Team">
          PS
        </div>
      </div>
    </header>
  );
}
