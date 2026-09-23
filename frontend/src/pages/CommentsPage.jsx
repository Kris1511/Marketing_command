import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useWorkspace } from '../context/WorkspaceContext';
import axiosInstance from '../api/axiosInstance';
import {
  Check,
  Search,
  Calendar,
  RefreshCw,
  ExternalLink,
  MessageSquare,
  AlertCircle,
  ChevronLeft,
  ChevronRight,
  Filter,
  X,
  Layers,
  Video,
  Film,
  Image as ImageIcon
} from 'lucide-react';

// Platform Brand Icons
function FacebookIcon({ size = 16, style = {} }) {
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

function InstagramIcon({ size = 16, style = {} }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" style={style}>
      <defs>
        <linearGradient id="ig-grad-comments" x1="0%" y1="100%" x2="100%" y2="0%">
          <stop offset="0%" stopColor="#f09433" />
          <stop offset="25%" stopColor="#e6683c" />
          <stop offset="50%" stopColor="#dc2743" />
          <stop offset="75%" stopColor="#cc2366" />
          <stop offset="100%" stopColor="#bc1888" />
        </linearGradient>
      </defs>
      <rect width="24" height="24" rx="6" fill="url(#ig-grad-comments)" />
      <path
        d="M12 5.838c2.007 0 2.245.008 3.038.044.733.033 1.132.156 1.397.259.351.136.602.299.865.563.264.263.427.514.563.865.103.265.226.664.259 1.397.036.793.044 1.031.044 3.038 0 2.007-.008 2.245-.044 3.038-.033.733-.156 1.132-.259 1.397-.136.351-.299.602-.563.865-.263.264-.514.427-.865.563-.265.103-.664.226-1.397.259-.793.036-1.031.044-3.038.044-2.007 0-2.245-.008-3.038-.044-.733-.033-1.132-.156-1.397-.259-.351-.136-.602-.299-.865-.563-.264-.263-.427-.514-.563-.865-.103-.265-.226-.664-.259-1.397-.036-.793-.044-1.031-.044-3.038 0-2.007.008-2.245.044-3.038.033-.733.156-1.132.259-1.397.136-.351.299-.602.563-.865.263-.264.514-.427.865-.563.265-.103.664-.226 1.397-.259.793-.036 1.031-.044 3.038-.044zm0-1.638c-2.042 0-2.298.009-3.101.045-.801.037-1.348.164-1.827.35-.495.192-.915.45-1.334.868-.418.419-.676.839-.868 1.334-.186.479-.313 1.026-.35 1.827-.036.803-.045 1.059-.045 3.101s.009 2.298.045 3.101c.037.801.164 1.348.35 1.827.192.495.45.915.868 1.334.419.418.839.676 1.334.868.479.186 1.026.313 1.827.35.803.036 1.059.045 3.101.045s2.298-.009 3.101-.045c.801-.037 1.348-.164 1.827-.35.495-.192.915-.45 1.334-.868.418-.419.676-.839.868-1.334.186-.479.313-1.026.35-1.827.036-.803.045-1.059.045-3.101s-.009-2.298-.045-3.101c-.037-.801-.164-1.348-.35-1.827-.192-.495-.45-.915-.868-1.334-.419-.418-.839-.676-1.334-.868-.479-.186-1.026-.313-1.827-.35-.803-.036-1.059-.045-3.101-.045zm0 3.738a4.162 4.162 0 100 8.324 4.162 4.162 0 000-8.324zm0 6.686a2.524 2.524 0 110-5.048 2.524 2.524 0 010 5.048zm4.331-6.855a.973.973 0 100-1.946.973.973 0 000 1.946z"
        fill="white"
      />
    </svg>
  );
}

function YouTubeIcon({ size = 16, style = {} }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" style={style}>
      <rect width="24" height="24" rx="6" fill="#FF0000" />
      <path d="M10 8.5L15.5 12L10 15.5V8.5Z" fill="white" />
    </svg>
  );
}

// Parse comment timestamp reliably across all browsers & formats
function parseCommentDate(val) {
  if (!val) return null;
  if (val instanceof Date) return isNaN(val.getTime()) ? null : val;
  const trimmed = String(val).trim();
  if (!trimmed) return null;

  // Numeric epoch
  if (/^\d+$/.test(trimmed)) {
    const num = Number(trimmed);
    const d = new Date(num < 1e11 ? num * 1000 : num);
    return isNaN(d.getTime()) ? null : d;
  }

  // Standard MySQL string without timezone: "YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DDTHH:MM:SS"
  // Since backend stores in UTC, append Z so JavaScript parses it consistently as UTC:
  if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/.test(trimmed)) {
    const iso = trimmed.replace(' ', 'T') + 'Z';
    const d = new Date(iso);
    if (!isNaN(d.getTime())) return d;
  }

  const direct = new Date(trimmed);
  if (!isNaN(direct.getTime())) return direct;

  const withT = new Date(trimmed.replace(' ', 'T'));
  if (!isNaN(withT.getTime())) return withT;

  return null;
}

// Normalize date string (handles YYYY-MM-DD, MM/DD/YYYY, DD/MM/YYYY, etc.)
function normalizeDateInput(val) {
  if (!val) return '';
  const trimmed = String(val).trim();
  if (!trimmed) return '';

  // YYYY-MM-DD
  const ymd = trimmed.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})/);
  if (ymd) {
    return `${ymd[1]}-${String(ymd[2]).padStart(2, '0')}-${String(ymd[3]).padStart(2, '0')}`;
  }

  // MM/DD/YYYY or DD/MM/YYYY
  const mdy = trimmed.match(/^(\d{1,2})[-/](\d{1,2})[-/](\d{4})/);
  if (mdy) {
    let m = Number(mdy[1]);
    let d = Number(mdy[2]);
    const y = mdy[3];
    if (m > 12 && d <= 12) {
      const t = m;
      m = d;
      d = t;
    }
    return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
  }

  return trimmed;
}

// Helper to format date & time nicely (e.g. "14 Aug 2026, 01:19 PM")
function formatCommentDate(dateStr) {
  if (!dateStr) return '—';
  try {
    const d = parseCommentDate(dateStr);
    if (!d) return String(dateStr);

    const dateFormatted = d.toLocaleDateString('en-GB', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });

    const timeFormatted = d.toLocaleTimeString('en-US', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: true,
    });

    return `${dateFormatted}, ${timeFormatted}`;
  } catch {
    return String(dateStr);
  }
}

// Parse comment message to separate author and comment body
function parseCommentMessage(rawMessage) {
  if (!rawMessage) return { author: 'User', comment: '' };

  const parts = rawMessage.split(/\n\n+/);
  let author = '';
  let comment = '';

  if (parts.length >= 2) {
    const header = parts[0];
    comment = parts.slice(1).join('\n\n').replace(/^["']|["']$/g, '').trim();

    const commentedMatch = header.match(/^(.*?)\s+commented\s+on/i);
    const receivedMatch = header.match(/^(.*?)\s+received/i);

    if (commentedMatch) {
      author = commentedMatch[1].trim();
    } else if (receivedMatch) {
      author = receivedMatch[1].trim();
    } else {
      author = header.trim();
    }
  } else {
    comment = rawMessage;
    author = 'User';
  }

  // Clean author name if needed
  if (author.startsWith('@')) {
    author = author.substring(1);
  }

  return { author: author || 'User', comment: comment || rawMessage };
}

function getCommentParentId(item) {
  const rel = item?.related_entity || '';

  if (item?.type === 'facebook_comment') {
    const cleanRel = rel.replace(/^facebook_comment:/, '').trim();
    const parts = cleanRel.split(':').filter(Boolean);
    if (parts.length >= 2) return parts[parts.length - 1];

    const commentId = parts[0] || cleanRel;
    if (!commentId) return null;

    // Check if format is PAGEID_POSTID_COMMENTID (e.g. 1247592255107307_122107128063421701_1639008021116388)
    const tripleMatch = String(commentId).match(/^(\d+_\d+)_\d+$/);
    if (tripleMatch) return tripleMatch[1];

    // Check if format is POSTID_COMMENTID (e.g. 122107473015421701_1583842176765714)
    const doubleMatch = String(commentId).match(/^(\d+)_\d+$/);
    if (doubleMatch) return doubleMatch[1];

    return commentId;
  }

  if (item?.type === 'instagram_comment') {
    const cleanRel = rel.replace(/^instagram_comment:/, '').trim();
    const parts = cleanRel.split(':').filter(Boolean);
    if (parts.length >= 2) {
      return parts[parts.length - 1].replace(/^media_/, '');
    }
    const single = parts[0] || cleanRel;
    if (!single) return null;
    return single.replace(/^media_/, '');
  }

  if (item?.type === 'youtube_comment') {
    const cleanRel = rel.replace(/^youtube_comment:/, '').trim();
    const parts = cleanRel.split(':').filter(Boolean);
    if (parts.length >= 2) {
      const vid = parts[parts.length - 1];
      if (vid && vid !== 'channel') return vid;
    }
    return null;
  }

  return null;
}

function getCanonicalParentId(item) {
  const parentId = getCommentParentId(item);
  if (!parentId) return null;

  // For Facebook composite IDs like "115864121526929_982499514861065",
  // normalize to the bare post id if it contains an underscore between digits
  if (item?.type === 'facebook_comment' && String(parentId).includes('_')) {
    const subParts = String(parentId).split('_');
    return subParts[subParts.length - 1];
  }

  return parentId;
}

function getCommentGroupKey(item) {
  const canonicalId = getCanonicalParentId(item);
  if (canonicalId) return `${item.type}:${canonicalId}`;

  return `${item.type}:unlinked:${item.id}`;
}

function idsReferToSameContent(candidate, parentId) {
  if (!candidate || !parentId) return false;
  const candidateStr = String(candidate);
  const parentStr = String(parentId);

  return (
    candidateStr === parentStr ||
    candidateStr.endsWith(`_${parentStr}`) ||
    parentStr.endsWith(`_${candidateStr}`)
  );
}

export default function CommentsPage() {
  const { selectedWorkspaceId, selectedWorkspace, workspaces } = useWorkspace();

  // Top Filters: ONLY 3 platforms: Facebook, Instagram, YouTube (NO "All" option!)
  const [platform, setPlatform] = useState('facebook'); // 'facebook' | 'instagram' | 'youtube'

  // Persist date filters in localStorage so page refresh preserves filter state
  const [startDate, setStartDate] = useState(() => {
    return localStorage.getItem('comments_start_date') || '';
  });
  const [endDate, setEndDate] = useState(() => {
    return localStorage.getItem('comments_end_date') || '';
  });

  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');

  // Sync date filter changes with localStorage
  useEffect(() => {
    if (startDate) localStorage.setItem('comments_start_date', startDate);
    else localStorage.removeItem('comments_start_date');
  }, [startDate]);

  useEffect(() => {
    if (endDate) localStorage.setItem('comments_end_date', endDate);
    else localStorage.removeItem('comments_end_date');
  }, [endDate]);

  // Data states
  const [rawComments, setRawComments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState('');
  const [actionLoading, setActionLoading] = useState({}); // { [commentId]: boolean }

  // Real-time stream status & alert
  const [realtimeStatus, setRealtimeStatus] = useState('connecting'); // 'connected' | 'reconnecting' | 'idle'
  const [newCommentAlert, setNewCommentAlert] = useState(0);

  // Pagination states
  const [page, setPage] = useState(1);
  const perPage = 10;

  // Cached related posts/media/videos
  const postsCacheRef = useRef({
    facebook: new Map(), // workspaceId -> posts[]
    instagram: new Map(), // workspaceId -> posts[]
    youtube: new Map(), // workspaceId -> videos[]
  });
  const latestSeenIdRef = useRef(0);
  const latestVersionRef = useRef(0);
  const [fbPosts, setFbPosts] = useState([]);
  const [igPosts, setIgPosts] = useState([]);
  const [ytVideos, setYtVideos] = useState([]);

  // Debounce search query
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Reset page when platform, workspace, or date changes
  useEffect(() => {
    setPage(1);
  }, [platform, selectedWorkspaceId, startDate, endDate]);

  // Helper to deduplicate array by id
  const dedupeById = (list) => {
    const seen = new Set();
    return list.filter((item) => {
      const idKey = item?.id || item?.content_id || item?.ig_media_id || item?.fb_post_id;
      if (!idKey) return true;
      if (seen.has(idKey)) return false;
      seen.add(idKey);
      return true;
    });
  };

  // Fetch parent posts/videos for association
  const fetchParentContent = useCallback(async (wsId, force = false) => {
    if (!wsId || wsId === 'all') return;

    if (force) {
      postsCacheRef.current.facebook.delete(wsId);
      postsCacheRef.current.instagram.delete(wsId);
      postsCacheRef.current.youtube.delete(wsId);
    }

    // 1. Facebook Posts
    if (!postsCacheRef.current.facebook.has(wsId)) {
      try {
        const res = await axiosInstance.get('/facebook/posts', {
          params: { workspace_id: wsId, all_posts: true, per_page: 50 },
        });
        if (res.data?.success && Array.isArray(res.data.data)) {
          postsCacheRef.current.facebook.set(wsId, res.data.data);
          setFbPosts((prev) => dedupeById([...prev, ...res.data.data]));
        }
      } catch (err) {
        console.warn('Could not prefetch Facebook posts for comments association:', err);
      }
    } else {
      setFbPosts((prev) => dedupeById([...prev, ...(postsCacheRef.current.facebook.get(wsId) || [])]));
    }

    // 2. Instagram Posts
    if (!postsCacheRef.current.instagram.has(wsId)) {
      try {
        const res = await axiosInstance.get('/instagram/posts', {
          params: { workspace_id: wsId, all_posts: true, per_page: 50 },
        });
        if (res.data?.success && Array.isArray(res.data.data)) {
          postsCacheRef.current.instagram.set(wsId, res.data.data);
          setIgPosts((prev) => dedupeById([...prev, ...res.data.data]));
        }
      } catch (err) {
        console.warn('Could not prefetch Instagram posts for comments association:', err);
      }
    } else {
      setIgPosts((prev) => dedupeById([...prev, ...(postsCacheRef.current.instagram.get(wsId) || [])]));
    }

    // 3. YouTube Videos
    if (!postsCacheRef.current.youtube.has(wsId)) {
      try {
        const res = await axiosInstance.get('/youtube/content', {
          params: { workspace_id: wsId, type: 'all', max_results: 50 },
        });
        const items = res.data?.items || res.data?.data || [];
        if (Array.isArray(items)) {
          postsCacheRef.current.youtube.set(wsId, items);
          setYtVideos((prev) => dedupeById([...prev, ...items]));
        }
      } catch (err) {
        console.warn('Could not prefetch YouTube content for comments association:', err);
      }
    } else {
      setYtVideos((prev) => dedupeById([...prev, ...(postsCacheRef.current.youtube.get(wsId) || [])]));
    }
  }, []);

  // Fetch comments from /notifications endpoint (pure fast DB query)
  const fetchComments = useCallback(async (isRefresh = false, silent = false) => {
    if (!selectedWorkspaceId) return;

    if (!silent) {
      if (isRefresh) setRefreshing(true);
      else setLoading(true);
    }
    setError('');

    try {
      const params = {
        category: 'comments',
        per_page: 100, // fetch up to 100 records for fast client-side tab switching and filtering
      };
      if (selectedWorkspaceId !== 'all') {
        params.workspace_id = selectedWorkspaceId;
      }

      const res = await axiosInstance.get('/notifications', { params });

      if (res.data?.success && Array.isArray(res.data.data)) {
        setRawComments(res.data.data);
      } else {
        setError(res.data?.message || 'Failed to fetch comments.');
      }
    } catch (err) {
      console.error('Error fetching comments:', err);
      setError(err.response?.data?.message || 'Unable to connect to comments service.');
    } finally {
      if (!silent) {
        setLoading(false);
        setRefreshing(false);
      }
    }
  }, [selectedWorkspaceId]);

  // Dedicated Fast Incremental Sync
  const handleSyncComments = async () => {
    if (!selectedWorkspaceId || refreshing) return;

    setRefreshing(true);
    try {
      await axiosInstance.post('/comments/sync', {
        workspace_id: selectedWorkspaceId,
        platform: 'all',
      });
      // Fast non-blocking refresh of comments data
      await fetchComments(true, true);
    } catch (err) {
      console.warn('Sync comments error, falling back to direct fetch:', err);
      await fetchComments(true, true);
    } finally {
      setRefreshing(false);
    }
  };

  // Centralized handlers for incoming comment events
  const handleIncomingCommentCreated = useCallback((newComment) => {
    if (!newComment || !newComment.id) return;
    const numId = Number(newComment.id);

    if (numId > latestSeenIdRef.current) {
      latestSeenIdRef.current = numId;
    }

    setRawComments((prev) => {
      const exists = prev.some(
        (c) =>
          Number(c.id) === numId ||
          (c.related_entity && newComment.related_entity && c.related_entity === newComment.related_entity)
      );
      if (exists) return prev;

      console.log('[COMMENTS STATE] Prepended new comment to React state:', newComment);
      setNewCommentAlert((cnt) => cnt + 1);
      return [newComment, ...prev];
    });
  }, []);

  const handleIncomingCommentUpdated = useCallback((updatedData) => {
    if (!updatedData || !updatedData.id) return;
    const numId = Number(updatedData.id);

    setRawComments((prev) =>
      prev.map((c) => (Number(c.id) === numId ? { ...c, ...updatedData } : c))
    );
  }, []);

  // Keep latestSeenIdRef in sync whenever rawComments updates
  useEffect(() => {
    if (rawComments.length > 0) {
      const maxId = rawComments.reduce((max, c) => Math.max(max, Number(c.id) || 0), 0);
      if (maxId > latestSeenIdRef.current) {
        latestSeenIdRef.current = maxId;
      }
    }
  }, [rawComments]);

  // Real-time Live Comments Stream (SSE Connection + Lightweight Adaptive Delta Fallback)
  useEffect(() => {
    if (!selectedWorkspaceId || selectedWorkspaceId === 'all') {
      setRealtimeStatus('idle');
      return;
    }

    let isUnmounted = false;
    let pollTimer = null;
    let eventSource = null;
    let sseReconnectTimer = null;
    let consecutiveErrors = 0;

    // 1. Establish SSE Stream Connection
    const connectSSE = () => {
      if (isUnmounted) return;

      try {
        const baseURL = axiosInstance.defaults.baseURL || 'http://localhost:8000/api/v1';
        const token = localStorage.getItem('auth_token') || '';
        const params = new URLSearchParams({
          workspace_id: String(selectedWorkspaceId),
          since: String(latestSeenIdRef.current || 0),
        });
        if (token) {
          params.append('token', token);
        }

        const sseUrl = `${baseURL}/comments/stream?${params.toString()}`;
        console.log('[COMMENTS SSE] Connecting EventSource to:', sseUrl);

        eventSource = new EventSource(sseUrl, {
          withCredentials: true,
        });

        eventSource.addEventListener('open', () => {
          if (isUnmounted) return;
          consecutiveErrors = 0;
          setRealtimeStatus('connected');
          console.log('[COMMENTS SSE] Connection established (open)');
        });

        eventSource.addEventListener('ready', (e) => {
          if (isUnmounted) return;
          consecutiveErrors = 0;
          setRealtimeStatus('connected');
          console.log('[COMMENTS SSE] Stream ready event:', e.data);
        });

        eventSource.addEventListener('comment.created', (e) => {
          if (isUnmounted) return;
          consecutiveErrors = 0;
          setRealtimeStatus('connected');
          try {
            const data = JSON.parse(e.data || '{}');
            console.log('[COMMENTS SSE] Received comment.created event:', data);
            handleIncomingCommentCreated(data);
          } catch (err) {
            console.debug('SSE parse comment.created error:', err);
          }
        });

        eventSource.addEventListener('comment.updated', (e) => {
          if (isUnmounted) return;
          consecutiveErrors = 0;
          setRealtimeStatus('connected');
          try {
            const data = JSON.parse(e.data || '{}');
            console.log('[COMMENTS SSE] Received comment.updated event:', data);
            handleIncomingCommentUpdated(data);
          } catch (err) {
            console.debug('SSE parse comment.updated error:', err);
          }
        });

        eventSource.addEventListener('error', (e) => {
          if (isUnmounted) return;
          console.log('[COMMENTS SSE] EventSource state change / error:', eventSource?.readyState);
          if (eventSource && eventSource.readyState === EventSource.CLOSED) {
            consecutiveErrors++;
            if (consecutiveErrors > 2) {
              setRealtimeStatus('reconnecting');
            }
            eventSource.close();
            eventSource = null;
            const backoff = Math.min(10000, 2000 * Math.pow(1.5, Math.min(consecutiveErrors, 3)));
            sseReconnectTimer = setTimeout(connectSSE, backoff);
          }
        });
      } catch (err) {
        console.warn('[COMMENTS SSE] Setup error:', err);
        consecutiveErrors++;
        const backoff = Math.min(10000, 2000 * Math.pow(1.5, Math.min(consecutiveErrors, 3)));
        sseReconnectTimer = setTimeout(connectSSE, backoff);
      }
    };

    // 2. Adaptive Delta Runner (Resilient Polling Fallback)
    const checkLiveUpdates = async () => {
      if (isUnmounted) return;

      try {
        const res = await axiosInstance.get('/comments/live-delta', {
          params: {
            workspace_id: selectedWorkspaceId,
            since: latestSeenIdRef.current,
            v: latestVersionRef.current,
          },
        });

        if (isUnmounted) return;

        if (res.data?.success) {
          consecutiveErrors = 0;
          setRealtimeStatus('connected');

          const events = res.data.events || [];
          for (const evt of events) {
            if (evt.event === 'comment.created' && evt.data) {
              handleIncomingCommentCreated(evt.data);
            } else if (evt.event === 'comment.updated' && evt.data) {
              handleIncomingCommentUpdated(evt.data);
            }
          }

          if (res.data.latest_id) {
            latestSeenIdRef.current = Math.max(latestSeenIdRef.current, Number(res.data.latest_id));
          }
          if (res.data.version) {
            latestVersionRef.current = Number(res.data.version);
          }
        }
      } catch (err) {
        if (isUnmounted) return;
        consecutiveErrors++;
        if (consecutiveErrors > 2) {
          setRealtimeStatus('reconnecting');
        }
      } finally {
        if (!isUnmounted) {
          // Adaptive interval: 3s when tab is active/visible, 15s when hidden
          const interval = document.visibilityState === 'visible' ? 3000 : 15000;
          const backoff = consecutiveErrors > 0
            ? Math.min(30000, 3000 * Math.pow(1.5, Math.min(consecutiveErrors, 4)))
            : interval;
          pollTimer = setTimeout(checkLiveUpdates, backoff);
        }
      }
    };

    // Visibility change handler for immediate refresh upon returning to tab
    const handleVisibilityChange = () => {
      if (document.visibilityState === 'visible') {
        if (pollTimer) clearTimeout(pollTimer);
        checkLiveUpdates();
      }
    };
    document.addEventListener('visibilitychange', handleVisibilityChange);

    // Initialize SSE and delta runner
    connectSSE();
    pollTimer = setTimeout(checkLiveUpdates, 2000);

    return () => {
      isUnmounted = true;
      document.removeEventListener('visibilitychange', handleVisibilityChange);
      if (pollTimer) clearTimeout(pollTimer);
      if (sseReconnectTimer) clearTimeout(sseReconnectTimer);
      if (eventSource) {
        eventSource.close();
        eventSource = null;
      }
    };
  }, [selectedWorkspaceId, handleIncomingCommentCreated, handleIncomingCommentUpdated]);

  // Initial fetch on workspace change
  useEffect(() => {
    fetchComments();
    setNewCommentAlert(0);
  }, [selectedWorkspaceId, fetchComments]);

  // Handle Check (✓) Button:
  // - Mark that comment as handled/read.
  // - Turn off/remove notification for that specific comment.
  // - Change its status from "New" to "Read".
  // - Keep the comment visible in the Comments page.
  // - Do NOT delete the comment.
  // - Do NOT delete the post.
  // - Do NOT delete any backend data.
  const handleMarkAsRead = async (commentId) => {
    if (!commentId || actionLoading[commentId]) return;

    const target = rawComments.find((c) => c.id === commentId);
    if (!target) return;

    // If already read, no-op or keep read
    if (target.is_read) return;

    setActionLoading((prev) => ({ ...prev, [commentId]: true }));

    // Optimistically mark as read in local state
    setRawComments((prev) =>
      prev.map((c) => (c.id === commentId ? { ...c, is_read: true } : c))
    );

    try {
      await axiosInstance.patch(`/notifications/${commentId}/read`);
    } catch (err) {
      console.error('Failed to mark comment as read:', err);
      // Revert on failure
      setRawComments((prev) =>
        prev.map((c) => (c.id === commentId ? { ...c, is_read: false } : c))
      );
    } finally {
      setActionLoading((prev) => ({ ...prev, [commentId]: false }));
    }
  };

  // Associate a comment with its parent post / video
  const getAssociatedContent = useCallback(
    (item) => {
      const type = item.type;
      const parentId = getCommentParentId(item);
      const canonicalId = getCanonicalParentId(item);

      // 1. Facebook association
      if (type === 'facebook_comment') {
        let matched = fbPosts.find((p) => {
          if (!p) return false;
          return (
            idsReferToSameContent(p.fb_post_id, parentId) ||
            idsReferToSameContent(p.fb_post_id, canonicalId) ||
            idsReferToSameContent(p.content_id, parentId) ||
            idsReferToSameContent(p.content_id, canonicalId) ||
            idsReferToSameContent(p.id, parentId) ||
            idsReferToSameContent(p.id, canonicalId)
          );
        });

        const isPhoto = item.message?.toLowerCase().includes('photo');
        const isVideo = item.message?.toLowerCase().includes('video');
        const defaultTitle = isPhoto ? 'Facebook Photo' : isVideo ? 'Facebook Video' : 'Facebook Post';
        const defaultType = isPhoto ? 'Photo' : isVideo ? 'Video' : 'Post';

        const title = matched?.content
          ? matched.content.split('\n')[0]
          : (matched?.title || item.content_title || defaultTitle);
        const thumbnailUrl = matched?.image_url || matched?.thumbnail_url || item.thumbnail_url || null;
        const postType = matched?.formatted_type || matched?.post_type || defaultType;
        const postUrl = matched?.permalink || matched?.permalink_url || matched?.post_url || item.post_url || (parentId ? `https://www.facebook.com/${parentId}` : null);
        const contentId = canonicalId || parentId;

        return {
          title,
          thumbnailUrl,
          thumbnail: thumbnailUrl,
          postType: postType.charAt(0).toUpperCase() + postType.slice(1),
          postUrl,
          permalink: postUrl,
          platform: 'facebook',
          contentId,
          parentId: contentId,
        };
      }

      // 2. Instagram association
      if (type === 'instagram_comment') {
        let matched = igPosts.find((p) => {
          if (!p) return false;
          return (
            idsReferToSameContent(p.content_id, parentId) ||
            idsReferToSameContent(p.ig_media_id, parentId) ||
            idsReferToSameContent(p.id, parentId)
          );
        });

        const title = matched?.content
          ? matched.content.split('\n')[0]
          : (matched?.title || item.content_title || 'Instagram Post');
        const thumbnailUrl =
          matched?.thumbnail_url ||
          matched?.image_url ||
          matched?.media_url ||
          item.thumbnail_url ||
          item.media_url ||
          null;
        const postType = matched?.formatted_type || matched?.post_type || 'Post';
        const postUrl = matched?.permalink || matched?.post_url || item.post_url || (parentId ? `https://www.instagram.com/p/${parentId}` : null);

        return {
          title,
          thumbnailUrl,
          thumbnail: thumbnailUrl,
          postType: postType.charAt(0).toUpperCase() + postType.slice(1),
          postUrl,
          permalink: postUrl,
          platform: 'instagram',
          contentId: parentId,
          parentId,
        };
      }

      // 3. YouTube association
      if (type === 'youtube_comment') {
        let matched = null;
        if (parentId) {
          matched = ytVideos.find((v) => idsReferToSameContent(v.id || v.content_id, parentId));
        }

        const title = matched?.title || item.content_title || 'YouTube Video';
        const thumbnailUrl =
          matched?.thumbnail ||
          matched?.thumbnail_url ||
          item.thumbnail_url ||
          (parentId && parentId !== 'channel' ? `https://i.ytimg.com/vi/${parentId}/hqdefault.jpg` : null);
        const isShort = matched?.type === 'short' || matched?.is_short;
        const postType = isShort ? 'Short' : 'Video';
        const postUrl = matched?.url || matched?.post_url || item.post_url || (parentId ? `https://www.youtube.com/watch?v=${parentId}` : null);

        return {
          title,
          thumbnailUrl,
          thumbnail: thumbnailUrl,
          postType,
          postUrl,
          permalink: postUrl,
          platform: 'youtube',
          contentId: parentId,
          parentId,
        };
      }

      return {
        title: item.content_title || 'Social Post',
        thumbnailUrl: item.thumbnail_url || null,
        thumbnail: item.thumbnail_url || null,
        postType: 'Post',
        postUrl: item.post_url || null,
        permalink: item.post_url || null,
        platform: item.platform || 'facebook',
        contentId: parentId,
        parentId,
      };
    },
    [fbPosts, igPosts, ytVideos]
  );

  // Compute start & end of day timestamps in local timezone
  const dateBoundaries = useMemo(() => {
    const normStart = normalizeDateInput(startDate);
    const normEnd = normalizeDateInput(endDate);
    let startMs = null;
    let endMs = null;

    if (normStart) {
      const parts = normStart.split('-').map(Number);
      if (parts.length === 3 && !parts.some(isNaN)) {
        // Complete start day: 00:00:00.000 local time
        startMs = new Date(parts[0], parts[1] - 1, parts[2], 0, 0, 0, 0).getTime();
      }
    }

    if (normEnd) {
      const parts = normEnd.split('-').map(Number);
      if (parts.length === 3 && !parts.some(isNaN)) {
        // Complete end day: 23:59:59.999 local time
        endMs = new Date(parts[0], parts[1] - 1, parts[2], 23, 59, 59, 999).getTime();
      }
    }

    return { normStart, normEnd, startMs, endMs };
  }, [startDate, endDate]);

  // Helper to test whether a comment's creation timestamp falls within the selected date range
  const isCommentInDateRange = useCallback((c, startMs, endMs) => {
    if (startMs === null && endMs === null) return true;
    const d = parseCommentDate(c.created_at);
    if (!d) return false;
    const timeMs = d.getTime();
    if (startMs !== null && timeMs < startMs) return false;
    if (endMs !== null && timeMs > endMs) return false;
    return true;
  }, []);

  // Filter comments based on platform, date range, and search
  const filteredComments = useMemo(() => {
    const { startMs, endMs } = dateBoundaries;
    const q = debouncedSearch.toLowerCase().trim();

    return rawComments
      .filter((c) => {
        // 1. Platform Filter (Facebook, Instagram, YouTube ONLY - NO "All" option)
        if (platform === 'facebook' && c.type !== 'facebook_comment') return false;
        if (platform === 'instagram' && c.type !== 'instagram_comment') return false;
        if (platform === 'youtube' && c.type !== 'youtube_comment') return false;

        // 2. Date Range Filter
        if (!isCommentInDateRange(c, startMs, endMs)) return false;

        // 3. Search Query Filter
        if (q) {
          const { author, comment } = parseCommentMessage(c.message);
          const postMeta = getAssociatedContent(c);
          const matchAuthor = author.toLowerCase().includes(q);
          const matchComment = comment.toLowerCase().includes(q);
          const matchPost = postMeta.title.toLowerCase().includes(q);
          if (!matchAuthor && !matchComment && !matchPost) return false;
        }

        return true;
      })
      .sort((a, b) => {
        const dA = parseCommentDate(a.created_at);
        const dB = parseCommentDate(b.created_at);
        const tA = dA ? dA.getTime() : 0;
        const tB = dB ? dB.getTime() : 0;
        return tB - tA;
      });
  }, [rawComments, platform, dateBoundaries, debouncedSearch, isCommentInDateRange, getAssociatedContent]);

  // Platform comment counts for tab badges (strictly reflects the filtered results!)
  const platformCounts = useMemo(() => {
    const { startMs, endMs } = dateBoundaries;
    const q = debouncedSearch.toLowerCase().trim();

    let fb = 0;
    let ig = 0;
    let yt = 0;

    rawComments.forEach((c) => {
      // Date Range Filter
      if (!isCommentInDateRange(c, startMs, endMs)) return;

      // Search Filter
      if (q) {
        const { author, comment } = parseCommentMessage(c.message);
        const postMeta = getAssociatedContent(c);
        const matchAuthor = author.toLowerCase().includes(q);
        const matchComment = comment.toLowerCase().includes(q);
        const matchPost = postMeta.title.toLowerCase().includes(q);
        if (!matchAuthor && !matchComment && !matchPost) return;
      }

      if (c.type === 'facebook_comment') fb++;
      else if (c.type === 'instagram_comment') ig++;
      else if (c.type === 'youtube_comment') yt++;
    });

    return { facebook: fb, instagram: ig, youtube: yt };
  }, [rawComments, dateBoundaries, debouncedSearch, isCommentInDateRange, getAssociatedContent]);

  // Pagination calculation
  const totalComments = filteredComments.length;
  const groupedComments = useMemo(() => {
    const groups = new Map();

    filteredComments.forEach((item) => {
      const groupKey = getCommentGroupKey(item);
      const postMeta = getAssociatedContent(item);

      if (!groups.has(groupKey)) {
        groups.set(groupKey, {
          key: groupKey,
          platform: postMeta.platform,
          contentId: postMeta.contentId,
          title: postMeta.title,
          thumbnailUrl: postMeta.thumbnailUrl,
          postUrl: postMeta.postUrl,
          postType: postMeta.postType,
          postMeta,
          comments: [],
          newestTime: 0,
        });
      }

      const group = groups.get(groupKey);
      const createdAt = parseCommentDate(item.created_at);
      const timeMs = createdAt ? createdAt.getTime() : 0;
      group.newestTime = Math.max(group.newestTime, timeMs);
      group.comments.push(item);
    });

    // Sort comments inside each group chronologically (newest first)
    groups.forEach((group) => {
      group.comments.sort((a, b) => {
        const dA = parseCommentDate(a.created_at);
        const dB = parseCommentDate(b.created_at);
        const tA = dA ? dA.getTime() : 0;
        const tB = dB ? dB.getTime() : 0;
        return tB - tA;
      });
    });

    const result = Array.from(groups.values()).sort((a, b) => b.newestTime - a.newestTime);

    // Temporary console logging for normalized grouped posts
    console.log('[DEBUG Grouped Comments Normalized]', result.map((g) => ({
      platform: g.platform,
      contentId: g.contentId,
      title: g.title,
      thumbnailUrl: g.thumbnailUrl,
      postUrl: g.postUrl,
      commentCount: g.comments?.length,
    })));

    return result;
  }, [filteredComments, getAssociatedContent]);

  const totalGroups = groupedComments.length;
  const totalPages = Math.max(1, Math.ceil(totalGroups / perPage));
  const currentPage = Math.min(page, totalPages);
  const paginatedGroups = useMemo(() => {
    const startIdx = (currentPage - 1) * perPage;
    return groupedComments.slice(startIdx, startIdx + perPage);
  }, [groupedComments, currentPage, perPage]);

  // Reset date range
  const handleClearDates = () => {
    setStartDate('');
    setEndDate('');
    localStorage.removeItem('comments_start_date');
    localStorage.removeItem('comments_end_date');
  };

  return (
    <div className="comments-page" style={{ paddingBottom: '32px' }}>
      {/* Section Header */}
      <div className="section-head" style={{ marginBottom: '22px' }}>
        <div>
          <h2 style={{ fontSize: '22px', fontWeight: '800', color: '#0f172a', letterSpacing: '-0.02em', margin: 0 }}>
            Comments
          </h2>
          <p style={{ margin: '4px 0 0', color: '#64748b', fontSize: '13px' }}>
            Monitor, handle, and manage user interactions across Facebook, Instagram, and YouTube.
          </p>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
          {/* Live Inbox Real-Time Status Pill */}
          <div
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
              padding: '6px 12px',
              borderRadius: '999px',
              fontSize: '12px',
              fontWeight: '600',
              background: realtimeStatus === 'connected' ? '#f0fdf4' : '#fffbeb',
              color: realtimeStatus === 'connected' ? '#16a34a' : '#d97706',
              border: `1px solid ${realtimeStatus === 'connected' ? '#bbf7d0' : '#fde68a'}`,
              transition: 'all 0.2s ease',
            }}
            title={
              realtimeStatus === 'connected'
                ? 'Live inbox connected (real-time stream active)'
                : 'Connecting to live real-time stream...'
            }
          >
            <span
              style={{
                width: '7px',
                height: '7px',
                borderRadius: '50%',
                backgroundColor: realtimeStatus === 'connected' ? '#16a34a' : '#d97706',
                boxShadow: realtimeStatus === 'connected' ? '0 0 0 2px rgba(22, 163, 74, 0.2)' : 'none',
              }}
            />
            <span>{realtimeStatus === 'connected' ? 'Live' : 'Reconnecting...'}</span>
          </div>

          {/* New Comments Floating Alert Pill */}
          {newCommentAlert > 0 && (
            <button
              type="button"
              onClick={() => {
                setNewCommentAlert(0);
                window.scrollTo({ top: 0, behavior: 'smooth' });
              }}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '5px',
                padding: '6px 11px',
                borderRadius: '999px',
                background: '#eff6ff',
                color: '#1d4ed8',
                border: '1px solid #bfdbfe',
                fontSize: '12px',
                fontWeight: '700',
                cursor: 'pointer',
                transition: 'all 0.15s ease',
              }}
              title="Click to view new comments"
            >
              <span>{newCommentAlert} new</span>
            </button>
          )}

          {/* Sync Comments Button */}
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={handleSyncComments}
            disabled={refreshing}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
              borderRadius: '9px',
              padding: '8px 14px',
              fontSize: '13px',
              cursor: refreshing ? 'not-allowed' : 'pointer',
            }}
            title="Perform incremental sync from platforms"
          >
            <RefreshCw size={14} className={refreshing ? 'spin-icon' : ''} />
            {refreshing ? 'Syncing...' : 'Sync Comments'}
          </button>
        </div>
      </div>

      {/* Top Filter Bar */}
      <div
        className="panel"
        style={{
          padding: '16px 20px',
          marginBottom: '20px',
          borderRadius: '14px',
          background: '#ffffff',
          border: '1px solid #e2e8f0',
        }}
      >
        <div
          style={{
            display: 'flex',
            flexWrap: 'wrap',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '16px',
          }}
        >
          {/* 1. Platform Tabs: Strictly Facebook, Instagram, YouTube (NO "All" option) */}
          <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
            <button
              type="button"
              className={`platform-tab ${platform === 'facebook' ? 'active' : ''}`}
              onClick={() => setPlatform('facebook')}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '8px',
                padding: '9px 16px',
                borderRadius: '10px',
                fontSize: '13px',
                fontWeight: platform === 'facebook' ? '700' : '600',
                cursor: 'pointer',
                border: platform === 'facebook' ? '1.5px solid #1877f2' : '1px solid #e2e8f0',
                background: platform === 'facebook' ? '#eff6ff' : '#ffffff',
                color: platform === 'facebook' ? '#1877f2' : '#475569',
                transition: 'all 0.15s ease',
              }}
            >
              <FacebookIcon size={16} />
              Facebook
              <span
                style={{
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '2px 7px',
                  borderRadius: '999px',
                  background: platform === 'facebook' ? '#1877f2' : '#f1f5f9',
                  color: platform === 'facebook' ? '#ffffff' : '#64748b',
                  marginLeft: '2px',
                }}
              >
                {platformCounts.facebook}
              </span>
            </button>

            <button
              type="button"
              className={`platform-tab ${platform === 'instagram' ? 'active' : ''}`}
              onClick={() => setPlatform('instagram')}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '8px',
                padding: '9px 16px',
                borderRadius: '10px',
                fontSize: '13px',
                fontWeight: platform === 'instagram' ? '700' : '600',
                cursor: 'pointer',
                border: platform === 'instagram' ? '1.5px solid #e1306c' : '1px solid #e2e8f0',
                background: platform === 'instagram' ? '#fdf2f8' : '#ffffff',
                color: platform === 'instagram' ? '#be185d' : '#475569',
                transition: 'all 0.15s ease',
              }}
            >
              <InstagramIcon size={16} />
              Instagram
              <span
                style={{
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '2px 7px',
                  borderRadius: '999px',
                  background: platform === 'instagram' ? '#e1306c' : '#f1f5f9',
                  color: platform === 'instagram' ? '#ffffff' : '#64748b',
                  marginLeft: '2px',
                }}
              >
                {platformCounts.instagram}
              </span>
            </button>

            <button
              type="button"
              className={`platform-tab ${platform === 'youtube' ? 'active' : ''}`}
              onClick={() => setPlatform('youtube')}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '8px',
                padding: '9px 16px',
                borderRadius: '10px',
                fontSize: '13px',
                fontWeight: platform === 'youtube' ? '700' : '600',
                cursor: 'pointer',
                border: platform === 'youtube' ? '1.5px solid #dc2626' : '1px solid #e2e8f0',
                background: platform === 'youtube' ? '#fef2f2' : '#ffffff',
                color: platform === 'youtube' ? '#dc2626' : '#475569',
                transition: 'all 0.15s ease',
              }}
            >
              <YouTubeIcon size={16} />
              YouTube
              <span
                style={{
                  fontSize: '11px',
                  fontWeight: '700',
                  padding: '2px 7px',
                  borderRadius: '999px',
                  background: platform === 'youtube' ? '#dc2626' : '#f1f5f9',
                  color: platform === 'youtube' ? '#ffffff' : '#64748b',
                  marginLeft: '2px',
                }}
              >
                {platformCounts.youtube}
              </span>
            </button>
          </div>

          {/* 2. Date Range & Search Filters */}
          <div style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
            {/* Date Range Inputs */}
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                background: '#f8fafc',
                padding: '4px 10px',
                borderRadius: '10px',
                border: '1px solid #e2e8f0',
              }}
            >
              <Calendar size={14} color="#64748b" />
              <input
                type="date"
                value={normalizeDateInput(startDate)}
                onChange={(e) => setStartDate(e.target.value)}
                title="Start Date"
                style={{
                  border: 'none',
                  background: 'transparent',
                  fontSize: '12px',
                  color: '#334155',
                  outline: 'none',
                  padding: '4px 2px',
                }}
              />
              <span style={{ color: '#94a3b8', fontSize: '12px' }}>–</span>
              <input
                type="date"
                value={normalizeDateInput(endDate)}
                onChange={(e) => setEndDate(e.target.value)}
                title="End Date"
                style={{
                  border: 'none',
                  background: 'transparent',
                  fontSize: '12px',
                  color: '#334155',
                  outline: 'none',
                  padding: '4px 2px',
                }}
              />
              {(startDate || endDate) && (
                <button
                  type="button"
                  onClick={handleClearDates}
                  title="Clear date filter"
                  style={{
                    background: 'transparent',
                    border: 'none',
                    cursor: 'pointer',
                    padding: '2px',
                    display: 'grid',
                    placeItems: 'center',
                    color: '#94a3b8',
                  }}
                >
                  <X size={13} />
                </button>
              )}
            </div>

            {/* Search Comments */}
            <div className="search-box" style={{ minWidth: '220px', maxWidth: '300px' }}>
              <Search size={15} style={{ position: 'absolute', left: '12px', top: '12px', color: '#94a3b8' }} />
              <input
                type="text"
                placeholder="Search comments or posts..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                style={{
                  paddingLeft: '34px',
                  paddingRight: '12px',
                  height: '38px',
                  fontSize: '13px',
                }}
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => setSearchQuery('')}
                  style={{
                    position: 'absolute',
                    right: '10px',
                    top: '11px',
                    background: 'transparent',
                    border: 'none',
                    cursor: 'pointer',
                    color: '#94a3b8',
                  }}
                >
                  <X size={14} />
                </button>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Error Notification */}
      {error && (
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: '10px',
            padding: '12px 16px',
            background: '#fef2f2',
            border: '1px solid #fee2e2',
            borderRadius: '12px',
            color: '#b91c1c',
            marginBottom: '18px',
            fontSize: '13px',
          }}
        >
          <AlertCircle size={17} />
          <span>{error}</span>
        </div>
      )}

      {/* Grouped Comments Container */}
      {loading && rawComments.length === 0 ? (
        // Loading Skeleton Cards
        <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
          {Array.from({ length: 3 }).map((_, idx) => (
            <div
              key={`skeleton-card-${idx}`}
              className="panel"
              style={{
                padding: 0,
                borderRadius: '14px',
                background: '#ffffff',
                border: '1px solid #e2e8f0',
                overflow: 'hidden',
              }}
            >
              {/* Skeleton Header */}
              <div
                style={{
                  padding: '14px 20px',
                  background: '#f8fafc',
                  borderBottom: '1px solid #e2e8f0',
                  display: 'flex',
                  alignItems: 'center',
                  gap: '14px',
                }}
              >
                <div style={{ width: '48px', height: '48px', background: '#e2e8f0', borderRadius: '8px' }} />
                <div style={{ flex: 1 }}>
                  <div style={{ height: '15px', width: '220px', background: '#e2e8f0', borderRadius: '4px', marginBottom: '8px' }} />
                  <div style={{ height: '12px', width: '130px', background: '#e2e8f0', borderRadius: '4px' }} />
                </div>
              </div>
              {/* Skeleton Rows */}
              <div style={{ padding: '16px 20px' }}>
                <div style={{ height: '14px', width: '80%', background: '#f1f5f9', borderRadius: '4px', marginBottom: '12px' }} />
                <div style={{ height: '14px', width: '60%', background: '#f1f5f9', borderRadius: '4px' }} />
              </div>
            </div>
          ))}
        </div>
      ) : paginatedGroups.length === 0 ? (
        // Empty State
        <div
          className="panel"
          style={{
            padding: '52px 24px',
            borderRadius: '16px',
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            textAlign: 'center',
            boxShadow: '0 4px 16px rgba(15, 31, 61, 0.03)',
          }}
        >
          <div style={{ maxWidth: '400px', margin: '0 auto' }}>
            <div
              style={{
                width: '56px',
                height: '56px',
                borderRadius: '16px',
                background: '#f1f5f9',
                color: '#94a3b8',
                display: 'grid',
                placeItems: 'center',
                margin: '0 auto 16px',
              }}
            >
              <MessageSquare size={28} />
            </div>
            <h4 style={{ margin: '0 0 6px', fontSize: '16px', fontWeight: '700', color: '#1e293b' }}>
              No {platform.charAt(0).toUpperCase() + platform.slice(1)} comments found
            </h4>
            <p style={{ margin: 0, fontSize: '13px', color: '#64748b', lineHeight: '1.5' }}>
              {searchQuery || startDate || endDate
                ? 'No comments match your active filters. Try clearing or expanding your date range.'
                : `No recent comments have been received from your connected ${platform.charAt(0).toUpperCase() + platform.slice(1)} account.`}
            </p>
          </div>
        </div>
      ) : (
        // Grouped Posts with Comments
        <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
          {paginatedGroups.map((group) => {
            const { platform: groupPlatform, contentId, title, thumbnailUrl, postUrl, postType, comments } = group;

            return (
              <div
                key={group.key}
                className="panel post-group-panel"
                style={{
                  padding: 0,
                  borderRadius: '14px',
                  background: '#ffffff',
                  border: '1px solid #e2e8f0',
                  overflow: 'hidden',
                  boxShadow: '0 2px 8px rgba(15, 23, 42, 0.035)',
                  transition: 'border-color 0.15s ease, box-shadow 0.15s ease',
                }}
              >
                {/* POST HEADER: Rendered ONCE per post */}
                <div
                  className="post-group-header"
                  style={{
                    padding: '14px 20px',
                    background: '#f8fafc',
                    borderBottom: '1px solid #e2e8f0',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: '16px',
                    flexWrap: 'wrap',
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: '14px', minWidth: 0, flex: 1 }}>
                    {/* Thumbnail with post type badge */}
                    <div style={{ position: 'relative', width: '48px', height: '48px', flexShrink: 0 }}>
                      {thumbnailUrl ? (
                        <img
                          src={thumbnailUrl}
                          alt={title || 'Post thumbnail'}
                          loading="lazy"
                          referrerPolicy="no-referrer"
                          style={{
                            width: '48px',
                            height: '48px',
                            objectFit: 'cover',
                            borderRadius: '8px',
                            border: '1px solid #cbd5e1',
                            display: 'block',
                          }}
                          onError={(e) => {
                            e.currentTarget.style.display = 'none';
                            const fallbackEl = e.currentTarget.parentElement?.querySelector('.post-thumbnail-fallback');
                            if (fallbackEl) fallbackEl.style.display = 'flex';
                          }}
                        />
                      ) : null}

                      {/* Fallback Icon */}
                      <div
                        className="post-thumbnail-fallback"
                        style={{
                          width: '48px',
                          height: '48px',
                          borderRadius: '8px',
                          background:
                            groupPlatform === 'facebook'
                              ? '#eff6ff'
                              : groupPlatform === 'instagram'
                              ? '#fdf2f8'
                              : '#fef2f2',
                          display: thumbnailUrl ? 'none' : 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          border: '1px solid #e2e8f0',
                        }}
                      >
                        {groupPlatform === 'facebook' && <FacebookIcon size={20} />}
                        {groupPlatform === 'instagram' && <InstagramIcon size={20} />}
                        {groupPlatform === 'youtube' && <YouTubeIcon size={20} />}
                      </div>

                      {/* Post Type Badge */}
                      <span
                        style={{
                          position: 'absolute',
                          bottom: '-2px',
                          right: '-2px',
                          padding: '1px 4px',
                          fontSize: '8.5px',
                          fontWeight: '700',
                          borderRadius: '4px',
                          background: '#0f172a',
                          color: '#ffffff',
                          lineHeight: '1.2',
                          textTransform: 'uppercase',
                          letterSpacing: '0.02em',
                        }}
                      >
                        {postType}
                      </span>
                    </div>

                    {/* Post Name & Meta Info */}
                    <div style={{ minWidth: 0 }}>
                      <div
                        title={title}
                        style={{
                          fontSize: '13.5px',
                          fontWeight: '700',
                          color: '#0f172a',
                          lineHeight: '1.35',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                          whiteSpace: 'nowrap',
                          maxWidth: '680px',
                        }}
                      >
                        {title}
                      </div>

                      <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginTop: '4px', flexWrap: 'wrap' }}>
                        {/* Platform Badge */}
                        {groupPlatform === 'facebook' && (
                          <span
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '4px',
                              padding: '2px 7px',
                              borderRadius: '6px',
                              background: '#eff6ff',
                              color: '#1d4ed8',
                              fontSize: '11px',
                              fontWeight: '700',
                              border: '1px solid #bfdbfe',
                            }}
                          >
                            <FacebookIcon size={12} />
                            Facebook
                          </span>
                        )}
                        {groupPlatform === 'instagram' && (
                          <span
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '4px',
                              padding: '2px 7px',
                              borderRadius: '6px',
                              background: '#fdf2f8',
                              color: '#be185d',
                              fontSize: '11px',
                              fontWeight: '700',
                              border: '1px solid #fbcfe8',
                            }}
                          >
                            <InstagramIcon size={12} />
                            Instagram
                          </span>
                        )}
                        {groupPlatform === 'youtube' && (
                          <span
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '4px',
                              padding: '2px 7px',
                              borderRadius: '6px',
                              background: '#fef2f2',
                              color: '#dc2626',
                              fontSize: '11px',
                              fontWeight: '700',
                              border: '1px solid #fecaca',
                            }}
                          >
                            <YouTubeIcon size={12} />
                            YouTube
                          </span>
                        )}

                        {/* View Post Link */}
                        {postUrl && (
                          <a
                            href={postUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '3px',
                              fontSize: '11.5px',
                              color: '#2563eb',
                              textDecoration: 'none',
                              fontWeight: '600',
                            }}
                          >
                            View post <ExternalLink size={11} />
                          </a>
                        )}
                      </div>
                    </div>
                  </div>

                  {/* Post Stats: Comment count badge */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <span
                      style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '5px',
                        padding: '4px 10px',
                        borderRadius: '999px',
                        background: '#ffffff',
                        border: '1px solid #cbd5e1',
                        fontSize: '12px',
                        fontWeight: '600',
                        color: '#475569',
                      }}
                    >
                      <MessageSquare size={13} color="#64748b" />
                      {comments.length} {comments.length === 1 ? 'comment' : 'comments'}
                    </span>
                    {comments.some((c) => !c.is_read) && (
                      <span
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          gap: '4px',
                          padding: '4px 9px',
                          borderRadius: '999px',
                          background: '#eff6ff',
                          border: '1px solid #bfdbfe',
                          fontSize: '11px',
                          fontWeight: '700',
                          color: '#1d4ed8',
                        }}
                      >
                        <span
                          style={{
                            width: '6px',
                            height: '6px',
                            borderRadius: '50%',
                            background: '#2563eb',
                            display: 'inline-block',
                          }}
                        />
                        {comments.filter((c) => !c.is_read).length} New
                      </span>
                    )}
                  </div>
                </div>

                {/* COMMENTS TABLE UNDER THE POST */}
                <div style={{ overflowX: 'auto' }}>
                  <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: '700px' }}>
                    <thead>
                      <tr style={{ background: '#fafbfc', borderBottom: '1px solid #edf2f7' }}>
                        <th style={{ padding: '10px 18px', fontSize: '11px', fontWeight: '700', color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.04em', textAlign: 'left' }}>
                          Comment
                        </th>
                        <th style={{ width: '170px', padding: '10px 18px', fontSize: '11px', fontWeight: '700', color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.04em', textAlign: 'left' }}>
                          Date
                        </th>
                        <th style={{ width: '100px', padding: '10px 18px', fontSize: '11px', fontWeight: '700', color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.04em', textAlign: 'center' }}>
                          Status
                        </th>
                        <th style={{ width: '80px', padding: '10px 18px', fontSize: '11px', fontWeight: '700', color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.04em', textAlign: 'center' }}>
                          Action
                        </th>
                      </tr>
                    </thead>
                    <tbody>
                      {comments.map((item, commentIdx) => {
                        const { author, comment } = parseCommentMessage(item.message);
                        const isRead = Boolean(item.is_read);
                        const isLoadingAction = Boolean(actionLoading[item.id]);

                        return (
                          <tr
                            key={item.id}
                            style={{
                              borderBottom: commentIdx < comments.length - 1 ? '1px solid #f1f5f9' : 'none',
                              background: isRead ? '#ffffff' : '#f8faff',
                              transition: 'background-color 0.15s ease',
                            }}
                          >
                            {/* Comment & Author Column */}
                            <td style={{ padding: '12px 18px', verticalAlign: 'middle' }}>
                              <div style={{ display: 'flex', flexDirection: 'column', gap: '3px' }}>
                                <span style={{ fontSize: '12.5px', fontWeight: '700', color: '#1e293b' }}>
                                  {author}
                                </span>
                                <span style={{ fontSize: '13px', color: '#334155', lineHeight: '1.45', wordBreak: 'break-word' }}>
                                  {comment}
                                </span>
                              </div>
                            </td>

                            {/* Date Column */}
                            <td style={{ padding: '12px 18px', verticalAlign: 'middle', whiteSpace: 'nowrap' }}>
                              <span style={{ fontSize: '12px', color: '#64748b', fontWeight: '500' }}>
                                {formatCommentDate(item.created_at)}
                              </span>
                            </td>

                            {/* Status Column */}
                            <td style={{ padding: '12px 18px', verticalAlign: 'middle', textAlign: 'center' }}>
                              {isRead ? (
                                <span
                                  style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: '4px',
                                    padding: '3px 8px',
                                    borderRadius: '999px',
                                    background: '#f0fdf4',
                                    color: '#15803d',
                                    fontSize: '11px',
                                    fontWeight: '700',
                                    border: '1px solid #bbf7d0',
                                  }}
                                >
                                  <Check size={11} strokeWidth={3} />
                                  Read
                                </span>
                              ) : (
                                <span
                                  style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: '5px',
                                    padding: '3px 8px',
                                    borderRadius: '999px',
                                    background: '#eff6ff',
                                    color: '#1d4ed8',
                                    fontSize: '11px',
                                    fontWeight: '700',
                                    border: '1px solid #bfdbfe',
                                  }}
                                >
                                  <span
                                    style={{
                                      width: '6px',
                                      height: '6px',
                                      borderRadius: '50%',
                                      background: '#2563eb',
                                      display: 'inline-block',
                                    }}
                                  />
                                  New
                                </span>
                              )}
                            </td>

                            {/* Action Column (Check ✓) */}
                            <td style={{ padding: '12px 18px', verticalAlign: 'middle', textAlign: 'center' }}>
                              <button
                                type="button"
                                onClick={() => handleMarkAsRead(item.id)}
                                disabled={isRead || isLoadingAction}
                                title={isRead ? 'Already marked as handled / read' : 'Mark as handled / read'}
                                style={{
                                  width: '30px',
                                  height: '30px',
                                  borderRadius: '7px',
                                  border: isRead ? '1px solid #e2e8f0' : '1px solid #16a34a',
                                  background: isRead ? '#f8fafc' : '#16a34a',
                                  color: isRead ? '#94a3b8' : '#ffffff',
                                  cursor: isRead ? 'default' : 'pointer',
                                  display: 'inline-grid',
                                  placeItems: 'center',
                                  transition: 'all 0.15s ease',
                                  boxShadow: isRead ? 'none' : '0 2px 4px rgba(22, 163, 74, 0.2)',
                                }}
                              >
                                <Check size={15} strokeWidth={isRead ? 2 : 2.6} />
                              </button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Pagination Footer */}
      {totalGroups > 0 && (
        <div
          className="panel"
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            padding: '14px 20px',
            background: '#ffffff',
            borderRadius: '14px',
            border: '1px solid #e2e8f0',
            marginTop: '16px',
            flexWrap: 'wrap',
            gap: '12px',
          }}
        >
          <div style={{ fontSize: '12.5px', color: '#64748b' }}>
            Showing posts <strong>{(currentPage - 1) * perPage + 1}</strong> to{' '}
            <strong>{Math.min(currentPage * perPage, totalGroups)}</strong> of{' '}
            <strong>{totalGroups}</strong> posts ({totalComments} {totalComments === 1 ? 'comment' : 'comments'} total)
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <button
              type="button"
              className="btn btn-secondary btn-sm"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={currentPage <= 1 || loading}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '4px',
                padding: '6px 12px',
                fontSize: '12.5px',
              }}
            >
              <ChevronLeft size={14} /> Previous
            </button>

            <span
              style={{
                fontSize: '12.5px',
                fontWeight: '700',
                padding: '4px 10px',
                background: '#f8fafc',
                border: '1px solid #e2e8f0',
                borderRadius: '6px',
                color: '#334155',
              }}
            >
              {currentPage} / {totalPages}
            </span>

            <button
              type="button"
              className="btn btn-secondary btn-sm"
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              disabled={currentPage >= totalPages || loading}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: '4px',
                padding: '6px 12px',
                fontSize: '12.5px',
              }}
            >
              Next <ChevronRight size={14} />
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
