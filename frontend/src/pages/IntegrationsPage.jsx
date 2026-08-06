import React, { useState } from 'react';

const initialConnections = [
  {
    id: 1,
    name: 'Facebook Pages',
    code: 'f',
    subtitle: 'Phase 1 integration',
    status: 'connected',
    statusText: '• Connected',
    timeAgo: '8 min ago',
    canTest: true,
  },
  {
    id: 2,
    name: 'Instagram Business',
    code: 'IG',
    subtitle: 'Phase 1 integration',
    status: 'disconnected',
    statusText: '• Not connected',
    timeAgo: 'No data',
    canTest: false,
  },
  {
    id: 3,
    name: 'YouTube Channels',
    code: 'YT',
    subtitle: 'Phase 1 integration',
    status: 'attention',
    statusText: '• Attention',
    timeAgo: '2 hr ago',
    canTest: true,
  },
  {
    id: 4,
    name: 'Google Analytics',
    code: 'GA',
    subtitle: 'Phase 1 integration',
    status: 'connected',
    statusText: '• Connected',
    timeAgo: '18 min ago',
    canTest: true,
  },
  {
    id: 5,
    name: 'Search Console',
    code: 'SC',
    subtitle: 'Phase 1 integration',
    status: 'connected',
    statusText: '• Connected',
    timeAgo: '24 min ago',
    canTest: true,
  },
  {
    id: 6,
    name: 'Google Business Profile',
    code: 'GB',
    subtitle: 'Phase 1 integration',
    status: 'disconnected',
    statusText: '• Not connected',
    timeAgo: 'No data',
    canTest: false,
  },
  {
    id: 7,
    name: 'LinkedIn Pages',
    code: 'in',
    subtitle: 'Phase 2 integration',
    status: 'connected',
    statusText: '• Connected',
    timeAgo: 'Just now',
    canTest: true,
  },
  {
    id: 8,
    name: 'X / Twitter',
    code: 'X',
    subtitle: 'Phase 2 integration',
    status: 'connected',
    statusText: '• Connected',
    timeAgo: 'Just now',
    canTest: true,
  },
];

export default function IntegrationsPage() {
  const [connections, setConnections] = useState(initialConnections);
  const [syncing, setSyncing] = useState(false);
  const [syncMsg, setSyncMsg] = useState('');

  const handleSyncAll = () => {
    setSyncing(true);
    setSyncMsg('Syncing all connected accounts...');
    setTimeout(() => {
      setSyncing(false);
      setSyncMsg('All connected accounts synced successfully!');
      setTimeout(() => setSyncMsg(''), 4000);
    }, 1200);
  };

  const handleConnectToggle = (id) => {
    setConnections((prev) =>
      prev.map((c) => {
        if (c.id === id) {
          const isConn = c.status === 'connected';
          return {
            ...c,
            status: isConn ? 'disconnected' : 'connected',
            statusText: isConn ? '• Not connected' : '• Connected',
            timeAgo: isConn ? 'No data' : 'Just now',
            canTest: !isConn,
          };
        }
        return c;
      })
    );
  };

  const handleTestConnection = (name) => {
    alert(`Testing API connection for ${name}... Connection verified successfully!`);
  };

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <h2>API connections</h2>
          <p>Connect, test, and monitor every platform from one non-technical screen.</p>
        </div>
        <button
          type="button"
          className="btn btn-primary"
          onClick={handleSyncAll}
          disabled={syncing}
        >
          {syncing ? 'Syncing...' : 'Sync all connected accounts'}
        </button>
      </div>

      {syncMsg && (
        <div
          style={{
            background: '#e7f7f0',
            color: '#11875d',
            padding: '10px 16px',
            borderRadius: '10px',
            marginBottom: '16px',
            fontSize: '13px',
            fontWeight: '600',
          }}
        >
          ✓ {syncMsg}
        </div>
      )}

      {/* 2. Developer Alert Banner */}
      <div className="dev-alert-banner">
        <strong>Developer-friendly setup:</strong> all API URLs and versions are stored in{' '}
        <code>js/api-config.js</code>. Every request, token header, timeout, error, and
        client-workspace header is handled in <code>js/api-service.js</code>.
      </div>

      {/* 3. API Connections Grid (8 Cards total) */}
      <div className="integration-grid mb-18">
        {connections.map((item) => (
          <div className="integration-card" key={item.id}>
            <div className="integration-card-head">
              <div className="api-logo-box">{item.code}</div>
              <div>
                <h4>{item.name}</h4>
                <p>{item.subtitle}</p>
              </div>
            </div>

            <div className="status-bar-wrap">
              <span className={`status-pill ${item.status}`}>{item.statusText}</span>
              <span className="status-time">{item.timeAgo}</span>
            </div>

            <div className="card-action-row">
              <button
                type="button"
                className="btn-outline-dark"
                onClick={() => handleConnectToggle(item.id)}
              >
                {item.status === 'disconnected' || item.status === 'phase2' ? 'Connect' : 'Reconnect'}
              </button>
              <button
                type="button"
                className={item.canTest ? 'btn-test-primary' : 'btn-test-disabled'}
                disabled={!item.canTest}
                onClick={() => item.canTest && handleTestConnection(item.name)}
              >
                Test
              </button>
            </div>
          </div>
        ))}
      </div>

      {/* 4. Bottom Guides Section (2 Equal Columns Grid) */}
      <div className="grid-equal mt-18" style={{ marginTop: '24px' }}>
        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Simple connection guide</h3>
              <p>For marketing team members</p>
            </div>
          </div>

          <div className="guide-step-list">
            <div className="guide-step-item">
              <div className="guide-step-num">1</div>
              <div className="guide-step-text">
                <strong>Select the correct client</strong>
                <p>Use the client selector at the top before connecting an account.</p>
              </div>
            </div>

            <div className="guide-step-item">
              <div className="guide-step-num">2</div>
              <div className="guide-step-text">
                <strong>Click Connect or Reconnect</strong>
                <p>You will be taken to the platform's secure authorization page.</p>
              </div>
            </div>

            <div className="guide-step-item">
              <div className="guide-step-num">3</div>
              <div className="guide-step-text">
                <strong>Choose the correct page or channel</strong>
                <p>Only approve the business accounts required for this client.</p>
              </div>
            </div>

            <div className="guide-step-item">
              <div className="guide-step-num">4</div>
              <div className="guide-step-text">
                <strong>Return and click Test</strong>
                <p>The dashboard confirms whether data can be read successfully.</p>
              </div>
            </div>
          </div>
        </section>

        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Technical handover notes</h3>
              <p>Production implementation checklist</p>
            </div>
          </div>

          <div className="guide-step-list">
            <div className="guide-step-item">
              <div className="guide-step-num">1</div>
              <div className="guide-step-text">
                <strong>Use backend OAuth callbacks</strong>
                <p>Never store client secrets or refresh tokens in the browser.</p>
              </div>
            </div>

            <div className="guide-step-item">
              <div className="guide-step-num">2</div>
              <div className="guide-step-text">
                <strong>Use one tenant header</strong>
                <p>Every API request carries the selected client workspace ID.</p>
              </div>
            </div>

            <div className="guide-step-item">
              <div className="guide-step-num">3</div>
              <div className="guide-step-text">
                <strong>Use scheduled sync jobs</strong>
                <p>Run background server jobs and save normalized analytics data.</p>
              </div>
            </div>

            <div className="guide-step-item">
              <div className="guide-step-num">4</div>
              <div className="guide-step-text">
                <strong>Enable audit logging</strong>
                <p>Record who published, changed a lead, or reconnected an account.</p>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
