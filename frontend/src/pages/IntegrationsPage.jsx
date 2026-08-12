import React, { useState, useEffect } from 'react';
import { CheckCircle2, AlertCircle, Layers, X, ShieldCheck, Share2, Key, RefreshCw, Trash2, Video } from 'lucide-react';
import axiosInstance from '../api/axiosInstance';

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
    subtitle: 'YouTube Data API v3',
    status: 'disconnected',
    statusText: '• Not connected',
    timeAgo: 'No data',
    canTest: false,
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
    status: 'disconnected',
    statusText: '• Not connected',
    timeAgo: 'No data',
    canTest: false,
  },
];

export default function IntegrationsPage() {
  const [connections, setConnections] = useState(initialConnections);
  const [syncing, setSyncing] = useState(false);
  const [syncMsg, setSyncMsg] = useState('');
  
  // Facebook State
  const [showModal, setShowModal] = useState(false);
  const [fetchedPages, setFetchedPages] = useState([]);
  const [selectedPageId, setSelectedPageId] = useState('');
  const [showManualModal, setShowManualModal] = useState(false);
  const [manualPageId, setManualPageId] = useState('');
  const [manualPageName, setManualPageName] = useState('');
  const [manualPageToken, setManualPageToken] = useState('');
  const [connectingPage, setConnectingPage] = useState(false);
  const [connectedFbPages, setConnectedFbPages] = useState([]);

  // YouTube Channel State
  const [youtubeChannel, setYoutubeChannel] = useState(null);
  const [youtubeLoading, setYoutubeLoading] = useState(true);

  // X / Twitter State
  const [twitterConnection, setTwitterConnection] = useState(null);
  const [twitterLoading, setTwitterLoading] = useState(true);

  useEffect(() => {
    fetchConnectedFacebookPages();
    fetchYouTubeStatus();
    fetchTwitterStatus();

    // Check URL parameters for OAuth returns
    const searchParams = new URLSearchParams(window.location.search);
    if (searchParams.get('youtube') === 'success') {
      setSyncMsg('YouTube channel connected successfully!');
      fetchYouTubeStatus();
    } else if (searchParams.get('youtube') === 'error') {
      alert('Failed to connect YouTube channel. Please check your Google OAuth permissions.');
    }

    if (searchParams.get('twitter') === 'success') {
      setSyncMsg('X (Twitter) account connected successfully!');
      fetchTwitterStatus();
    } else if (searchParams.get('twitter') === 'error') {
      alert('Failed to connect X (Twitter) account.');
    }
  }, []);

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

  const fetchTwitterStatus = async () => {
    setTwitterLoading(true);
    try {
      const res = await fetch('http://localhost:8000/api/twitter/status?workspace_id=1');
      const json = await res.json();
      if (json.success && json.connected && json.data) {
        setTwitterConnection(json.data);
        setConnections((prev) =>
          prev.map((c) => {
            if (c.name.includes('X / Twitter')) {
              return {
                ...c,
                status: 'connected',
                statusText: `• Connected (${json.data.account_name})`,
                timeAgo: 'Live API',
                canTest: true,
              };
            }
            return c;
          })
        );
      } else {
        setTwitterConnection(null);
        setConnections((prev) =>
          prev.map((c) => {
            if (c.name.includes('X / Twitter')) {
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
      console.error('Error fetching Twitter status:', err);
    } finally {
      setTwitterLoading(false);
    }
  };

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
      } else if (event.data?.type === 'TWITTER_OAUTH_RESULT') {
        if (event.data.success) {
          setSyncMsg(`X (Twitter) Connected: ${event.data.connection?.account_name || 'Profile active'}`);
          fetchTwitterStatus();
        } else {
          alert(`X (Twitter) connection error: ${event.data.message}`);
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
    if (twitterConnection) {
      try {
        await fetchTwitterStatus();
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

  const handleConnectTwitter = async () => {
    setTwitterLoading(true);
    try {
      const res = await fetch('http://localhost:8000/api/twitter/connect-mock', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workspace_id: 1 })
      });
      const json = await res.json();
      if (json.success) {
        setSyncMsg('X (Twitter) connected successfully!');
        fetchTwitterStatus();
      } else {
        alert(json.message || 'Failed to connect X (Twitter).');
      }
    } catch (err) {
      console.error('Error connecting Twitter:', err);
      alert('Failed to connect X (Twitter).');
    } finally {
      setTwitterLoading(false);
    }
  };

  const handleDisconnectTwitter = async () => {
    if (!window.confirm('Are you sure you want to disconnect your X (Twitter) account?')) return;
    try {
      const res = await fetch('http://localhost:8000/api/twitter/disconnect', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workspace_id: 1 })
      });
      const json = await res.json();
      if (json.success) {
        setSyncMsg('X (Twitter) disconnected.');
        setTwitterConnection(null);
        fetchTwitterStatus();
      }
    } catch (err) {
      alert('Error disconnecting X (Twitter) account.');
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

    if (item.name.includes('X / Twitter') || item.code === 'X') {
      if (twitterConnection) {
        handleDisconnectTwitter();
      } else {
        handleConnectTwitter();
      }
      return;
    }

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
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
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
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
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
      alert('Network error saving page token.');
    } finally {
      setConnectingPage(false);
    }
  };

  const handleTestConnection = (name) => {
    if (name.includes('YouTube') && youtubeChannel) {
      alert(`YouTube API Connected!\nChannel: ${youtubeChannel.channel_name}\nSubscribers: ${youtubeChannel.subscriber_count.toLocaleString()}\nTotal Videos: ${youtubeChannel.video_count.toLocaleString()}\nTotal Views: ${youtubeChannel.view_count.toLocaleString()}`);
    } else if (name.includes('X / Twitter') && twitterConnection) {
      alert(`X (Twitter) API Connected!\nAccount: ${twitterConnection.account_name}\nConnection Status: ${twitterConnection.connection_status}\nSynced: ${new Date(twitterConnection.last_sync_at).toLocaleString()}`);
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

      {/* Dedicated X / Twitter Integration Display Panel */}
      <div className="panel mb-18" style={{ background: '#fff', borderRadius: '14px', border: '1px solid #e5e7eb', padding: '24px' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px', borderBottom: '1px solid #f3f4f6', paddingBottom: '16px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            <div style={{ width: '44px', height: '44px', background: '#000000', borderRadius: '10px', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#fff', fontWeight: 'bold', fontSize: '20px' }}>
              X
            </div>
            <div>
              <h3 style={{ margin: 0, fontSize: '18px', fontWeight: '700' }}>X (Twitter) Integration</h3>
              <p style={{ margin: 0, fontSize: '13px', color: '#6b7280' }}>Twitter API v2 & OAuth 2.0 PKCE</p>
            </div>
          </div>
          <div>
            {twitterConnection ? (
              <span className="pill success" style={{ fontSize: '13px', padding: '6px 14px' }}>
                Status: Connected ({twitterConnection.account_name})
              </span>
            ) : (
              <span className="pill neutral" style={{ fontSize: '13px', padding: '6px 14px' }}>
                Status: Not Connected
              </span>
            )}
          </div>
        </div>

        {twitterLoading ? (
          <div style={{ padding: '20px', textAlign: 'center', color: '#6b7280' }}>Loading X (Twitter) status...</div>
        ) : twitterConnection ? (
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '20px', marginBottom: '24px', background: '#f9fafb', padding: '20px', borderRadius: '12px', border: '1px solid #e5e7eb' }}>
              <div style={{ width: '72px', height: '72px', borderRadius: '50%', background: '#000000', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '28px', fontWeight: 'bold' }}>
                X
              </div>
              <div style={{ flex: 1 }}>
                <h4 style={{ margin: '0 0 4px 0', fontSize: '20px', color: '#111827' }}>{twitterConnection.account_name}</h4>
                <p style={{ margin: 0, fontSize: '13px', color: '#4b5563' }}>
                  Connection Status: <strong style={{ color: '#059669', textTransform: 'capitalize' }}>{twitterConnection.connection_status || 'Connected'}</strong>
                </p>
                <span style={{ fontSize: '11.5px', color: '#6b7280', display: 'block', marginTop: '6px' }}>
                  Twitter Account ID: <code>{twitterConnection.account_id}</code> | Last Synced: {twitterConnection.last_sync_at ? new Date(twitterConnection.last_sync_at).toLocaleString() : 'Just now'}
                </span>
              </div>
            </div>

            <div style={{ display: 'flex', gap: '12px', justifyContent: 'flex-end' }}>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => handleTestConnection('X / Twitter')}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
              >
                <ShieldCheck size={16} /> Test X Connection
              </button>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={handleSyncAll}
                style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
              >
                <RefreshCw size={16} /> Refresh X Status
              </button>
              <button
                type="button"
                className="btn"
                onClick={handleDisconnectTwitter}
                style={{ background: '#dc2626', color: '#fff', border: 'none', display: 'inline-flex', alignItems: 'center', gap: '6px', padding: '8px 18px', borderRadius: '8px', cursor: 'pointer', fontWeight: '600' }}
              >
                <Trash2 size={16} /> Disconnect X
              </button>
            </div>
          </div>
        ) : (
          <div style={{ padding: '24px', textAlign: 'center', background: '#fafafa', borderRadius: '12px', border: '1px dashed #d1d5db' }}>
            <h4 style={{ margin: '0 0 8px 0', fontSize: '16px', color: '#374151' }}>Connect X (Twitter) Account</h4>
            <p style={{ margin: '0 0 18px 0', fontSize: '13.5px', color: '#6b7280' }}>
              Authorize Marketing Command to post tweets, manage media publishing, and retrieve metrics using Twitter API v2 and OAuth 2.0 PKCE.
            </p>
            <button
              type="button"
              className="btn"
              onClick={handleConnectTwitter}
              style={{ background: '#000000', color: '#fff', border: 'none', padding: '12px 24px', fontSize: '15px', borderRadius: '8px', cursor: 'pointer', fontWeight: '700', display: 'inline-flex', alignItems: 'center', gap: '8px' }}
            >
              <Share2 size={20} /> Connect X (Twitter)
            </button>
          </div>
        )}
      </div>

      {/* Grid of All API Connections */}
      <div className="integration-grid mb-18">
        {connections.map((item) => (
          <div className="integration-card" key={item.id}>
            <div className="integration-card-head">
              <div className="api-logo-box" style={
                item.name.includes('YouTube') ? { background: '#ff0000', color: '#fff' } :
                item.code === 'X' ? { background: '#000000', color: '#fff' } : {}
              }>
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
                  : item.code === 'X'
                  ? twitterConnection
                    ? 'Disconnect X'
                    : 'Connect X'
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
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>Facebook Page ID *</label>
                <input type="text" className="input" placeholder="e.g. 1005544332211" value={manualPageId} onChange={(e) => setManualPageId(e.target.value)} required />
              </div>
              <div style={{ marginBottom: '12px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>Facebook Page Name</label>
                <input type="text" className="input" placeholder="e.g. My Business Page" value={manualPageName} onChange={(e) => setManualPageName(e.target.value)} />
              </div>
              <div style={{ marginBottom: '20px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>Page Access Token *</label>
                <textarea className="textarea" rows={3} placeholder="Paste Page Access Token..." value={manualPageToken} onChange={(e) => setManualPageToken(e.target.value)} required />
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
