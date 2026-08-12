import React, { useEffect, useState } from 'react';
import axiosInstance from '../api/axiosInstance';
import { Plus, X } from 'lucide-react';


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
        if (res.data.success && res.data.data) {
          setMembers(res.data.data);
        } else {
          setMembers([]);
        }
      })
      .catch(() => setMembers([]))
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
      .catch((err) => {
        alert(err.response?.data?.message || 'Failed to add team member');
      })
      .finally(() => setSubmitting(false));
  };

  const adminCount = members.filter((m) => m.role === 'Administrator' || m.role_key === 'admin').length;
  const managerCount = members.filter((m) => m.role === 'Marketing Manager' || m.role_key === 'manager').length;
  const execCount = members.filter((m) => m.role === 'Executive' || m.role_key === 'executive').length;

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
          style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
        >
          <Plus size={16} /> Add team member
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
                <X size={18} />
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
