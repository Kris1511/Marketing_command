import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import axios from 'axios';
import { useWorkspace } from '../context/WorkspaceContext';

const CHANNELS = ['Instagram', 'Facebook', 'YouTube', 'Google Analytics', 'Search Console', 'Google Business'];

const emptyForm = {
  name: '',
  industry: '',
  primary_contact: '',
  primary_contact_email: '',
  budget: '',
  status: 'active',
  channels: [],
};

function getInitials(name) {
  if (!name) return 'WS';
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) {
    return name.slice(0, 2).toUpperCase();
  }
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

const DEMO_CLIENT_METRICS = {
  'Aara Wellness': { reach: '128.4K', leads: 186, channels: 4 },
  'Fast Logistics': { reach: '84.2K', leads: 104, channels: 4 },
};

export default function ClientsPage() {
  const { workspaces, setActiveWorkspaceId, fetchWorkspaces, loading } = useWorkspace();
  const navigate = useNavigate();

  const [searchTerm, setSearchTerm] = useState('');
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

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
  };

  const handleChannelToggle = (ch) => {
    setForm((prev) => ({
      ...prev,
      channels: prev.channels.includes(ch)
        ? prev.channels.filter((c) => c !== ch)
        : [...prev.channels, ch],
    }));
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
      const res = await axios.post('/api/v1/workspaces', {
        name: form.name.trim(),
        industry: form.industry ? form.industry.trim() : null,
        primary_contact: form.primary_contact ? form.primary_contact.trim() : null,
        primary_contact_email: form.primary_contact_email && form.primary_contact_email.trim() ? form.primary_contact_email.trim() : null,
        budget: form.budget || 0,
        status: form.status || 'active',
      });

      if (res.data.success) {
        setSuccessMsg(`✅ "${res.data.data.name}" workspace created!`);
        fetchWorkspaces();
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
    setActiveWorkspaceId(wsId);
    navigate('/');
  };

  const filteredWorkspaces = workspaces.filter((ws) => {
    const term = searchTerm.toLowerCase();
    return (
      ws.name?.toLowerCase().includes(term) ||
      ws.industry?.toLowerCase().includes(term) ||
      ws.primary_contact?.toLowerCase().includes(term)
    );
  });

  const getStatusClass = (status) => {
    const s = (status || '').toLowerCase();
    if (s === 'active') return 'pill success';
    if (s === 'setup' || s === 'pending') return 'pill warning';
    return 'pill';
  };

  return (
    <div>
      {/* Page Header */}
      <div className="section-head">
        <div>
          <h2>Client workspaces</h2>
          <p>Each client's channels, leads, content, and reports stay separate.</p>
        </div>
        <div className="toolbar">
          <button type="button" className="btn btn-primary" onClick={openModal}>
            + Add new client
          </button>
        </div>
      </div>

      {/* Metrics Header Grid */}
      <div className="metric-grid">
        <div className="metric-card">
          <div className="metric-label">Total clients</div>
          <div className="metric-value">{workspaces.length}</div>
          <div className="metric-foot">All isolated workspaces</div>
        </div>
        <div className="metric-card">
          <div className="metric-label">Active clients</div>
          <div className="metric-value">{workspaces.filter((w) => (w.status || 'active').toLowerCase() === 'active').length}</div>
          <div className="metric-foot">
            <span className="trend-up">● Healthy</span>
          </div>
        </div>
        <div className="metric-card">
          <div className="metric-label">Connected accounts</div>
          <div className="metric-value">13</div>
          <div className="metric-foot">Across Phase 1 channels</div>
        </div>
        <div className="metric-card">
          <div className="metric-label">Setup pending</div>
          <div className="metric-value">{workspaces.filter((w) => (w.status || '').toLowerCase() === 'setup' || (w.status || '').toLowerCase() === 'pending').length}</div>
          <div className="metric-foot">
            <span className="trend-down">Action required</span>
          </div>
        </div>
      </div>

      {/* All Clients Section */}
      <section className="panel">
        <div className="panel-header">
          <div className="panel-title">
            <h3>All clients</h3>
            <p>Select a workspace to view or manage it</p>
          </div>
          <div className="panel-actions">
            <div className="search-box">
              <span>⌕</span>
              <input
                type="search"
                placeholder="Search client or industry"
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
              />
            </div>
          </div>
        </div>

        {/* Client Cards Grid matching Screenshot 1 design */}
        <div className="client-card-grid">
          {loading ? (
            <p style={{ color: '#64748b' }}>Loading client workspaces...</p>
          ) : filteredWorkspaces.length === 0 ? (
            <p style={{ color: '#64748b' }}>No client workspaces found.</p>
          ) : (
            filteredWorkspaces.map((ws) => {
              const initials = getInitials(ws.name);
              const demoM = DEMO_CLIENT_METRICS[ws.name] || { reach: '0', leads: 0, channels: 0 };
              const reachVal = ws.reach_formatted || demoM.reach;
              const leadsVal = ws.leads_count ?? demoM.leads;
              const channelsVal = ws.channels_count ?? demoM.channels;

              return (
                <div className="client-card" key={ws.id} style={{ display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
                  <div>
                    <div className="client-card-head" style={{ alignItems: 'center', marginBottom: 12 }}>
                      <div className="initial" style={{ width: 38, height: 38, borderRadius: 10, display: 'grid', placeItems: 'center', fontWeight: 800, background: '#eaf0ff', color: '#2457e6', fontSize: '0.9rem' }}>
                        {initials}
                      </div>
                      <span className={getStatusClass(ws.status)} style={{ textTransform: 'capitalize' }}>
                        {ws.status || 'Active'}
                      </span>
                    </div>

                    <h3 style={{ margin: '0 0 4px', fontSize: '1rem', fontWeight: 700 }}>{ws.name}</h3>
                    <p className="muted" style={{ fontSize: '0.8rem', margin: '0 0 16px' }}>
                      {ws.industry || 'General'} • Contact: {ws.primary_contact || 'N/A'}
                    </p>

                    <div className="client-card-metrics" style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 8, margin: '14px 0' }}>
                      <div className="client-mini" style={{ background: '#f8fafc', padding: 8, borderRadius: 8, textAlign: 'center' }}>
                        <strong style={{ display: 'block', fontSize: '0.9rem', color: '#0f172a' }}>
                          {reachVal}
                        </strong>
                        <span style={{ fontSize: '0.7rem', color: '#64748b' }}>Reach</span>
                      </div>
                      <div className="client-mini" style={{ background: '#f8fafc', padding: 8, borderRadius: 8, textAlign: 'center' }}>
                        <strong style={{ display: 'block', fontSize: '0.9rem', color: '#0f172a' }}>
                          {leadsVal}
                        </strong>
                        <span style={{ fontSize: '0.7rem', color: '#64748b' }}>Leads</span>
                      </div>
                      <div className="client-mini" style={{ background: '#f8fafc', padding: 8, borderRadius: 8, textAlign: 'center' }}>
                        <strong style={{ display: 'block', fontSize: '0.9rem', color: '#0f172a' }}>
                          {channelsVal}
                        </strong>
                        <span style={{ fontSize: '0.7rem', color: '#64748b' }}>Channels</span>
                      </div>
                    </div>
                  </div>

                  <button
                    type="button"
                    className="btn btn-secondary btn-block"
                    onClick={() => handleOpenWorkspace(ws.id)}
                    style={{ marginTop: 12, width: '100%', fontWeight: 600 }}
                  >
                    Open workspace
                  </button>
                </div>
              );
            })
          )}
        </div>
      </section>

      {/* ── Add Client Modal ── */}
      {showModal && (
        <div
          className="modal-backdrop"
          onClick={(e) => { if (e.target === e.currentTarget) closeModal(); }}
        >
          <div className="modal">
            <div className="modal-head">
              <h3 id="clientModalTitle">Add new client workspace</h3>
              <button type="button" className="modal-close" onClick={closeModal} aria-label="Close">
                ×
              </button>
            </div>

            <form onSubmit={handleSubmit}>
              <div className="modal-body">
                {successMsg && (
                  <div style={{ background: '#dcfce7', color: '#15803d', padding: '0.7rem 1rem', borderRadius: 8, marginBottom: '1rem', fontSize: '0.875rem' }}>
                    {successMsg}
                  </div>
                )}
                {error && (
                  <div style={{ background: '#fee2e2', color: '#dc2626', padding: '0.7rem 1rem', borderRadius: 8, marginBottom: '1rem', fontSize: '0.875rem' }}>
                    {error}
                  </div>
                )}

                <div className="form-grid">
                  <div className="form-field">
                    <label className="form-label" htmlFor="newClientName">Client / business name</label>
                    <input
                      className="input"
                      id="newClientName"
                      name="name"
                      value={form.name}
                      onChange={handleChange}
                      placeholder="Example: ABC Retail"
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
                      placeholder="Example: Retail"
                    />
                  </div>
                  <div className="form-field">
                    <label className="form-label" htmlFor="newClientOwner">Primary contact</label>
                    <input
                      className="input"
                      id="newClientOwner"
                      name="primary_contact"
                      value={form.primary_contact}
                      onChange={handleChange}
                      placeholder="Contact person name"
                    />
                  </div>
                  <div className="form-field">
                    <label className="form-label" htmlFor="newClientTeam">Assigned team member</label>
                    <select
                      className="select"
                      id="newClientTeam"
                      name="assigned_team"
                      value={form.assigned_team || 'Nisha V'}
                      onChange={handleChange}
                    >
                      <option value="Nisha V">Nisha V</option>
                      <option value="Kavin R">Kavin R</option>
                      <option value="Vijay M">Vijay M</option>
                    </select>
                  </div>
                  <div className="form-field full">
                    <label className="form-label">Required channels</label>
                    <div className="check-grid">
                      {CHANNELS.map((ch) => (
                        <label key={ch} className="check-card">
                          <input
                            type="checkbox"
                            checked={form.channels.includes(ch)}
                            onChange={() => handleChannelToggle(ch)}
                          />
                          {ch}
                        </label>
                      ))}
                    </div>
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


const labelStyle = { fontSize: '0.8rem', fontWeight: 600, color: '#374151', marginBottom: '0.3rem', display: 'block' };
const inputStyle = { width: '100%', padding: '0.55rem 0.8rem', border: '1px solid #d1d5db', borderRadius: 8, fontSize: '0.875rem', boxSizing: 'border-box', outline: 'none', color: '#111', background: '#f9fafb' };
