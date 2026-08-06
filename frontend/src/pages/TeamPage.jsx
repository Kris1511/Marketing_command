import React, { useEffect, useState } from 'react';
import axiosInstance from '../api/axiosInstance';

const initialMembers = [
  {
    id: 1,
    name: 'Priya S',
    email: 'priya@redmind.example',
    role: 'Administrator',
    assigned_clients: 'All clients',
    last_active: 'Now',
    status: 'Active',
    initials: 'PS',
  },
  {
    id: 2,
    name: 'Nisha V',
    email: 'nisha@redmind.example',
    role: 'Marketing Manager',
    assigned_clients: 'Aara, I2 Studio',
    last_active: '18 min ago',
    status: 'Active',
    initials: 'NV',
  },
  {
    id: 3,
    name: 'Kavin R',
    email: 'kavin@redmind.example',
    role: 'Executive',
    assigned_clients: 'Fast Logistics, MM',
    last_active: '1 hr ago',
    status: 'Active',
    initials: 'KV',
  },
];

export default function TeamPage() {
  const [members, setMembers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const [formData, setFormData] = useState({
    name: '',
    email: '',
    role: 'Marketing Manager',
    assigned_clients: '',
  });

  const fetchTeam = () => {
    setLoading(true);
    axiosInstance
      .get('/team')
      .then((res) => {
        if (res.data.success && res.data.data && res.data.data.length > 0) {
          setMembers(res.data.data);
        } else {
          setMembers(initialMembers);
        }
      })
      .catch(() => setMembers(initialMembers))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    fetchTeam();
  }, []);

  const handleSubmit = (e) => {
    e.preventDefault();
    if (!formData.name.trim()) return;

    setSubmitting(true);
    axiosInstance
      .post('/team', formData)
      .then((res) => {
        setMembers((prev) => [...prev, res.data.data]);
        setShowModal(false);
        setFormData({
          name: '',
          email: '',
          role: 'Marketing Manager',
          assigned_clients: '',
        });
      })
      .catch(() => {
        const initials = formData.name
          .split(' ')
          .map((n) => n[0])
          .join('')
          .substring(0, 2)
          .toUpperCase();
        const newMember = {
          id: Date.now(),
          name: formData.name,
          email: formData.email,
          role: formData.role,
          assigned_clients: formData.assigned_clients || 'Aara Wellness',
          last_active: 'Just now',
          status: 'Active',
          initials,
        };
        setMembers((prev) => [...prev, newMember]);
        setShowModal(false);
      })
      .finally(() => setSubmitting(false));
  };

  const adminCount = members.filter((m) => m.role === 'Administrator').length || 2;
  const managerCount = members.filter((m) => m.role === 'Marketing Manager').length || 5;
  const execCount = members.filter((m) => m.role === 'Executive').length || 8;

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <h2>Team & access control</h2>
          <p>Internal digital marketing team only. Clients do not receive login access.</p>
        </div>
        <button
          type="button"
          className="btn btn-primary"
          onClick={() => setShowModal(true)}
        >
          + Add team member
        </button>
      </div>

      {/* 2. Role Metric Cards Grid */}
      <div className="metric-grid">
        <div className="metric-card">
          <span className="metric-label" style={{ fontWeight: '700', fontSize: '15px', color: '#0f172a' }}>
            Administrator
          </span>
          <div className="muted" style={{ fontSize: '12px', marginTop: '4px', height: '36px' }}>
            Full client, API, user, publishing, CRM, and report access.
          </div>
          <div className="metric-value" style={{ marginTop: '12px' }}>{adminCount}</div>
          <div className="metric-foot" style={{ marginTop: '8px' }}>
            <span className="pill success">Full access</span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label" style={{ fontWeight: '700', fontSize: '15px', color: '#0f172a' }}>
            Marketing Manager
          </span>
          <div className="muted" style={{ fontSize: '12px', marginTop: '4px', height: '36px' }}>
            Manage assigned clients, content, leads, and reports.
          </div>
          <div className="metric-value" style={{ marginTop: '12px' }}>{managerCount}</div>
          <div className="metric-foot" style={{ marginTop: '8px' }}>
            <span className="pill info">Operational access</span>
          </div>
        </div>

        <div className="metric-card">
          <span className="metric-label" style={{ fontWeight: '700', fontSize: '15px', color: '#0f172a' }}>
            Executive
          </span>
          <div className="muted" style={{ fontSize: '12px', marginTop: '4px', height: '36px' }}>
            Assigned tasks, publishing drafts, and lead follow-up.
          </div>
          <div className="metric-value" style={{ marginTop: '12px' }}>{execCount}</div>
          <div className="metric-foot" style={{ marginTop: '8px' }}>
            <span className="pill warning">Limited access</span>
          </div>
        </div>
      </div>

      {/* 3. Team Members Table Panel */}
      <section className="panel mt-18" style={{ marginTop: '24px' }}>
        <div className="panel-header">
          <div className="panel-title">
            <h3>Team members</h3>
            <p>OTP-based login is recommended for production</p>
          </div>
        </div>

        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>TEAM MEMBER</th>
                <th>ROLE</th>
                <th>ASSIGNED CLIENTS</th>
                <th>LAST ACTIVE</th>
                <th>STATUS</th>
                <th>ACTION</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan="6" style={{ textAlign: 'center', padding: '24px' }}>
                    Loading team members...
                  </td>
                </tr>
              ) : (
                members.map((member) => (
                  <tr key={member.id}>
                    <td>
                      <div className="name-cell">
                        <div className="initial">{member.initials}</div>
                        <div>
                          <strong>{member.name}</strong>
                          <div className="muted">{member.email}</div>
                        </div>
                      </div>
                    </td>
                    <td>{member.role}</td>
                    <td>{member.assigned_clients}</td>
                    <td>{member.last_active}</td>
                    <td>
                      <span className="pill success">{member.status}</span>
                    </td>
                    <td>
                      <button type="button" className="link-button">
                        Manage
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>

      {/* 4. Add Team Member Modal */}
      {showModal && (
        <div className="modal-overlay" onClick={() => setShowModal(false)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3>Add new team member</h3>
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
                    <label className="form-label">Full name</label>
                    <input
                      type="text"
                      className="input"
                      placeholder="Example: Rahul Sharma"
                      value={formData.name}
                      onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                      required
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Email address</label>
                    <input
                      type="email"
                      className="input"
                      placeholder="rahul@redmind.example"
                      value={formData.email}
                      onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                      required
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Role</label>
                    <select
                      className="select"
                      value={formData.role}
                      onChange={(e) => setFormData({ ...formData, role: e.target.value })}
                    >
                      <option value="Administrator">Administrator</option>
                      <option value="Marketing Manager">Marketing Manager</option>
                      <option value="Executive">Executive</option>
                    </select>
                  </div>

                  <div className="form-field">
                    <label className="form-label">Assigned clients</label>
                    <input
                      type="text"
                      className="input"
                      placeholder="Example: Aara, I2 Studio"
                      value={formData.assigned_clients}
                      onChange={(e) =>
                        setFormData({ ...formData, assigned_clients: e.target.value })
                      }
                    />
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
                  {submitting ? 'Saving...' : 'Add member'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
