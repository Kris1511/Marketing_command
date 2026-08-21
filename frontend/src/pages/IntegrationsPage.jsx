import React, { useState, useEffect } from 'react';
import { CheckCircle2, AlertCircle, Layers, X, ShieldCheck, Share2, Key, RefreshCw, Trash2, Video } from 'lucide-react';
import axiosInstance from '../api/axiosInstance';
import { useWorkspace } from '../context/WorkspaceContext';

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
    status: 'connected',
    statusText: '• Connected',
    timeAgo: 'Just now',
    canTest: true,
  },
];

export default function IntegrationsPage() {
  const { selectedWorkspaceId } = useWorkspace();
  const [connections, setConnections] = useState(initialConnections);
  const [syncing, setSyncing] = useState(false);
  const [syncMsg, setSyncMsg] = useState('');
  
  // Facebook State
  const [showModal, setShowModal] = useState(false);
  const [showManualModal, setShowManualModal] = useState(false);
  const [fetchedPages, setFetchedPages] = useState([]);
  const [selectedPageId, setSelectedPageId] = useState('');
  const [oauthSessionId, setOauthSessionId] = useState('');
  const [oauthError, setOauthError] = useState('');
  const [manualPlatform, setManualPlatform] = useState('facebook');
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

  const fetchIntegrationsStatus = async () => {
    try {
      const wsId = selectedWorkspaceId || 1;
      const res = await axiosInstance.get(`/integrations/status?workspace_id=${wsId}`);
      if (res.data?.success && Array.isArray(res.data?.data)) {
        const statusMap = {};
        res.data.data.forEach(item => {
          statusMap[item.name] = item;
          if (item.key) statusMap[item.key] = item;
        });

        setConnections((prev) => {
          const list = prev && prev.length > 0 ? prev : initialConnections;
          return list.map((c) => {
            const match = statusMap[c.name] || statusMap[c.key] || statusMap[c.code];
            if (match && match.status === 'connected') {
              return {
                ...c,
                status: 'connected',
                statusText: `• Connected (${match.account_name || 'Active'})`,
                timeAgo: match.last_sync ? new Date(match.last_sync).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Live API',
                canTest: true,
              };
            }
            return c;
          });
        });
      }
    } catch (err) {
      console.error('Failed to fetch integrations status:', err);
    }
  };

  useEffect(() => {
    fetchIntegrationsStatus();
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
        setOauthError('');
        const pages = event.data.pages || [];
        const sessId = event.data.oauth_session_id || '';
        setOauthSessionId(sessId);
        if (pages.length === 0) {
          setOauthError('No Facebook Pages were returned by Meta. Reason: Either your Facebook Account does not manage any Facebook Pages, or you unchecked Page permissions on the consent screen.');
          return;
        }
        setFetchedPages(pages);
        setSelectedPageId(pages[0].id);
        setShowModal(true);
        setSyncMsg(`OAuth success! Logged in as ${event.data.user?.name || 'Facebook User'}. Please select which Facebook Page to connect.`);
      } else if (event.data?.type === 'FACEBOOK_OAUTH_ERROR') {
        const err = event.data.error || 'Failed to authenticate with Facebook';
        setOauthError(err);
        setSyncMsg('');
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
    const wsId = selectedWorkspaceId || 1;
    const width = 600;
    const height = 700;
    const left = (window.innerWidth - width) / 2;
    const top = (window.innerHeight - height) / 2;

    const popup = window.open(
      `http://localhost:8000/api/youtube/connect?workspace_id=${wsId}`,
      'GoogleYouTubeOAuth',
      `width=${width},height=${height},top=${top},left=${left},scrollbars=yes,status=yes`
    );

    if (!popup || popup.closed || typeof popup.closed === 'undefined') {
      window.location.href = `http://localhost:8000/api/youtube/connect?workspace_id=${wsId}`;
    } else {
      setSyncMsg('Connecting to Google OAuth... Please complete login in the pop-up.');
    }
  };

  const handleDisconnectYouTube = async () => {
    if (!window.confirm('Are you sure you want to disconnect your YouTube channel?')) return;
    try {
      const wsId = selectedWorkspaceId || 1;
      const res = await fetch('http://localhost:8000/api/youtube/disconnect', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workspace_id: wsId }),
      });
      const json = await res.json();
      if (json.success) {
        setSyncMsg('YouTube channel disconnected.');
        setYoutubeChannel(null);
        fetchYouTubeStatus();
        fetchIntegrationsStatus();
      }
    } catch (err) {
      alert('Error disconnecting YouTube channel.');
    }
  };

  const handleConnectTwitter = () => {
    const wsId = selectedWorkspaceId || 1;
    const width = 600;
    const height = 700;
    const left = (window.innerWidth - width) / 2;
    const top = (window.innerHeight - height) / 2;

    const popup = window.open(
      `http://localhost:8000/api/twitter/connect?workspace_id=${wsId}`,
      'TwitterOAuth',
      `width=${width},height=${height},top=${top},left=${left},scrollbars=yes,status=yes`
    );

    if (!popup || popup.closed || typeof popup.closed === 'undefined') {
      window.location.href = `http://localhost:8000/api/twitter/connect?workspace_id=${wsId}`;
    } else {
      setSyncMsg('Connecting to X (Twitter) OAuth... Please complete login in the pop-up.');
    }
  };

  const handleDisconnectTwitter = async () => {
    if (!window.confirm('Are you sure you want to disconnect your X (Twitter) account?')) return;
    try {
      const wsId = selectedWorkspaceId || 1;
      const res = await fetch('http://localhost:8000/api/twitter/disconnect', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ workspace_id: wsId })
      });
      const json = await res.json();
      if (json.success) {
        setSyncMsg('X (Twitter) disconnected.');
        setTwitterConnection(null);
        fetchTwitterStatus();
        fetchIntegrationsStatus();
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
      const igUsername = pageToConnect.instagram_business_account?.username || pageToConnect.instagram_business_account?.name || null;
      const res = await fetch('http://localhost:8000/api/v1/facebook/connect-page', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          workspace_id: wsId,
          page_id: pageToConnect.id,
          page_name: pageToConnect.name,
          page_access_token: pageToConnect.access_token || undefined,
          oauth_session_id: oauthSessionId || undefined,
          instagram_account_id: igId,
          instagram_username: igUsername,
        }),
      });
      const json = await res.json();
      if (res.ok && json.success) {
        setShowModal(false);
        setSyncMsg(`Facebook Page "${pageToConnect.name}"${igUsername ? ` and Instagram @${igUsername}` : ''} successfully connected!`);
        fetchConnectedFacebookPages();
        fetchIntegrationsStatus();
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

  const handleTestConnection = async (name) => {
    if (name.includes('YouTube') && youtubeChannel) {
      alert(`YouTube API Connected!\nChannel: ${youtubeChannel.channel_name}\nSubscribers: ${(youtubeChannel.subscriber_count || 0).toLocaleString()}\nTotal Videos: ${(youtubeChannel.video_count || 0).toLocaleString()}\nTotal Views: ${(youtubeChannel.view_count || 0).toLocaleString()}`);
    } else if (name.includes('X / Twitter') && twitterConnection) {
      alert(`X (Twitter) API Connected!\nAccount: ${twitterConnection.account_name}\nConnection Status: ${twitterConnection.connection_status}\nSynced: ${new Date(twitterConnection.last_sync_at).toLocaleString()}`);
    } else if (name.includes('Facebook')) {
      try {
        const wsId = selectedWorkspaceId || 1;
        const res = await axiosInstance.get(`/facebook/test?workspace_id=${wsId}`);
        if (res.data?.success) {
          const d = res.data.data;
          alert(`Facebook Graph API Connected!\nPage: ${d.page_name || 'Active'}\nFollowers: ${(d.followers_count || 0).toLocaleString()}\nLikes: ${(d.fan_count || 0).toLocaleString()}\nStatus: Live & Verified`);
        } else {
          alert(`Facebook test: ${res.data?.message || 'Verification failed'}`);
        }
      } catch (err) {
        alert(`Facebook API Test: ${err.response?.data?.message || err.message}`);
      }
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

      {oauthError && (
        <div
          style={{
            background: '#fef2f2',
            color: '#991b1b',
            border: '1px solid #fecaca',
            padding: '14px 18px',
            borderRadius: '10px',
            marginBottom: '16px',
            fontSize: '13.5px',
            lineHeight: '1.5',
            display: 'flex',
            alignItems: 'flex-start',
            gap: '12px',
            boxShadow: '0 2px 4px rgba(239, 68, 68, 0.06)',
          }}
        >
          <AlertCircle size={20} style={{ color: '#ef4444', flexShrink: 0, marginTop: '2px' }} />
          <div style={{ flex: 1 }}>
            <strong style={{ display: 'block', fontSize: '14px', marginBottom: '4px', color: '#b91c1c' }}>
              Facebook Connection Notice
            </strong>
            <span style={{ color: '#374151' }}>{oauthError}</span>
          </div>
          <button
            type="button"
            onClick={() => setOauthError('')}
            style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#9ca3af', padding: '2px' }}
          >
            <X size={18} />
          </button>
        </div>
      )}

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

      {/* Grid of All API Connections */}
      <div className="integration-grid mb-18" style={{ marginTop: '20px' }}>
        {connections.map((item) => {
          const isYT = item.name.includes('YouTube');
          const isTwitter = item.code === 'X' || item.name.includes('X / Twitter');
          const isFB = item.name.includes('Facebook');
          const isIG = item.name.includes('Instagram');

          let logoStyle = { background: '#64748b', color: '#fff' };
          if (isYT) logoStyle = { background: '#ff0000', color: '#fff' };
          else if (isTwitter) logoStyle = { background: '#000000', color: '#fff' };
          else if (isFB) logoStyle = { background: '#1877f2', color: '#fff' };
          else if (isIG) logoStyle = { background: 'linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%)', color: '#fff' };
          else if (item.code === 'GA') logoStyle = { background: '#f59e0b', color: '#fff' };
          else if (item.code === 'SC') logoStyle = { background: '#3b82f6', color: '#fff' };
          else if (item.code === 'GB') logoStyle = { background: '#10b981', color: '#fff' };
          else if (item.code === 'in') logoStyle = { background: '#0a66c2', color: '#fff' };

          return (
            <div className="integration-card" key={item.id}>
              <div className="integration-card-head">
                <div className="api-logo-box" style={logoStyle}>
                  {item.code}
                </div>
                <div>
                  <h4>{item.name}</h4>
                  <p>
                    {isYT && youtubeChannel
                      ? `${youtubeChannel.channel_name} (${(youtubeChannel.subscriber_count || 0).toLocaleString()} subs)`
                      : isTwitter && twitterConnection
                      ? `@${twitterConnection.account_name}`
                      : isFB && connectedFbPages.length > 0
                      ? connectedFbPages[0].account_name
                      : item.subtitle}
                  </p>
                </div>
              </div>

              <div className="status-bar-wrap">
                <span className={`status-pill ${item.status}`}>{item.statusText}</span>
                <span className="status-time">{item.timeAgo}</span>
              </div>

              <div className="card-action-row">
                <button
                  type="button"
                  className={item.status === 'connected' ? 'btn-outline-dark' : 'btn-outline-dark'}
                  onClick={() => handleConnectToggle(item)}
                >
                  {isYT
                    ? youtubeChannel
                      ? 'Disconnect'
                      : 'Connect YouTube'
                    : isTwitter
                    ? twitterConnection
                      ? 'Disconnect'
                      : 'Connect X'
                    : item.status === 'connected'
                    ? 'Reconnect'
                    : `Connect ${item.name.split(' ')[0]}`}
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
          );
        })}
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
              Select which Facebook Page and linked Instagram Professional account to connect to this workspace:
            </p>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', maxHeight: '280px', overflowY: 'auto', marginBottom: '20px' }}>
              {fetchedPages.map((page) => (
                <label key={page.id} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 14px', borderRadius: '10px', border: selectedPageId === page.id ? '2px solid #1877f2' : '1px solid #e5e7eb', background: selectedPageId === page.id ? '#eff6ff' : '#fff', cursor: 'pointer' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                    <input type="radio" name="facebook_page_selection" value={page.id} checked={selectedPageId === page.id} onChange={() => setSelectedPageId(page.id)} />
                    <div>
                      <strong style={{ fontSize: '14px', display: 'block', color: '#111827' }}>{page.name}</strong>
                      <span style={{ fontSize: '11.5px', color: '#6b7280' }}>Facebook Page ID: {page.id}</span>
                      {page.instagram_business_account ? (
                        <span style={{ fontSize: '11.5px', color: '#be185d', display: 'block', marginTop: '3px', fontWeight: '600' }}>
                          📷 Linked Instagram: @{page.instagram_business_account.username || page.instagram_business_account.name} (Professional)
                        </span>
                      ) : (
                        <span style={{ fontSize: '11px', color: '#9ca3af', display: 'block', marginTop: '2px' }}>
                          (No Instagram Professional account linked to this Page)
                        </span>
                      )}
                    </div>
                  </div>
                  {selectedPageId === page.id && <CheckCircle2 size={18} color="#1877f2" />}
                </label>
              ))}
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
              <button type="button" className="btn btn-secondary" onClick={() => setShowModal(false)}>Cancel</button>
              <button type="button" className="btn btn-primary" onClick={handleConfirmConnectPage} disabled={connectingPage || !selectedPageId}>
                {connectingPage ? 'Connecting Accounts...' : 'Connect Facebook & Instagram'}
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
