import React, { useEffect, useState } from 'react';
import axios from 'axios';

export default function LeadsPage() {
  const [leads, setLeads] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    axios
      .get('/api/v1/leads')
      .then((res) => {
        if (res.data.success) {
          setLeads(res.data.data);
        }
      })
      .catch((err) => console.error('Error fetching leads:', err))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div>
      <div className="section-head">
        <div>
          <h2>CRM & Lead Management</h2>
          <p>Track, manage, and nurture marketing leads captured across channels.</p>
        </div>
        <button type="button" className="btn btn-primary">
          + Add Lead
        </button>
      </div>

      <div className="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Lead Name</th>
              <th>Contact Email</th>
              <th>Phone</th>
              <th>Source</th>
              <th>Status</th>
              <th>Created Date</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan="6" style={{ textAlign: 'center' }}>
                  Loading leads from Laravel backend...
                </td>
              </tr>
            ) : (
              leads.map((lead) => (
                <tr key={lead.id}>
                  <td>
                    <strong>{lead.name}</strong>
                  </td>
                  <td>{lead.email}</td>
                  <td>{lead.phone}</td>
                  <td>
                    <span className="pill info">{lead.source}</span>
                  </td>
                  <td>
                    <span className={`pill ${lead.status === 'new' ? 'warning' : 'success'}`}>
                      {lead.status}
                    </span>
                  </td>
                  <td>{lead.created_at}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
