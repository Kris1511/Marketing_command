import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  AlertCircle,
  ChevronDown,
  Inbox,
  Key,
  Loader2,
  MessageCircle,
  RefreshCw,
  Search,
  Send,
  X,
} from 'lucide-react';
import axiosInstance, { BACKEND_URL, API_BASE_URL } from '../api/axiosInstance';
import { useWorkspace } from '../context/WorkspaceContext';

const INITIAL_CONVERSATION_LIMIT = 10;

function FacebookIcon({ size = 18, style = {} }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" style={style}>
      <circle cx="12" cy="12" r="12" fill="#1877F2" />
      <path
        d="M16.5 12.05H13.6V20.5H10.1V12.05H8.45V9.08H10.1V6.93C10.1 5.57 10.74 4 13.64 4L16.27 4.01V6.89H14.36C14.05 6.89 13.6 7.04 13.6 7.7V9.08H16.57L16.5 12.05Z"
        fill="white"
      />
    </svg>
  );
}

function InstagramIcon({ size = 18, style = {} }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" style={style}>
      <defs>
        <linearGradient id="ig-grad-inbox-icon" x1="0%" y1="100%" x2="100%" y2="0%">
          <stop offset="0%" stopColor="#f09433" />
          <stop offset="25%" stopColor="#e6683c" />
          <stop offset="50%" stopColor="#dc2743" />
          <stop offset="75%" stopColor="#cc2366" />
          <stop offset="100%" stopColor="#bc1888" />
        </linearGradient>
      </defs>
      <rect width="24" height="24" rx="6.5" fill="url(#ig-grad-inbox-icon)" />
      <path
        d="M12 5.838c2.007 0 2.245.008 3.038.044.733.033 1.132.156 1.397.259.351.136.602.299.865.563.264.263.427.514.563.865.103.265.226.664.259 1.397.036.793.044 1.031.044 3.038 0 2.007-.008 2.245-.044 3.038-.033.733-.156 1.132-.259 1.397-.136.351-.299.602-.563.865-.263.264-.514.427-.865.563-.265.103-.664.226-1.397.259-.793.036-1.031.044-3.038.044-2.007 0-2.245-.008-3.038-.044-.733-.033-1.132-.156-1.397-.259-.351-.136-.602-.299-.865-.563-.264-.263-.427-.514-.563-.865-.103-.265-.226-.664-.259-1.397-.036-.793-.044-1.031-.044-3.038 0-2.007.008-2.245.044-3.038.033-.733.156-1.132.259-1.397.136-.351.299-.602.563-.865.263-.264.514-.427.865-.563.265-.103.664-.226 1.397-.259.793-.036 1.031-.044 3.038-.044zm0-1.638c-2.042 0-2.298.009-3.101.045-.801.037-1.348.164-1.827.35-.495.192-.915.45-1.334.868-.418.419-.676.839-.868 1.334-.186.479-.313 1.026-.35 1.827-.036.803-.045 1.059-.045 3.101s.009 2.298.045 3.101c.037.801.164 1.348.35 1.827.192.495.45.915.868 1.334.419.418.839.676 1.334.868.479.186 1.026.313 1.827.35.803.036 1.059.045 3.101.045s2.298-.009 3.101-.045c.801-.037 1.348-.164 1.827-.35.495-.192.915-.45 1.334-.868.418-.419.676-.839.868-1.334.186-.479.313-1.026.35-1.827.036-.803.045-1.059.045-3.101s-.009-2.298-.045-3.101c-.037-.801-.164-1.348-.35-1.827-.192-.495-.45-.915-.868-1.334-.419-.418-.839-.676-1.334-.868-.479-.186-1.026-.313-1.827-.35-.803-.036-1.059-.045-3.101-.045zm0 3.738a4.162 4.162 0 100 8.324 4.162 4.162 0 000-8.324zm0 6.686a2.524 2.524 0 110-5.048 2.524 2.524 0 010 5.048zm4.331-6.855a.973.973 0 100-1.946.973.973 0 000 1.946z"
        fill="white"
      />
    </svg>
  );
}

function YouTubeIcon({ size = 18, style = {} }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" style={style}>
      <rect width="24" height="24" rx="6" fill="#FF0000" />
      <path d="M10 8.5L16 12L10 15.5V8.5Z" fill="white" />
    </svg>
  );
}

function formatTime(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  return date.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function initials(name) {
  if (!name) return 'U';
  return name
    .replace(/^@/, '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase();
}

function messagePreview(conversation) {
  const latest = conversation.latest_message;
  if (!latest) return 'No message text returned';
  if (latest.message) return latest.message;
  if (latest.attachments?.length) return 'Attachment';
  return 'Message';
}

export default function InboxPage() {
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspace();
  const [platform, setPlatform] = useState('facebook'); // 'facebook' | 'instagram'
  const [conversations, setConversations] = useState([]);
  const [conversationCursor, setConversationCursor] = useState(null);
  const [selectedConversationId, setSelectedConversationId] = useState(null);
  const [messages, setMessages] = useState([]);
  const [messageCursor, setMessageCursor] = useState(null);
  const [loadingConversations, setLoadingConversations] = useState(false);
  const [loadingMessages, setLoadingMessages] = useState(false);
  const [sending, setSending] = useState(false);
  const [replyText, setReplyText] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const [error, setError] = useState('');
  const [permissionError, setPermissionError] = useState(null);
  const [realtimeStatus, setRealtimeStatus] = useState('idle');
  const [lastWebhookEventId, setLastWebhookEventId] = useState(() => {
    return Number(localStorage.getItem('inboxLastWebhookEventId') || 0);
  });

  const selectedConversationRef = useRef(null);
  const lastWebhookEventIdRef = useRef(lastWebhookEventId);
  const conversationsAbortRef = useRef(null);
  const messagesAbortRef = useRef(null);
  const platformRef = useRef(platform);

  const isWorkspaceSelected = Boolean(selectedWorkspaceId && selectedWorkspaceId !== 'all');

  useEffect(() => {
    platformRef.current = platform;
  }, [platform]);

  useEffect(() => {
    selectedConversationRef.current = selectedConversationId;
  }, [selectedConversationId]);

  useEffect(() => {
    lastWebhookEventIdRef.current = lastWebhookEventId;
  }, [lastWebhookEventId]);

  // Filter conversations by search term
  const filteredConversations = useMemo(() => {
    if (!searchQuery.trim()) return conversations;
    const q = searchQuery.toLowerCase().trim();
    return conversations.filter((c) => {
      const name = (c.customer?.name || '').toLowerCase();
      const username = (c.customer?.username || '').toLowerCase();
      const msg = messagePreview(c).toLowerCase();
      return name.includes(q) || username.includes(q) || msg.includes(q);
    });
  }, [conversations, searchQuery]);

  const selectedConversation = useMemo(
    () => conversations.find((item) => item.id === selectedConversationId) || null,
    [conversations, selectedConversationId]
  );

  // Fetch conversations for the active platform
  const fetchConversations = useCallback(
    async ({ append = false, after = null, fresh = false } = {}) => {
      if (!isWorkspaceSelected) return;
      if (conversationsAbortRef.current) {
        conversationsAbortRef.current.abort();
      }
      const controller = new AbortController();
      conversationsAbortRef.current = controller;
      setLoadingConversations(true);
      setError('');
      if (!append) setPermissionError(null);

      const endpoint =
        platformRef.current === 'instagram'
          ? '/instagram/inbox/conversations'
          : platformRef.current === 'youtube'
          ? '/youtube/inbox/conversations'
          : '/facebook/inbox/conversations';

      try {
        const res = await axiosInstance.get(endpoint, {
          params: {
            workspace_id: selectedWorkspaceId,
            limit: INITIAL_CONVERSATION_LIMIT,
            ...(after ? { after } : {}),
            ...(fresh ? { fresh: 1 } : {}),
          },
          signal: controller.signal,
        });

        const rows = res.data?.data || [];
        setConversations((prev) => (append ? [...prev, ...rows] : rows));
        setConversationCursor(res.data?.paging?.after || null);

        if (!append) {
          setSelectedConversationId((current) => {
            if (current && rows.some((r) => r.id === current)) return current;
            return rows[0]?.id || null;
          });
        }
      } catch (err) {
        if (err.name === 'CanceledError' || err.code === 'ERR_CANCELED') return;

        if (
          err.response?.data?.code === 'PERMISSION_REQUIRED' ||
          err.response?.data?.code === 'REAUTHORIZATION_REQUIRED' ||
          err.response?.data?.code === 'NOT_CONNECTED'
        ) {
          setPermissionError(err.response.data);
          setError('');
        } else {
          setPermissionError(null);
          const platformLabel =
            platformRef.current === 'instagram'
              ? 'Instagram Direct'
              : platformRef.current === 'youtube'
              ? 'YouTube'
              : 'Facebook Messenger';
          setError(
            err.response?.data?.message ||
              `Unable to load ${platformLabel} conversations.`
          );
        }

        if (!append) {
          setConversations([]);
          setSelectedConversationId(null);
        }
      } finally {
        if (conversationsAbortRef.current === controller) {
          conversationsAbortRef.current = null;
        }
        setLoadingConversations(false);
      }
    },
    [isWorkspaceSelected, selectedWorkspaceId]
  );

  // Fetch messages for a selected conversation
  const fetchMessages = useCallback(
    async (conversationId, { append = false, after = null } = {}) => {
      if (!isWorkspaceSelected || !conversationId) return;
      if (messagesAbortRef.current) {
        messagesAbortRef.current.abort();
      }
      const controller = new AbortController();
      messagesAbortRef.current = controller;
      setLoadingMessages(true);
      setError('');

      const endpoint =
        platformRef.current === 'instagram'
          ? `/instagram/inbox/conversations/${conversationId}/messages`
          : platformRef.current === 'youtube'
          ? `/youtube/inbox/conversations/${conversationId}/messages`
          : `/facebook/inbox/conversations/${conversationId}/messages`;

      try {
        const res = await axiosInstance.get(endpoint, {
          params: {
            workspace_id: selectedWorkspaceId,
            limit: 25,
            ...(after ? { after } : {}),
          },
          signal: controller.signal,
        });

        const rows = res.data?.data || [];
        const orderedRows = [...rows].sort((a, b) => new Date(a.created_time) - new Date(b.created_time));
        setMessages((prev) => (append ? [...orderedRows, ...prev] : orderedRows));
        setMessageCursor(res.data?.paging?.after || null);
      } catch (err) {
        if (err.name === 'CanceledError' || err.code === 'ERR_CANCELED') return;
        setError(
          err.response?.data?.message ||
            `Unable to load this ${platformRef.current === 'instagram' ? 'Instagram Direct' : 'Messenger'} conversation.`
        );
        if (!append) setMessages([]);
      } finally {
        if (messagesAbortRef.current === controller) {
          messagesAbortRef.current = null;
        }
        setLoadingMessages(false);
      }
    },
    [isWorkspaceSelected, selectedWorkspaceId]
  );

  // Switch active platform
  const handlePlatformChange = (newPlatform) => {
    if (newPlatform === platform) return;
    conversationsAbortRef.current?.abort();
    messagesAbortRef.current?.abort();

    setPlatform(newPlatform);
    setConversations([]);
    setMessages([]);
    setSelectedConversationId(null);
    setConversationCursor(null);
    setMessageCursor(null);
    setReplyText('');
    setSearchQuery('');
    setError('');
    setPermissionError(null);
  };

  // Trigger conversation load when platform or workspace changes
  useEffect(() => {
    if (isWorkspaceSelected) {
      fetchConversations();
    }
    return () => {
      conversationsAbortRef.current?.abort();
      messagesAbortRef.current?.abort();
    };
  }, [fetchConversations, isWorkspaceSelected, platform, selectedWorkspaceId]);

  // Facebook Webhook Subscription Check (deferred)
  useEffect(() => {
    if (!isWorkspaceSelected || platform !== 'facebook') return;

    const workspaceKey = String(selectedWorkspaceId);
    const storageKey = `fb_inbox_webhook_sub_${workspaceKey}`;
    const lastSubscribed = Number(localStorage.getItem(storageKey) || 0);

    if (Date.now() - lastSubscribed < 24 * 60 * 60 * 1000) {
      return;
    }

    const timer = setTimeout(async () => {
      try {
        await axiosInstance.post('/facebook/inbox/subscribe-webhook', {
          workspace_id: selectedWorkspaceId,
        });
        localStorage.setItem(storageKey, String(Date.now()));
      } catch (err) {
        console.warn('Facebook webhook subscription notice:', err.response?.data?.message || err.message);
      }
    }, 1500);

    return () => clearTimeout(timer);
  }, [isWorkspaceSelected, platform, selectedWorkspaceId]);

  // Real-time SSE Connection
  useEffect(() => {
    if (!isWorkspaceSelected) {
      setRealtimeStatus('idle');
      return undefined;
    }

    let isUnmounted = false;
    let eventSource = null;
    let reconnectTimeout = null;
    let consecutiveErrors = 0;
    let refreshDebounce = null;

    setRealtimeStatus('connecting');

    const triggerRefresh = () => {
      if (refreshDebounce) clearTimeout(refreshDebounce);
      refreshDebounce = setTimeout(() => {
        if (isUnmounted) return;
        fetchConversations({ fresh: true });
        const activeId = selectedConversationRef.current;
        if (activeId) {
          fetchMessages(activeId);
        }
      }, 300);
    };

    const connectSSE = () => {
      if (isUnmounted) return;

      const baseURL = axiosInstance.defaults.baseURL || '';
      const ssePath = platform === 'instagram' ? '/instagram/inbox/events' : '/facebook/inbox/events';
      const params = new URLSearchParams({
        workspace_id: String(selectedWorkspaceId),
        since: String(lastWebhookEventIdRef.current || 0),
      });

      try {
        eventSource = new EventSource(`${baseURL}${ssePath}?${params.toString()}`, {
          withCredentials: true,
        });

        eventSource.addEventListener('open', () => {
          if (isUnmounted) return;
          consecutiveErrors = 0;
          setRealtimeStatus('connected');
        });

        eventSource.addEventListener('ready', () => {
          if (isUnmounted) return;
          consecutiveErrors = 0;
          setRealtimeStatus('connected');
        });

        eventSource.addEventListener('messenger.message', (event) => {
          if (isUnmounted) return;
          consecutiveErrors = 0;
          setRealtimeStatus('connected');

          try {
            const payload = JSON.parse(event.data || '{}');
            const eventId = Number(payload.id || event.lastEventId || 0);
            if (eventId > 0) {
              lastWebhookEventIdRef.current = eventId;
              setLastWebhookEventId(eventId);
              localStorage.setItem('inboxLastWebhookEventId', String(eventId));
            }
          } catch (e) {
            // Ignore parse errors
          }

          triggerRefresh();
        });

        eventSource.addEventListener('error', () => {
          if (isUnmounted) return;
          consecutiveErrors++;
          if (consecutiveErrors > 2) {
            setRealtimeStatus('reconnecting');
          }

          if (eventSource && eventSource.readyState === EventSource.CLOSED) {
            eventSource.close();
            const backoff = Math.min(30000, 3000 * Math.pow(1.5, Math.min(consecutiveErrors, 5)));
            reconnectTimeout = setTimeout(connectSSE, backoff);
          }
        });
      } catch (err) {
        consecutiveErrors++;
        setRealtimeStatus('reconnecting');
        const backoff = Math.min(30000, 3000 * Math.pow(1.5, Math.min(consecutiveErrors, 5)));
        reconnectTimeout = setTimeout(connectSSE, backoff);
      }
    };

    const initTimer = setTimeout(connectSSE, 200);

    return () => {
      isUnmounted = true;
      clearTimeout(initTimer);
      if (reconnectTimeout) clearTimeout(reconnectTimeout);
      if (refreshDebounce) clearTimeout(refreshDebounce);
      if (eventSource) {
        eventSource.close();
      }
    };
  }, [fetchConversations, fetchMessages, isWorkspaceSelected, platform, selectedWorkspaceId]);

  // Load messages whenever selected conversation changes
  useEffect(() => {
    setMessages([]);
    setMessageCursor(null);
    setReplyText('');
    if (selectedConversationId) {
      fetchMessages(selectedConversationId);
    }
  }, [fetchMessages, selectedConversationId]);

  // Listen for OAuth completion messages to auto-refresh
  useEffect(() => {
    const handleOauthMessage = async (event) => {
      if (
        event.data?.type === 'FACEBOOK_PAGES_FETCHED' ||
        event.data?.type === 'FACEBOOK_ALREADY_CONNECTED'
      ) {
        const pages = event.data?.pages || [];
        const sessId = event.data?.oauth_session_id || '';
        if (pages.length > 0) {
          const page = pages.find((p) => p.id === '115864121526929') || pages[0];
          try {
            await axiosInstance.post('/facebook/connect-page', {
              workspace_id: selectedWorkspaceId,
              page_id: page.id,
              page_name: page.name,
              page_access_token: page.access_token || undefined,
              oauth_session_id: sessId || undefined,
              instagram_account_id: page.instagram_business_account?.id || undefined,
              instagram_username:
                page.instagram_business_account?.username ||
                page.instagram_business_account?.name ||
                undefined,
            });
          } catch (err) {
            console.warn('Auto-connect on OAuth callback notice:', err);
          }
        }
        setPermissionError(null);
        setError('');
        fetchConversations({ fresh: true });
      } else if (
        event.data?.type === 'YOUTUBE_OAUTH_RESULT' ||
        event.data?.type === 'YOUTUBE_CONNECTED'
      ) {
        if (event.data?.success) {
          setPermissionError(null);
          setError('');
          fetchConversations({ fresh: true });
        }
      }
    };
    window.addEventListener('message', handleOauthMessage);
    return () => window.removeEventListener('message', handleOauthMessage);
  }, [fetchConversations, selectedWorkspaceId]);

  // Auto-refresh YouTube conversations every 15 seconds to receive latest comments automatically
  useEffect(() => {
    if (!isWorkspaceSelected || platform !== 'youtube') return;

    const interval = setInterval(() => {
      fetchConversations({ fresh: true });
      const activeId = selectedConversationRef.current;
      if (activeId) {
        fetchMessages(activeId);
      }
    }, 15000);

    return () => clearInterval(interval);
  }, [fetchConversations, fetchMessages, isWorkspaceSelected, platform]);

  // Send reply handler
  const handleSendReply = async (event) => {
    event.preventDefault();
    const text = replyText.trim();
    if (!text || !selectedConversationId) return;

    setSending(true);
    setError('');

    try {
      if (platform === 'instagram') {
        const res = await axiosInstance.post('/instagram/inbox/send-message', {
          workspace_id: selectedWorkspaceId,
          conversation_id: selectedConversationId,
          message: text,
        });
        if (res.data?.data) {
          setMessages((prev) => [
            ...prev,
            {
              id: res.data.data.message_id || `temp-${Date.now()}`,
              message: text,
              created_time: new Date().toISOString(),
              direction: 'outbound',
              sender_name: 'You',
              attachments: [],
            },
          ]);
        }
      } else if (platform === 'youtube') {
        const res = await axiosInstance.post(`/youtube/inbox/conversations/${selectedConversationId}/messages`, {
          workspace_id: selectedWorkspaceId,
          message: text,
        });
        if (res.data?.data) {
          setMessages((prev) => [
            ...prev,
            {
              id: res.data.data.message_id || `temp-${Date.now()}`,
              message: text,
              created_time: res.data.data.created_time || new Date().toISOString(),
              direction: 'outbound',
              sender_name: res.data.data.sender_name || 'You',
              attachments: [],
            },
          ]);
        }
      } else {
        await axiosInstance.post(`/facebook/inbox/conversations/${selectedConversationId}/messages`, {
          workspace_id: selectedWorkspaceId,
          message: text,
        });
      }
      setReplyText('');
      await fetchMessages(selectedConversationId);
      await fetchConversations();
    } catch (err) {
      setError(
        err.response?.data?.message ||
          `Unable to send this ${platform === 'instagram' ? 'Instagram Direct' : platform === 'youtube' ? 'YouTube' : 'Messenger'} reply.`
      );
    } finally {
      setSending(false);
    }
  };

  // Reconnect Meta account for Instagram permissions
  const handleReconnectInstagram = () => {
    const wsId = selectedWorkspaceId || 1;
    const targetUrl = `${API_BASE_URL}/auth/facebook?workspace_id=${wsId}&reconnect=true&force=true`;

    const popup = window.open(
      targetUrl,
      'MetaOAuthPopup',
      'width=650,height=750,scrollbars=yes'
    );
    if (!popup || popup.closed || typeof popup.closed === 'undefined') {
      alert('Pop-up was blocked. Please allow pop-ups for this site to complete Meta authentication.');
    }
  };

  // Reconnect Google account for YouTube permissions
  const handleReconnectYouTube = () => {
    const wsId = selectedWorkspaceId || 1;
    const targetUrl = `${BACKEND_URL}/api/youtube/connect?workspace_id=${wsId}&reconnect=true&force=true`;

    const popup = window.open(
      targetUrl,
      'YouTubeOAuthPopup',
      'width=650,height=750,scrollbars=yes'
    );
    if (!popup || popup.closed || typeof popup.closed === 'undefined') {
      alert('Pop-up was blocked. Please allow pop-ups for this site to complete YouTube authentication.');
    }
  };

  return (
    <div>
      {/* Header section with platform tabs and status */}
      <div className="section-head" style={{ marginBottom: '16px' }}>
        <div>
          <h2>Inbox</h2>
          <p>
            {platform === 'instagram' ? (
              <>
                Instagram Direct Messages for{' '}
                <strong>{selectedWorkspace?.name || 'Selected Workspace'}</strong>.
              </>
            ) : platform === 'youtube' ? (
              <>
                YouTube Comments &amp; Replies for{' '}
                <strong>{selectedWorkspace?.name || 'Selected Workspace'}</strong>.
              </>
            ) : (
              <>
                Facebook Page Messenger conversations for{' '}
                <strong>{selectedWorkspace?.name || 'Selected Workspace'}</strong>.
              </>
            )}
          </p>
        </div>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: '8px',
            flexWrap: 'wrap',
            justifyContent: 'flex-end',
          }}
        >
          {isWorkspaceSelected && (
            <span
              className={`pill ${
                realtimeStatus === 'connected'
                  ? 'success'
                  : realtimeStatus === 'degraded'
                  ? 'warning'
                  : ''
              }`}
            >
              <span className="dot"></span>
              {realtimeStatus === 'connected'
                ? 'Real-time active'
                : realtimeStatus === 'reconnecting'
                ? 'Reconnecting'
                : realtimeStatus === 'degraded'
                ? 'Webhook pending'
                : realtimeStatus === 'connecting'
                ? 'Connecting'
                : 'Real-time idle'}
            </span>
          )}
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => fetchConversations({ fresh: true })}
            disabled={!isWorkspaceSelected || loadingConversations}
          >
            <RefreshCw size={14} className={loadingConversations ? 'spin' : ''} />
            Refresh
          </button>
        </div>
      </div>

      {/* Platform Switcher Buttons */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
          marginBottom: '16px',
        }}
      >
        <div
          role="tablist"
          aria-label="Messaging Platform"
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            background: '#f1f5f9',
            padding: '4px',
            borderRadius: '12px',
            gap: '4px',
            border: '1px solid #e2e8f0',
          }}
        >
          <button
            type="button"
            role="tab"
            aria-selected={platform === 'facebook'}
            id="platform-tab-facebook"
            onClick={() => handlePlatformChange('facebook')}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '8px',
              padding: '8px 18px',
              borderRadius: '8px',
              border: 0,
              cursor: 'pointer',
              fontWeight: 600,
              fontSize: '13.5px',
              background: platform === 'facebook' ? '#ffffff' : 'transparent',
              color: platform === 'facebook' ? '#1877f2' : '#64748b',
              boxShadow: platform === 'facebook' ? '0 2px 6px rgba(24, 119, 242, 0.16)' : 'none',
              transition: 'all 0.15s ease-in-out',
            }}
          >
            <FacebookIcon size={18} />
            <span>Facebook</span>
          </button>

          <button
            type="button"
            role="tab"
            aria-selected={platform === 'instagram'}
            id="platform-tab-instagram"
            onClick={() => handlePlatformChange('instagram')}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '8px',
              padding: '8px 18px',
              borderRadius: '8px',
              border: 0,
              cursor: 'pointer',
              fontWeight: 600,
              fontSize: '13.5px',
              background: platform === 'instagram' ? '#ffffff' : 'transparent',
              color: platform === 'instagram' ? '#d92e7f' : '#64748b',
              boxShadow: platform === 'instagram' ? '0 2px 6px rgba(217, 46, 127, 0.16)' : 'none',
              transition: 'all 0.15s ease-in-out',
            }}
          >
            <InstagramIcon size={18} />
            <span>Instagram</span>
          </button>

          <button
            type="button"
            role="tab"
            aria-selected={platform === 'youtube'}
            id="platform-tab-youtube"
            onClick={() => handlePlatformChange('youtube')}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '8px',
              padding: '8px 18px',
              borderRadius: '8px',
              border: 0,
              cursor: 'pointer',
              fontWeight: 600,
              fontSize: '13.5px',
              background: platform === 'youtube' ? '#ffffff' : 'transparent',
              color: platform === 'youtube' ? '#dc2626' : '#64748b',
              boxShadow: platform === 'youtube' ? '0 2px 6px rgba(220, 38, 38, 0.16)' : 'none',
              transition: 'all 0.15s ease-in-out',
            }}
          >
            <YouTubeIcon size={18} />
            <span>YouTube</span>
          </button>
        </div>

        <span style={{ fontSize: '12px', color: '#94a3b8', marginLeft: '4px' }}>
          Viewing {platform === 'instagram' ? 'Instagram Direct' : platform === 'youtube' ? 'YouTube Comments' : 'Facebook Messenger'}
        </span>
      </div>

      {!isWorkspaceSelected && (
        <section className="panel" style={{ minHeight: '220px', display: 'grid', placeItems: 'center' }}>
          <div style={{ textAlign: 'center', color: '#64748b', maxWidth: '420px' }}>
            <Inbox size={34} style={{ color: '#2457e6', marginBottom: '10px' }} />
            <h3 style={{ margin: '0 0 6px', color: '#14213d' }}>Select a workspace</h3>
            <p style={{ margin: 0, fontSize: '13px', lineHeight: 1.5 }}>
              Choose a workspace above to view its connected Facebook Page or Instagram Direct conversations.
            </p>
          </div>
        </section>
      )}

      {/* Instagram Permission Required Card */}
      {isWorkspaceSelected && platform === 'instagram' && permissionError && (
        <div
          style={{
            background: 'linear-gradient(135deg, #fff5f5 0%, #fff0f5 100%)',
            border: '1px solid #fecdd3',
            borderRadius: '12px',
            padding: '22px 24px',
            marginBottom: '16px',
            boxShadow: '0 2px 10px rgba(225, 48, 108, 0.08)',
          }}
        >
          <div style={{ display: 'flex', gap: '16px', alignItems: 'flex-start' }}>
            <div
              style={{
                width: '46px',
                height: '46px',
                borderRadius: '12px',
                background:
                  'linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%)',
                display: 'grid',
                placeItems: 'center',
                flexShrink: 0,
                color: '#ffffff',
                boxShadow: '0 4px 12px rgba(225, 48, 108, 0.25)',
              }}
            >
              <InstagramIcon size={26} />
            </div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '6px' }}>
                <h3 style={{ margin: 0, fontSize: '16px', color: '#881337', fontWeight: 700 }}>
                  Instagram Messaging Permission Required
                </h3>
                {permissionError.account?.username && (
                  <span
                    style={{
                      fontSize: '12px',
                      padding: '2px 8px',
                      borderRadius: '20px',
                      background: '#ffe4e6',
                      color: '#be123c',
                      fontWeight: 600,
                    }}
                  >
                    @{permissionError.account.username}
                  </span>
                )}
              </div>
              <p style={{ margin: '0 0 14px', fontSize: '13px', color: '#4c0519', lineHeight: 1.5 }}>
                {permissionError.message ||
                  'Meta requires the instagram_manage_messages permission to read and reply to Instagram Direct Messages.'}
              </p>
              <div style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
                <button
                  type="button"
                  className="btn btn-primary"
                  onClick={handleReconnectInstagram}
                  style={{
                    background:
                      'linear-gradient(45deg, #f09433 0%, #dc2743 50%, #bc1888 100%)',
                    border: 'none',
                    fontWeight: 600,
                    boxShadow: '0 2px 6px rgba(225, 48, 108, 0.3)',
                  }}
                >
                  <Key size={14} style={{ marginRight: '6px' }} />
                  Reconnect Meta &amp; Grant Messages Permission
                </button>
                <button
                  type="button"
                  className="btn btn-secondary btn-sm"
                  onClick={() => fetchConversations({ fresh: true })}
                  disabled={loadingConversations}
                >
                  <RefreshCw
                    size={13}
                    className={loadingConversations ? 'spin' : ''}
                    style={{ marginRight: '4px' }}
                  />
                  Retry Check
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* YouTube Permission / Reauthorization Required Card */}
      {isWorkspaceSelected && platform === 'youtube' && permissionError && (
        <div
          style={{
            background: 'linear-gradient(135deg, #fff5f5 0%, #fff0f5 100%)',
            border: '1px solid #fecdd3',
            borderRadius: '12px',
            padding: '22px 24px',
            marginBottom: '16px',
            boxShadow: '0 2px 10px rgba(220, 38, 38, 0.08)',
          }}
        >
          <div style={{ display: 'flex', gap: '16px', alignItems: 'flex-start' }}>
            <div
              style={{
                width: '46px',
                height: '46px',
                borderRadius: '12px',
                background: '#FF0000',
                display: 'grid',
                placeItems: 'center',
                flexShrink: 0,
                color: '#ffffff',
                boxShadow: '0 4px 12px rgba(220, 38, 38, 0.25)',
              }}
            >
              <YouTubeIcon size={26} />
            </div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '6px' }}>
                <h3 style={{ margin: 0, fontSize: '16px', color: '#991b1b', fontWeight: 700 }}>
                  YouTube Channel Connection Required
                </h3>
                {permissionError.account?.name && (
                  <span
                    style={{
                      fontSize: '12px',
                      padding: '2px 8px',
                      borderRadius: '20px',
                      background: '#fee2e2',
                      color: '#b91c1c',
                      fontWeight: 600,
                    }}
                  >
                    {permissionError.account.name}
                  </span>
                )}
              </div>
              <p style={{ margin: '0 0 14px', fontSize: '13px', color: '#7f1d1d', lineHeight: 1.5 }}>
                {permissionError.message ||
                  'Your Google YouTube authorization has expired or requires reconnection to view and reply to comments.'}
              </p>
              <div style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
                <button
                  type="button"
                  className="btn btn-primary"
                  onClick={handleReconnectYouTube}
                  style={{
                    background: '#FF0000',
                    border: 'none',
                    fontWeight: 600,
                    boxShadow: '0 2px 6px rgba(220, 38, 38, 0.3)',
                  }}
                >
                  <Key size={14} style={{ marginRight: '6px' }} />
                  Reconnect YouTube Channel
                </button>
                <button
                  type="button"
                  className="btn btn-secondary btn-sm"
                  onClick={() => fetchConversations({ fresh: true })}
                  disabled={loadingConversations}
                >
                  <RefreshCw
                    size={13}
                    className={loadingConversations ? 'spin' : ''}
                    style={{ marginRight: '4px' }}
                  />
                  Retry Check
                </button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Generic Error Alert */}
      {isWorkspaceSelected && error && (
        <div
          style={{
            background: '#fef2f2',
            color: '#991b1b',
            border: '1px solid #fecaca',
            padding: '12px 14px',
            borderRadius: '10px',
            marginBottom: '14px',
            display: 'flex',
            gap: '10px',
            alignItems: 'flex-start',
            fontSize: '13px',
          }}
        >
          <AlertCircle size={18} style={{ flexShrink: 0, marginTop: '1px' }} />
          <span>{error}</span>
        </div>
      )}

      {/* Main Inbox Shell */}
      {isWorkspaceSelected && (
        <section
          className="panel inbox-shell"
          style={{
            padding: 0,
            overflow: 'hidden',
            minHeight: 'calc(100vh - 210px)',
            display: 'grid',
            gridTemplateColumns: 'minmax(280px, 360px) minmax(0, 1fr)',
          }}
        >
          {/* Left Column: Conversations List */}
          <aside
            style={{
              borderRight: '1px solid #e2e8f0',
              minWidth: 0,
              display: 'flex',
              flexDirection: 'column',
            }}
          >
            <div style={{ padding: '14px 16px', borderBottom: '1px solid #e2e8f0' }}>
              <div
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  gap: '10px',
                }}
              >
                <div className="panel-title">
                  <h3>Conversations</h3>
                  <p>
                    {conversations.length}{' '}
                    {platform === 'instagram' ? 'from Instagram' : platform === 'youtube' ? 'from YouTube' : 'from Facebook'}
                  </p>
                </div>
                {loadingConversations && (
                  <Loader2 size={17} className="spin" style={{ color: '#64748b' }} />
                )}
              </div>

              {/* Search conversations */}
              <div style={{ position: 'relative', marginTop: '10px' }}>
                <Search
                  size={14}
                  style={{
                    position: 'absolute',
                    left: '10px',
                    top: '50%',
                    transform: 'translateY(-50%)',
                    color: '#94a3b8',
                    pointerEvents: 'none',
                  }}
                />
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder={
                    platform === 'instagram'
                      ? 'Search Instagram user or message...'
                      : platform === 'youtube'
                      ? 'Search YouTube commenter or comment...'
                      : 'Search customer or message...'
                  }
                  style={{
                    width: '100%',
                    padding: '7px 28px 7px 30px',
                    fontSize: '12.5px',
                    border: '1px solid #e2e8f0',
                    borderRadius: '8px',
                    background: '#f8fafc',
                    outline: 'none',
                    boxSizing: 'border-box',
                  }}
                />
                {searchQuery && (
                  <button
                    type="button"
                    onClick={() => setSearchQuery('')}
                    style={{
                      position: 'absolute',
                      right: '8px',
                      top: '50%',
                      transform: 'translateY(-50%)',
                      border: 0,
                      background: 'transparent',
                      color: '#94a3b8',
                      cursor: 'pointer',
                      padding: '2px',
                      display: 'grid',
                      placeItems: 'center',
                    }}
                  >
                    <X size={13} />
                  </button>
                )}
              </div>
            </div>

            {/* Conversation list items */}
            <div style={{ overflowY: 'auto', flex: 1 }}>
              {loadingConversations && conversations.length === 0 ? (
                <div
                  style={{
                    padding: '28px',
                    textAlign: 'center',
                    color: '#64748b',
                    fontSize: '13px',
                  }}
                >
                  Loading conversations...
                </div>
              ) : platform === 'instagram' && permissionError ? (
                <div
                  style={{
                    padding: '28px 16px',
                    textAlign: 'center',
                    color: '#881337',
                    fontSize: '13px',
                  }}
                >
                  <p style={{ margin: '0 0 10px', fontWeight: 600 }}>
                    Permission Required
                  </p>
                  <p style={{ margin: 0, color: '#94a3b8', fontSize: '12px' }}>
                    Grant <code>instagram_manage_messages</code> via the Reconnect button above to load Instagram conversations.
                  </p>
                </div>
              ) : platform === 'youtube' && permissionError ? (
                <div
                  style={{
                    padding: '28px 16px',
                    textAlign: 'center',
                    color: '#991b1b',
                    fontSize: '13px',
                  }}
                >
                  <p style={{ margin: '0 0 10px', fontWeight: 600 }}>
                    Reconnection Required
                  </p>
                  <p style={{ margin: 0, color: '#94a3b8', fontSize: '12px' }}>
                    Please reconnect your YouTube channel using the button above to load comments.
                  </p>
                </div>
              ) : conversations.length === 0 ? (
                <div
                  style={{
                    padding: '28px',
                    textAlign: 'center',
                    color: '#64748b',
                    fontSize: '13px',
                  }}
                >
                  {platform === 'instagram'
                    ? 'No Instagram Direct conversations found for this account.'
                    : platform === 'youtube'
                    ? 'No YouTube comment threads found for this channel.'
                    : 'No Messenger conversations returned by Facebook for this Page.'}
                </div>
              ) : filteredConversations.length === 0 ? (
                <div
                  style={{
                    padding: '28px',
                    textAlign: 'center',
                    color: '#64748b',
                    fontSize: '13px',
                  }}
                >
                  No conversations match "{searchQuery}".
                  <br />
                  <button
                    type="button"
                    onClick={() => setSearchQuery('')}
                    style={{
                      background: 'none',
                      border: 0,
                      color: '#2457e6',
                      cursor: 'pointer',
                      fontSize: '12px',
                      marginTop: '6px',
                      textDecoration: 'underline',
                    }}
                  >
                    Clear search
                  </button>
                </div>
              ) : (
                filteredConversations.map((conversation) => {
                  const customer = conversation.customer || {};
                  const active = conversation.id === selectedConversationId;
                  const unread = Number(conversation.unread_count || 0);
                  const isIg = platform === 'instagram';
                  const isYt = platform === 'youtube';
                  const displayName =
                    isIg
                      ? customer.username
                        ? `@${customer.username}`
                        : customer.name || 'Instagram user'
                      : isYt
                      ? customer.name || 'YouTube user'
                      : customer.name || 'Facebook user';

                  const avatarUrl = customer.profile_picture_url || customer.profile_pic;

                  return (
                    <button
                      type="button"
                      key={conversation.id}
                      onClick={() => setSelectedConversationId(conversation.id)}
                      style={{
                        width: '100%',
                        border: 0,
                        borderBottom: '1px solid #f1f5f9',
                        background: active
                          ? isIg
                            ? '#fdf2f8'
                            : isYt
                            ? '#fef2f2'
                            : '#eaf0ff'
                          : '#ffffff',
                        padding: '14px 16px',
                        display: 'grid',
                        gridTemplateColumns: '42px minmax(0, 1fr) auto',
                        gap: '11px',
                        alignItems: 'center',
                        textAlign: 'left',
                        cursor: 'pointer',
                      }}
                    >
                      {avatarUrl ? (
                        <img
                          src={avatarUrl}
                          alt={displayName}
                          style={{
                            width: '40px',
                            height: '40px',
                            borderRadius: '50%',
                            objectFit: 'cover',
                            border: active
                              ? isIg
                                ? '2px solid #db2777'
                                : isYt
                                ? '2px solid #dc2626'
                                : '2px solid #2457e6'
                              : '1px solid #e2e8f0',
                          }}
                        />
                      ) : (
                        <span
                          className="initial"
                          style={{
                            borderRadius: '50%',
                            background: active
                              ? isIg
                                ? 'linear-gradient(45deg, #f09433, #dc2743)'
                                : isYt
                                ? '#dc2626'
                                : '#2457e6'
                              : isIg
                              ? '#fdf2f8'
                              : isYt
                              ? '#fef2f2'
                              : '#eaf0ff',
                            color: active
                              ? '#ffffff'
                              : isIg
                              ? '#be185d'
                              : isYt
                              ? '#b91c1c'
                              : '#173aa3',
                          }}
                        >
                          {initials(customer.username || customer.name)}
                        </span>
                      )}

                      <span style={{ minWidth: 0 }}>
                        <strong
                          style={{
                            display: 'block',
                            fontSize: '13px',
                            color: '#14213d',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                          }}
                        >
                          {displayName}
                        </strong>
                        <span
                          style={{
                            display: 'block',
                            marginTop: '4px',
                            fontSize: '12px',
                            color: '#64748b',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            whiteSpace: 'nowrap',
                          }}
                        >
                          {messagePreview(conversation)}
                        </span>
                      </span>

                      <span style={{ display: 'grid', justifyItems: 'end', gap: '6px' }}>
                        <small style={{ color: '#94a3b8', whiteSpace: 'nowrap' }}>
                          {formatTime(conversation.updated_time)}
                        </small>
                        {unread > 0 && (
                          <span
                            className="pill info"
                            style={{
                              padding: '2px 7px',
                              background: isIg ? '#fdf2f8' : undefined,
                              color: isIg ? '#be185d' : undefined,
                              borderColor: isIg ? '#fbcfe8' : undefined,
                            }}
                          >
                            {unread}
                          </span>
                        )}
                      </span>
                    </button>
                  );
                })
              )}
            </div>

            {conversationCursor && (
              <div style={{ padding: '12px 16px', borderTop: '1px solid #e2e8f0' }}>
                <button
                  type="button"
                  className="btn btn-secondary btn-sm btn-block"
                  onClick={() =>
                    fetchConversations({ append: true, after: conversationCursor })
                  }
                  disabled={loadingConversations}
                >
                  <ChevronDown size={14} /> Load more
                </button>
              </div>
            )}
          </aside>

          {/* Right Column: Active Conversation Messages & Composer */}
          <div
            style={{
              minWidth: 0,
              display: 'flex',
              flexDirection: 'column',
              background: '#f8fafc',
            }}
          >
            {selectedConversation ? (
              <>
                {/* Conversation Header */}
                <div
                  style={{
                    padding: '16px 18px',
                    borderBottom: '1px solid #e2e8f0',
                    background: '#ffffff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: '12px',
                  }}
                >
                  <div
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: '11px',
                      minWidth: 0,
                    }}
                  >
                    {selectedConversation.customer?.profile_picture_url || selectedConversation.customer?.profile_pic ? (
                      <img
                        src={selectedConversation.customer?.profile_picture_url || selectedConversation.customer?.profile_pic}
                        alt="Profile"
                        style={{
                          width: '40px',
                          height: '40px',
                          borderRadius: '50%',
                          objectFit: 'cover',
                        }}
                      />
                    ) : (
                      <span className="initial" style={{ borderRadius: '50%' }}>
                        {initials(
                          selectedConversation.customer?.username ||
                            selectedConversation.customer?.name
                        )}
                      </span>
                    )}
                    <div style={{ minWidth: 0 }}>
                      <h3
                        style={{
                          margin: 0,
                          fontSize: '15px',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                          whiteSpace: 'nowrap',
                        }}
                      >
                        {platform === 'instagram'
                          ? selectedConversation.customer?.username
                            ? `@${selectedConversation.customer.username}`
                            : selectedConversation.customer?.name || 'Instagram user'
                          : platform === 'youtube'
                          ? selectedConversation.customer?.name || 'YouTube user'
                          : selectedConversation.customer?.name || 'Facebook user'}
                      </h3>
                      <p style={{ margin: '3px 0 0', color: '#64748b', fontSize: '12px' }}>
                        {selectedConversation.message_count !== null &&
                        selectedConversation.message_count !== undefined
                          ? `${selectedConversation.message_count} messages`
                          : platform === 'instagram'
                          ? 'Direct Message'
                          : platform === 'youtube'
                          ? 'Comment Thread'
                          : 'Messenger'}
                      </p>
                    </div>
                  </div>

                  {platform === 'instagram' ? (
                    <span
                      className="pill"
                      style={{
                        background: 'linear-gradient(45deg, #f09433, #dc2743)',
                        color: '#ffffff',
                        border: 0,
                        fontWeight: 600,
                      }}
                    >
                      Instagram Direct
                    </span>
                  ) : platform === 'youtube' ? (
                    <span
                      className="pill"
                      style={{
                        background: '#FF0000',
                        color: '#ffffff',
                        border: 0,
                        fontWeight: 600,
                      }}
                    >
                      YouTube
                    </span>
                  ) : (
                    <span className="pill">Messenger</span>
                  )}
                </div>

                {/* Message Thread */}
                <div style={{ flex: 1, overflowY: 'auto', padding: '18px' }}>
                  {messageCursor && (
                    <div
                      style={{
                        display: 'flex',
                        justifyContent: 'center',
                        marginBottom: '14px',
                      }}
                    >
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={() =>
                          fetchMessages(selectedConversation.id, {
                            append: true,
                            after: messageCursor,
                          })
                        }
                        disabled={loadingMessages}
                      >
                        <ChevronDown size={14} /> Load older
                      </button>
                    </div>
                  )}

                  {loadingMessages && messages.length === 0 ? (
                    <div
                      style={{
                        color: '#64748b',
                        textAlign: 'center',
                        paddingTop: '40px',
                        fontSize: '13px',
                      }}
                    >
                      Loading messages...
                    </div>
                  ) : messages.length === 0 ? (
                    <div
                      style={{
                        color: '#64748b',
                        textAlign: 'center',
                        paddingTop: '40px',
                        fontSize: '13px',
                      }}
                    >
                      No messages returned for this conversation.
                    </div>
                  ) : (
                    <div style={{ display: 'grid', gap: '12px' }}>
                      {messages.map((message) => {
                        const outbound = message.direction === 'outbound';
                        const isIg = platform === 'instagram';
                        const isYt = platform === 'youtube';
                        const bubbleBg = outbound
                          ? isIg
                            ? 'linear-gradient(135deg, #833ab4 0%, #fd1d1d 50%, #fcb045 100%)'
                            : isYt
                            ? '#dc2626'
                            : '#2457e6'
                          : '#ffffff';

                        return (
                          <div
                            key={message.id}
                            style={{
                              display: 'flex',
                              justifyContent: outbound ? 'flex-end' : 'flex-start',
                            }}
                          >
                            <div
                              style={{
                                maxWidth: 'min(620px, 78%)',
                                background: bubbleBg,
                                color: outbound ? '#ffffff' : '#14213d',
                                border: outbound
                                  ? 'none'
                                  : '1px solid #e2e8f0',
                                borderRadius: outbound
                                  ? '14px 14px 4px 14px'
                                  : '14px 14px 14px 4px',
                                padding: '10px 14px',
                                boxShadow: '0 2px 8px rgba(15,31,61,.04)',
                                wordBreak: 'break-word',
                              }}
                            >
                              <div
                                style={{
                                  fontSize: '13px',
                                  lineHeight: 1.45,
                                  whiteSpace: 'pre-wrap',
                                }}
                              >
                                {message.message ||
                                  (message.attachments?.length
                                    ? 'Attachment'
                                    : 'Message')}
                              </div>
                              {message.attachments?.length > 0 && (
                                <div
                                  style={{
                                    display: 'grid',
                                    gap: '6px',
                                    marginTop: '8px',
                                  }}
                                >
                                  {message.attachments.map((attachment, index) => (
                                    <a
                                      key={`${message.id}-attachment-${index}`}
                                      href={attachment.url}
                                      target="_blank"
                                      rel="noreferrer"
                                      style={{
                                        color: outbound ? '#ffffff' : '#2457e6',
                                        fontSize: '12px',
                                        fontWeight: 700,
                                        textDecoration: 'underline',
                                      }}
                                    >
                                      {attachment.type || 'Attachment'}
                                    </a>
                                  ))}
                                </div>
                              )}
                              <div
                                style={{
                                  marginTop: '6px',
                                  fontSize: '10.5px',
                                  color: outbound
                                    ? 'rgba(255,255,255,.78)'
                                    : '#94a3b8',
                                  textAlign: outbound ? 'right' : 'left',
                                }}
                              >
                                {formatTime(message.created_time)}
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>

                {/* Reply Composer Form */}
                <form
                  onSubmit={handleSendReply}
                  style={{
                    padding: '14px 16px',
                    borderTop: '1px solid #e2e8f0',
                    background: '#ffffff',
                    display: 'grid',
                    gridTemplateColumns: 'minmax(0, 1fr) auto',
                    gap: '10px',
                    alignItems: 'end',
                  }}
                >
                  <textarea
                    className="textarea"
                    value={replyText}
                    onChange={(event) => setReplyText(event.target.value)}
                    placeholder={
                      platform === 'instagram'
                        ? 'Write an Instagram Direct reply...'
                        : platform === 'youtube'
                        ? 'Write a reply to this YouTube comment thread...'
                        : 'Write a Messenger reply...'
                    }
                    rows={2}
                    disabled={sending}
                    style={{
                      minHeight: '46px',
                      maxHeight: '140px',
                      resize: 'vertical',
                    }}
                  />
                  <button
                    type="submit"
                    className="btn btn-primary"
                    disabled={sending || !replyText.trim()}
                    style={{
                      minHeight: '46px',
                      background:
                        platform === 'instagram'
                          ? 'linear-gradient(45deg, #f09433 0%, #dc2743 50%, #bc1888 100%)'
                          : platform === 'youtube'
                          ? '#dc2626'
                          : undefined,
                      borderColor:
                        platform === 'instagram' || platform === 'youtube' ? 'transparent' : undefined,
                    }}
                  >
                    <Send size={16} />
                    {sending ? 'Sending' : 'Send'}
                  </button>
                </form>
              </>
            ) : (
              <div
                style={{
                  flex: 1,
                  display: 'grid',
                  placeItems: 'center',
                  color: '#64748b',
                  textAlign: 'center',
                  padding: '40px 20px',
                }}
              >
                {platform === 'instagram' ? (
                  <div
                    style={{
                      maxWidth: '480px',
                      margin: '0 auto',
                      background: '#ffffff',
                      border: '1px solid #e2e8f0',
                      borderRadius: '16px',
                      padding: '32px 28px',
                      boxShadow: '0 4px 20px rgba(0, 0, 0, 0.04)',
                      textAlign: 'center',
                    }}
                  >
                    <div
                      style={{
                        width: '56px',
                        height: '56px',
                        borderRadius: '16px',
                        background:
                          'linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%)',
                        display: 'grid',
                        placeItems: 'center',
                        margin: '0 auto 16px',
                        color: '#ffffff',
                        boxShadow: '0 4px 14px rgba(225, 48, 108, 0.25)',
                      }}
                    >
                      <InstagramIcon size={30} />
                    </div>

                    <h3 style={{ margin: '0 0 6px', color: '#0f172a', fontSize: '18px', fontWeight: 700 }}>
                      Instagram Direct is Connected
                    </h3>
                    <div style={{ marginBottom: '14px' }}>
                      <span
                        style={{
                          display: 'inline-block',
                          fontSize: '12px',
                          padding: '3px 10px',
                          borderRadius: '20px',
                          background: '#fdf2f8',
                          color: '#db2777',
                          fontWeight: 600,
                          border: '1px solid #fbcfe8',
                        }}
                      >
                        Account Active: @redmindtechnologies
                      </span>
                    </div>

                    <p style={{ margin: '0 0 16px', fontSize: '13px', color: '#64748b', lineHeight: 1.5 }}>
                      Meta's API returned 0 conversations for this Instagram account.
                    </p>

                    <div
                      style={{
                        textAlign: 'left',
                        background: '#f8fafc',
                        border: '1px solid #e2e8f0',
                        borderRadius: '10px',
                        padding: '14px 16px',
                        fontSize: '12.5px',
                        color: '#334155',
                        lineHeight: 1.6,
                        marginBottom: '18px',
                      }}
                    >
                      <strong style={{ display: 'block', color: '#0f172a', marginBottom: '6px' }}>
                        To receive messages here:
                      </strong>
                      <ul style={{ margin: 0, paddingLeft: '18px' }}>
                        <li style={{ marginBottom: '6px' }}>
                          <strong>Instagram App Setting</strong>: In the mobile app, go to <em>Settings &amp; privacy</em> &rarr; <em>Messages and story replies</em> &rarr; <em>Message controls</em> &rarr; <em>Connected tools</em> &rarr; toggle <strong>Allow access to messages</strong> to <strong>ON</strong>.
                        </li>
                        <li>
                          <strong>Send a DM</strong>: Send an Instagram direct message to <strong>@redmindtechnologies</strong>. New incoming messages appear in real-time.
                        </li>
                      </ul>
                    </div>

                    <button
                      type="button"
                      className="btn btn-secondary btn-sm"
                      onClick={() => fetchConversations({ fresh: true })}
                      disabled={loadingConversations}
                      style={{ fontWeight: 600 }}
                    >
                      <RefreshCw
                        size={13}
                        className={loadingConversations ? 'spin' : ''}
                        style={{ marginRight: '6px' }}
                      />
                      Check for New Messages
                    </button>
                  </div>
                ) : (
                  <div>
                    <MessageCircle
                      size={34}
                      style={{
                        color: '#2457e6',
                        marginBottom: '10px',
                      }}
                    />
                    <h3 style={{ margin: '0 0 6px', color: '#14213d' }}>
                      No conversation selected
                    </h3>
                    <p style={{ margin: 0, fontSize: '13px' }}>
                      Choose a Messenger conversation from the list.
                    </p>
                  </div>
                )}
              </div>
            )}
          </div>
        </section>
      )}
    </div>
  );
}
