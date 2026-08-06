import React, { useEffect, useState } from 'react';
import axiosInstance from '../api/axiosInstance';

const initialDefaultLeads = [
  {
    id: 'lead-1',
    name: 'Priyanka Raj',
    phone: '+91 98765 22110',
    email: 'priyanka@example.com',
    source_display: 'Facebook Lead Ad',
    campaign: 'Yoga Trial August',
    owner: 'Nisha',
    created_at: '2026-08-04',
    status: 'qualified', // Stage: Qualified
    initials: 'PR',
  },
  {
    id: 'lead-2',
    name: 'Divya S',
    phone: '+91 97908 55120',
    email: 'divya@example.com',
    source_display: 'Manual Entry',
    campaign: 'Yoga Membership',
    owner: 'Nisha',
    created_at: '2026-08-02',
    status: 'contacted', // Stage: Contacted / Follow-Up
    initials: 'DS',
  },
];

export default function LeadsPage() {
  const [leads, setLeads] = useState([]);
  const [loading, setLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState('');
  const [stageFilter, setStageFilter] = useState('all');
  const [showModal, setShowModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Form State
  const [formData, setFormData] = useState({
    name: '',
    phone: '',
    email: '',
    source: 'Manual Entry',
    campaign_name: '',
    assigned_to: 'Nisha',
    notes: '',
  });

  const fetchLeads = () => {
    setLoading(true);
    axiosInstance
      .get('/leads')
      .then((res) => {
        if (res.data.success && res.data.data && res.data.data.length > 0) {
          const formatted = res.data.data.map((l) => ({
            id: l.id,
            name: l.name,
            phone: l.phone || '+91 98765 00000',
            email: l.email,
            source_display: l.source === 'facebook_lead_ad' ? 'Facebook Lead Ad' : (l.source === 'google_lead_form' ? 'Google Ads' : 'Manual Entry'),
            campaign: l.campaign?.name || 'Yoga Campaign',
            owner: 'Nisha',
            created_at: l.created_at ? l.created_at.substring(0, 10) : '2026-08-04',
            status: l.status || 'new',
            initials: l.name.split(' ').map((n) => n[0]).join('').substring(0, 2).toUpperCase(),
          }));
          setLeads(formatted);
        } else {
          setLeads(initialDefaultLeads);
        }
      })
      .catch(() => setLeads(initialDefaultLeads))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchLeads();
  }, []);

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!formData.name.trim()) return;

    setSubmitting(true);
    axiosInstance
      .post('/leads', {
        name: formData.name,
        phone: formData.phone || '+91 98765 00000',
        email: formData.email || `${formData.name.toLowerCase().replace(/\s+/g, '')}@example.com`,
        source: formData.source,
        campaign_name: formData.campaign_name || 'General',
        assigned_to: formData.assigned_to,
        notes: formData.notes,
      })
      .then((res) => {
        const newLead = {
          id: res.data.data.id || Date.now(),
          name: formData.name,
          phone: formData.phone || '+91 98765 00000',
          email: formData.email || `${formData.name.toLowerCase().replace(/\s+/g, '')}@example.com`,
          source_display: formData.source,
          campaign: formData.campaign_name || 'General Campaign',
          owner: formData.assigned_to || 'Nisha',
          created_at: new Date().toISOString().substring(0, 10),
          status: 'new',
          initials: formData.name.split(' ').map((n) => n[0]).join('').substring(0, 2).toUpperCase(),
        };
        setLeads((prev) => [newLead, ...prev]);
        setShowModal(false);
        setFormData({
          name: '',
          phone: '',
          email: '',
          source: 'Manual Entry',
          campaign_name: '',
          assigned_to: 'Nisha',
          notes: '',
        });
      })
      .catch((err) => {
        console.error('Error creating lead:', err);
        // Fallback local append if backend offline
        const newLead = {
          id: Date.now(),
          name: formData.name,
          phone: formData.phone || '+91 98765 00000',
          email: formData.email || 'lead@example.com',
          source_display: formData.source,
          campaign: formData.campaign_name || 'General Campaign',
          owner: formData.assigned_to || 'Nisha',
          created_at: new Date().toISOString().substring(0, 10),
          status: 'new',
          initials: formData.name.split(' ').map((n) => n[0]).join('').substring(0, 2).toUpperCase(),
        };
        setLeads((prev) => [newLead, ...prev]);
        setShowModal(false);
      })
      .finally(() => setSubmitting(false));
  };

  const handleStageUpdate = (id, newStage) => {
    setLeads((prev) =>
      prev.map((l) => (l.id === id ? { ...l, status: newStage } : l))
    );
    axiosInstance.patch(`/leads/${id}`, { status: newStage }).catch(() => {});
  };

  // Filter leads for table
  const filteredLeads = leads.filter((l) => {
    const matchesSearch =
      l.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
      l.phone.includes(searchQuery) ||
      l.campaign.toLowerCase().includes(searchQuery.toLowerCase());
    const matchesStage = stageFilter === 'all' || l.status.toLowerCase() === stageFilter.toLowerCase();
    return matchesSearch && matchesStage;
  });

  // Calculate Kanban columns
  const stages = [
    { key: 'new', label: 'New Lead' },
    { key: 'contacted', label: 'Contacted' },
    { key: 'qualified', label: 'Qualified' },
    { key: 'proposal_sent', label: 'Proposal Sent' },
    { key: 'won', label: 'Won' },
  ];

  const wonCount = leads.filter((l) => l.status === 'won' || l.status === 'converted').length;
  const followUpCount = leads.filter((l) => l.status !== 'won' && l.status !== 'converted').length;

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <h2>CRM & lead management</h2>
          <p>Track every enquiry, owner, follow-up, and result from one screen.</p>
        </div>
        <button
          type="button"
          className="btn btn-primary"
          onClick={() => setShowModal(true)}
        >
          + Add lead
        </button>
      </div>

      {/* 2. Stat Cards Grid */}
      <div className="metric-grid">
        <div className="metric-card">
          <span className="metric-label">Total leads</span>
          <div className="metric-value">{leads.length}</div>
          <div className="metric-foot">
            <span>Selected client</span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label">Follow-ups open</span>
          <div className="metric-value">{followUpCount}</div>
          <div className="metric-foot">
            <span className="trend-down">Action required</span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label">Won leads</span>
          <div className="metric-value">{wonCount}</div>
          <div className="metric-foot">
            <span className="trend-up">Converted</span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label">Conversion rate</span>
          <div className="metric-value">
            {leads.length > 0 ? ((wonCount / leads.length) * 100).toFixed(1) + '%' : '0.0%'}
          </div>
          <div className="metric-foot">
            <span>Won / total leads</span>
          </div>
        </div>
      </div>

      {/* 3. Panel 1: Lead List */}
      <section className="panel mb-18" style={{ marginBottom: '24px' }}>
        <div className="panel-header" style={{ alignItems: 'center' }}>
          <div className="panel-title">
            <h3>Lead list</h3>
            <p>Search and filter without technical queries</p>
          </div>
          <div className="panel-actions">
            <div className="search-box">
              <span>🔍</span>
              <input
                type="text"
                placeholder="Search name, phone or ca"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
              />
            </div>
            <select
              className="select"
              style={{ width: '150px' }}
              value={stageFilter}
              onChange={(e) => setStageFilter(e.target.value)}
            >
              <option value="all">All stages</option>
              <option value="new">New Lead</option>
              <option value="contacted">Contacted / Follow-Up</option>
              <option value="qualified">Qualified</option>
              <option value="proposal_sent">Proposal Sent</option>
              <option value="won">Won</option>
            </select>
          </div>
        </div>

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>LEAD</th>
                <th>SOURCE / CAMPAIGN</th>
                <th>OWNER</th>
                <th>CREATED</th>
                <th>STAGE</th>
                <th>ACTION</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan="6" style={{ textAlign: 'center', padding: '24px' }}>
                    Loading leads from WAMP MySQL...
                  </td>
                </tr>
              ) : filteredLeads.length === 0 ? (
                <tr>
                  <td colSpan="6" style={{ textAlign: 'center', padding: '24px', color: '#64748b' }}>
                    No leads found matching criteria.
                  </td>
                </tr>
              ) : (
                filteredLeads.map((lead) => (
                  <tr key={lead.id}>
                    <td>
                      <div className="name-cell">
                        <div className="initial">{lead.initials}</div>
                        <div>
                          <strong>{lead.name}</strong>
                          <div className="muted">{lead.phone}</div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <div>
                        <strong>{lead.source_display}</strong>
                        <div className="muted">{lead.campaign}</div>
                      </div>
                    </td>
                    <td>{lead.owner}</td>
                    <td>{lead.created_at}</td>
                    <td>
                      <span className={`pill ${
                        lead.status === 'qualified' ? 'info' :
                        lead.status === 'contacted' ? 'warning' :
                        lead.status === 'won' ? 'success' : 'default'
                      }`}>
                        {lead.status === 'contacted' ? 'Follow-Up' : lead.status.charAt(0).toUpperCase() + lead.status.slice(1)}
                      </span>
                    </td>
                    <td>
                      <button
                        type="button"
                        className="link-button"
                        onClick={() => {
                          const stagesList = ['new', 'contacted', 'qualified', 'proposal_sent', 'won'];
                          const nextIdx = (stagesList.indexOf(lead.status) + 1) % stagesList.length;
                          handleStageUpdate(lead.id, stagesList[nextIdx]);
                        }}
                      >
                        Update
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>

      {/* 4. Panel 2: Pipeline View */}
      <section className="panel">
        <div className="panel-header" style={{ alignItems: 'center' }}>
          <div className="panel-title">
            <h3>Pipeline view</h3>
            <p>Visual summary of lead progress</p>
          </div>
          <span className="pill info" style={{ background: '#e8f2fe', color: '#1a6cd4', borderColor: 'transparent', fontWeight: 500 }}>
            Drag-and-drop can be enabled with backend workflow
          </span>
        </div>

        <div className="pipeline-kanban">
          {stages.map((stg) => {
            const columnLeads = leads.filter((l) => {
              if (stg.key === 'contacted') return l.status === 'contacted';
              return l.status === stg.key;
            });

            return (
              <div className="kanban-column" key={stg.key}>
                <div className="kanban-header">
                  <span>{stg.label}</span>
                  <span className="kanban-badge">{columnLeads.length}</span>
                </div>

                {columnLeads.length === 0 ? (
                  <p className="kanban-empty">No leads</p>
                ) : (
                  columnLeads.map((lead) => (
                    <div className="kanban-card" key={lead.id}>
                      <h5>{lead.name}</h5>
                      <p>{lead.campaign}</p>
                      <div className="kanban-card-foot">
                        <span className="kanban-tag">{lead.source_display}</span>
                        <span className="kanban-avatar">N</span>
                      </div>
                    </div>
                  ))
                )}
              </div>
            );
          })}
        </div>
      </section>

      {/* 5. Modal: Add Lead Manually */}
      {showModal && (
        <div className="modal-overlay" onClick={() => setShowModal(false)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>Add lead manually</h3>
              <button
                type="button"
                className="modal-close-btn"
                onClick={() => setShowModal(false)}
              >
                &times;
              </button>
            </div>

            <form onSubmit={handleSubmit}>
              <div className="modal-body">
                <div className="form-grid">
                  <div className="form-field">
                    <label className="form-label">Lead name</label>
                    <input
                      type="text"
                      className="input"
                      value={formData.name}
                      onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                      required
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Mobile number</label>
                    <input
                      type="text"
                      className="input"
                      value={formData.phone}
                      onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Email address</label>
                    <input
                      type="email"
                      className="input"
                      value={formData.email}
                      onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Source</label>
                    <select
                      className="select"
                      value={formData.source}
                      onChange={(e) => setFormData({ ...formData, source: e.target.value })}
                    >
                      <option value="Manual Entry">Manual Entry</option>
                      <option value="Facebook Lead Ad">Facebook Lead Ad</option>
                      <option value="Google Ads">Google Ads</option>
                      <option value="Website Enquiry">Website Enquiry</option>
                      <option value="Instagram Direct">Instagram Direct</option>
                    </select>
                  </div>

                  <div className="form-field">
                    <label className="form-label">Campaign name</label>
                    <input
                      type="text"
                      className="input"
                      value={formData.campaign_name}
                      onChange={(e) => setFormData({ ...formData, campaign_name: e.target.value })}
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Assign to</label>
                    <select
                      className="select"
                      value={formData.assigned_to}
                      onChange={(e) => setFormData({ ...formData, assigned_to: e.target.value })}
                    >
                      <option value="Nisha">Nisha</option>
                      <option value="Rahul">Rahul</option>
                      <option value="Priya">Priya</option>
                    </select>
                  </div>

                  <div className="form-field full">
                    <label className="form-label">Notes</label>
                    <textarea
                      className="textarea"
                      value={formData.notes}
                      onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                    ></textarea>
                  </div>
                </div>
              </div>

              <div className="modal-footer">
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={() => setShowModal(false)}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="btn btn-primary"
                  disabled={submitting}
                >
                  {submitting ? 'Saving...' : 'Save lead'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
