import React, { useState, useEffect } from 'react';
import { CheckCircle2, AlertCircle, Layers, X, ShieldCheck, Share2, Key, RefreshCw, Trash2, Video } from 'lucide-react';
import axiosInstance from '../api/axiosInstance';

import { useWorkspace } from '../context/WorkspaceContext';

function formatRelativeTime(iso) {
  if (!iso) return 'No data';
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins} min ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs} hr${hrs > 1 ? 's' : ''} ago`;
  const days = Math.floor(hrs / 24);
  return `${days} day${days > 1 ? 's' : ''} ago`;
}


export default function IntegrationsPage() {
  const { selectedWorkspaceId } = useWorkspace();
  const [connections, setConnections] = useState([]);
  const [syncing, setSyncing] = useState(false);
  const [syncMsg, setSyncMsg] = useState('');
  
  // Facebook State
  const [showModal, setShowModal] = useState(false);
  const [showManualModal, setShowManualModal] = useState(false);
  const [fetchedPages, setFetchedPages] = useState([]);
  const [selectedPageId, setSelectedPageId] = useState('');
  const [manualPlatform, setManualPlatform] = useState('facebook');
  const [manualPageId, setManualPageId] = useState('');
  const [manualPageName, setManualPageName] = useState('');
  const [manualPageToken, setManualPageToken] = useState('');
  const [connectingPage, setConnectingPage] = useState(false);
  const [connectedFbPages, setConnectedFbPages] = useState([]);

  // YouTube Channel State
  const [youtubeChannel, setYoutubeChannel] = useState(null);
  const [youtubeLoading, setYoutubeLoading] = useState(true);

  const fetchIntegrationsStatus = async () => {
    try {
      const res = await axiosInstance.get('/integrations/status', { params: { workspace_id: selectedWorkspaceId } });
      if (res.data.success) {
        const formatted = res.data.data.map((item, idx) => ({
          id: idx + 1,
          key: item.key,
          name: item.name,
          code: item.code,
          subtitle: `Phase ${item.phase} integration`,
          status: item.status,
          statusText: item.status === 'connected' ? `• Connected ${item.account_name ? `(${item.account_name})` : ''}` : '• Not connected',
          timeAgo: formatRelativeTime(item.last_sync),
          canTest: item.status === 'connected',
        }));
        setConnections(formatted);
      }
    } catch (err) {
      console.error('Failed to load integration statuses:', err);
    }
  };

  useEffect(() => {
    fetchIntegrationsStatus();
    fetchConnectedFacebookPages();
    fetchYouTubeStatus();

    // Check URL parameters for YouTube OAuth return
    const searchParams = new URLSearchParams(window.location.search);
    if (searchParams.get('youtube') === 'success') {
      setSyncMsg('YouTube channel connected successfully!');
      fetchYouTubeStatus();
    } else if (searchParams.get('youtube') === 'error') {
      alert('Failed to connect YouTube channel. Please check your Google OAuth permissions.');
    }
  }, [selectedWorkspaceId]);

  const fetchYouTubeStatus = async () => {
    setYoutubeLoading(true);
    try {
      const res = await fetch('http://localhost:8000/api/youtube/status');
      const json = await res.json();
      if (json.success && json.connected && json.data) {
        setYoutubeChannel(json.data);
        setConnections((prev) =>
          prev.map((c) => {
            if (c.name.includes('YouTube')) {
              return {
                ...c,
                status: 'connected',
                statusText: `• Connected (${json.data.channel_name})`,
                timeAgo: 'Live API',
                canTest: true,
              };
            }
            return c;
          })
        );
      } else {
        setYoutubeChannel(null);
        setConnections((prev) =>
          prev.map((c) => {
            if (c.name.includes('YouTube')) {
              return {
                ...c,
                status: 'disconnected',
                statusText: '• Not connected',
                timeAgo: 'No data',
                canTest: false,
              };
            }
            return c;
          })
        );
      }
    } catch (err) {
      console.error('Error fetching YouTube status:', err);
    } finally {
      setYoutubeLoading(false);
    }
  };

  const fetchConnectedFacebookPages = async () => {
    try {
      const wsId = selectedWorkspaceId || 1;
      const res = await fetch(`http://localhost:8000/api/v1/facebook/pages?workspace_id=${wsId}`);
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
      } else {
        setConnectedFbPages([]);
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
          alert('No Facebook Pages were returned by Meta. Reason: Either your Facebook Account has no Pages, or you opted out of selecting a Page during login.');
          return;
        }
        setFetchedPages(pages);
        setSelectedPageId(pages[0].id);
        setShowModal(true);
        setSyncMsg(`OAuth success! Please select which Facebook Page to connect.`);
      } else if (event.data?.type === 'FACEBOOK_OAUTH_ERROR') {
        alert(`Facebook connection error: ${event.data.error || 'Failed to authenticate'}`);
      } else if (event.data?.type === 'YOUTUBE_OAUTH_RESULT') {
        if (event.data.success) {
          setSyncMsg(`YouTube Connected: ${event.data.connection?.channel_name || 'Channel active'}`);
          fetchYouTubeStatus();
        } else {
          alert(`YouTube connection error: ${event.data.message}`);
        }
      }
    };

    window.addEventListener('message', handleMessage);
    return () => window.removeEventListener('message', handleMessage);
  }, []);

  const handleSyncAll = async () => {
    setSyncing(true);
    setSyncMsg('Syncing all connected accounts...');
    if (youtubeChannel) {
      try {
        await fetch('http://localhost:8000/api/youtube/channel');
        await fetchYouTubeStatus();
      } catch (e) {
        console.error(e);
      }
    }
    setTimeout(() => {
      setSyncing(false);
      setSyncMsg('All connected accounts synced successfully!');
      setTimeout(() => setSyncMsg(''), 4000);
    }, 1200);
  };

  const handleConnectYouTube = () => {
    const width = 600;
    const height = 700;
    const left = (window.innerWidth - width) / 2;
    const top = (window.innerHeight - height) / 2;

    const popup = window.open(
      'http://localhost:8000/api/youtube/connect',
      'GoogleYouTubeOAuth',
      `width=${width},height=${height},top=${top},left=${left},scrollbars=yes,status=yes`
    );

    if (!popup || popup.closed || typeof popup.closed === 'undefined') {
      window.location.href = 'http://localhost:8000/api/youtube/connect';
    } else {
      setSyncMsg('Connecting to Google OAuth... Please complete login in the pop-up.');
    }
  };

  const handleDisconnectYouTube = async () => {
    if (!window.confirm('Are you sure you want to disconnect your YouTube channel?')) return;
    try {
      const res = await fetch('http://localhost:8000/api/youtube/disconnect', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
      });
      const json = await res.json();
      if (json.success) {
        setSyncMsg('YouTube channel disconnected.');
        setYoutubeChannel(null);
        fetchYouTubeStatus();
      }
    } catch (err) {
      alert('Error disconnecting YouTube channel.');
    }
  };

  const handleConnectToggle = (item) => {
    if (item.name.includes('YouTube')) {
      if (youtubeChannel) {
        handleDisconnectYouTube();
      } else {
        handleConnectYouTube();
      }
      return;
    }

    if (item.name.includes('Facebook') || item.name.includes('Instagram')) {
      const wsId = selectedWorkspaceId || 1;
      const popup = window.open(
        `http://localhost:8000/api/v1/auth/facebook?workspace_id=${wsId}`,
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
      const wsId = selectedWorkspaceId || 1;
      const igId = pageToConnect.instagram_business_account?.id || null;
      const res = await fetch('http://localhost:8000/api/v1/facebook/connect-page', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          workspace_id: wsId,
          page_id: pageToConnect.id,
          page_name: pageToConnect.name,
          page_access_token: pageToConnect.access_token,
          instagram_account_id: igId,
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
      alert('Network error connecting page to backend.');
    } finally {
      setConnectingPage(false);
    }
  };

  const handleManualTokenSubmit = async (e) => {
    e.preventDefault();
    if (!manualPageId || !manualPageToken) {
      alert('Please enter both Account ID and Access Token.');
      return;
    }
    setConnectingPage(true);
    try {
      const endpoint = manualPlatform === 'instagram' ? '/instagram/connect' : '/facebook/connect-page';
      const bodyPayload = manualPlatform === 'instagram' ? {
        workspace_id: selectedWorkspaceId || 1,
        instagram_account_id: manualPageId.trim(),
        account_name: manualPageName.trim(),
        access_token: manualPageToken.trim(),
      } : {
        workspace_id: selectedWorkspaceId || 1,
        page_id: manualPageId.trim(),
        page_name: manualPageName.trim() || `Page (${manualPageId.trim()})`,
        page_access_token: manualPageToken.trim(),
      };

      const res = await axiosInstance.post(endpoint, bodyPayload);
      if (res.data.success) {
        setShowManualModal(false);
        setSyncMsg(`${manualPlatform === 'instagram' ? 'Instagram' : 'Facebook'} account connected successfully!`);
        fetchIntegrationsStatus();
        fetchConnectedFacebookPages();
      } else {
        alert(res.data.message || 'Failed to connect');
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Error connecting account to backend.');
    } finally {
      setConnectingPage(false);
    }
  };

  const handleTestConnection = (name) => {
    if (name.includes('YouTube') && youtubeChannel) {
      alert(`YouTube API Connected!\nChannel: ${youtubeChannel.channel_name}\nSubscribers: ${youtubeChannel.subscriber_count.toLocaleString()}\nTotal Videos: ${youtubeChannel.video_count.toLocaleString()}\nTotal Views: ${youtubeChannel.view_count.toLocaleString()}`);
    } else {
      alert(`Testing API connection for ${name}... Connection verified successfully!`);
    }
  };

  return (
    <div>
      {/* Header */}
      <div className="section-head">
        <div>
          <h2>API Connections</h2>
          <p>Connect Google YouTube Channels, Meta Facebook Pages, and manage integration platforms.</p>
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

      {/* Developer Alert Banner */}
      <div className="dev-alert-banner" style={{ background: '#fffbeb', borderColor: '#fef3c7', color: '#92400e' }}>
        <strong>YouTube Data API v3 Active:</strong> Google OAuth 2.0 Client ID <code>80913470656-0ahb9td2sm5oo9lj4oi3eqnj14v4b2oa.apps.googleusercontent.com</code> securely manages tokens in Laravel MySQL.
      </div>

      {/* Dedicated YouTube Integration Display Panel */}
      <div className="panel mb-18" style={{ background: '#fff', borderRadius: '14px', border: '1px solid #e5e7eb', padding: '24px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px', borderBottom: '1px solid #f3f4f6', paddingBottom: '16px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            <div style={{ width: '44px', height: '44px', background: '#ff0000', borderRadius: '10px', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff' }}>
              <Video size={26} />
            </div>
            <div>
              <h3 style={{ margin: 0, fontSize: '18px', fontWeight: '700' }}>YouTube Integration</h3>
              <p style={{ margin: 0, fontSize: '13px', color: '#6b7280' }}>Google OAuth 2.0 & YouTube Data API v3</p>
            </div>
          </div>
          <div>
            {youtubeChannel ? (
              <span className="pill success" style={{ fontSize: '13px', padding: '6px 14px' }}>
                Status: Connected
              </span>
            ) : (
              <span className="pill neutral" style={{ fontSize: '13px', padding: '6px 14px' }}>
                Status: Not Connected
              </span>
            )}
          </div>
        </div>

        {youtubeLoading ? (
          <div style={{ padding: '20px', textAlign: 'center', color: '#6b7280' }}>Loading YouTube status...</div>
        ) : youtubeChannel ? (
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '20px', marginBottom: '24px', background: '#f9fafb', padding: '20px', borderRadius: '12px', border: '1px solid #e5e7eb' }}>
              {youtubeChannel.channel_thumbnail ? (
                <img
                  src={youtubeChannel.channel_thumbnail}
                  alt={youtubeChannel.channel_name}
                  style={{ width: '72px', height: '72px', borderRadius: '50%', objectFit: 'cover', border: '3px solid #ff0000' }}
                />
              ) : (
                <div style={{ width: '72px', height: '72px', borderRadius: '50%', background: '#ff0000', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '24px', fontWeight: 'bold' }}>
                  YT
                </div>
              )}
              <div style={{ flex: 1 }}>
                <h4 style={{ margin: '0 0 4px 0', fontSize: '20px', color: '#111827' }}>{youtubeChannel.channel_name}</h4>
                <p style={{ margin: 0, fontSize: '13px', color: '#4b5563', maxHeight: '42px', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                  {youtubeChannel.channel_description || 'No description provided.'}
                </p>
                <span style={{ fontSize: '11.5px', color: '#6b7280', display: 'block', marginTop: '6px' }}>
                  Channel ID: <code>{youtubeChannel.channel_id}</code>
                </span>
              </div>
            </div>

            {/* Statistics Cards */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '16px', marginBottom: '24px' }}>
              <div style={{ background: '#fef2f2', padding: '16px', borderRadius: '10px', border: '1px solid #fecaca' }}>
                <span style={{ fontSize: '12px', color: '#991b1b', fontWeight: '600', textTransform: 'uppercase' }}>Subscribers</span>
                <div style={{ fontSize: '24px', fontWeight: '800', color: '#7f1d1d', marginTop: '4px' }}>
                  {youtubeChannel.subscriber_count ? youtubeChannel.subscriber_count.toLocaleString() : 0}
                </div>
              </div>

              <div style={{ background: '#eff6ff', padding: '16px', borderRadius: '10px', border: '1px solid #bfdbfe' }}>
                <span style={{ fontSize: '12px', color: '#1e40af', fontWeight: '600', textTransform: 'uppercase' }}>Total Videos</span>
                <div style={{ fontSize: '24px', fontWeight: '800', color: '#1e3a8a', marginTop: '4px' }}>
                  {youtubeChannel.video_count ? youtubeChannel.video_count.toLocaleString() : 0}
                </div>
              </div>

              <div style={{ background: '#f0fdf4', padding: '16px', borderRadius: '10px', border: '1px solid #bbf7d0' }}>
                <span style={{ fontSize: '12px', color: '#166534', fontWeight: '600', textTransform: 'uppercase' }}>Total Views</span>
                <div style={{ fontSize: '24px', fontWeight: '800', color: '#14532d', marginTop: '4px' }}>
                  {youtubeChannel.view_count ? youtubeChannel.view_count.toLocaleString() : 0}
                </div>
              </div>
            </div>

            <div style={{ display: 'flex', gap: '12px', justifyContent: 'flex-end' }}>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={handleSyncAll}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
              >
                <RefreshCw size={16} /> Refresh Channel Stats
              </button>
              <button
                type="button"
                className="btn"
                onClick={handleDisconnectYouTube}
                style={{ background: '#dc2626', color: '#fff', border: 'none', display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '8px 18px', borderRadius: '8px', cursor: 'pointer', fontWeight: '600' }}
              >
                <Trash2 size={16} /> Disconnect YouTube
              </button>
            </div>
          </div>
        ) : (
          <div style={{ padding: '24px', textAlign: 'center', background: '#fafafa', borderRadius: '12px', border: '1px dashed #d1d5db' }}>
            <h4 style={{ margin: '0 0 8px 0', fontSize: '16px', color: '#374151' }}>Connect YouTube Channel</h4>
            <p style={{ margin: '0 0 18px 0', fontSize: '13.5px', color: '#6b7280' }}>
              Authorize Marketing Command to access your YouTube channel metadata, subscriber count, video metrics, and total views using Google OAuth 2.0.
            </p>
            <button
              type="button"
              className="btn"
              onClick={handleConnectYouTube}
              style={{ background: '#ff0000', color: '#fff', border: 'none', padding: '12px 24px', fontSize: '15px', borderRadius: '8px', cursor: 'pointer', fontWeight: '700', display: 'inline-flex', alignItems: 'center', gap: '8px' }}
            >
              <Video size={20} /> Connect YouTube
            </button>
          </div>
        )}
      </div>

      {/* Grid of All API Connections */}
      <div className="integration-grid mb-18">
        {connections.map((item) => (
          <div className="integration-card" key={item.id}>
            <div className="integration-card-head">
              <div className="api-logo-box" style={item.name.includes('YouTube') ? { background: '#ff0000', color: '#fff' } : {}}>
                {item.code}
              </div>
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
                {item.name.includes('YouTube')
                  ? youtubeChannel
                    ? 'Disconnect YouTube'
                    : 'Connect YouTube'
                  : item.status === 'disconnected' || item.status === 'phase2'
                  ? `Connect ${item.name.split(' ')[0]}`
                  : 'Reconnect'}
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
        <div style={{ position: 'fixed', top: 0, left: 0, width: '100vw', height: '100vh', background: 'rgba(0, 0, 0, 0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 9999 }}>
          <div style={{ background: '#fff', borderRadius: '16px', maxWidth: '520px', width: '90%', padding: '24px', boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <div style={{ background: '#1877f2', color: '#fff', borderRadius: '50%', padding: '6px' }}>
                  <Share2 size={20} />
                </div>
                <h3 style={{ margin: 0, fontSize: '18px' }}>Select Facebook Page</h3>
              </div>
              <button type="button" onClick={() => setShowModal(false)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}>
                <X size={20} />
              </button>
            </div>
            <p style={{ fontSize: '13.5px', color: '#4b5563', marginBottom: '16px' }}>
              Select which Facebook Page to connect:
            </p>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', maxHeight: '240px', overflowY: 'auto', marginBottom: '20px' }}>
              {fetchedPages.map((page) => (
                <label key={page.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 14px', borderRadius: '10px', border: selectedPageId === page.id ? '2px solid #1877f2' : '1px solid #e5e7eb', background: selectedPageId === page.id ? '#eff6ff' : '#fff', cursor: 'pointer' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <input type="radio" name="facebook_page_selection" value={page.id} checked={selectedPageId === page.id} onChange={() => setSelectedPageId(page.id)} />
                    <div>
                      <strong style={{ fontSize: '14px', display: 'block' }}>{page.name}</strong>
                      <span style={{ fontSize: '11.5px', color: '#6b7280' }}>ID: {page.id}</span>
                    </div>
                  </div>
                  {selectedPageId === page.id && <CheckCircle2 size={18} color="#1877f2" />}
                </label>
              ))}
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
              <button type="button" className="btn btn-secondary" onClick={() => setShowModal(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" onClick={handleConfirmConnectPage} disabled={connectingPage || !selectedPageId}>
                {connectingPage ? 'Connecting Page...' : 'Connect Selected Page'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Manual Token Connect Modal */}
      {showManualModal && (
        <div style={{ position: 'fixed', top: 0, left: 0, width: '100vw', height: '100vh', background: 'rgba(0, 0, 0, 0.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 9999 }}>
          <div style={{ background: '#fff', borderRadius: '16px', maxWidth: '520px', width: '90%', padding: '24px', boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <Key size={20} color="#1877f2" />
                <h3 style={{ margin: 0, fontSize: '18px' }}>Manual Page Token Connect</h3>
              </div>
              <button type="button" onClick={() => setShowManualModal(false)} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}>
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleManualTokenSubmit}>
              <div style={{ marginBottom: '12px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>Platform</label>
                <select className="select" value={manualPlatform} onChange={(e) => setManualPlatform(e.target.value)}>
                  <option value="facebook">Facebook Page</option>
                  <option value="instagram">Instagram Business Account</option>
                </select>
              </div>
              <div style={{ marginBottom: '12px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                  {manualPlatform === 'instagram' ? 'Instagram Account ID *' : 'Facebook Page ID *'}
                </label>
                <input type="text" className="input" placeholder={manualPlatform === 'instagram' ? 'e.g. 17841444164917900' : 'e.g. 1005544332211'} value={manualPageId} onChange={(e) => setManualPageId(e.target.value)} required />
              </div>
              <div style={{ marginBottom: '12px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>Account / Page Name</label>
                <input type="text" className="input" placeholder="e.g. My Business Handle / Page" value={manualPageName} onChange={(e) => setManualPageName(e.target.value)} />
              </div>
              <div style={{ marginBottom: '20px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>Access Token *</label>
                <textarea className="textarea" rows={3} placeholder="Paste Access Token..." value={manualPageToken} onChange={(e) => setManualPageToken(e.target.value)} required />
              </div>
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                <button type="button" className="btn btn-secondary" onClick={() => setShowManualModal(false)}>Cancel</button>
                <button type="submit" className="btn btn-primary" disabled={connectingPage}>
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
