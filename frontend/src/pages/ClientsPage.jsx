import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import axiosInstance from '../api/axiosInstance';
import { useWorkspace } from '../context/WorkspaceContext';
import { Plus, Search, X, CheckCircle2, Building2, AlertTriangle, Layers, ArrowRight } from 'lucide-react';

const CHANNELS = ['Facebook', 'Instagram', 'YouTube', 'X / Twitter', 'Google Analytics', 'Search Console'];

const emptyForm = {
  name: '',
  industry: '',
  primary_contact: '',
  primary_contact_email: '',
  budget: '',
  status: 'active',
};

export default function ClientsPage() {
  const { workspaces, setSelectedWorkspaceId, fetchWorkspaces, loadingWorkspaces } = useWorkspace();
  const navigate = useNavigate();
  const [searchTerm, setSearchTerm] = useState('');
  const [statusFilter, setStatusFilter] = useState('all'); // 'all', 'active', 'setup_pending'
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState(emptyForm);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');
  const [successMsg, setSuccessMsg] = useState('');

  const openModal = () => {
    setForm(emptyForm);
    setError('');
    setSuccessMsg('');
    setShowModal(true);
  };

  const closeModal = () => {
    setShowModal(false);
    setError('');
  };

  // Helper: compute initials from workspace name
  const getInitials = (name) => {
    if (!name) return 'WS';
    const parts = name.trim().split(/\s+/);
    return parts.length === 1
      ? name.slice(0, 2).toUpperCase()
      : (parts[0][0] + parts[1][0]).toUpperCase();
  };

  // Helper: format numbers
  const formatNum = (n) => {
    if (n === null || n === undefined) return '0';
    const num = Number(n);
    if (isNaN(num)) return '0';
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return String(num);
  };

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    if (!form.name.trim()) {
      setError('Client / business name is required.');
      return;
    }
    setSubmitting(true);
    try {
      const res = await axiosInstance.post('/workspaces', {
        name: form.name.trim(),
        industry: form.industry ? form.industry.trim() : 'General',
        primary_contact: form.primary_contact ? form.primary_contact.trim() : 'Primary Contact',
        primary_contact_email: form.primary_contact_email && form.primary_contact_email.trim() ? form.primary_contact_email.trim() : null,
        budget: form.budget ? Number(form.budget) : 0,
        status: form.status || 'active',
      });

      if (res.data.success) {
        setSuccessMsg(`"${res.data.data.name}" workspace created!`);
        if (fetchWorkspaces) fetchWorkspaces();
        setTimeout(() => {
          closeModal();
          setSuccessMsg('');
        }, 1200);
      }
    } catch (err) {
      const msg = err.response?.data?.message || (err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat().join(', ')
        : 'Failed to create workspace.');
      setError(typeof msg === 'string' ? msg : JSON.stringify(msg));
    } finally {
      setSubmitting(false);
    }
  };

  const handleOpenWorkspace = (wsId) => {
    setSelectedWorkspaceId(wsId);
    navigate('/');
  };

  // Helper predicates for client operational status
  const isClientActive = (w) => {
    const s = (w.status || 'active').toLowerCase();
    const channels = w.channels_count ?? 0;
    return s === 'active' && channels > 0;
  };

  const isClientPending = (w) => {
    const s = (w.status || '').toLowerCase();
    const channels = w.channels_count ?? 0;
    return s === 'setup' || s === 'pending' || channels === 0;
  };

  // Compute aggregate real metrics
  const clientList = workspaces || [];
  const totalClients = clientList.length;
  const activeClients = clientList.filter(isClientActive).length;
  const totalConnectedAccounts = clientList.reduce((acc, w) => acc + (w.channels_count || 0), 0);
  const setupPendingClients = clientList.filter(isClientPending).length;

  const filteredWorkspaces = clientList.filter((ws) => {
    const term = searchTerm.toLowerCase();
    const matchesSearch = (
      ws.name?.toLowerCase().includes(term) ||
      ws.industry?.toLowerCase().includes(term) ||
      ws.primary_contact?.toLowerCase().includes(term) ||
      ws.primary_contact_email?.toLowerCase().includes(term)
    );

    if (!matchesSearch) return false;

    if (statusFilter === 'active') {
      return isClientActive(ws);
    }
    if (statusFilter === 'setup_pending') {
      return isClientPending(ws);
    }

    return true;
  });

  const getStatusClass = (status, channelCount) => {
    if (channelCount === 0) return 'pill warning';
    const s = (status || '').toLowerCase();
    if (s === 'setup' || s === 'pending') return 'pill warning';
    if (s === 'active') return 'pill success';
    return 'pill';
  };

  const getStatusLabel = (status, channelCount) => {
    if (channelCount === 0) return 'Setup pending';
    const s = (status || '').toLowerCase();
    if (s === 'active') return 'Active';
    if (s === 'setup') return 'Setup';
    if (s === 'pending') return 'Pending';
    return status || 'Active';
  };

  return (
    <div>
      {/* Page Header */}
      <div className="section-head">
        <div>
          <h2>Client workspaces</h2>
          <p>Each client's channels, leads, content, and reports stay completely isolated.</p>
        </div>
        <div className="toolbar">
          <button type="button" className="btn btn-primary" onClick={openModal} style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}>
            <Plus size={16} /> Add new client
          </button>
        </div>
      </div>

      {/* Metrics Header Grid */}
      <div className="metric-grid">
        <div className="metric-card">
          <div className="metric-label">Total clients</div>
          <div className="metric-value">{totalClients}</div>
          <div className="metric-foot">Isolated workspaces</div>
        </div>
        <div className="metric-card">
          <div className="metric-label">Active clients</div>
          <div className="metric-value">{activeClients}</div>
          <div className="metric-foot">
            <span className="trend-up" style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
              <CheckCircle2 size={13} /> Active workspaces
            </span>
          </div>
        </div>
        <div className="metric-card">
          <div className="metric-label">Connected accounts</div>
          <div className="metric-value">{totalConnectedAccounts}</div>
          <div className="metric-foot">Live social & marketing channels</div>
        </div>
        <div className="metric-card">
          <div className="metric-label">Setup pending</div>
          <div className="metric-value">{setupPendingClients}</div>
          <div className="metric-foot">
            <span className={setupPendingClients > 0 ? 'trend-down' : 'trend-up'}>
              {setupPendingClients > 0 ? 'Channels not connected' : 'All setup'}
            </span>
          </div>
        </div>
      </div>

      {/* All Clients Section */}
      <section className="panel">
        <div className="panel-header" style={{ flexWrap: 'wrap', gap: '12px' }}>
          <div className="panel-title">
            <h3>All clients</h3>
            <p>Select a workspace to manage its channels and campaigns</p>
          </div>
          <div className="panel-actions" style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
            {/* Filter Tabs */}
            <div style={{ display: 'inline-flex', background: '#f1f5f9', borderRadius: '8px', padding: '3px', gap: '2px' }}>
              <button
                type="button"
                onClick={() => setStatusFilter('all')}
                style={{
                  padding: '4px 10px',
                  borderRadius: '6px',
                  border: 'none',
                  fontSize: '12px',
                  fontWeight: '600',
                  cursor: 'pointer',
                  background: statusFilter === 'all' ? '#ffffff' : 'transparent',
                  color: statusFilter === 'all' ? '#0f172a' : '#64748b',
                  boxShadow: statusFilter === 'all' ? '0 1px 2px rgba(0,0,0,0.06)' : 'none',
                }}
              >
                All ({totalClients})
              </button>
              <button
                type="button"
                onClick={() => setStatusFilter('active')}
                style={{
                  padding: '4px 10px',
                  borderRadius: '6px',
                  border: 'none',
                  fontSize: '12px',
                  fontWeight: '600',
                  cursor: 'pointer',
                  background: statusFilter === 'active' ? '#ffffff' : 'transparent',
                  color: statusFilter === 'active' ? '#0f172a' : '#64748b',
                  boxShadow: statusFilter === 'active' ? '0 1px 2px rgba(0,0,0,0.06)' : 'none',
                }}
              >
                Active ({activeClients})
              </button>
              <button
                type="button"
                onClick={() => setStatusFilter('setup_pending')}
                style={{
                  padding: '4px 10px',
                  borderRadius: '6px',
                  border: 'none',
                  fontSize: '12px',
                  fontWeight: '600',
                  cursor: 'pointer',
                  background: statusFilter === 'setup_pending' ? '#ffffff' : 'transparent',
                  color: statusFilter === 'setup_pending' ? '#0f172a' : '#64748b',
                  boxShadow: statusFilter === 'setup_pending' ? '0 1px 2px rgba(0,0,0,0.06)' : 'none',
                }}
              >
                Setup Pending ({setupPendingClients})
              </button>
            </div>

            {/* Search input */}
            <div className="search-box">
              <span style={{ display: 'inline-flex', alignItems: 'center' }}><Search size={16} /></span>
              <input
                type="search"
                placeholder="Search client, contact, industry..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
          </div>
        </div>

        {/* Client Cards Grid */}
        <div className="client-card-grid">
          {loadingWorkspaces ? (
            <p style={{ color: '#64748b', padding: '24px 0' }}>Loading client workspaces...</p>
          ) : filteredWorkspaces.length === 0 ? (
            <div style={{ padding: '36px 0', textAlign: 'center', color: '#64748b', width: '100%' }}>
              <Building2 size={36} color="#94a3b8" style={{ margin: '0 auto 8px', display: 'block' }} />
              <p style={{ margin: 0, fontWeight: 600 }}>No client workspaces match your filter.</p>
            </div>
          ) : (
            filteredWorkspaces.map((ws) => {
              const initials = getInitials(ws.name);
              const channelCount = ws.channels_count ?? 0;
              const leadsCount = ws.leads_count ?? 0;
              const reachCount = ws.reach_count ?? 0;
              const connectedChannels = ws.connected_channels || [];

              return (
                <div className="client-card" key={ws.id} style={{ display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
                  <div>
                    <div className="client-card-head" style={{ alignItems: 'center', marginBottom: 12 }}>
                      <div className="initial" style={{ width: 38, height: 38, borderRadius: 10, display: 'grid', placeItems: 'center', fontWeight: 800, background: '#eaf0ff', color: '#2457e6', fontSize: '0.9rem' }}>
                        {initials}
                      </div>
                      <span className={getStatusClass(ws.status, channelCount)}>
                        {getStatusLabel(ws.status, channelCount)}
                      </span>
                    </div>

                    <h3 style={{ margin: '0 0 4px', fontSize: '1rem', fontWeight: 700 }}>{ws.name}</h3>
                    <p className="muted" style={{ fontSize: '0.8rem', margin: '0 0 12px' }}>
                      {ws.industry || 'General'} • Contact: {ws.primary_contact || 'N/A'}
                    </p>

                    {/* Channel Badges List */}
                    <div style={{ marginBottom: '14px', minHeight: '22px' }}>
                      {channelCount === 0 ? (
                        <span style={{ fontSize: '11px', color: '#d97706', background: '#fffbeb', border: '1px solid #fef3c7', padding: '2px 8px', borderRadius: '4px', display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
                          <AlertTriangle size={12} /> 0 Channels connected
                        </span>
                      ) : (
                        <div style={{ display: 'flex', gap: '4px', flexWrap: 'wrap' }}>
                          {connectedChannels.map((ch, idx) => (
                            <span
                              key={idx}
                              style={{
                                fontSize: '10px',
                                fontWeight: '700',
                                padding: '2px 6px',
                                borderRadius: '4px',
                                background: ch === 'Facebook' ? '#eff6ff' : ch === 'Instagram' ? '#fdf2f8' : ch === 'YouTube' ? '#fef2f2' : ch === 'Twitter' ? '#f8fafc' : '#f0fdf4',
                                color: ch === 'Facebook' ? '#1d4ed8' : ch === 'Instagram' ? '#be185d' : ch === 'YouTube' ? '#dc2626' : ch === 'Twitter' ? '#0f172a' : '#16a34a',
                                border: '1px solid currentColor',
                                opacity: 0.85,
                              }}
                            >
                              {ch}
                            </span>
                          ))}
                        </div>
                      )}
                    </div>

                    {/* Metrics Grid */}
                    <div className="client-card-metrics" style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8, margin: '14px 0' }}>
                      <div className="client-mini" style={{ background: '#f8fafc', padding: 8, borderRadius: 8, textAlign: 'center' }}>
                        <strong style={{ display: 'block', fontSize: '0.9rem', color: '#0f172a' }}>{formatNum(reachCount)}</strong>
                        <span style={{ fontSize: '0.7rem', color: '#64748b' }}>Reach</span>
                      </div>
                      <div className="client-mini" style={{ background: '#f8fafc', padding: 8, borderRadius: 8, textAlign: 'center' }}>
                        <strong style={{ display: 'block', fontSize: '0.9rem', color: '#0f172a' }}>{leadsCount}</strong>
                        <span style={{ fontSize: '0.7rem', color: '#64748b' }}>Leads</span>
                      </div>
                      <div className="client-mini" style={{ background: '#f8fafc', padding: 8, borderRadius: 8, textAlign: 'center' }}>
                        <strong style={{ display: 'block', fontSize: '0.9rem', color: channelCount === 0 ? '#d97706' : '#0f172a' }}>
                          {channelCount}
                        </strong>
                        <span style={{ fontSize: '0.7rem', color: '#64748b' }}>Channels</span>
                      </div>
                    </div>
                  </div>

                  <button
                    type="button"
                    className="btn btn-secondary btn-block"
                    onClick={() => handleOpenWorkspace(ws.id)}
                    style={{ marginTop: 12, width: '100%', fontWeight: 600, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '6px' }}
                  >
                    Open workspace <ArrowRight size={14} />
                  </button>
                </div>
              );
            })
          )}
        </div>
      </section>

      {/* Add Client Modal */}
      {showModal && (
        <div
          className="modal-backdrop"
          onClick={(e) => { if (e.target === e.currentTarget) closeModal(); }}
        >
          <div className="modal">
            <div className="modal-head">
              <h3 id="clientModalTitle">Add new client workspace</h3>
              <button type="button" className="modal-close" onClick={closeModal} aria-label="Close">
                <X size={18} />
              </button>
            </div>

            <form onSubmit={handleSubmit}>
              <div className="modal-body">
                {successMsg && (
                  <div style={{ background: '#dcfce7', color: '#15803d', padding: '0.7rem 1rem', borderRadius: 8, marginBottom: '1rem', fontSize: '0.875rem', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <CheckCircle2 size={16} />
                    <span>{successMsg}</span>
                  </div>
                )}
                {error && (
                  <div style={{ background: '#fee2e2', color: '#dc2626', padding: '0.7rem 1rem', borderRadius: 8, marginBottom: '1rem', fontSize: '0.875rem' }}>
                    {error}
                  </div>
                )}

                <div className="form-grid">
                  <div className="form-field full">
                    <label className="form-label" htmlFor="newClientName">Client / Business Name *</label>
                    <input
                      className="input"
                      id="newClientName"
                      name="name"
                      value={form.name}
                      onChange={handleChange}
                      placeholder="e.g. Acme Corporation"
                      required
                    />
                  </div>
                  <div className="form-field">
                    <label className="form-label" htmlFor="newClientIndustry">Industry</label>
                    <input
                      className="input"
                      id="newClientIndustry"
                      name="industry"
                      value={form.industry}
                      onChange={handleChange}
                      placeholder="e.g. Software, Retail, Healthcare"
                    />
                  </div>
                  <div className="form-field">
                    <label className="form-label" htmlFor="newClientOwner">Primary Contact Person</label>
                    <input
                      className="input"
                      id="newClientOwner"
                      name="primary_contact"
                      value={form.primary_contact}
                      onChange={handleChange}
                      placeholder="e.g. John Doe"
                    />
                  </div>
                  <div className="form-field">
                    <label className="form-label" htmlFor="newClientEmail">Primary Contact Email</label>
                    <input
                      type="email"
                      className="input"
                      id="newClientEmail"
                      name="primary_contact_email"
                      value={form.primary_contact_email}
                      onChange={handleChange}
                      placeholder="e.g. contact@client.com"
                    />
                  </div>
                  <div className="form-field">
                    <label className="form-label" htmlFor="newClientBudget">Monthly Marketing Budget</label>
                    <input
                      type="number"
                      className="input"
                      id="newClientBudget"
                      name="budget"
                      value={form.budget}
                      onChange={handleChange}
                      placeholder="e.g. 15000"
                    />
                  </div>
                </div>
              </div>

              <div className="modal-foot">
                <button type="button" className="btn btn-secondary" onClick={closeModal}>
                  Cancel
                </button>
                <button type="submit" className="btn btn-primary" disabled={submitting}>
                  {submitting ? 'Creating…' : 'Create workspace'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
