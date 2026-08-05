import React from 'react';

export default function IntegrationsPage() {
  const integrations = [
    { id: 1, name: 'Meta Business Suite', platform: 'Facebook & Instagram Ads', status: 'Connected', lastSync: '10 mins ago' },
    { id: 2, name: 'Google Analytics 4', platform: 'Website Traffic & Conversions', status: 'Connected', lastSync: '1 hour ago' },
    { id: 3, name: 'Google Ads', platform: 'Paid Search & Display', status: 'Connected', lastSync: '25 mins ago' },
    { id: 4, name: 'LinkedIn Campaign Manager', platform: 'B2B Sponsored Content', status: 'Pending Auth', lastSync: 'Never' },
    { id: 5, name: 'YouTube Analytics', platform: 'Video Reach & Retention', status: 'Connected', lastSync: '2 hours ago' },
  ];

  return (
    <div>
      <div className="section-head">
        <div>
          <h2>API & Platform Connections</h2>
          <p>Manage OAuth credentials, access tokens, and data synchronization across channels.</p>
        </div>
        <button type="button" className="btn btn-primary">+ Connect Channel</button>
      </div>

      <div className="integration-grid">
        {integrations.map((item) => (
          <div className="integration-card" key={item.id}>
            <div className="integration-head">
              <div className="channel-logo">⚡</div>
              <div>
                <h3>{item.name}</h3>
                <p>{item.platform}</p>
              </div>
            </div>
            <div className="integration-status">
              <span className={`pill ${item.status === 'Connected' ? 'success' : 'warning'}`}>
                {item.status}
              </span>
              <small style={{ color: '#64748b' }}>Sync: {item.lastSync}</small>
            </div>
            <div className="integration-actions">
              <button type="button" className="btn btn-secondary btn-sm btn-block">Configure Settings</button>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
