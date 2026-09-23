import React, { useState, useEffect, useCallback } from 'react';
import {
  CheckCircle2,
  AlertCircle,
  X,
  ShieldCheck,
  Share2,
  Key,
  RefreshCw,
  Trash2,
  ExternalLink,
  Activity,
  Check,
  AlertTriangle,
  RotateCw,
} from 'lucide-react';
import axiosInstance from '../api/axiosInstance';
import { useWorkspace } from '../context/WorkspaceContext';

const PLATFORM_CONFIGS = [
  {
    key: 'facebook',
    name: 'Facebook Pages',
    code: 'FB',
    subtitle: 'Meta Graph API v23.0',
    logoStyle: { background: '#1877f2', color: '#fff' },
  },
  {
    key: 'instagram',
    name: 'Instagram Business',
    code: 'IG',
    subtitle: 'Meta Instagram API',
    logoStyle: {
      background: 'linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%)',
      color: '#fff',
    },
  },
  {
    key: 'youtube',
    name: 'YouTube Channels',
    code: 'YT',
    subtitle: 'YouTube Data API v3',
    logoStyle: { background: '#ff0000', color: '#fff' },
  },
  {
    key: 'google_analytics',
    name: 'Google Analytics',
    code: 'GA',
    subtitle: 'Google Analytics 4 (GA4)',
    logoStyle: { background: '#f59e0b', color: '#fff' },
  },
  {
    key: 'search_console',
    name: 'Google Search Console',
    code: 'SC',
    subtitle: 'Google Search Console API',
    logoStyle: { background: '#0284c7', color: '#fff' },
  },
  {
    key: 'google_business',
    name: 'Google Business Profile',
    code: 'GB',
    subtitle: 'Google Business Profile API',
    logoStyle: { background: '#10b981', color: '#fff' },
  },
  {
    key: 'linkedin',
    name: 'LinkedIn Pages',
    code: 'in',
    subtitle: 'LinkedIn Marketing Developer Platform',
    logoStyle: { background: '#0a66c2', color: '#fff' },
  },
  {
    key: 'twitter',
    name: 'X / Twitter',
    code: 'X',
    subtitle: 'X API v2 Integration',
    logoStyle: { background: '#000000', color: '#fff' },
  },
];

function formatTimeAgo(iso) {
  if (!iso) return 'No sync data';
  const diff = Date.now() - new Date(iso).getTime();
  if (isNaN(diff)) return 'No sync data';
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  return `${days}d ago`;
}

export default function IntegrationsPage() {
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspace();

  const [connections, setConnections] = useState(() =>
    PLATFORM_CONFIGS.map((cfg) => ({
      ...cfg,
      status: 'disconnected',
      statusText: '• Not connected',
      accountName: null,
      lastSync: null,
      timeAgo: 'No data',
      canTest: false,
    }))
  );

  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [syncingKey, setSyncingKey] = useState(null);
  const [syncMsg, setSyncMsg] = useState('');
  const [testingKey, setTestingKey] = useState(null);

  // Facebook & Instagram Modals
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

  // Google Analytics & Search Console State
  const [showGaModal, setShowGaModal] = useState(false);
  const [showGaPropertySelectModal, setShowGaPropertySelectModal] = useState(false);
  const [gaAvailableProperties, setGaAvailableProperties] = useState([]);
  const [gaSelectedPropertyId, setGaSelectedPropertyId] = useState('');
  const [gaPropertyId, setGaPropertyId] = useState('');
  const [gaSaving, setGaSaving] = useState(false);

  const [showGscModal, setShowGscModal] = useState(false);
  const [showGscSiteSelectModal, setShowGscSiteSelectModal] = useState(false);
  const [gscAvailableSites, setGscAvailableSites] = useState([]);
  const [gscSelectedSiteUrl, setGscSelectedSiteUrl] = useState('');
  const [gscSiteUrl, setGscSiteUrl] = useState('');
  const [gscSaving, setGscSaving] = useState(false);

  // Test Results Modal
  const [testResultModal, setTestResultModal] = useState(null);

  // Fetch verified integration statuses strictly for active workspace
  const fetchAllIntegrationStatuses = useCallback(async () => {
    if (!selectedWorkspaceId) return;
    setLoading(true);

    try {
      const res = await axiosInstance.get(`/integrations/status?workspace_id=${selectedWorkspaceId}`);
      if (res.data?.success && Array.isArray(res.data?.data)) {
        const statusMap = {};
        res.data.data.forEach((item) => {
          statusMap[item.key] = item;
        });

        setConnections(
          PLATFORM_CONFIGS.map((cfg) => {
            const item = statusMap[cfg.key];
            const isConn = item?.status === 'connected';
            const isErr = item?.status === 'error';

            return {
              ...cfg,
              status: isConn ? 'connected' : isErr ? 'error' : 'disconnected',
              statusText: isConn
                ? `• Connected${item.account_name ? ` (${item.account_name})` : ''}`
                : isErr
                ? '• Error (Reconnect needed)'
                : '• Not connected',
              accountName: item?.account_name || null,
              lastSync: item?.last_sync || null,
              timeAgo: formatTimeAgo(item?.last_sync),
              canTest: isConn,
            };
          })
        );
      }

      // Also fetch connected Facebook Pages list
      const fbRes = await axiosInstance.get(`/facebook/pages?workspace_id=${selectedWorkspaceId}`);
      if (fbRes.data?.success && Array.isArray(fbRes.data?.data)) {
        setConnectedFbPages(fbRes.data.data);
      } else {
        setConnectedFbPages([]);
      }
    } catch (err) {
      console.error('Error fetching integrations status:', err);
    } finally {
      setLoading(false);
    }
  }, [selectedWorkspaceId]);

  useEffect(() => {
    fetchAllIntegrationStatuses();
  }, [fetchAllIntegrationStatuses]);

  // Listen for OAuth messages from popups
  useEffect(() => {
    const handleMessage = (event) => {
      if (event.data?.type === 'FACEBOOK_PAGES_FETCHED') {
        setOauthError('');
        const pages = event.data.pages || [];
        const sessId = event.data.oauth_session_id || '';
        setOauthSessionId(sessId);
        if (pages.length === 0) {
          setOauthError(
            'No Facebook Pages were returned by Meta. Ensure your Facebook account manages Pages and you granted Page permissions.'
          );
          return;
        }
        setFetchedPages(pages);
        setSelectedPageId(pages[0].id);
        setShowModal(true);
        setSyncMsg(`OAuth success! Logged in as ${event.data.user?.name || 'Facebook User'}. Select your Page below.`);
      } else if (event.data?.type === 'FACEBOOK_ALREADY_CONNECTED') {
        setOauthError('');
        setSyncMsg(event.data.message || 'Facebook is already connected and operational. Reusing verified credentials.');
        fetchAllIntegrationStatuses();
      } else if (event.data?.type === 'FACEBOOK_OAUTH_ERROR') {
        setOauthError(event.data.error || 'Failed to authenticate with Facebook.');
        setSyncMsg('');
      } else if (event.data?.type === 'YOUTUBE_OAUTH_RESULT') {
        if (event.data.success) {
          setSyncMsg(`YouTube Channel Connected: ${event.data.connection?.channel_name || 'Channel active'}`);
          fetchAllIntegrationStatuses();
        } else {
          alert(`YouTube connection error: ${event.data.message}`);
        }
      } else if (event.data?.type === 'TWITTER_OAUTH_RESULT') {
        if (event.data.success) {
          setSyncMsg(`X (Twitter) Connected: @${event.data.connection?.account_name || 'Profile active'}`);
          fetchAllIntegrationStatuses();
        } else {
          alert(`X (Twitter) connection error: ${event.data.message}`);
        }
      } else if (event.data?.type === 'GOOGLE_ANALYTICS_OAUTH_RESULT') {
        if (event.data.success) {
          const props = event.data.properties || [];
          if (props.length > 1) {
            setGaAvailableProperties(props);
            setGaSelectedPropertyId(props[0]?.property_id || '');
            setShowGaPropertySelectModal(true);
            setSyncMsg('Google Analytics authenticated! Please choose your GA4 Property below.');
          } else {
            setSyncMsg(event.data.message || 'Google Analytics connected successfully!');
            fetchAllIntegrationStatuses();
          }
        } else {
          alert(`Google Analytics connection error: ${event.data.message}`);
        }
      } else if (event.data?.type === 'SEARCH_CONSOLE_OAUTH_RESULT') {
        if (event.data.success) {
          const sites = event.data.sites || [];
          if (sites.length > 1) {
            setGscAvailableSites(sites);
            setGscSelectedSiteUrl(sites[0]?.site_url || '');
            setShowGscSiteSelectModal(true);
            setSyncMsg('Google Search Console authenticated! Please choose your verified property below.');
          } else {
            setSyncMsg(event.data.message || 'Google Search Console connected successfully!');
            fetchAllIntegrationStatuses();
          }
        } else {
          alert(`Google Search Console connection error: ${event.data.message}`);
        }
      }
    };

    window.addEventListener('message', handleMessage);
    return () => window.removeEventListener('message', handleMessage);
  }, [fetchAllIntegrationStatuses]);

  // 1. Disconnect Platform Action
  const handleDisconnect = async (platformKey, platformName) => {
    if (!window.confirm(`Are you sure you want to disconnect ${platformName} for this workspace?`)) return;

    try {
      const res = await axiosInstance.post('/integrations/disconnect', {
        workspace_id: selectedWorkspaceId,
        platform: platformKey,
      });

      if (res.data?.success) {
        setSyncMsg(`${platformName} disconnected successfully.`);
        setConnections((prev) =>
          prev.map((c) =>
            c.key === platformKey
              ? {
                  ...c,
                  status: 'disconnected',
                  statusText: '• Not connected',
                  accountName: null,
                  canTest: false,
                }
              : c
          )
        );
        fetchAllIntegrationStatuses();
      }
    } catch (err) {
      alert(err.response?.data?.message || `Error disconnecting ${platformName}.`);
    }
  };

  // 2. Connect Platform Action (with Active Connection Guard)
  const handleConnect = (item) => {
    const wsId = selectedWorkspaceId || 1;

    // Guard: If platform is already connected with valid credentials, reuse them directly (except if re-authenticating Google services)!
    if (item.status === 'connected' && item.key !== 'google_analytics' && item.key !== 'search_console') {
      setSyncMsg(`${item.name} is already connected${item.accountName ? ` (${item.accountName})` : ''}. Verified credentials are active.`);
      handleTestConnection(item.key, item.name);
      return;
    }

    if (item.key === 'facebook' || item.key === 'instagram') {
      const popup = window.open(
        `http://localhost:8000/api/v1/auth/facebook?workspace_id=${wsId}`,
        'MetaOAuthPopup',
        'width=650,height=750,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete Meta authentication.');
      } else {
        setSyncMsg('Connecting with Meta... Complete login in the pop-up window.');
      }
      return;
    }

    if (item.key === 'youtube') {
      const popup = window.open(
        `http://localhost:8000/api/youtube/connect?workspace_id=${wsId}`,
        'GoogleYouTubeOAuth',
        'width=600,height=700,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete Google OAuth.');
      } else {
        setSyncMsg('Connecting to Google OAuth... Complete login in the pop-up.');
      }
      return;
    }

    if (item.key === 'twitter') {
      const popup = window.open(
        `http://localhost:8000/api/twitter/connect?workspace_id=${wsId}`,
        'TwitterOAuth',
        'width=600,height=700,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete X / Twitter OAuth.');
      } else {
        setSyncMsg('Connecting to X (Twitter) OAuth... Complete login in the pop-up.');
      }
      return;
    }

    if (item.key === 'google_analytics') {
      const popup = window.open(
        `http://localhost:8000/api/v1/google-analytics/connect?workspace_id=${wsId}`,
        'GoogleAnalyticsOAuth',
        'width=600,height=700,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete Google OAuth.');
      } else {
        setSyncMsg('Connecting to Google Analytics... Complete login in the pop-up.');
      }
      return;
    }

    if (item.key === 'search_console') {
      const popup = window.open(
        `http://localhost:8000/api/v1/search-console/connect?workspace_id=${wsId}`,
        'GoogleSearchConsoleOAuth',
        'width=600,height=700,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete Google OAuth.');
      } else {
        setSyncMsg('Connecting to Google Search Console... Complete login in the pop-up.');
      }
      return;
    }

    alert(`${item.name} connect flow is ready for API configuration.`);
  };

  // 2b. Explicit Manual Reconnect Fallback (Opens OAuth only after user confirms intent)
  const handleForceReconnect = (item) => {
    const confirmed = window.confirm(
      `Re-authenticate ${item.name}?\n\nThis platform is already connected${item.accountName ? ` (${item.accountName})` : ''}. Only reconnect if you wish to switch accounts or update permissions.`
    );
    if (!confirmed) return;

    const wsId = selectedWorkspaceId || 1;

    if (item.key === 'facebook' || item.key === 'instagram') {
      const popup = window.open(
        `http://localhost:8000/api/v1/auth/facebook?workspace_id=${wsId}&reconnect=true&force=true`,
        'MetaOAuthPopup',
        'width=650,height=750,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete Meta authentication.');
      } else {
        setSyncMsg(`Re-authenticating ${item.name}... Complete login in the pop-up window.`);
      }
      return;
    }

    if (item.key === 'youtube') {
      const popup = window.open(
        `http://localhost:8000/api/youtube/connect?workspace_id=${wsId}&reconnect=true&force=true`,
        'GoogleYouTubeOAuth',
        'width=600,height=700,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete Google OAuth.');
      } else {
        setSyncMsg('Re-authenticating with Google OAuth... Complete login in the pop-up.');
      }
      return;
    }

    if (item.key === 'twitter') {
      const popup = window.open(
        `http://localhost:8000/api/twitter/connect?workspace_id=${wsId}&reconnect=true&force=true`,
        'TwitterOAuth',
        'width=600,height=700,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete X / Twitter OAuth.');
      } else {
        setSyncMsg('Re-authenticating with X (Twitter) OAuth... Complete login in the pop-up.');
      }
      return;
    }

    if (item.key === 'google_analytics') {
      const popup = window.open(
        `http://localhost:8000/api/v1/google-analytics/connect?workspace_id=${wsId}&reconnect=true&force=true`,
        'GoogleAnalyticsOAuth',
        'width=600,height=700,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete Google OAuth.');
      } else {
        setSyncMsg('Re-authenticating with Google Analytics... Complete login in the pop-up.');
      }
      return;
    }

    if (item.key === 'search_console') {
      const popup = window.open(
        `http://localhost:8000/api/v1/search-console/connect?workspace_id=${wsId}&reconnect=true&force=true`,
        'GoogleSearchConsoleOAuth',
        'width=600,height=700,scrollbars=yes'
      );
      if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        alert('Pop-up was blocked. Please allow pop-ups for this site to complete Google OAuth.');
      } else {
        setSyncMsg('Re-authenticating with Google Search Console... Complete login in the pop-up.');
      }
      return;
    }
  };

  // 2c. Single Platform Sync (Refreshes data using existing connection)
  const handleSyncSinglePlatform = async (platformKey, platformName) => {
    setSyncingKey(platformKey);
    setSyncMsg(`Refreshing data for ${platformName} using existing connection...`);
    try {
      const res = await axiosInstance.post('/integrations/sync', {
        workspace_id: selectedWorkspaceId,
        platform: platformKey,
      });
      if (res.data?.success) {
        setSyncMsg(res.data.message || `${platformName} synced successfully.`);
        fetchAllIntegrationStatuses();
      } else {
        alert(res.data?.message || `Sync failed for ${platformName}.`);
      }
    } catch (err) {
      setSyncMsg(err.response?.data?.message || `Sync error for ${platformName}.`);
    } finally {
      setSyncingKey(null);
      setTimeout(() => setSyncMsg(''), 5000);
    }
  };

  // 3. Real API Test Connection Action
  const handleTestConnection = async (platformKey, platformName) => {
    setTestingKey(platformKey);
    try {
      const res = await axiosInstance.post('/integrations/test', {
        workspace_id: selectedWorkspaceId,
        platform: platformKey,
      });

      if (res.data?.success) {
        setTestResultModal({
          success: true,
          platformName,
          message: res.data.message,
          data: res.data.data,
        });
      } else {
        setTestResultModal({
          success: false,
          platformName,
          message: res.data?.message || 'Verification test failed.',
        });
      }
    } catch (err) {
      setTestResultModal({
        success: false,
        platformName,
        message: err.response?.data?.message || err.message || 'API connection test failed.',
      });
    } finally {
      setTestingKey(null);
    }
  };

  // 4. Sync All Connected Accounts
  const handleSyncAll = async () => {
    setSyncing(true);
    setSyncMsg('Syncing all active integrations for this workspace...');

    try {
      const res = await axiosInstance.post('/integrations/sync', {
        workspace_id: selectedWorkspaceId,
      });

      if (res.data?.success) {
        setSyncMsg(res.data.message);
        fetchAllIntegrationStatuses();
      }
    } catch (err) {
      setSyncMsg(err.response?.data?.message || 'Sync failed.');
    } finally {
      setSyncing(false);
      setTimeout(() => setSyncMsg(''), 5000);
    }
  };

  // 5. Confirm Facebook / Instagram Page Selection from OAuth
  const handleConfirmConnectPage = async () => {
    const pageToConnect = fetchedPages.find((p) => p.id === selectedPageId);
    if (!pageToConnect) return;
    setConnectingPage(true);
    try {
      const igId = pageToConnect.instagram_business_account?.id || null;
      const igUsername =
        pageToConnect.instagram_business_account?.username || pageToConnect.instagram_business_account?.name || null;

      const res = await axiosInstance.post('/facebook/connect-page', {
        workspace_id: selectedWorkspaceId,
        page_id: pageToConnect.id,
        page_name: pageToConnect.name,
        page_access_token: pageToConnect.access_token || undefined,
        oauth_session_id: oauthSessionId || undefined,
        instagram_account_id: igId,
        instagram_username: igUsername,
      });

      if (res.data?.success) {
        setShowModal(false);
        setSyncMsg(`Facebook Page "${pageToConnect.name}"${igUsername ? ` and Instagram @${igUsername}` : ''} connected!`);
        fetchAllIntegrationStatuses();
      } else {
        alert(res.data?.message || 'Failed to connect page.');
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Network error connecting page to backend.');
    } finally {
      setConnectingPage(false);
    }
  };

  // 6. Manual Token Connect Submission (All 8 Platforms)
  const handleManualTokenSubmit = async (e) => {
    e.preventDefault();
    if (!manualPageId.trim()) {
      alert('Please enter the required ID or identifier.');
      return;
    }
    setConnectingPage(true);
    try {
      const bodyPayload = {
        workspace_id: selectedWorkspaceId,
        platform: manualPlatform,
        account_id: manualPageId.trim(),
        account_name: manualPageName.trim() || undefined,
        access_token: manualPageToken.trim() || undefined,
      };

      const res = await axiosInstance.post('/integrations/manual-connect', bodyPayload);
      if (res.data?.success) {
        setShowManualModal(false);
        setSyncMsg(res.data.message || 'Account connected successfully!');
        setManualPageId('');
        setManualPageName('');
        setManualPageToken('');
        fetchAllIntegrationStatuses();
        setTimeout(() => setSyncMsg(''), 4000);
      } else {
        alert(res.data?.message || 'Failed to connect account.');
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Error connecting account to backend.');
    } finally {
      setConnectingPage(false);
    }
  };

  // 6b. Confirm GA4 Property Selection from OAuth Discovery
  const handleConfirmSelectGaProperty = async () => {
    if (!gaSelectedPropertyId) {
      alert('Please select a GA4 Property.');
      return;
    }
    const chosen = gaAvailableProperties.find((p) => p.property_id === gaSelectedPropertyId);
    setGaSaving(true);
    try {
      const res = await axiosInstance.post('/google-analytics/select-property', {
        workspace_id: selectedWorkspaceId,
        property_id: gaSelectedPropertyId,
        account_name: chosen ? chosen.name : `GA4 Property (${gaSelectedPropertyId})`,
      });
      if (res.data?.success) {
        setShowGaPropertySelectModal(false);
        setSyncMsg(`GA4 Property "${chosen?.name || gaSelectedPropertyId}" linked successfully!`);
        fetchAllIntegrationStatuses();
      } else {
        alert(res.data?.message || 'Failed to select GA4 Property.');
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Error selecting GA4 property.');
    } finally {
      setGaSaving(false);
    }
  };

  // 6c. Confirm Search Console Site Selection from OAuth Discovery
  const handleConfirmSelectGscSite = async () => {
    if (!gscSelectedSiteUrl) {
      alert('Please select a Search Console site property.');
      return;
    }
    setGscSaving(true);
    try {
      const res = await axiosInstance.post('/search-console/select-site', {
        workspace_id: selectedWorkspaceId,
        site_url: gscSelectedSiteUrl,
      });
      if (res.data?.success) {
        setShowGscSiteSelectModal(false);
        setSyncMsg(`Search Console site "${gscSelectedSiteUrl}" linked successfully!`);
        fetchAllIntegrationStatuses();
      } else {
        alert(res.data?.message || 'Failed to select Search Console site.');
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Error selecting Search Console site.');
    } finally {
      setGscSaving(false);
    }
  };

  // 7. Save GA4 Configuration
  const handleSaveGaConfig = async (e) => {
    e.preventDefault();
    if (!gaPropertyId) {
      alert('Please enter your GA4 Property ID.');
      return;
    }
    setGaSaving(true);
    try {
      const res = await axiosInstance.post('/google-analytics/configure', {
        workspace_id: selectedWorkspaceId,
        property_id: gaPropertyId.trim(),
      });
      if (res.data?.success) {
        setShowGaModal(false);
        setSyncMsg(`Google Analytics GA4 Property (${gaPropertyId}) configured successfully!`);
        fetchAllIntegrationStatuses();
      } else {
        alert(res.data?.message || 'Failed to configure GA4.');
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Error configuring Google Analytics.');
    } finally {
      setGaSaving(false);
    }
  };

  // 8. Save Search Console Configuration
  const handleSaveGscConfig = async (e) => {
    e.preventDefault();
    if (!gscSiteUrl) {
      alert('Please enter your Search Console Site URL or Domain resource.');
      return;
    }
    setGscSaving(true);
    try {
      const res = await axiosInstance.post('/search-console/configure', {
        workspace_id: selectedWorkspaceId,
        site_url: gscSiteUrl.trim(),
      });
      if (res.data?.success) {
        setShowGscModal(false);
        setSyncMsg(`Google Search Console site (${gscSiteUrl}) configured successfully!`);
        fetchAllIntegrationStatuses();
      } else {
        alert(res.data?.message || 'Failed to configure Search Console.');
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Error configuring Search Console.');
    } finally {
      setGscSaving(false);
    }
  };

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <h2>API Connections</h2>
          <p>
            Client workspace integrations and verified OAuth credentials for{' '}
            <strong>{selectedWorkspace?.name || 'Selected Workspace'}</strong>.
          </p>
        </div>
        <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={fetchAllIntegrationStatuses}
            disabled={loading}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}
          >
            <RotateCw size={13} className={loading ? 'spin' : ''} /> Refresh
          </button>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => setShowManualModal(true)}
            style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
          >
            <Key size={14} /> Token Connect
          </button>
          <button
            type="button"
            className="btn btn-primary btn-sm"
            onClick={handleSyncAll}
            disabled={syncing || loading}
          >
            {syncing ? 'Syncing...' : 'Sync all connected accounts'}
          </button>
        </div>
      </div>

      {/* Notifications / Alerts */}
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
            display: 'flex',
            alignItems: 'flex-start',
            gap: '12px',
          }}
        >
          <AlertCircle size={20} style={{ color: '#ef4444', flexShrink: 0, marginTop: '2px' }} />
          <div style={{ flex: 1 }}>
            <strong style={{ display: 'block', fontSize: '14px', marginBottom: '4px', color: '#b91c1c' }}>
              Connection Notice
            </strong>
            <span style={{ color: '#374151' }}>{oauthError}</span>
          </div>
          <button
            type="button"
            onClick={() => setOauthError('')}
            style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#9ca3af' }}
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

      {/* 2. Grid of All 8 API Connections */}
      <div className="integration-grid mb-18" style={{ marginTop: '20px', opacity: loading ? 0.7 : 1 }}>
        {connections.map((item) => {
          const isConnected = item.status === 'connected';

          return (
            <div className="integration-card" key={item.key}>
              <div className="integration-card-head">
                <div className="api-logo-box" style={item.logoStyle}>
                  {item.code}
                </div>
                <div>
                  <h4>{item.name}</h4>
                  <p>{item.accountName ? item.accountName : item.subtitle}</p>
                </div>
              </div>

              <div className="status-bar-wrap">
                <span className={`status-pill ${item.status}`}>{item.statusText}</span>
                <span className="status-time">{item.timeAgo}</span>
              </div>

              <div className="card-action-row" style={{ display: 'flex', gap: '8px' }}>
                {isConnected ? (
                  <>
                    <button
                      type="button"
                      className="btn-test-primary"
                      style={{ flex: 1.2, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '5px' }}
                      disabled={testingKey === item.key}
                      onClick={() => handleTestConnection(item.key, item.name)}
                    >
                      <CheckCircle2 size={13} />
                      {testingKey === item.key ? 'Testing...' : 'Test'}
                    </button>
                    <button
                      type="button"
                      className="btn-outline-dark"
                      style={{ flex: 1, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '5px' }}
                      disabled={syncingKey === item.key || syncing}
                      onClick={() => handleSyncSinglePlatform(item.key, item.name)}
                    >
                      <RotateCw size={12} className={syncingKey === item.key ? 'spin' : ''} />
                      {syncingKey === item.key ? 'Syncing...' : 'Sync'}
                    </button>
                    <button
                      type="button"
                      className="btn-outline-dark"
                      style={{ color: '#ef4444', borderColor: '#fecaca', flex: 1 }}
                      onClick={() => handleDisconnect(item.key, item.name)}
                    >
                      Disconnect
                    </button>
                  </>
                ) : (
                  <>
                    <button
                      type="button"
                      className="btn-outline-dark"
                      style={{ flex: 2 }}
                      onClick={() => handleConnect(item)}
                    >
                      Connect {item.name.split(' ')[0]}
                    </button>
                    <button
                      type="button"
                      className={item.canTest ? 'btn-test-primary' : 'btn-test-disabled'}
                      disabled={!item.canTest || testingKey === item.key}
                      style={{ flex: 1 }}
                      onClick={() => item.canTest && handleTestConnection(item.key, item.name)}
                    >
                      {testingKey === item.key ? 'Testing...' : 'Test'}
                    </button>
                  </>
                )}
              </div>

              {/* Subtle Manual Fallback for Reconnecting Account */}
              {isConnected && (
                <div style={{ marginTop: '8px', textAlign: 'right' }}>
                  <button
                    type="button"
                    onClick={() => handleForceReconnect(item)}
                    style={{
                      background: 'none',
                      border: 'none',
                      color: '#6b7280',
                      fontSize: '11.5px',
                      cursor: 'pointer',
                      padding: '2px 4px',
                      textDecoration: 'underline',
                    }}
                  >
                    Change account / Reconnect
                  </button>
                </div>
              )}
            </div>
          );
        })}
      </div>

      {/* 3. Connected Facebook Pages Listing */}
      {connectedFbPages.length > 0 && (
        <div className="panel mt-18" style={{ marginBottom: '24px' }}>
          <div className="panel-header">
            <div className="panel-title">
              <h3>Active Connected Facebook Pages</h3>
              <p>Verified Facebook Pages with secure Page Access Tokens stored in database</p>
            </div>
            <span className="pill success">Active Verified</span>
          </div>
          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))',
              gap: '16px',
              padding: '16px 0',
            }}
          >
            {connectedFbPages.map((page) => (
              <div
                key={page.id}
                style={{
                  border: '1px solid #e5e7eb',
                  padding: '14px',
                  borderRadius: '10px',
                  background: '#f9fafb',
                }}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                  <div
                    style={{
                      width: '36px',
                      height: '36px',
                      background: '#1877f2',
                      color: '#fff',
                      borderRadius: '50%',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      fontWeight: 'bold',
                    }}
                  >
                    f
                  </div>
                  <div>
                    <strong style={{ fontSize: '14px', display: 'block' }}>{page.account_name}</strong>
                    <span style={{ fontSize: '11px', color: '#6b7280' }}>Page ID: {page.account_id}</span>
                  </div>
                </div>
                <div
                  style={{
                    fontSize: '12px',
                    color: '#059669',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '4px',
                    marginTop: '6px',
                  }}
                >
                  <ShieldCheck size={14} /> Page Access Token Secured & Operational
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* 4. Test Result Modal */}
      {testResultModal && (
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
              maxWidth: '480px',
              width: '90%',
              padding: '24px',
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.15)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                {testResultModal.success ? (
                  <CheckCircle2 size={24} color="#10b981" />
                ) : (
                  <AlertTriangle size={24} color="#ef4444" />
                )}
                <h3 style={{ margin: 0, fontSize: '18px' }}>
                  {testResultModal.platformName} Test Result
                </h3>
              </div>
              <button
                type="button"
                onClick={() => setTestResultModal(null)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}
              >
                <X size={20} />
              </button>
            </div>

            <div
              style={{
                background: testResultModal.success ? '#f0fdf4' : '#fef2f2',
                border: testResultModal.success ? '1px solid #bbf7d0' : '1px solid #fecaca',
                color: testResultModal.success ? '#166534' : '#991b1b',
                padding: '14px',
                borderRadius: '10px',
                marginBottom: '16px',
                fontSize: '13.5px',
                fontWeight: '500',
              }}
            >
              {testResultModal.message}
            </div>

            {testResultModal.data && (
              <div
                style={{
                  background: '#f8fafc',
                  border: '1px solid #e2e8f0',
                  padding: '14px',
                  borderRadius: '10px',
                  fontSize: '12.5px',
                  marginBottom: '20px',
                  display: 'flex',
                  flexDirection: 'column',
                  gap: '6px',
                }}
              >
                {Object.entries(testResultModal.data).map(([k, v]) => (
                  <div key={k} style={{ display: 'flex', justifyContent: 'space-between' }}>
                    <span style={{ color: '#64748b', textTransform: 'capitalize' }}>
                      {k.replace(/_/g, ' ')}:
                    </span>
                    <strong style={{ color: '#1e293b' }}>{String(v)}</strong>
                  </div>
                ))}
              </div>
            )}

            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
              <button type="button" className="btn btn-primary" onClick={() => setTestResultModal(null)}>
                Close
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 5. Facebook / Instagram Page Selection Modal */}
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
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)',
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
              Select which Facebook Page and linked Instagram Professional account to connect to this workspace:
            </p>
            <div
              style={{
                display: 'flex',
                flexDirection: 'column',
                gap: '10px',
                maxHeight: '280px',
                overflowY: 'auto',
                marginBottom: '20px',
              }}
            >
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
                      <strong style={{ fontSize: '14px', display: 'block', color: '#111827' }}>{page.name}</strong>
                      <span style={{ fontSize: '11.5px', color: '#6b7280' }}>Facebook Page ID: {page.id}</span>
                      {page.instagram_business_account ? (
                        <span
                          style={{
                            fontSize: '11.5px',
                            color: '#be185d',
                            display: 'block',
                            marginTop: '3px',
                            fontWeight: '600',
                          }}
                        >
                          📷 Linked Instagram: @
                          {page.instagram_business_account.username || page.instagram_business_account.name} (Professional)
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
              <button type="button" className="btn btn-secondary" onClick={() => setShowModal(false)}>
                Cancel
              </button>
              <button
                type="button"
                className="btn btn-primary"
                onClick={handleConfirmConnectPage}
                disabled={connectingPage || !selectedPageId}
              >
                {connectingPage ? 'Connecting Accounts...' : 'Connect Facebook & Instagram'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 6. Manual Token Connect Modal (Supports all 8 Platforms) */}
      {showManualModal && (() => {
        const platformConfigs = {
          facebook: {
            name: 'Facebook Page',
            idLabel: 'Facebook Page ID *',
            idPlaceholder: 'e.g. 1047677964792425',
            nameLabel: 'Page Name',
            namePlaceholder: 'e.g. Redmind Technologies Test',
            tokenLabel: 'Page Access Token *',
            tokenPlaceholder: 'Paste Page Access Token (EAAB...)',
            tokenRequired: true,
            btnText: 'Connect Facebook Page',
          },
          instagram: {
            name: 'Instagram Business Account',
            idLabel: 'Instagram Business Account ID *',
            idPlaceholder: 'e.g. 17841444194617900',
            nameLabel: 'Account Handle / Username',
            namePlaceholder: 'e.g. @testfor8639',
            tokenLabel: 'Access Token *',
            tokenPlaceholder: 'Paste Instagram Graph API Access Token...',
            tokenRequired: true,
            btnText: 'Connect Instagram Account',
          },
          youtube: {
            name: 'YouTube Channel',
            idLabel: 'YouTube Channel ID *',
            idPlaceholder: 'e.g. UC_x5XG1OV2P6uZZ5FSM9Ttw',
            nameLabel: 'Channel Name / Title',
            namePlaceholder: 'e.g. Redmind Technologies Official',
            tokenLabel: 'OAuth Access Token / API Key (Optional)',
            tokenPlaceholder: 'Paste Google OAuth Token or YouTube API Key...',
            tokenRequired: false,
            btnText: 'Connect YouTube Channel',
          },
          linkedin: {
            name: 'LinkedIn Page',
            idLabel: 'LinkedIn Organization / Page ID *',
            idPlaceholder: 'e.g. 1337482 or urn:li:organization:1337482',
            nameLabel: 'Company Page Name',
            namePlaceholder: 'e.g. Redmind Technologies',
            tokenLabel: 'Access Token *',
            tokenPlaceholder: 'Paste LinkedIn OAuth2 Access Token...',
            tokenRequired: true,
            btnText: 'Connect LinkedIn Page',
          },
          twitter: {
            name: 'X / Twitter Profile',
            idLabel: 'X / Twitter Username or User ID *',
            idPlaceholder: 'e.g. @RedmindTech or 12345678',
            nameLabel: 'Account Display Name',
            namePlaceholder: 'e.g. Redmind Technologies',
            tokenLabel: 'Bearer / Access Token (Optional)',
            tokenPlaceholder: 'Paste X Bearer Token or User Access Token...',
            tokenRequired: false,
            btnText: 'Connect X / Twitter',
          },
          google_analytics: {
            name: 'Google Analytics (GA4)',
            idLabel: 'GA4 Property ID *',
            idPlaceholder: 'e.g. 123456789',
            nameLabel: 'Property Name / Label',
            namePlaceholder: 'e.g. Redmind Main Website',
            tokenLabel: 'Credentials / Token (Optional)',
            tokenPlaceholder: 'Paste JSON credentials text or access token...',
            tokenRequired: false,
            btnText: 'Connect Google Analytics',
          },
          search_console: {
            name: 'Google Search Console',
            idLabel: 'Site URL or Domain Property *',
            idPlaceholder: 'e.g. sc-domain:redmindtechnologies.com or https://example.com/',
            nameLabel: 'Site Name / Property Label',
            namePlaceholder: 'e.g. Redmind Official Website',
            tokenLabel: 'Credentials / Token (Optional)',
            tokenPlaceholder: 'Paste JSON credentials text or access token...',
            tokenRequired: false,
            btnText: 'Connect Search Console',
          },
          google_business: {
            name: 'Google Business Profile',
            idLabel: 'Location ID or Account ID *',
            idPlaceholder: 'e.g. locations/104928472918',
            nameLabel: 'Business / Location Name',
            namePlaceholder: 'e.g. Redmind Technologies HQ',
            tokenLabel: 'Access Token / API Key (Optional)',
            tokenPlaceholder: 'Paste Google OAuth Token or API Key...',
            tokenRequired: false,
            btnText: 'Connect Google Business Profile',
          },
        };

        const currentConfig = platformConfigs[manualPlatform] || platformConfigs.facebook;

        return (
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
                maxWidth: '540px',
                width: '90%',
                padding: '24px',
                boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)',
                maxHeight: '90vh',
                overflowY: 'auto',
              }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                  <Key size={20} color="#1877f2" />
                  <h3 style={{ margin: 0, fontSize: '18px' }}>Manual Connection & Token Setup</h3>
                </div>
                <button
                  type="button"
                  onClick={() => setShowManualModal(false)}
                  style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}
                >
                  <X size={20} />
                </button>
              </div>

              <form onSubmit={handleManualTokenSubmit}>
                <div style={{ marginBottom: '12px' }}>
                  <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                    Select Platform
                  </label>
                  <select
                    className="select"
                    value={manualPlatform}
                    onChange={(e) => {
                      setManualPlatform(e.target.value);
                      setManualPageId('');
                      setManualPageName('');
                      setManualPageToken('');
                    }}
                  >
                    <option value="facebook">Facebook Page</option>
                    <option value="instagram">Instagram Business Account</option>
                    <option value="youtube">YouTube Channel</option>
                    <option value="linkedin">LinkedIn Page</option>
                    <option value="twitter">X / Twitter</option>
                    <option value="google_analytics">Google Analytics (GA4)</option>
                    <option value="search_console">Google Search Console</option>
                    <option value="google_business">Google Business Profile</option>
                  </select>
                </div>

                <div style={{ marginBottom: '12px' }}>
                  <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                    {currentConfig.idLabel}
                  </label>
                  <input
                    type="text"
                    className="input"
                    placeholder={currentConfig.idPlaceholder}
                    value={manualPageId}
                    onChange={(e) => setManualPageId(e.target.value)}
                    required
                  />
                </div>

                <div style={{ marginBottom: '12px' }}>
                  <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                    {currentConfig.nameLabel}
                  </label>
                  <input
                    type="text"
                    className="input"
                    placeholder={currentConfig.namePlaceholder}
                    value={manualPageName}
                    onChange={(e) => setManualPageName(e.target.value)}
                  />
                </div>

                <div style={{ marginBottom: '20px' }}>
                  <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                    {currentConfig.tokenLabel}
                  </label>
                  <textarea
                    className="textarea"
                    rows={3}
                    placeholder={currentConfig.tokenPlaceholder}
                    value={manualPageToken}
                    onChange={(e) => setManualPageToken(e.target.value)}
                    required={currentConfig.tokenRequired}
                  />
                </div>

                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                  <button type="button" className="btn btn-secondary" onClick={() => setShowManualModal(false)}>
                    Cancel
                  </button>
                  <button type="submit" className="btn btn-primary" disabled={connectingPage}>
                    {connectingPage ? 'Connecting...' : currentConfig.btnText}
                  </button>
                </div>
              </form>
            </div>
          </div>
        );
      })()}

      {/* 7. Google Analytics (GA4) Modal */}
      {showGaModal && (
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
              maxWidth: '500px',
              width: '90%',
              padding: '24px',
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <div
                  style={{
                    width: '28px',
                    height: '28px',
                    borderRadius: '6px',
                    background: '#f59e0b',
                    color: '#fff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontWeight: 'bold',
                    fontSize: '12px',
                  }}
                >
                  GA
                </div>
                <h3 style={{ margin: 0, fontSize: '18px' }}>Google Analytics (GA4) Setup</h3>
              </div>
              <button
                type="button"
                onClick={() => setShowGaModal(false)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}
              >
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleSaveGaConfig}>
              <div
                style={{
                  marginBottom: '14px',
                  background: '#f8fafc',
                  padding: '12px',
                  borderRadius: '8px',
                  border: '1px solid #e2e8f0',
                  fontSize: '12px',
                  color: '#475569',
                }}
              >
                <div style={{ marginBottom: '10px' }}>
                  <button
                    type="button"
                    className="btn btn-primary"
                    style={{ width: '100%', justifyContent: 'center', display: 'flex', alignItems: 'center', gap: '8px' }}
                    onClick={() => {
                      setShowGaModal(false);
                      handleConnect({ key: 'google_analytics', name: 'Google Analytics' });
                    }}
                  >
                    <ExternalLink size={14} /> Connect with Google OAuth (Recommended)
                  </button>
                </div>
                <div style={{ textAlign: 'center', margin: '8px 0', color: '#94a3b8', fontSize: '11px', fontWeight: '600' }}>
                  — OR CONFIGURE MANUALLY —
                </div>
                <strong>Manual GA4 Property Setup:</strong>
                <p style={{ margin: '4px 0 0', color: '#64748b' }}>
                  Enter your numeric <strong>GA4 Property ID</strong> to link it to this workspace.
                </p>
              </div>
              <div style={{ marginBottom: '16px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                  GA4 Property ID *
                </label>
                <input
                  type="text"
                  className="input"
                  placeholder="e.g. 538133379"
                  value={gaPropertyId}
                  onChange={(e) => setGaPropertyId(e.target.value)}
                  required
                />
              </div>
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                <button type="button" className="btn btn-secondary" onClick={() => setShowGaModal(false)}>
                  Cancel
                </button>
                <button type="submit" className="btn btn-primary" disabled={gaSaving}>
                  {gaSaving ? 'Saving...' : 'Save Configuration'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* 7b. GA4 Property Selection Modal (from OAuth Discovery) */}
      {showGaPropertySelectModal && (
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
              maxWidth: '540px',
              width: '90%',
              padding: '24px',
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <div
                  style={{
                    width: '28px',
                    height: '28px',
                    borderRadius: '6px',
                    background: '#f59e0b',
                    color: '#fff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontWeight: 'bold',
                    fontSize: '12px',
                  }}
                >
                  GA
                </div>
                <h3 style={{ margin: 0, fontSize: '18px' }}>Select GA4 Property</h3>
              </div>
              <button
                type="button"
                onClick={() => setShowGaPropertySelectModal(false)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}
              >
                <X size={20} />
              </button>
            </div>

            <p style={{ fontSize: '13px', color: '#4b5563', marginBottom: '14px' }}>
              Google Analytics account authenticated! Select which GA4 property you want to connect to{' '}
              <strong>{selectedWorkspace?.name || 'this workspace'}</strong>:
            </p>

            <div style={{ maxHeight: '240px', overflowY: 'auto', marginBottom: '16px', display: 'flex', flexDirection: 'column', gap: '8px' }}>
              {gaAvailableProperties.map((p) => {
                const isSelected = gaSelectedPropertyId === p.property_id;
                return (
                  <div
                    key={p.property_id}
                    onClick={() => setGaSelectedPropertyId(p.property_id)}
                    style={{
                      border: isSelected ? '2px solid #f59e0b' : '1px solid #e2e8f0',
                      background: isSelected ? '#fffbeb' : '#ffffff',
                      borderRadius: '10px',
                      padding: '12px 14px',
                      cursor: 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      transition: 'all 0.15s ease',
                    }}
                  >
                    <div>
                      <strong style={{ fontSize: '13.5px', color: '#1e293b', display: 'block' }}>{p.name}</strong>
                      <span style={{ fontSize: '11.5px', color: '#64748b' }}>
                        {p.account_name ? `${p.account_name} • ` : ''}Property ID: {p.property_id}
                      </span>
                    </div>
                    <div
                      style={{
                        width: '18px',
                        height: '18px',
                        borderRadius: '50%',
                        border: isSelected ? '5px solid #f59e0b' : '2px solid #cbd5e1',
                        background: '#fff',
                      }}
                    />
                  </div>
                );
              })}
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setShowGaPropertySelectModal(false)}
              >
                Cancel
              </button>
              <button
                type="button"
                className="btn btn-primary"
                disabled={gaSaving || !gaSelectedPropertyId}
                onClick={handleConfirmSelectGaProperty}
              >
                {gaSaving ? 'Linking Property...' : 'Confirm Property'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 7b. Google Search Console Site Selector Modal (Discovered via OAuth) */}
      {showGscSiteSelectModal && (
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
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <div
                  style={{
                    width: '30px',
                    height: '30px',
                    borderRadius: '8px',
                    background: '#0284c7',
                    color: '#fff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontWeight: '800',
                    fontSize: '13px',
                  }}
                >
                  SC
                </div>
                <h3 style={{ margin: 0, fontSize: '17px', color: '#1e293b' }}>Select Search Console Site</h3>
              </div>
              <button
                type="button"
                onClick={() => setShowGscSiteSelectModal(false)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}
              >
                <X size={20} />
              </button>
            </div>

            <p style={{ fontSize: '13px', color: '#4b5563', marginBottom: '14px' }}>
              Google Search Console account authenticated! Select which website property belongs to{' '}
              <strong>{selectedWorkspace?.name || 'this workspace'}</strong>:
            </p>

            <div style={{ maxHeight: '240px', overflowY: 'auto', marginBottom: '16px', display: 'flex', flexDirection: 'column', gap: '8px' }}>
              {gscAvailableSites.map((s) => {
                const isSelected = gscSelectedSiteUrl === s.site_url;
                return (
                  <div
                    key={s.site_url}
                    onClick={() => setGscSelectedSiteUrl(s.site_url)}
                    style={{
                      border: isSelected ? '2px solid #0284c7' : '1px solid #e2e8f0',
                      background: isSelected ? '#f0f9ff' : '#ffffff',
                      borderRadius: '10px',
                      padding: '12px 14px',
                      cursor: 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      transition: 'all 0.15s ease',
                    }}
                  >
                    <div>
                      <strong style={{ fontSize: '13.5px', color: '#1e293b', display: 'block' }}>{s.site_url}</strong>
                      <span style={{ fontSize: '11.5px', color: '#64748b' }}>
                        Permission: {s.permission_level || 'siteOwner'}
                      </span>
                    </div>
                    <div
                      style={{
                        width: '18px',
                        height: '18px',
                        borderRadius: '50%',
                        border: isSelected ? '5px solid #0284c7' : '2px solid #cbd5e1',
                        background: '#fff',
                      }}
                    />
                  </div>
                );
              })}
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setShowGscSiteSelectModal(false)}
              >
                Cancel
              </button>
              <button
                type="button"
                className="btn btn-primary"
                disabled={gscSaving || !gscSelectedSiteUrl}
                onClick={handleConfirmSelectGscSite}
                style={{ background: '#0284c7', borderColor: '#0284c7' }}
              >
                {gscSaving ? 'Linking Site...' : 'Confirm Site'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* 8. Google Search Console Modal */}
      {showGscModal && (
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
              maxWidth: '500px',
              width: '90%',
              padding: '24px',
              boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <div
                  style={{
                    width: '28px',
                    height: '28px',
                    borderRadius: '6px',
                    background: '#0284c7',
                    color: '#fff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontWeight: 'bold',
                    fontSize: '12px',
                  }}
                >
                  SC
                </div>
                <h3 style={{ margin: 0, fontSize: '18px' }}>Google Search Console Setup</h3>
              </div>
              <button
                type="button"
                onClick={() => setShowGscModal(false)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#6b7280' }}
              >
                <X size={20} />
              </button>
            </div>
            <form onSubmit={handleSaveGscConfig}>
              <div
                style={{
                  marginBottom: '14px',
                  background: '#f8fafc',
                  padding: '12px',
                  borderRadius: '8px',
                  border: '1px solid #e2e8f0',
                  fontSize: '12px',
                  color: '#475569',
                }}
              >
                <strong>How to connect Search Console:</strong>
                <ol style={{ margin: '6px 0 0 16px', padding: 0 }}>
                  <li>
                    Ensure your service account key is saved in <code>ga-credentials.json</code>.
                  </li>
                  <li>
                    Enter your site URL or domain resource below (e.g.{' '}
                    <code>sc-domain:redmindtechnologies.com</code>).
                  </li>
                </ol>
              </div>
              <div style={{ marginBottom: '16px' }}>
                <label style={{ display: 'block', fontSize: '12px', fontWeight: '600', marginBottom: '4px' }}>
                  Site URL or Domain Property *
                </label>
                <input
                  type="text"
                  className="input"
                  placeholder="e.g. sc-domain:redmindtechnologies.com"
                  value={gscSiteUrl}
                  onChange={(e) => setGscSiteUrl(e.target.value)}
                  required
                />
              </div>
              <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                <button type="button" className="btn btn-secondary" onClick={() => setShowGscModal(false)}>
                  Cancel
                </button>
                <button type="submit" className="btn btn-primary" disabled={gscSaving}>
                  {gscSaving ? 'Saving...' : 'Save Configuration'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
