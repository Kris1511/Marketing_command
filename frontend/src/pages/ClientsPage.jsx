import React, { useEffect, useState } from 'react';
import axios from 'axios';

export default function ClientsPage() {
  const [workspaces, setWorkspaces] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    axios
      .get('/api/v1/workspaces')
      .then((res) => {
        if (res.data.success) {
          setWorkspaces(res.data.data);
        }
      })
      .catch((err) => console.error('Error fetching workspaces:', err))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div>
      <div className="section-head">
        <div>
          <h2>Client Workspaces</h2>
          <p>Manage active client accounts, budgets, and primary contacts.</p>
        </div>
        <button type="button" className="btn btn-primary">
          + Add Workspace
        </button>
      </div>

      <div className="client-card-grid">
        {loading ? (
          <p>Loading workspaces from WAMP MySQL...</p>
        ) : (
          workspaces.map((ws) => (
            <div className="client-card" key={ws.id}>
              <div className="client-card-head">
                <span className="pill success">{ws.status}</span>
                <span className="muted">{ws.industry}</span>
              </div>
              <h3>{ws.name}</h3>
              <p>Contact: {ws.primary_contact} ({ws.primary_contact_email})</p>
              <div className="client-card-metrics">
                <div className="client-mini">
                  <strong>${ws.budget?.toLocaleString()}</strong>
                  <span>Monthly Budget</span>
                </div>
                <div className="client-mini">
                  <strong>4</strong>
                  <span>Active Channels</span>
                </div>
                <div className="client-mini">
                  <strong>98.4%</strong>
                  <span>Health Score</span>
                </div>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}
