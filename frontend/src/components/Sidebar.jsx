import React, { useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import {
  Home,
  Building2,
  Send,
  Target,
  BarChart3,
  Bell,
  Link2,
  Shield,
  X,
  LogOut
} from 'lucide-react';

export default function Sidebar({ isOpen, onClose }) {
  const [showGuideModal, setShowGuideModal] = useState(false);
  const { user, logout } = useAuth();
  const navigate = useNavigate();

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  const navItems = [
    { label: 'Overview', path: '/', icon: Home },
    { label: 'Clients', path: '/clients', icon: Building2 },
    { label: 'Publishing', path: '/publishing', icon: Send },
    { label: 'CRM & Leads', path: '/leads', icon: Target },
    { label: 'Reports', path: '/reports', icon: BarChart3 },
  ];

  const systemItems = [
    { label: 'Notifications', path: '/notifications', icon: Bell },
    { label: 'API Connections', path: '/integrations', icon: Link2 },
    { label: 'Team & Access', path: '/team', icon: Shield },
  ];

  const steps = [
    {
      num: 1,
      title: 'Select a client first',
      desc: 'The top client selector changes all dashboard data to that workspace.',
    },
    {
      num: 2,
      title: 'Use Overview every morning',
      desc: 'It shows performance, new leads, scheduled content, and urgent actions.',
    },
    {
      num: 3,
      title: 'Use Publishing for daily posts',
      desc: 'Select platforms, add media and caption, then publish now or schedule.',
    },
    {
      num: 4,
      title: 'Use CRM for every enquiry',
      desc: 'Assign an owner and update the stage after each call or follow-up.',
    },
    {
      num: 5,
      title: 'Use Reports for client sharing',
      desc: 'Choose the period and platform, then download PDF or Excel/CSV.',
    },
  ];

  return (
    <>
      <aside className={`sidebar ${isOpen ? 'open' : ''}`}>
        <div className="brand">
          <div className="brand-mark">RM</div>
          <div>
            <strong style={{ whiteSpace: 'nowrap' }}>Marketing Command</strong>
            <small>Operations Dashboard</small>
          </div>
        </div>

        <div className="nav-group-title">Workspace</div>
        <nav className="nav-list" aria-label="Main navigation">
          {navItems.map((item) => {
            const IconComponent = item.icon;
            return (
              <NavLink
                key={item.path}
                to={item.path}
                onClick={onClose}
                className={({ isActive }) => `nav-btn ${isActive ? 'active' : ''}`}
              >
                <span className="nav-icon" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}>
                  <IconComponent size={18} />
                </span>
                {item.label}
              </NavLink>
            );
          })}
        </nav>

        <div className="nav-group-title">System</div>
        <nav className="nav-list" aria-label="System navigation">
          {systemItems.map((item) => {
            const IconComponent = item.icon;
            return (
              <NavLink
                key={item.path}
                to={item.path}
                onClick={onClose}
                className={({ isActive }) => `nav-btn ${isActive ? 'active' : ''}`}
              >
                <span className="nav-icon" style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}>
                  <IconComponent size={18} />
                </span>
                {item.label}
              </NavLink>
            );
          })}
        </nav>

        <div className="sidebar-help">
          <strong>Need help?</strong>
          <p>Use the setup guide to connect a client account or understand any dashboard section.</p>
          <button type="button" onClick={() => setShowGuideModal(true)}>
            Open simple guide
          </button>
        </div>

        {/* User Info & Logout Button Section */}
        {user && (
          <div
            style={{
              marginTop: '16px',
              padding: '12px',
              borderRadius: '12px',
              background: 'rgba(255, 255, 255, 0.08)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: '8px',
            }}
          >
            <div style={{ minWidth: 0 }}>
              <strong style={{ fontSize: '13px', display: 'block', color: '#fff', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {user.name}
              </strong>
              <small style={{ color: 'rgba(255, 255, 255, 0.65)', fontSize: '11px', display: 'block', textTransform: 'capitalize' }}>
                Role: {user.role || 'Admin'}
              </small>
            </div>
            <button
              type="button"
              onClick={handleLogout}
              style={{
                border: 'none',
                background: '#c23a4b',
                color: '#ffffff',
                padding: '6px 12px',
                borderRadius: '8px',
                fontSize: '12px',
                fontWeight: '700',
                cursor: 'pointer',
                flexShrink: 0,
                display: 'inline-flex',
                alignItems: 'center',
                gap: '4px',
              }}
            >
              <LogOut size={13} />
              Logout
            </button>
          </div>
        )}
      </aside>

      {/* Simple Dashboard Guide Modal */}
      {showGuideModal && (
        <div className="modal-overlay" onClick={() => setShowGuideModal(false)}>
          <div
            className="modal-card"
            style={{ maxWidth: '620px', borderRadius: '16px' }}
            onClick={(e) => e.stopPropagation()}
          >
            <div className="modal-header" style={{ padding: '20px 24px' }}>
              <h3 style={{ fontSize: '18px', fontWeight: '700', color: '#0f172a' }}>
                Simple dashboard guide
              </h3>
              <button
                type="button"
                className="modal-close-btn"
                onClick={() => setShowGuideModal(false)}
                style={{
                  background: '#f1f5f9',
                  border: 'none',
                  borderRadius: '8px',
                  width: '32px',
                  height: '32px',
                  fontSize: '16px',
                  cursor: 'pointer',
                  display: 'grid',
                  placeItems: 'center',
                }}
              >
                <X size={18} />
              </button>
            </div>

            <div className="modal-body" style={{ padding: '16px 24px 24px' }}>
              <div style={{ display: 'grid', gap: '18px' }}>
                {steps.map((step) => (
                  <div key={step.num} style={{ display: 'flex', gap: '16px', alignItems: 'flex-start' }}>
                    <div className="modal-step-badge">{step.num}</div>
                    <div>
                      <strong style={{ fontSize: '14px', fontWeight: '700', color: '#0f172a', display: 'block' }}>
                        {step.title}
                      </strong>
                      <p style={{ margin: '3px 0 0', fontSize: '13px', color: '#64748b', lineHeight: '1.4' }}>
                        {step.desc}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="modal-footer" style={{ padding: '16px 24px', borderTop: 'none', justifyContent: 'flex-end' }}>
              <button
                type="button"
                className="btn btn-primary"
                onClick={() => setShowGuideModal(false)}
                style={{
                  padding: '10px 28px',
                  borderRadius: '10px',
                  fontWeight: '700',
                  fontSize: '14px',
                  background: '#2457e6',
                }}
              >
                Understood
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
