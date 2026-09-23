import React, { useEffect, useState, useCallback } from 'react';
import axiosInstance from '../api/axiosInstance';
import { useAuth } from '../hooks/useAuth';
import {
  Plus,
  X,
  ShieldCheck,
  UserCheck,
  UserX,
  Edit2,
  Trash2,
  RotateCw,
  CheckCircle2,
  AlertCircle,
  Building,
} from 'lucide-react';

export default function TeamPage() {
  const { user: currentUser } = useAuth();

  const [members, setMembers] = useState([]);
  const [roleCounts, setRoleCounts] = useState({
    admin: 0,
    manager: 0,
    executive: 0,
    viewer: 0,
  });
  const [workspaces, setWorkspaces] = useState([]);
  const [loading, setLoading] = useState(true);
  const [actionMsg, setActionMsg] = useState('');

  // Add Modal State
  const [showAddModal, setShowAddModal] = useState(false);
  const [submittingAdd, setSubmittingAdd] = useState(false);
  const [addForm, setAddForm] = useState({
    name: '',
    email: '',
    role: 'Marketing Manager',
    workspace_ids: [],
  });

  // Manage / Edit Modal State
  const [showManageModal, setShowManageModal] = useState(false);
  const [selectedMember, setSelectedMember] = useState(null);
  const [submittingEdit, setSubmittingEdit] = useState(false);
  const [editForm, setEditForm] = useState({
    name: '',
    role: 'Marketing Manager',
    is_active: true,
    workspace_ids: [],
  });

  // Fetch real team members from backend
  const fetchTeam = useCallback(async () => {
    setLoading(true);
    try {
      const res = await axiosInstance.get('/team');
      if (res.data?.success && Array.isArray(res.data?.data)) {
        setMembers(res.data.data);
        if (res.data.role_counts) {
          setRoleCounts(res.data.role_counts);
        }
      } else {
        setMembers([]);
      }
    } catch (err) {
      console.error('Failed to load team members:', err);
      setMembers([]);
    } finally {
      setLoading(false);
    }
  }, []);

  // Fetch real client workspaces for assignment
  const fetchWorkspaces = useCallback(async () => {
    try {
      const res = await axiosInstance.get('/workspaces');
      if (res.data?.success && Array.isArray(res.data?.data)) {
        setWorkspaces(res.data.data);
      }
    } catch (err) {
      console.error('Failed to fetch workspaces for team assignment:', err);
    }
  }, []);

  useEffect(() => {
    fetchTeam();
    fetchWorkspaces();
  }, [fetchTeam, fetchWorkspaces]);

  // Handle Add Member
  const handleAddSubmit = async (e) => {
    e.preventDefault();
    if (!addForm.name.trim() || !addForm.email.trim()) return;

    setSubmittingAdd(true);
    try {
      const res = await axiosInstance.post('/team', addForm);
      if (res.data?.success) {
        setShowAddModal(false);
        setActionMsg(`Team member '${addForm.name}' created successfully.`);
        setAddForm({
          name: '',
          email: '',
          role: 'Marketing Manager',
          workspace_ids: [],
        });
        fetchTeam();
        setTimeout(() => setActionMsg(''), 4000);
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Failed to add team member.');
    } finally {
      setSubmittingAdd(false);
    }
  };

  // Open Manage Modal for a member
  const handleOpenManage = (member) => {
    setSelectedMember(member);
    setEditForm({
      name: member.name,
      role: member.role,
      is_active: member.status === 'Active',
      workspace_ids: member.assigned_workspace_ids || [],
    });
    setShowManageModal(true);
  };

  // Handle Save Edit Member
  const handleEditSubmit = async (e) => {
    e.preventDefault();
    if (!selectedMember) return;

    setSubmittingEdit(true);
    try {
      const res = await axiosInstance.put(`/team/${selectedMember.id}`, editForm);
      if (res.data?.success) {
        setShowManageModal(false);
        setActionMsg(`Team member '${selectedMember.name}' updated successfully.`);
        fetchTeam();
        setTimeout(() => setActionMsg(''), 4000);
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Failed to update team member.');
    } finally {
      setSubmittingEdit(false);
    }
  };

  // Handle Deactivate Member
  const handleDeactivate = async (member) => {
    if (currentUser && currentUser.id === member.id) {
      alert('You cannot deactivate your own logged-in account.');
      return;
    }

    if (!window.confirm(`Are you sure you want to deactivate ${member.name}?`)) return;

    try {
      const res = await axiosInstance.delete(`/team/${member.id}`);
      if (res.data?.success) {
        setShowManageModal(false);
        setActionMsg(`Team member '${member.name}' deactivated.`);
        fetchTeam();
        setTimeout(() => setActionMsg(''), 4000);
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Failed to deactivate team member.');
    }
  };

  const toggleAddWorkspace = (wsId) => {
    setAddForm((prev) => {
      const exists = prev.workspace_ids.includes(wsId);
      return {
        ...prev,
        workspace_ids: exists
          ? prev.workspace_ids.filter((id) => id !== wsId)
          : [...prev.workspace_ids, wsId],
      };
    });
  };

  const toggleEditWorkspace = (wsId) => {
    setEditForm((prev) => {
      const exists = prev.workspace_ids.includes(wsId);
      return {
        ...prev,
        workspace_ids: exists
          ? prev.workspace_ids.filter((id) => id !== wsId)
          : [...prev.workspace_ids, wsId],
      };
    });
  };

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <h2>Team & access control</h2>
          <p>Verified database users and role-based permissions for internal marketing team operations.</p>
        </div>
        <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={fetchTeam}
            disabled={loading}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}
          >
            <RotateCw size={13} className={loading ? 'spin' : ''} /> Refresh
          </button>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={() => setShowAddModal(true)}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
          >
            <Plus size={15} /> Add team member
          </button>
        </div>
      </div>

      {actionMsg && (
        <div
          style={{
            background: '#e7f7f0',
            color: '#11875d',
            padding: '12px 16px',
            borderRadius: '10px',
            marginBottom: '16px',
            fontSize: '13.5px',
            fontWeight: '600',
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
          }}
        >
          <CheckCircle2 size={18} />
          <span>{actionMsg}</span>
        </div>
      )}

      {/* 2. Real Database Role Metric Cards */}
      <div className="metric-grid">
        <div className="metric-card">
          <span className="metric-label" style={{ fontWeight: '700', fontSize: '15px', color: '#0f172a' }}>
            Administrator
          </span>
          <div className="muted" style={{ fontSize: '12px', marginTop: '4px', height: '36px' }}>
            Full client, API, user, publishing, CRM, and report access.
          </div>
          <div className="metric-value" style={{ marginTop: '12px' }}>
            {roleCounts.admin ?? 0}
          </div>
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
          <div className="metric-value" style={{ marginTop: '12px' }}>
            {roleCounts.manager ?? 0}
          </div>
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
          <div className="metric-value" style={{ marginTop: '12px' }}>
            {roleCounts.executive ?? 0}
          </div>
          <div className="metric-foot" style={{ marginTop: '8px' }}>
            <span className="pill warning">Limited access</span>
          </div>
        </div>
      </div>

      {/* 3. Real Team Members Table */}
      <section className="panel mt-18" style={{ marginTop: '24px' }}>
        <div className="panel-header">
          <div className="panel-title">
            <h3>Team members</h3>
            <p>Active authenticated users retrieved directly from database</p>
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
                  <td colSpan="6" style={{ textAlign: 'center', padding: '32px', color: '#64748b' }}>
                    Loading team members...
                  </td>
                </tr>
              ) : members.length === 0 ? (
                <tr>
                  <td colSpan="6" style={{ textAlign: 'center', padding: '32px', color: '#64748b' }}>
                    No team members found in database.
                  </td>
                </tr>
              ) : (
                members.map((member) => {
                  const isCurrent = currentUser && (currentUser.id === member.id || currentUser.email === member.email);

                  return (
                    <tr key={member.id}>
                      <td>
                        <div className="name-cell">
                          <div className="initial">{member.initials}</div>
                          <div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                              <strong>{member.name}</strong>
                              {isCurrent && (
                                <span className="pill" style={{ fontSize: '10px', padding: '1px 6px', background: '#eff6ff', color: '#1d4ed8' }}>
                                  You
                                </span>
                              )}
                            </div>
                            <div className="muted">{member.email}</div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <span
                          style={{
                            fontWeight: '600',
                            fontSize: '13px',
                            color: member.role === 'Administrator' ? '#1e293b' : '#475569',
                          }}
                        >
                          {member.role}
                        </span>
                      </td>
                      <td>
                        <span style={{ fontSize: '13px', color: '#334155' }}>
                          {member.assigned_clients}
                        </span>
                      </td>
                      <td>
                        <span style={{ fontSize: '12.5px', color: '#64748b' }}>{member.last_active}</span>
                      </td>
                      <td>
                        <span className={`pill ${member.status === 'Active' ? 'success' : 'danger'}`}>
                          {member.status}
                        </span>
                      </td>
                      <td>
                        <button
                          type="button"
                          className="link-button"
                          onClick={() => handleOpenManage(member)}
                        >
                          Manage
                        </button>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>
      </section>

      {/* 4. Add Team Member Modal */}
      {showAddModal && (
        <div className="modal-overlay" onClick={() => setShowAddModal(false)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '520px' }}>
            <div className="modal-header">
              <h3>Add new team member</h3>
              <button
                type="button"
                className="modal-close-btn"
                onClick={() => setShowAddModal(false)}
              >
                <X size={18} />
              </button>
            </div>

            <form onSubmit={handleAddSubmit}>
              <div className="modal-body">
                <div className="form-grid">
                  <div className="form-field">
                    <label className="form-label">Full name *</label>
                    <input
                      type="text"
                      className="input"
                      placeholder="e.g. Rahul Sharma"
                      value={addForm.name}
                      onChange={(e) => setAddForm({ ...addForm, name: e.target.value })}
                      required
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Email address *</label>
                    <input
                      type="email"
                      className="input"
                      placeholder="e.g. rahul@example.com"
                      value={addForm.email}
                      onChange={(e) => setAddForm({ ...addForm, email: e.target.value })}
                      required
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Role *</label>
                    <select
                      className="select"
                      value={addForm.role}
                      onChange={(e) => setAddForm({ ...addForm, role: e.target.value })}
                    >
                      <option value="Administrator">Administrator (Full Access)</option>
                      <option value="Marketing Manager">Marketing Manager (Operational)</option>
                      <option value="Executive">Executive (Limited Tasks)</option>
                      <option value="Viewer">Viewer (Read Only)</option>
                    </select>
                  </div>

                  <div className="form-field">
                    <label className="form-label">Assign Workspaces / Clients</label>
                    <div
                      style={{
                        border: '1px solid #e2e8f0',
                        borderRadius: '8px',
                        padding: '10px',
                        maxHeight: '130px',
                        overflowY: 'auto',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '6px',
                        background: '#f8fafc',
                      }}
                    >
                      {workspaces.map((ws) => (
                        <label
                          key={ws.id}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '8px',
                            fontSize: '13px',
                            cursor: 'pointer',
                          }}
                        >
                          <input
                            type="checkbox"
                            checked={addForm.workspace_ids.includes(ws.id)}
                            onChange={() => toggleAddWorkspace(ws.id)}
                          />
                          <span>{ws.name}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                </div>
              </div>

              <div className="modal-footer">
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={() => setShowAddModal(false)}
                >
                  Cancel
                </button>
                <button type="submit" className="btn btn-primary" disabled={submittingAdd}>
                  {submittingAdd ? 'Adding...' : 'Add team member'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 5. Manage / Edit Team Member Modal */}
      {showManageModal && selectedMember && (
        <div className="modal-overlay" onClick={() => setShowManageModal(false)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '520px' }}>
            <div className="modal-header">
              <h3>Manage team member</h3>
              <button
                type="button"
                className="modal-close-btn"
                onClick={() => setShowManageModal(false)}
              >
                <X size={18} />
              </button>
            </div>

            <form onSubmit={handleEditSubmit}>
              <div className="modal-body">
                <div className="form-grid">
                  <div className="form-field">
                    <label className="form-label">Full name</label>
                    <input
                      type="text"
                      className="input"
                      value={editForm.name}
                      onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}
                      required
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Email address</label>
                    <input
                      type="email"
                      className="input"
                      value={selectedMember.email}
                      disabled
                      style={{ background: '#f1f5f9', cursor: 'not-allowed' }}
                    />
                  </div>

                  <div className="form-field">
                    <label className="form-label">Role</label>
                    <select
                      className="select"
                      value={editForm.role}
                      onChange={(e) => setEditForm({ ...editForm, role: e.target.value })}
                    >
                      <option value="Administrator">Administrator (Full Access)</option>
                      <option value="Marketing Manager">Marketing Manager (Operational)</option>
                      <option value="Executive">Executive (Limited Tasks)</option>
                      <option value="Viewer">Viewer (Read Only)</option>
                    </select>
                  </div>

                  <div className="form-field">
                    <label className="form-label">Assign Workspaces / Clients</label>
                    <div
                      style={{
                        border: '1px solid #e2e8f0',
                        borderRadius: '8px',
                        padding: '10px',
                        maxHeight: '130px',
                        overflowY: 'auto',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '6px',
                        background: '#f8fafc',
                      }}
                    >
                      {workspaces.map((ws) => (
                        <label
                          key={ws.id}
                          style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '8px',
                            fontSize: '13px',
                            cursor: 'pointer',
                          }}
                        >
                          <input
                            type="checkbox"
                            checked={editForm.workspace_ids.includes(ws.id)}
                            onChange={() => toggleEditWorkspace(ws.id)}
                          />
                          <span>{ws.name}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                </div>
              </div>

              <div className="modal-footer" style={{ justifyContent: 'space-between' }}>
                <button
                  type="button"
                  className="btn btn-secondary"
                  style={{ color: '#ef4444', borderColor: '#fecaca' }}
                  onClick={() => handleDeactivate(selectedMember)}
                  disabled={currentUser && currentUser.id === selectedMember.id}
                >
                  <Trash2 size={14} style={{ marginRight: '4px' }} /> Deactivate Member
                </button>

                <div style={{ display: 'flex', gap: '8px' }}>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    onClick={() => setShowManageModal(false)}
                  >
                    Cancel
                  </button>
                  <button type="submit" className="btn btn-primary" disabled={submittingEdit}>
                    {submittingEdit ? 'Saving...' : 'Save changes'}
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
