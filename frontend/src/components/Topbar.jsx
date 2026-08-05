import React from 'react';

export default function Topbar({ onToggleMobileMenu, title = 'Overview', subtitle = 'All important updates in one place' }) {
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
          <select id="clientSelector" aria-label="Select client">
            <option value="1">Acme Growth Labs</option>
            <option value="2">Nexus Retail Group</option>
          </select>
        </div>
        <button type="button" className="icon-btn" title="Sync connected platforms">
          ↻
        </button>
        <button type="button" className="icon-btn" title="Notifications">
          ◉<span className="notification-dot"></span>
        </button>
        <div className="avatar" title="Digital Marketing Team">
          DM
        </div>
      </div>
    </header>
  );
}
