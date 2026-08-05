import React from 'react';
import { NavLink } from 'react-router-dom';

export default function Sidebar({ isOpen, onClose }) {
  const navItems = [
    { label: 'Overview', path: '/', icon: '⌂' },
    { label: 'Clients', path: '/clients', icon: '▦' },
    { label: 'Publishing', path: '/publishing', icon: '✦' },
    { label: 'CRM & Leads', path: '/leads', icon: '◎' },
    { label: 'Reports', path: '/reports', icon: '▥' },
  ];

  const systemItems = [
    { label: 'Notifications', path: '/notifications', icon: '◉' },
    { label: 'API Connections', path: '/integrations', icon: '⌁' },
    { label: 'Team & Access', path: '/team', icon: '♙' },
  ];

  return (
    <aside className={`sidebar ${isOpen ? 'open' : ''}`}>
      <div className="brand">
        <div className="brand-mark">RM</div>
        <div>
          <strong>Marketing Command</strong>
          <small>Operations Dashboard</small>
        </div>
      </div>

      <div className="nav-group-title">Workspace</div>
      <nav className="nav-list" aria-label="Main navigation">
        {navItems.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            onClick={onClose}
            className={({ isActive }) => `nav-btn ${isActive ? 'active' : ''}`}
          >
            <span className="nav-icon">{item.icon}</span>
            {item.label}
          </NavLink>
        ))}
      </nav>

      <div className="nav-group-title">System</div>
      <nav className="nav-list" aria-label="System navigation">
        {systemItems.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            onClick={onClose}
            className={({ isActive }) => `nav-btn ${isActive ? 'active' : ''}`}
          >
            <span className="nav-icon">{item.icon}</span>
            {item.label}
          </NavLink>
        ))}
      </nav>

      <div className="sidebar-help">
        <strong>Need help?</strong>
        <p>Use the setup guide to connect a client account or understand any dashboard section.</p>
        <button type="button">Open simple guide</button>
      </div>
    </aside>
  );
}
