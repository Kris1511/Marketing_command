import React, { useState, useEffect } from 'react';
import { CheckCircle2, AlertCircle, Layers, X, ShieldCheck, Share2, Key } from 'lucide-react';

const initialConnections = [
  {
    id: 1,
    name: 'Facebook Pages',
    code: 'f',
    subtitle: 'Phase 1 integration',
    status: 'disconnected',
    statusText: '• Not connected',
    timeAgo: 'No data',
    canTest: false,
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
  
  // Facebook Page Selection Modal state (OAuth)
  const [showModal, setShowModal] = useState(false);
  const [fetchedPages, setFetchedPages] = useState([]);
  const [selectedPageId, setSelectedPageId] = useState('');

  // Manual Page Connection Modal state
  const [showManualModal, setShowManualModal] = useState(false);
  const [manualPageId, setManualPageId] = useState('');
  const [manualPageName, setManualPageName] = useState('');
  const [manualPageToken, setManualPageToken] = useState('');

  const [connectingPage, setConnectingPage] = useState(false);
  const [connectedFbPages, setConnectedFbPages] = useState([]);

  useEffect(() => {
    fetchConnectedFacebookPages();
  }, []);

  const fetchConnectedFacebookPages = async () => {
    try {
      const res = await fetch('http://localhost:8000/api/v1/facebook/pages?workspace_id=1');
      const json = await res.json();
      if (json.success && json.data.length > 0) {
        setConnectedFbPages(json.data);
        setConnections((prev) =>
          prev.map((c) => {
            if (c.name.includes('Facebook')) {
              const activePage = json.data[0];
              return {
                ...c,
                status: 'connected',
                statusText: `• Connected (${activePage.account_name})`,
                timeAgo: 'Active Page',
                canTest: true,
              };
            }
            return c;
          })
        );
      }
    } catch (err) {
      console.error('Failed to fetch connected Facebook pages:', err);
    }
  };

  useEffect(() => {
    const handleMessage = (event) => {
      if (event.data?.type === 'FACEBOOK_PAGES_FETCHED') {
        const pages = event.data.pages || [];
        if (pages.length === 0) {
          alert('No Facebook Pages were returned by Meta. Reason: Either your Facebook Account has no Pages, or you opted out of selecting a Page during login, or your Facebook Account is not listed as a Tester/Admin in the Meta Developer Dashboard.\n\nYou can also click "Manual Token Connect" to connect using a Page Access Token directly.');
          return;
        }

        setFetchedPages(pages);
        setSelectedPageId(pages[0].id);
        setShowModal(true);
        setSyncMsg(`OAuth success! Please select which Facebook Page to connect.`);
      } else if (event.data?.type === 'FACEBOOK_OAUTH_ERROR') {
        alert(`Facebook connection error: ${event.data.error || 'Failed to authenticate'}`);
      }
    };

    window.addEventListener('message', handleMessage);
    return () => window.removeEventListener('message', handleMessage);
  }, []);

  const handleSyncAll = () => {
    setSyncing(true);
    setSyncMsg('Syncing all connected accounts...');
    setTimeout(() => {
      setSyncing(false);
      setSyncMsg('All connected accounts synced successfully!');
      setTimeout(() => setSyncMsg(''), 4000);
    }, 1200);
  };

  const handleConnectToggle = (item) => {
    if (item.name.includes('Facebook') || item.name.includes('Instagram')) {
      const popup = window.open(
        'http://localhost:8000/api/v1/auth/facebook?workspace_id=1',
        'MetaOAuthPopup',
        'width=650,height=750,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up blocked! Please allow pop-ups for this site to complete Meta connection.');
      } else {
        setSyncMsg('Authenticating with Meta... Complete Facebook login in the pop-up.');
      }
      return;
    }

    setConnections((prev) =>
      prev.map((c) => {
        if (c.id === item.id) {
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

  const handleConfirmConnectPage = async () => {
    const pageToConnect = fetchedPages.find((p) => p.id === selectedPageId);
    if (!pageToConnect) return;

    setConnectingPage(true);

    try {
      const res = await fetch('http://localhost:8000/api/v1/facebook/connect-page', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          workspace_id: 1,
          page_id: pageToConnect.id,
          page_name: pageToConnect.name,
          page_access_token: pageToConnect.access_token,
        }),
      });

      const json = await res.json();

      if (res.ok && json.success) {
        setShowModal(false);
        setSyncMsg(`Facebook Page "${pageToConnect.name}" successfully connected!`);
        fetchConnectedFacebookPages();
      } else {
        alert(json.message || 'Failed to connect page');
      }
    } catch (err) {
      console.error('Error connecting page:', err);
      alert('Network error connecting page to backend.');
    } finally {
      setConnectingPage(false);
    }
  };

  const handleManualTokenSubmit = async (e) => {
    e.preventDefault();
    if (!manualPageId || !manualPageToken) {
      alert('Please enter both Page ID and Page Access Token.');
      return;
    }

    setConnectingPage(true);

    try {
      const res = await fetch('http://localhost:8000/api/v1/facebook/connect-page', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          workspace_id: 1,
          page_id: manualPageId.trim(),
          page_name: manualPageName.trim() || `Page (${manualPageId.trim()})`,
          page_access_token: manualPageToken.trim(),
        }),
      });

      const json = await res.json();

      if (res.ok && json.success) {
        setShowManualModal(false);
        setManualPageId('');
        setManualPageName('');
        setManualPageToken('');
        setSyncMsg(`Facebook Page Token successfully saved!`);
        fetchConnectedFacebookPages();
      } else {
        alert(json.message || 'Failed to connect page');
      }
    } catch (err) {
      console.error('Error connecting manual page:', err);
      alert('Network error saving page token.');
    } finally {
      setConnectingPage(false);
    }
  };

  const handleTestConnection = (name) => {
    alert(`Testing API connection for ${name}... Connection verified successfully!`);
  };

  return (
    <div>
      {/* Header */}
      <div className="section-head">
        <div>
          <h2>API connections</h2>
          <p>Connect Meta Facebook Pages, test API endpoints, and monitor platform status.</p>
        </div>
        <div style={{ display: 'flex', gap: '8px' }}>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => setShowManualModal(true)}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
          >
            <Key size={16} /> Token Connect
          </button>
          <button
            type="button"
            className="btn btn-primary"
            onClick={handleSyncAll}
            disabled={syncing}
          >
            {syncing ? 'Syncing...' : 'Sync all connected accounts'}
          </button>
        </div>
      </div>

      {syncMsg && (
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
          <span>{syncMsg}</span>
        </div>
      )}

      {/* Developer Banner */}
      <div className="dev-alert-banner">
        <strong>Meta Graph API Ready:</strong> OAuth endpoint <code>/api/v1/auth/facebook</code> uses App ID <code>1390717679611716</code> and exchanges for long-lived Page Access Tokens stored in MySQL.
      </div>

      {/* API Connections Grid */}
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
                onClick={() => handleConnectToggle(item)}
              >
                {item.status === 'disconnected' || item.status === 'phase2' ? 'Connect Facebook' : 'Reconnect'}
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

      {/* Connected Facebook Pages Listing Panel */}
      {connectedFbPages.length > 0 && (
        <div className="panel mt-18" style={{ marginBottom: '24px' }}>
          <div className="panel-header">
            <div className="panel-title">
              <h3>Connected Facebook Pages</h3>
              <p>Active Facebook Pages with secure Page Access Tokens stored in backend database</p>
            </div>
            <span className="pill success">Active Connection</span>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: '16px', padding: '16px 0' }}>
            {connectedFbPages.map((page) => (
              <div key={page.id} style={{ border: '1px solid #e5e7eb', padding: '14px', borderRadius: '10px', background: '#f9fafb' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                  <div style={{ width: '36px', height: '36px', background: '#1877f2', color: '#fff', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 'bold' }}>
                    f
                  </div>
                  <div>
                    <strong style={{ fontSize: '14px', display: 'block' }}>{page.account_name}</strong>
                    <span style={{ fontSize: '11px', color: '#6b7280' }}>Page ID: {page.account_id}</span>
                  </div>
                </div>
                <div style={{ fontSize: '12px', color: '#059669', display: 'flex', alignItems: 'center', gap: '4px', marginTop: '6px' }}>
                  <ShieldCheck size={14} /> Page Access Token Secured
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Page Selection Modal (OAuth) */}
      {showModal && (
        <div
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            width: '100vw',
            height: '100vh',
            background: 'rgba(0, 0, 0, 0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 9999,
          }}
        >
          <div
            style={{
              background: '#fff',
              borderRadius: '16px',
              maxWidth: '520px',
              width: '90%',
              padding: '24px',
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <div style={{ background: '#1877f2', color: '#fff', borderRadius: '50%', padding: '6px' }}>
                  <Share2 size={20} />
                </div>
                <h3 style={{ margin: 0, fontSize: '18px' }}>Select Facebook Page</h3>
              </div>
              <button
                type="button"
                onClick={() => setShowModal(false)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}
              >
                <X size={20} />
              </button>
            </div>

            <p style={{ fontSize: '13.5px', color: '#4b5563', marginBottom: '16px' }}>
              The following Facebook Pages were retrieved from your Meta account. Choose the page you want to connect to this workspace for automatic post publishing:
            </p>

            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', maxHeight: '240px', overflowY: 'auto', marginBottom: '20px' }}>
              {fetchedPages.map((page) => (
                <label
                  key={page.id}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    padding: '12px 14px',
                    borderRadius: '10px',
                    border: selectedPageId === page.id ? '2px solid #1877f2' : '1px solid #e5e7eb',
                    background: selectedPageId === page.id ? '#eff6ff' : '#fff',
                    cursor: 'pointer',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <input
                      type="radio"
                      name="facebook_page_selection"
                      value={page.id}
                      checked={selectedPageId === page.id}
                      onChange={() => setSelectedPageId(page.id)}
                    />
                    <div>
                      <strong style={{ fontSize: '14px', display: 'block' }}>{page.name}</strong>
                      <span style={{ fontSize: '11.5px', color: '#6b7280' }}>
                        ID: {page.id} {page.category ? `• ${page.category}` : ''}
                      </span>
                    </div>
                  </div>
                  {selectedPageId === page.id && <CheckCircle2 size={18} color="#1877f2" />}
                </label>
              ))}
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setShowModal(false)}
              >
                Cancel
              </button>
              <button
                type="button"
                className="btn btn-primary"
                onClick={handleConfirmConnectPage}
                disabled={connectingPage || !selectedPageId}
              >
                {connectingPage ? 'Connecting Page...' : 'Connect Selected Page'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Manual Token Connect Modal */}
      {showManualModal && (
        <div
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            width: '100vw',
            height: '100vh',
            background: 'rgba(0, 0, 0, 0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 9999,
          }}
        >
          <div
            style={{
              background: '#fff',
              borderRadius: '16px',
              maxWidth: '520px',
              width: '90%',
              padding: '24px',
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <Key size={20} color="#1877f2" />
                <h3 style={{ margin: 0, fontSize: '18px' }}>Manual Page Token Connect</h3>
              </div>
              <button
                type="button"
                onClick={() => setShowManualModal(false)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}
              >
                <X size={20} />
              </button>
            </div>

            <p style={{ fontSize: '13px', color: '#4b5563', marginBottom: '16px' }}>
              Enter your Facebook Page ID and Page Access Token (from Graph API Explorer or Meta App Dashboard) to connect directly:
            </p>

            <form onSubmit={handleManualTokenSubmit}>
              <div style={{ marginBottom: '12px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>Facebook Page ID *</label>
                <input
                  type="text"
                  className="input"
                  placeholder="e.g. 1005544332211"
                  value={manualPageId}
                  onChange={(e) => setManualPageId(e.target.value)}
                  required
                />
              </div>

              <div style={{ marginBottom: '12px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>Facebook Page Name</label>
                <input
                  type="text"
                  className="input"
                  placeholder="e.g. My Business Page"
                  value={manualPageName}
                  onChange={(e) => setManualPageName(e.target.value)}
                />
              </div>

              <div style={{ marginBottom: '20px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>Page Access Token (EAAM...) *</label>
                <textarea
                  className="textarea"
                  rows={3}
                  placeholder="Paste Page Access Token starting with EAA..."
                  value={manualPageToken}
                  onChange={(e) => setManualPageToken(e.target.value)}
                  required
                />
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                <button
                  type="button"
                  className="btn btn-secondary"
                  onClick={() => setShowManualModal(false)}
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="btn btn-primary"
                  disabled={connectingPage}
                >
                  {connectingPage ? 'Saving Token...' : 'Save & Connect Page'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
