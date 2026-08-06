import React from 'react';
import { useWorkspace } from '../context/WorkspaceContext';

export default function Topbar({ onToggleMobileMenu, title = 'Overview', subtitle = 'All important updates in one place' }) {
  const { workspaces, activeWorkspaceId, setActiveWorkspaceId } = useWorkspace();

  return (
    <header className="topbar">
      <div className="topbar-left">
        <button
          type="button"
          className="mobile-menu"
          onClick={onToggleMobileMenu}
          aria-label="Open menu"
        >
          ☰
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
            value={activeWorkspaceId || ''}
            onChange={(e) => setActiveWorkspaceId(e.target.value)}
          >
            {workspaces.map((ws) => (
              <option key={ws.id} value={ws.id}>
                {ws.name}
              </option>
            ))}
          </select>
        </div>
        <button type="button" className="icon-btn" title="Sync connected platforms">
          ↻
        </button>
        <button type="button" className="icon-btn" title="Notifications">
          ◉<span className="notification-dot"></span>
        </button>
        <div className="avatar" title="Digital Marketing Team">
          PS
        </div>
      </div>
    </header>
  );
}
