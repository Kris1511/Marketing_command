import React, { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import axiosInstance from '../api/axiosInstance';
import { useWorkspace } from '../context/WorkspaceContext';
import {
  CheckCircle2,
  AlertCircle,
  RotateCw,
  Copy,
  Trash2,
  Save,
  Send,
  Calendar as CalendarIcon,
  Image as ImageIcon,
  Video,
  Layers,
  Shield,
  X,
  ExternalLink,
  Plus,
  AlertTriangle,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Sparkles,
  LayoutGrid,
  Pencil,
  Info,
  Clock,
  Globe,
} from 'lucide-react';
import AiCaptionModal from '../components/AiCaptionModal';
import VisualContentCalendar from '../components/VisualContentCalendar';
import PostThumbnail from '../components/PostThumbnail';
import {
  CAROUSEL_MAX,
  MAX_IMAGE_MB,
  MAX_VIDEO_MB,
  MESSAGES,
  THUMBNAIL_ACCEPT,
  THUMBNAIL_MAX_MB,
  VIDEO_ACCEPT,
  buildMessage,
  captionLimitFor,
  contentTypeFromPostType,
  contentTypeLabel,
  postPreviewImage,
  statusLabel,
  DEFAULT_YOUTUBE_PRIVACY,
  YOUTUBE_PRIVACY_OPTIONS,
  YOUTUBE_PRIVACY_REQUIRED,
  isValidYoutubePrivacy,
  youtubePrivacyFromSaved,
  youtubePrivacyLabel,
  youtubeThumbnailProblem,
  youtubeThumbnailState,
  YOUTUBE_THUMBNAIL_MAX_MB,
  hashtagProblems,
  isAllWorkspaces,
  isUsableWorkspaceId,
  isFutureSchedule,
  normalizeHashtags,
  splitMessage,
  moveToFront,
  validateImageFile,
  validateThumbnailDimensions,
  validateThumbnailFile,
  validateVideoFile,
  youtubeTitleFor,
} from './publishingRules';

const DEFAULT_CTA = 'Learn More';
const EMPTY_CONNECTIONS = { facebook: null, instagram: null, youtube: null };
const EMPTY_FIELD_ERRORS = { caption: '', media: '', platforms: '', hashtags: '', workspace: '', youtubePrivacy: '', thumbnail: '' };

// Visually hidden but still focusable/interactable (the whole drop area opens the picker).
const SR_ONLY_INPUT = {
  position: 'absolute',
  width: '1px',
  height: '1px',
  margin: '-1px',
  padding: 0,
  overflow: 'hidden',
  clip: 'rect(0, 0, 0, 0)',
  whiteSpace: 'nowrap',
  border: 0,
};

const defaultPlatformSelection = (conns) => ({
  Facebook: Boolean(conns.facebook),
  Instagram: false,
  YouTube: false,
});

const charCount = (text) => Array.from(text || '').length;

const apiErrorText = (err, fallback) => {
  const data = err?.response?.data;
  const first = data?.errors ? Object.values(data.errors).flat()[0] : null;
  return first || data?.message || err?.message || fallback;
};

export default function PublishingPage() {
  const { selectedWorkspaceId, selectedWorkspace, registerWorkspaceSwitchGuard } = useWorkspace();
  const [activeTab, setActiveTab] = useState('composer'); // 'composer' | 'calendar'
  const [showAiModal, setShowAiModal] = useState(false);
  const navigate = useNavigate();

  const allWorkspaces = isAllWorkspaces(selectedWorkspaceId);
  const workspaceReady = isUsableWorkspaceId(selectedWorkspaceId);

  // Connected accounts for this specific workspace (Facebook, Instagram, YouTube)
  const [connections, setConnections] = useState(EMPTY_CONNECTIONS);
  const connectionsRef = useRef(EMPTY_CONNECTIONS);
  const [loadingIntegrations, setLoadingIntegrations] = useState(true);

  // Form states - Strictly scoped to Facebook, Instagram, YouTube
  const [platforms, setPlatforms] = useState({
    Facebook: false,
    Instagram: false,
    YouTube: false,
  });

  const [postTitle, setPostTitle] = useState('');
  const [videoTitle, setVideoTitle] = useState('');
  const [youtubePrivacy, setYoutubePrivacy] = useState(DEFAULT_YOUTUBE_PRIVACY);
  const [publishResults, setPublishResults] = useState(null); // Platform-level publish breakdown
  const [postCaption, setPostCaption] = useState('');
  const [postHashtags, setPostHashtags] = useState('');
  const [postCTA, setPostCTA] = useState(DEFAULT_CTA);

  // Content type selector: 'post' | 'carousel' | 'video'
  const [contentType, setContentType] = useState('post');
  const [activeCarouselSlide, setActiveCarouselSlide] = useState(0);
  const [mediaError, setMediaError] = useState('');
  const [mediaNotice, setMediaNotice] = useState('');

  // Media & drag state
  const [selectedFiles, setSelectedFiles] = useState([]);
  const [mediaPreviews, setMediaPreviews] = useState([]);
  const [isDragging, setIsDragging] = useState(false);
  const [isProcessingMedia, setIsProcessingMedia] = useState(false);
  const fileInputRef = useRef(null);

  // Draft being edited (B4): the saved record's id, its stored media, and what the server already holds
  const [draftId, setDraftId] = useState(null);
  const [existingMedia, setExistingMedia] = useState([]); // [{ id, type, url }] loaded from a saved draft
  const [serverMediaCount, setServerMediaCount] = useState(0);
  const [filesDirty, setFilesDirty] = useState(true); // local files not yet stored on the server
  const [loadingDraftId, setLoadingDraftId] = useState(null);
  const [mediaOrderDirty, setMediaOrderDirty] = useState(false); // saved carousel images were re-ordered

  // Custom video thumbnail / Reel cover, stored separately from the video
  const [thumbnailFile, setThumbnailFile] = useState(null); // newly chosen local file
  const [thumbnailPreview, setThumbnailPreview] = useState(''); // object URL of thumbnailFile
  const [thumbnailDirty, setThumbnailDirty] = useState(false); // thumbnailFile not yet stored on the server
  const [savedThumbnailUrl, setSavedThumbnailUrl] = useState(''); // thumbnail saved with the loaded draft
  const [removeThumbnailOnSave, setRemoveThumbnailOnSave] = useState(false);
  const [thumbnailError, setThumbnailError] = useState('');
  const thumbnailInputRef = useRef(null);
  const thumbnailSrc = thumbnailPreview || savedThumbnailUrl;

  // Field validation errors
  const [fieldErrors, setFieldErrors] = useState(EMPTY_FIELD_ERRORS);

  // Scheduled + history state
  const [scheduledPosts, setScheduledPosts] = useState([]);
  const [historyPosts, setHistoryPosts] = useState([]);
  const [historyPage, setHistoryPage] = useState(1);
  const [historyTotalPages, setHistoryTotalPages] = useState(1);
  const [historyTotalCount, setHistoryTotalCount] = useState(0);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [schedulerStatus, setSchedulerStatus] = useState(null); // { running, last_run_at } from /scheduled-posts
  const [retryingPostId, setRetryingPostId] = useState(null);
  const [retryingThumbnailId, setRetryingThumbnailId] = useState(null);

  // Unsaved changes & submit locks
  const [isDirty, setIsDirty] = useState(false);
  const isDirtyRef = useRef(false);
  isDirtyRef.current = isDirty;
  const isSavingRef = useRef(false);
  const activeObjectUrlsRef = useRef(new Set());
  const prevWorkspaceRef = useRef(null);
  const historyWorkspaceRef = useRef(null);
  const historyRequestRef = useRef(0);
  const workspaceRequestRef = useRef(0);

  // Publish confirmation dialog (B1): null | { platforms: string[] }
  const [publishConfirm, setPublishConfirm] = useState(null);

  // Schedule post dialog & loaded post state
  const [showScheduleModal, setShowScheduleModal] = useState(false);
  const [scheduleDate, setScheduleDate] = useState('');
  const [scheduleTime, setScheduleTime] = useState('');
  const [loadedPostStatus, setLoadedPostStatus] = useState('draft');
  const [loadedScheduledAt, setLoadedScheduledAt] = useState('');

  // Helpers for Asia/Kolkata timezone scheduling
  const getTodayKolkata = useCallback(() => {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata' }).format(new Date());
  }, []);

  const getDefaultTimeKolkata = useCallback(() => {
    const d = new Date(Date.now() + 60 * 60 * 1000);
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Asia/Kolkata',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).formatToParts(d);
    const hour = parts.find((p) => p.type === 'hour')?.value || '12';
    const minute = parts.find((p) => p.type === 'minute')?.value || '00';
    return `${hour}:${minute}`;
  }, []);

  // Object URL tracking and cleanup
  const createTrackedObjectURL = useCallback((file) => {
    const url = URL.createObjectURL(file);
    activeObjectUrlsRef.current.add(url);
    return url;
  }, []);

  const revokeTrackedObjectURL = useCallback((url) => {
    if (url && activeObjectUrlsRef.current.has(url)) {
      try {
        URL.revokeObjectURL(url);
      } catch {
      // best effort
    }
      activeObjectUrlsRef.current.delete(url);
    }
  }, []);

  const revokeAllObjectURLs = useCallback(() => {
    activeObjectUrlsRef.current.forEach((url) => {
      try {
        URL.revokeObjectURL(url);
      } catch {
      // best effort
    }
    });
    activeObjectUrlsRef.current.clear();
  }, []);

  // Cleanup all object URLs when unmounting
  useEffect(() => revokeAllObjectURLs, [revokeAllObjectURLs]);

  // Preview toggle
  const [previewPlatform, setPreviewPlatform] = useState('Facebook');

  // Status & Submit
  const [submitting, setSubmitting] = useState(false);
  const [statusMsg, setStatusMsg] = useState({ type: '', text: '' });

  // Saved media of a loaded draft is shown until new files replace it
  const mediaCount = selectedFiles.length > 0 ? selectedFiles.length : existingMedia.length;
  const displayPreviews = mediaPreviews.length > 0 ? mediaPreviews : existingMedia.map((m) => m.url);

  // Platform compatibility rules (Facebook, Instagram, YouTube)
  const getPlatformCompatibility = useCallback((key) => {
    if (key === 'YouTube') {
      if (contentType !== 'video') {
        return { compatible: false, reason: 'YouTube requires video content.' };
      }
      return { compatible: true, reason: '' };
    }
    if (key === 'Instagram') {
      if (contentType === 'post' && mediaCount === 0) {
        return { compatible: false, reason: 'Instagram requires media.' };
      }
      return { compatible: true, reason: '' };
    }
    if (key === 'Facebook') {
      return { compatible: true, reason: '' };
    }
    return { compatible: false, reason: 'Unsupported platform' };
  }, [contentType, mediaCount]);

  // A platform that becomes incompatible is un-ticked, so a box is never "checked but disabled" (B17)
  useEffect(() => {
    setPlatforms((prev) => {
      const next = { ...prev };
      let changed = false;
      Object.keys(next).forEach((key) => {
        if (next[key] && !getPlatformCompatibility(key).compatible) {
          next[key] = false;
          changed = true;
        }
      });
      return changed ? next : prev;
    });
  }, [getPlatformCompatibility]);

  const selectedPlatformNames = useMemo(
    () => Object.keys(platforms).filter((key) => platforms[key] && getPlatformCompatibility(key).compatible),
    [platforms, getPlatformCompatibility]
  );

  // Character limit calculations (platform specific, same numbers as the API)
  const fullMessage = buildMessage(postCaption, postHashtags);
  const totalLength = charCount(fullMessage);
  const maxCharLimit = captionLimitFor(selectedPlatformNames);
  const isOverLimit = totalLength > maxCharLimit;
  const isNearLimit = totalLength > maxCharLimit * 0.85;

  const hashtagIssues = useMemo(
    () => hashtagProblems(postHashtags, { instagram: selectedPlatformNames.includes('Instagram'), caption: postCaption }),
    [postHashtags, postCaption, selectedPlatformNames]
  );

  // Unsaved changes confirmation before leaving page
  useEffect(() => {
    const handleBeforeUnload = (e) => {
      if (isDirty) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Leave without saving?';
        return 'You have unsaved changes. Leave without saving?';
      }
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [isDirty]);

  // Clears the whole composer back to a pristine "new draft" (content type, platforms and draft identity included)
  const resetComposer = useCallback(() => {
    revokeAllObjectURLs();
    setContentType('post');
    setPlatforms(defaultPlatformSelection(connectionsRef.current));
    setPostTitle('');
    setVideoTitle('');
    setYoutubePrivacy(DEFAULT_YOUTUBE_PRIVACY);
    setPublishResults(null);
    setPostCaption('');
    setPostHashtags('');
    setPostCTA(DEFAULT_CTA);
    setSelectedFiles([]);
    setMediaPreviews([]);
    setExistingMedia([]);
    setServerMediaCount(0);
    setFilesDirty(true);
    setMediaOrderDirty(false);
    setThumbnailFile(null);
    setThumbnailPreview('');
    setThumbnailDirty(false);
    setSavedThumbnailUrl('');
    setRemoveThumbnailOnSave(false);
    setThumbnailError('');
    setDraftId(null);
    setActiveCarouselSlide(0);
    setMediaError('');
    setMediaNotice('');
    setFieldErrors(EMPTY_FIELD_ERRORS);
    setPublishConfirm(null);
    setShowScheduleModal(false);
    setScheduleDate('');
    setScheduleTime('');
    setLoadedPostStatus('draft');
    setLoadedScheduledAt('');
    setIsDirty(false);
  }, [revokeAllObjectURLs]);

  // Explicit New Draft reset
  const handleNewDraft = useCallback((confirm = true) => {
    if (confirm && isDirty && !window.confirm('Start a new draft? Any unsaved changes in the current draft will be discarded.')) {
      return;
    }
    resetComposer();
    setStatusMsg({ type: '', text: '' });
  }, [isDirty, resetComposer]);

  // Compute dynamic 7-day week starting Monday in Asia/Kolkata (IST)
  const weekDays = useMemo(() => {
    const now = new Date();
    const currentDay = now.getDay(); // 0 is Sun, 1 is Mon...
    const distanceToMonday = (currentDay + 6) % 7;
    const monday = new Date(now);
    monday.setDate(now.getDate() - distanceToMonday);
    monday.setHours(0, 0, 0, 0);

    const days = [];
    for (let i = 0; i < 7; i++) {
      const d = new Date(monday);
      d.setDate(monday.getDate() + i);
      days.push(d);
    }
    return days;
  }, []);

  const weekRangeLabel = useMemo(() => {
    if (!weekDays || weekDays.length === 0) return '';
    const startMonth = weekDays[0].toLocaleString('en-US', { timeZone: 'Asia/Kolkata', month: 'long' });
    const endMonth = weekDays[6].toLocaleString('en-US', { timeZone: 'Asia/Kolkata', month: 'long' });
    const startDay = weekDays[0].getDate();
    const endDay = weekDays[6].getDate();
    const year = weekDays[6].getFullYear();
    return startMonth === endMonth
      ? `${startMonth} ${startDay}–${endDay}, ${year}`
      : `${startMonth} ${startDay} – ${endMonth} ${endDay}, ${year}`;
  }, [weekDays]);

  // Combine and deduplicate scheduled posts for calendar
  const allScheduledPosts = useMemo(() => {
    const map = new Map();
    (scheduledPosts || []).forEach((p) => {
      if (p.status === 'scheduled') map.set(p.id, p);
    });
    return Array.from(map.values()).sort((a, b) => new Date(a.scheduled_at) - new Date(b.scheduled_at));
  }, [scheduledPosts]);

  // Loads connected accounts + scheduled posts. `resetSelection` (workspace change only) also resets the
  // platform ticks; refreshes after saving never touch what the user selected (B17).
  const loadWorkspaceData = useCallback(async ({ resetSelection = false } = {}) => {
    const requestId = ++workspaceRequestRef.current;
    const isCurrent = () => requestId === workspaceRequestRef.current;

    if (!isUsableWorkspaceId(selectedWorkspaceId)) {
      connectionsRef.current = EMPTY_CONNECTIONS;
      setConnections(EMPTY_CONNECTIONS);
      setScheduledPosts([]);
      if (resetSelection) setPlatforms(defaultPlatformSelection(EMPTY_CONNECTIONS));
      setLoadingIntegrations(false);
      return;
    }

    setLoadingIntegrations(true);

    try {
      // 1. Fetch real integrations status for this workspace
      const integRes = await axiosInstance.get('/integrations/status', {
        params: { workspace_id: selectedWorkspaceId },
      });

      const connMap = { facebook: null, instagram: null, youtube: null };

      if (integRes.data.success && Array.isArray(integRes.data.data)) {
        integRes.data.data.forEach((item) => {
          if (item.status === 'connected') {
            if (item.key === 'facebook') connMap.facebook = item;
            if (item.key === 'instagram') connMap.instagram = item;
            if (item.key === 'youtube') connMap.youtube = item;
          }
        });
      }

      // Also verify Facebook Page endpoint
      try {
        const pageRes = await axiosInstance.get('/facebook/pages', {
          params: { workspace_id: selectedWorkspaceId },
        });
        if (pageRes.data.success && pageRes.data.data.length > 0) {
          connMap.facebook = {
            ...connMap.facebook,
            ...pageRes.data.data[0],
            account_name: pageRes.data.data[0].account_name || pageRes.data.data[0].page_name,
            account_id: pageRes.data.data[0].account_id || pageRes.data.data[0].page_id,
          };
        }
      } catch {
      // best effort
    }

      // Also verify YouTube status endpoint
      try {
        const ytRes = await axiosInstance.get('/youtube/status', {
          params: { workspace_id: selectedWorkspaceId },
        });
        if (ytRes.data.success && ytRes.data.connected && ytRes.data.data) {
          connMap.youtube = {
            ...connMap.youtube,
            ...ytRes.data.data,
          };
        }
      } catch {
      // best effort
    }

      if (!isCurrent()) return;
      connectionsRef.current = connMap;
      setConnections(connMap);

      if (resetSelection) {
        setPlatforms(defaultPlatformSelection(connMap));
        // Default preview platform: first connected platform
        if (connMap.facebook) setPreviewPlatform('Facebook');
        else if (connMap.instagram) setPreviewPlatform('Instagram');
        else if (connMap.youtube) setPreviewPlatform('YouTube');
        else setPreviewPlatform('Facebook');
      }

      // Scheduled Posts (drafts are listed through the history table)
      const schedRes = await axiosInstance.get('/scheduled-posts', {
        params: { workspace_id: selectedWorkspaceId },
      });
      if (isCurrent() && schedRes.data.success) {
        setScheduledPosts(schedRes.data.data || []);
        setSchedulerStatus(schedRes.data.scheduler || null);
      }
    } catch (err) {
      console.error('Error loading publishing data:', err);
    } finally {
      if (isCurrent()) setLoadingIntegrations(false);
    }
  }, [selectedWorkspaceId]);

  const fetchHistoryPosts = useCallback(async (page = 1) => {
    const requestId = ++historyRequestRef.current;
    if (!isUsableWorkspaceId(selectedWorkspaceId)) {
      setHistoryPosts([]);
      setHistoryTotalPages(1);
      setHistoryTotalCount(0);
      setHistoryLoading(false);
      return;
    }
    setHistoryLoading(true);
    try {
      const res = await axiosInstance.get('/posts/history', {
        params: {
          workspace_id: selectedWorkspaceId,
          page,
          per_page: 5,
        },
      });
      if (requestId !== historyRequestRef.current) return; // a newer request superseded this one
      if (res.data.success) {
        setHistoryPosts(res.data.data || []);
        setHistoryTotalPages(res.data.last_page || 1);
        setHistoryTotalCount(res.data.total || 0);
      }
    } catch (err) {
      console.error('Error fetching paginated history:', err);
    } finally {
      if (requestId === historyRequestRef.current) setHistoryLoading(false);
    }
  }, [selectedWorkspaceId]);

  // Go to a history page: changing the page state triggers the single fetch effect below; if we are already on
  // that page, fetch it directly.
  const reloadHistory = useCallback((page = historyPage) => {
    if (page !== historyPage) setHistoryPage(page);
    else fetchHistoryPosts(page);
  }, [historyPage, fetchHistoryPosts]);

  // The Topbar client selector asks us before switching away from unsaved work (B10). Cancel = nothing changes:
  // the selector snaps back and the draft stays with its own client.
  useEffect(() => registerWorkspaceSwitchGuard(() => {
    if (!isDirtyRef.current) return true;
    return window.confirm(
      'You have unsaved changes in this draft. Switching client will discard them. Discard the draft and switch?'
    );
  }), [registerWorkspaceSwitchGuard]);

  // Reload everything when the client/workspace switches. Content never travels between clients.
  useEffect(() => {
    const previous = prevWorkspaceRef.current;
    prevWorkspaceRef.current = selectedWorkspaceId;
    if (previous !== null && previous !== selectedWorkspaceId) {
      resetComposer();
      setStatusMsg({ type: '', text: '' });
    }
    loadWorkspaceData({ resetSelection: true });
  }, [selectedWorkspaceId, loadWorkspaceData, resetComposer]);

  // Single history fetch for workspace and page changes (no duplicate requests on mount)
  useEffect(() => {
    if (historyWorkspaceRef.current !== selectedWorkspaceId) {
      historyWorkspaceRef.current = selectedWorkspaceId;
      if (historyPage !== 1) {
        setHistoryPage(1);
        return;
      }
    }
    fetchHistoryPosts(historyPage);
  }, [selectedWorkspaceId, historyPage, fetchHistoryPosts]);

  // Escape closes the publish confirmation
  useEffect(() => {
    if (!publishConfirm) return undefined;
    const onKey = (e) => {
      if (e.key === 'Escape') setPublishConfirm(null);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [publishConfirm]);

  const handlePlatformToggle = (key) => {
    if (!getPlatformCompatibility(key).compatible) return;
    setPlatforms((prev) => ({ ...prev, [key]: !prev[key] }));
    setIsDirty(true);
    setFieldErrors((prev) => ({ ...prev, platforms: '' }));
  };

  const handleContentTypeChange = (newType) => {
    if (newType === contentType) return;
    setContentType(newType);
    setMediaError('');
    setMediaNotice('');
    setFieldErrors(EMPTY_FIELD_ERRORS);
    setActiveCarouselSlide(0);
    setIsDirty(true);
    setFilesDirty(true);
    setMediaOrderDirty(false);

    // A custom thumbnail only belongs to video posts; image posts use the image itself.
    if (newType !== 'video') {
      if (thumbnailPreview) revokeTrackedObjectURL(thumbnailPreview);
      setThumbnailFile(null);
      setThumbnailPreview('');
      setThumbnailDirty(false);
      setThumbnailError('');
      if (savedThumbnailUrl) {
        setSavedThumbnailUrl('');
        setRemoveThumbnailOnSave(true);
      }
    }

    if (existingMedia.length > 0) {
      // Saved media belongs to the previous content type; it is replaced on the next save
      setExistingMedia([]);
      setMediaNotice('The media saved with this draft was removed because the content type changed. Attach new media.');
    }

    // Adjust selected files to match the new type cleanly with tracked URL cleanup
    mediaPreviews.forEach((url) => revokeTrackedObjectURL(url));
    if (newType === 'video') {
      const videoFiles = selectedFiles.filter((f) => f.type.startsWith('video/'));
      if (videoFiles.length > 0) {
        const keptFile = videoFiles[0];
        setSelectedFiles([keptFile]);
        setMediaPreviews([createTrackedObjectURL(keptFile)]);
      } else {
        setSelectedFiles([]);
        setMediaPreviews([]);
      }
    } else {
      const imageFiles = selectedFiles.filter((f) => f.type.startsWith('image/'));
      const kept = newType === 'post' ? imageFiles.slice(0, 1) : imageFiles;
      setSelectedFiles(kept);
      setMediaPreviews(kept.map((f) => createTrackedObjectURL(f)));
    }
  };

  // Centralized media selection handler (used by file inputs & drag-drop)
  const handleFilesSelected = (files) => {
    if (!files || !files.length) return;
    setIsProcessingMedia(true);
    setMediaError('');
    setMediaNotice('');
    setFieldErrors((prev) => ({ ...prev, media: '' }));

    const acceptFiles = (list) => {
      setFilesDirty(true);
      setIsDirty(true);
      setExistingMedia([]); // new files replace whatever the draft had stored
      return list;
    };

    if (contentType === 'video' || contentType === 'post') {
      const file = files[0];
      const problem = contentType === 'video' ? validateVideoFile(file) : validateImageFile(file);
      if (problem) {
        setMediaError(problem);
        setIsProcessingMedia(false);
        return;
      }

      // Cleanup previous preview URL
      mediaPreviews.forEach((url) => revokeTrackedObjectURL(url));

      acceptFiles([file]);
      setSelectedFiles([file]);
      setMediaPreviews([createTrackedObjectURL(file)]);
      if (files.length > 1) {
        setMediaNotice(
          contentType === 'video'
            ? `Only one video can be attached. "${file.name}" was added; ${files.length - 1} other file(s) were ignored.`
            : `A single-image post takes one image. "${file.name}" was added; ${files.length - 1} other file(s) were ignored. Choose Carousel to attach several images.`
        );
      }
      setIsProcessingMedia(false);
    } else if (contentType === 'carousel') {
      // Validate every file (type + size) before adding any
      for (const f of files) {
        const problem = validateImageFile(f);
        if (problem) {
          setMediaError(problem);
          setIsProcessingMedia(false);
          return;
        }
      }

      // Deduplication: prevent adding duplicate images (match name + size)
      const existingSignatures = new Set(selectedFiles.map((f) => `${f.name}-${f.size}`));
      const uniqueNewFiles = [];
      let duplicateCount = 0;

      files.forEach((file) => {
        const sig = `${file.name}-${file.size}`;
        if (existingSignatures.has(sig)) {
          duplicateCount++;
        } else {
          existingSignatures.add(sig);
          uniqueNewFiles.push(file);
        }
      });

      if (duplicateCount > 0 && uniqueNewFiles.length === 0) {
        setMediaNotice(`All ${duplicateCount} selected image(s) have already been added to the carousel.`);
        setIsProcessingMedia(false);
        return;
      }

      // Max 10 images limit
      let filesToAdd = uniqueNewFiles;
      if (selectedFiles.length + uniqueNewFiles.length > CAROUSEL_MAX) {
        const allowedCount = Math.max(0, CAROUSEL_MAX - selectedFiles.length);
        filesToAdd = uniqueNewFiles.slice(0, allowedCount);
        setMediaNotice(`${MESSAGES.carouselMax} Added ${allowedCount} image(s); the extra ones were skipped.`);
      } else if (duplicateCount > 0) {
        setMediaNotice(`Added ${uniqueNewFiles.length} image(s); skipped ${duplicateCount} duplicate image(s).`);
      }

      acceptFiles(filesToAdd);
      const newUrls = filesToAdd.map((f) => createTrackedObjectURL(f));
      setSelectedFiles((prev) => [...prev, ...filesToAdd]);
      setMediaPreviews((prev) => [...prev, ...newUrls]);
      setIsProcessingMedia(false);
    }
  };

  const handleDragOver = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(true);
  };

  const handleDragLeave = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(false);
  };

  const handleDrop = (e) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragging(false);
    const files = Array.from(e.dataTransfer?.files || []);
    if (files.length > 0) {
      handleFilesSelected(files);
    }
  };

  const handleFileChange = (e) => {
    const files = Array.from(e.target.files || []);
    if (files.length > 0) {
      handleFilesSelected(files);
    }
    e.target.value = '';
  };

  // The whole drop area opens the file picker; controls inside it are marked data-no-pick (B17)
  const openFilePicker = () => fileInputRef.current?.click();
  const handleDropzoneClick = (e) => {
    if (e.target.closest?.('[data-no-pick]')) return;
    if (e.target === fileInputRef.current) return;
    openFilePicker();
  };
  const handleDropzoneKeyDown = (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      openFilePicker();
    }
  };

  const handleRemoveMedia = (index) => {
    if (mediaPreviews[index]) {
      revokeTrackedObjectURL(mediaPreviews[index]);
    }
    const newFiles = selectedFiles.filter((_, idx) => idx !== index);
    setSelectedFiles(newFiles);
    const newPreviews = mediaPreviews.filter((_, idx) => idx !== index);
    setMediaPreviews(newPreviews);
    setIsDirty(true);
    setFilesDirty(true);
    if (activeCarouselSlide >= newPreviews.length) {
      setActiveCarouselSlide(Math.max(0, newPreviews.length - 1));
    }
  };

  const handleRemoveSavedMedia = () => {
    setExistingMedia([]);
    setIsDirty(true);
    setFilesDirty(true);
    setMediaNotice('');
  };

  // Re-ordering images saved with a draft (no re-upload; the new order is sent as media_order[])
  const reorderSavedMedia = (nextMedia, slideToShow) => {
    setExistingMedia(nextMedia);
    setMediaOrderDirty(true);
    setIsDirty(true);
    setActiveCarouselSlide(slideToShow);
  };

  // Carousel cover = first slide. Moves the chosen slide to the front, keeping the others in order.
  const handleSetCarouselCover = (index) => {
    if (index <= 0) return;
    if (selectedFiles.length > 0) {
      setSelectedFiles(moveToFront(selectedFiles, index));
      setMediaPreviews(moveToFront(mediaPreviews, index));
      setIsDirty(true);
      setFilesDirty(true);
      setActiveCarouselSlide(0);
    } else if (existingMedia.length > 0) {
      reorderSavedMedia(moveToFront(existingMedia, index), 0);
    }
  };

  const handleMoveMedia = (index, direction) => {
    const targetIndex = index + direction;
    if (selectedFiles.length === 0 && existingMedia.length > 0) {
      if (targetIndex < 0 || targetIndex >= existingMedia.length) return;
      const next = [...existingMedia];
      [next[index], next[targetIndex]] = [next[targetIndex], next[index]];
      const slide = activeCarouselSlide === index ? targetIndex : (activeCarouselSlide === targetIndex ? index : activeCarouselSlide);
      reorderSavedMedia(next, slide);
      return;
    }
    if (targetIndex < 0 || targetIndex >= selectedFiles.length) return;

    const newFiles = [...selectedFiles];
    const tempFile = newFiles[index];
    newFiles[index] = newFiles[targetIndex];
    newFiles[targetIndex] = tempFile;
    setSelectedFiles(newFiles);

    const newPreviews = [...mediaPreviews];
    const tempPreview = newPreviews[index];
    newPreviews[index] = newPreviews[targetIndex];
    newPreviews[targetIndex] = tempPreview;
    setMediaPreviews(newPreviews);
    setIsDirty(true);
    setFilesDirty(true);

    if (activeCarouselSlide === index) {
      setActiveCarouselSlide(targetIndex);
    } else if (activeCarouselSlide === targetIndex) {
      setActiveCarouselSlide(index);
    }
  };

  // Custom thumbnail / cover image for video posts
  const handleThumbnailSelected = (file) => {
    setThumbnailError('');
    const problem = validateThumbnailFile(file);
    if (problem) {
      setThumbnailError(problem);
      return;
    }
    const url = createTrackedObjectURL(file);
    const probe = new window.Image();
    probe.onload = () => {
      const dimensionProblem = validateThumbnailDimensions(probe.naturalWidth, probe.naturalHeight);
      if (dimensionProblem) {
        revokeTrackedObjectURL(url);
        setThumbnailError(dimensionProblem);
        return;
      }
      if (thumbnailPreview) revokeTrackedObjectURL(thumbnailPreview);
      setThumbnailFile(file);
      setThumbnailPreview(url);
      setThumbnailDirty(true);
      setRemoveThumbnailOnSave(false);
      setIsDirty(true);
    };
    probe.onerror = () => {
      revokeTrackedObjectURL(url);
      setThumbnailError('The thumbnail could not be read as an image.');
    };
    probe.src = url;
  };

  const handleThumbnailChange = (e) => {
    const file = e.target.files && e.target.files[0];
    if (file) handleThumbnailSelected(file);
    e.target.value = '';
  };

  const handleRemoveThumbnail = () => {
    if (thumbnailPreview) revokeTrackedObjectURL(thumbnailPreview);
    setThumbnailFile(null);
    setThumbnailPreview('');
    setThumbnailDirty(false);
    setThumbnailError('');
    setSavedThumbnailUrl('');
    // Whenever a saved record exists, tell the server to drop its thumbnail (a no-op if it has none)
    if (draftId || savedThumbnailUrl) setRemoveThumbnailOnSave(true);
    setIsDirty(true);
  };

  const hasAnyConnection = Boolean(
    connections.facebook || connections.instagram || connections.youtube
  );

  // Pure validation of the composer for 'draft' or 'publish'. Mirrors the API rules.
  const validateComposer = (action) => {
    const errors = { ...EMPTY_FIELD_ERRORS };
    let hasErrors = false;

    // 0. A single, real client workspace is required (B14)
    if (!workspaceReady) {
      errors.workspace = allWorkspaces ? MESSAGES.allWorkspaces : 'Please select a client workspace first.';
      hasErrors = true;
    }

    // A. Validate platform compatibility
    if (selectedPlatformNames.length === 0) {
      errors.platforms = 'Please select at least one compatible platform for this content type.';
      hasErrors = true;
    }

    // B. If publishing for real, validate required connected account availability
    if (action === 'publish' && selectedPlatformNames.length > 0) {
      const missingPlatforms = [];
      if (selectedPlatformNames.includes('Facebook') && !connections.facebook) {
        missingPlatforms.push('Facebook Page');
      }
      if (selectedPlatformNames.includes('Instagram') && !connections.instagram && !connections.facebook) {
        missingPlatforms.push('Instagram Account');
      }
      if (selectedPlatformNames.includes('YouTube') && !connections.youtube) {
        missingPlatforms.push('YouTube Channel');
      }

      if (missingPlatforms.length > 0) {
        errors.platforms = `Cannot publish: No connected account found for ${missingPlatforms.join(', ')}. Please connect them in API Connections or unselect them.`;
        hasErrors = true;
      }
    }

    // C. Validate caption & length
    if (!postCaption.trim() && mediaCount === 0) {
      errors.caption = MESSAGES.emptyPost;
      hasErrors = true;
    } else if (isOverLimit) {
      errors.caption = `Exceeds the ${maxCharLimit}-character limit for the selected platform(s).`;
      hasErrors = true;
    }

    // D. Hashtags
    if (hashtagIssues.length > 0) {
      errors.hashtags = hashtagIssues.join(' ');
      hasErrors = true;
    }

    // E. Validate media based on content type
    if (contentType === 'carousel') {
      if (mediaCount < 2) {
        errors.media = `${MESSAGES.carouselMin} You have ${mediaCount}. Please attach more images or select Post.`;
        hasErrors = true;
      }
    } else if (contentType === 'video') {
      if (mediaCount === 0) {
        errors.media = 'Please attach a video file for video content.';
        hasErrors = true;
      }
    }

    // E2. YouTube visibility must be Public or Private before scheduling/publishing (old Unlisted drafts)
    if (action !== 'draft' && contentType === 'video' && selectedPlatformNames.includes('YouTube') && !isValidYoutubePrivacy(youtubePrivacy)) {
      errors.youtubePrivacy = YOUTUBE_PRIVACY_REQUIRED;
      hasErrors = true;
    }

    // E3. YouTube takes custom thumbnails up to 2 MB: refuse before the video goes live without it
    if (action !== 'draft' && contentType === 'video' && selectedPlatformNames.includes('YouTube') && thumbnailFile) {
      const thumbProblem = youtubeThumbnailProblem(thumbnailFile);
      if (thumbProblem) {
        errors.thumbnail = thumbProblem;
        hasErrors = true;
      }
    }

    // F. Instagram specific requirement: cannot be text-only
    if (action === 'publish' && selectedPlatformNames.includes('Instagram') && mediaCount === 0) {
      errors.media = 'Instagram requires an image or video to create a post. Please attach media.';
      hasErrors = true;
    }

    return { errors, hasErrors };
  };

  const applyServerErrors = (data) => {
    const serverErrors = data?.errors;
    if (!serverErrors || typeof serverErrors !== 'object') return;
    const first = (key) => (Array.isArray(serverErrors[key]) ? serverErrors[key][0] : '');
    const mediaMsg = first('video') || first('images') || Object.keys(serverErrors).filter((k) => k.startsWith('images.')).map(first)[0] || first('media_order') || '';
    if (first('thumbnail')) setThumbnailError(first('thumbnail'));
    setFieldErrors((prev) => ({
      ...prev,
      caption: first('message') || prev.caption,
      hashtags: first('hashtags') || prev.hashtags,
      media: mediaMsg || prev.media,
      workspace: first('workspace_id') || prev.workspace,
      youtubePrivacy: first('youtube_privacy') || prev.youtubePrivacy,
    }));
  };

  // Form Submission. 'draft' only ever saves; 'publish' must be confirmed in the dialog first (B1).
  const handleSubmit = async (action = 'draft', confirmed = false) => {
    // 1. Prevent double clicks / concurrent requests
    if (isSavingRef.current || submitting) return;

    // 2. Validation
    const { errors, hasErrors } = validateComposer(action);
    setFieldErrors(errors);
    if (errors.thumbnail) setThumbnailError(errors.thumbnail);

    if (hasErrors) {
      setPublishConfirm(null);
    setShowScheduleModal(false);
    setScheduleDate('');
    setScheduleTime('');
    setLoadedPostStatus('draft');
    setLoadedScheduledAt('');
      setStatusMsg({
        type: 'error',
        text: `Please resolve the highlighted validation errors before ${action === 'publish' ? 'publishing' : (action === 'scheduled' ? 'scheduling' : 'saving draft')}.`,
      });
      return;
    }

    // 3. Publishing is irreversible: ask first. Nothing is sent until the user confirms.
    if (action === 'publish' && !confirmed) {
      setPublishConfirm({ platforms: selectedPlatformNames });
      return;
    }
    setPublishConfirm(null);
    setShowScheduleModal(false);
    setScheduleDate('');
    setScheduleTime('');
    setLoadedPostStatus('draft');
    setLoadedScheduledAt('');

    isSavingRef.current = true;
    setSubmitting(action);
    setStatusMsg({ type: '', text: '' });
    setPublishResults(null);

    try {
      const formData = new FormData();
      formData.append('workspace_id', selectedWorkspaceId);
      if (draftId) formData.append('draft_id', draftId);
      formData.append('message', fullMessage);
      formData.append('status', action === 'publish' ? 'published' : (action === 'scheduled' ? 'scheduled' : 'draft'));
      if (action === 'scheduled') {
        formData.append('scheduled_at', `${scheduleDate}T${scheduleTime}:00`);
      }
      if (postTitle.trim()) formData.append('internal_title', postTitle.trim());
      formData.append('cta', postCTA);
      const tags = normalizeHashtags(postHashtags);
      if (tags) formData.append('hashtags', tags);

      selectedPlatformNames.forEach((p) => {
        formData.append('platforms[]', p);
      });

      // Local files are only (re)uploaded when they changed since the draft was last stored
      const sendFiles = selectedFiles.length > 0 && (!draftId || filesDirty || serverMediaCount === 0);
      const clearMedia = Boolean(draftId) && serverMediaCount > 0 && selectedFiles.length === 0 && existingMedia.length === 0;

      if (contentType === 'video') {
        if (sendFiles && selectedFiles[0].type.startsWith('video/')) formData.append('video', selectedFiles[0]);
        formData.append('post_type', 'video');
        if (videoTitle.trim()) {
          formData.append('video_title', videoTitle.trim());
        }
        if (isValidYoutubePrivacy(youtubePrivacy)) formData.append('youtube_privacy', youtubePrivacy);
        // Custom thumbnail: only (re)uploaded when it changed since the draft was last stored
        const hasVideo = selectedFiles.length > 0 || existingMedia.length > 0;
        if (thumbnailFile && hasVideo && (thumbnailDirty || !draftId)) {
          formData.append('thumbnail', thumbnailFile);
        } else if (removeThumbnailOnSave && draftId) {
          formData.append('remove_thumbnail', '1');
        }
      } else if (contentType === 'carousel') {
        if (sendFiles) selectedFiles.forEach((file) => formData.append('images[]', file));
        // Saved images re-ordered (e.g. a different cover chosen) without re-uploading them
        if (!sendFiles && mediaOrderDirty && existingMedia.length > 0) {
          existingMedia.forEach((m) => formData.append('media_order[]', m.id));
        }
        formData.append('post_type', 'multi_image');
      } else {
        if (sendFiles) selectedFiles.forEach((file) => formData.append('images[]', file));
        formData.append('post_type', mediaCount > 0 ? 'single_image' : 'text');
      }
      if (clearMedia) formData.append('clear_media', '1');

      const res = await axiosInstance.post('/facebook/publish-post', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      if (res.data.success) {
        setPublishResults(res.data.platform_statuses || null);
        if (action === 'publish') {
          // Lets dashboards drop cached platform data so the new post shows up immediately.
          window.dispatchEvent(new CustomEvent('mc:post-published', { detail: { platformStatuses: res.data.platform_statuses || {} } }));
        }
        const isPartial = Boolean(res.data.partial);
        const saved = res.data.data;

        if (action === 'draft' || action === 'scheduled') {
          setLoadedPostStatus(action);
          setLoadedScheduledAt(saved?.scheduled_at ?? (action === 'scheduled' ? `${scheduleDate}T${scheduleTime}:00` : ''));
          setShowScheduleModal(false);
          // Keep editing the SAME record: the next save updates it instead of creating another (B4)
          setDraftId(saved?.id ?? draftId);
          setServerMediaCount(Array.isArray(saved?.media) ? saved.media.length : 0);
          setFilesDirty(false);
          setMediaOrderDirty(false);
          setExistingMedia((prev) => (selectedFiles.length > 0 ? [] : prev));
          // The server now holds the thumbnail (or none): later saves only send changes
          setSavedThumbnailUrl(saved?.thumbnail_url || '');
          setThumbnailDirty(false);
          setRemoveThumbnailOnSave(false);
          if (!saved?.thumbnail_url && thumbnailFile) {
            // Not stored (e.g. no video attached yet): keep it pending for the next save
            setThumbnailDirty(true);
          }
        } else {
          setDraftId(null);
          setLoadedPostStatus('draft');
          setLoadedScheduledAt('');
          setServerMediaCount(0);
          setFilesDirty(true);
          setMediaOrderDirty(false);
          // The next publish creates a new record, so a chosen thumbnail must be sent again
          setThumbnailDirty(Boolean(thumbnailFile));
          setRemoveThumbnailOnSave(false);
        }

        setStatusMsg({
          type: isPartial ? 'warning' : 'success',
          text: res.data.message || (action === 'publish' ? 'Post published successfully!' : (action === 'scheduled' ? (draftId ? 'Scheduled post updated successfully!' : 'Post scheduled successfully!') : 'Draft saved successfully!')),
        });
        // Form data is preserved on purpose. The success message above is NOT cleared by the refresh (B5).
        setIsDirty(false);
        loadWorkspaceData();
        reloadHistory(1);
      } else {
        setPublishResults(res.data.platform_statuses || null);
        setStatusMsg({
          type: 'error',
          text: res.data.message || `${action === 'publish' ? 'Publishing' : 'Saving draft'} failed.`,
        });
      }
    } catch (err) {
      console.error(`${action} error:`, err);
      setPublishResults(err.response?.data?.platform_statuses || null);
      applyServerErrors(err.response?.data);
      setStatusMsg({
        type: 'error',
        text: apiErrorText(err, `Network error while ${action === 'publish' ? 'publishing' : 'saving draft'}.`),
      });
      // Preserves form data on error so user can retry!
    } finally {
      setSubmitting(false);
      isSavingRef.current = false;
    }
  };

  // Load a saved draft back into the composer (B4/B6)
  const handleEditDraft = async (post) => {
    if (loadingDraftId) return;
    if (isDirty && !window.confirm('Open this draft? Unsaved changes in the composer will be discarded.')) return;

    setLoadingDraftId(post.id);
    try {
      const res = await axiosInstance.get(`/drafts/${post.id}`);
      const draft = res.data?.data;
      if (!res.data?.success || !draft) throw new Error(res.data?.message || 'Draft could not be loaded.');

      revokeAllObjectURLs();
      const { caption, hashtags } = splitMessage(draft.content, draft.hashtags);
      const media = (draft.media || []).map((m) => ({ id: m.id, type: m.media_type, url: m.file_url }));
      const savedPlatforms = Array.isArray(draft.platform_list) ? draft.platform_list : [];
      const selection = { Facebook: false, Instagram: false, YouTube: false };
      const dropped = [];
      savedPlatforms.forEach((name) => {
        if (connectionsRef.current[String(name).toLowerCase()]) selection[name] = true;
        else dropped.push(name);
      });

      setContentType(contentTypeFromPostType(draft.post_type));
      setPlatforms(selection); // incompatible ones are un-ticked by the compatibility effect
      setPostCaption(caption);
      setPostHashtags(hashtags);
      setPostTitle(draft.internal_title || '');
      setPostCTA(draft.cta || DEFAULT_CTA);
      setVideoTitle(draft.video_title || '');
      // A YouTube post saved as Unlisted (no longer offered) loads with no choice: the user must pick Public or Private.
      setYoutubePrivacy(youtubePrivacyFromSaved(draft.youtube_privacy, savedPlatforms));
      setSelectedFiles([]);
      setMediaPreviews([]);
      setExistingMedia(media);
      setServerMediaCount(media.length);
      setFilesDirty(false);
      setMediaOrderDirty(false);
      setThumbnailFile(null);
      setThumbnailPreview('');
      setThumbnailDirty(false);
      setSavedThumbnailUrl(draft.thumbnail_url || '');
      setRemoveThumbnailOnSave(false);
      setThumbnailError('');
      setDraftId(draft.id);
      setLoadedPostStatus(draft.status || 'draft');
      if (draft.scheduled_at) {
        setLoadedScheduledAt(draft.scheduled_at);
        const schedDate = new Date(draft.scheduled_at);
        const dateStr = schedDate.toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
        const timeStr = schedDate.toLocaleTimeString('en-GB', { timeZone: 'Asia/Kolkata', hour: '2-digit', minute: '2-digit', hour12: false });
        setScheduleDate(dateStr);
        setScheduleTime(timeStr);
      } else {
        setLoadedScheduledAt('');
      }
      setActiveCarouselSlide(0);
      setMediaError('');
      setMediaNotice('');
      setFieldErrors(EMPTY_FIELD_ERRORS);
      setPublishResults(null);
      setIsDirty(false);
      setActiveTab('composer');
      setStatusMsg({
        type: dropped.length ? 'warning' : 'success',
        text: dropped.length
          ? `Draft #${draft.id} loaded. ${dropped.join(', ')} is no longer connected for this client and was not selected.`
          : `${draft.status === 'scheduled' ? 'Scheduled post' : 'Draft'} #${draft.id} loaded. Changes you save will update this ${draft.status === 'scheduled' ? 'scheduled post' : 'draft'}.`,
      });
      if (typeof window.scrollTo === 'function') window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err) {
      setStatusMsg({ type: 'error', text: apiErrorText(err, 'Could not load this draft.') });
    } finally {
      setLoadingDraftId(null);
    }
  };

  // Retry a failed / partially published post: only the platforms that failed are attempted again
  const handleRetryPost = async (post) => {
    if (retryingPostId) return;
    const failed = Object.values(post.platform_statuses || {})
      .filter((s) => s && s.status === 'failed')
      .map((s) => s.platform)
      .filter(Boolean);
    const target = failed.length ? failed.join(', ') : (post.platform_list || []).join(', ');
    if (!window.confirm(`Retry publishing to ${target || 'the selected platforms'}? Platforms that already have this post are skipped.`)) return;

    setRetryingPostId(post.id);
    try {
      const res = await axiosInstance.post(`/posts/${post.id}/retry`);
      setPublishResults(res.data?.platform_statuses || null);
      // 202 = temporary error, the post was re-scheduled for an automatic retry
      setStatusMsg({ type: res.data?.success === false || res.data?.partial ? 'warning' : 'success', text: res.data?.message || 'Post retried.' });
    } catch (err) {
      setPublishResults(err.response?.data?.platform_statuses || null);
      setStatusMsg({ type: err.response?.status === 202 ? 'warning' : 'error', text: apiErrorText(err, 'Retry failed.') });
    } finally {
      setRetryingPostId(null);
      loadWorkspaceData();
      reloadHistory(historyPage);
    }
  };

  // Re-applies only the custom thumbnail to the existing YouTube video (the video is not uploaded again).
  const handleRetryThumbnail = async (post) => {
    if (retryingThumbnailId) return;
    setRetryingThumbnailId(post.id);
    try {
      const res = await axiosInstance.post(`/posts/${post.id}/youtube-thumbnail/retry`);
      const t = res.data?.thumbnail;
      setStatusMsg({ type: t?.applied ? 'success' : 'warning', text: res.data?.message || 'Thumbnail retried.' });
    } catch (err) {
      setStatusMsg({ type: 'error', text: apiErrorText(err, 'The thumbnail could not be applied.') });
    } finally {
      setRetryingThumbnailId(null);
      reloadHistory(historyPage);
    }
  };

  const handleDuplicatePost = async (id) => {
    try {
      const res = await axiosInstance.post(`/posts/${id}/duplicate`);
      if (res.data.success) {
        setStatusMsg({ type: 'success', text: 'Post duplicated as a new draft!' });
        reloadHistory(1);
      }
    } catch (err) {
      setStatusMsg({ type: 'error', text: apiErrorText(err, 'Error duplicating post') });
    }
  };

  const handleDeleteDraft = async (id) => {
    if (!window.confirm('Delete this draft?')) return;
    try {
      await axiosInstance.delete(`/drafts/${id}`);
      if (draftId === id) {
        // The draft being edited no longer exists on the server; the next save creates a fresh one
        setDraftId(null);
        setServerMediaCount(0);
        setFilesDirty(true);
        setExistingMedia([]);
      }
      setStatusMsg({ type: 'success', text: 'Draft deleted.' });
      reloadHistory(historyPage);
    } catch (err) {
      setStatusMsg({ type: 'error', text: apiErrorText(err, 'Error deleting draft') });
    }
  };

  const handleDeleteScheduled = async (id) => {
    if (!window.confirm('Cancel this scheduled post?')) return;
    try {
      await axiosInstance.delete(`/scheduled-posts/${id}`);
      setStatusMsg({ type: 'success', text: 'Scheduled post cancelled.' });
      loadWorkspaceData();
      reloadHistory(historyPage);
    } catch (err) {
      setStatusMsg({ type: 'error', text: apiErrorText(err, 'Error cancelling scheduled post') });
    }
  };

  // Preview profile details
  const getPreviewAccountDetails = () => {
    if (previewPlatform === 'YouTube') {
      return {
        name: connections.youtube ? connections.youtube.channel_name : 'YouTube Channel',
        sub: connections.youtube ? `${connections.youtube.subscriber_count?.toLocaleString() || 0} subscribers` : 'Not Connected',
        initials: connections.youtube ? connections.youtube.channel_name.substring(0, 2).toUpperCase() : 'YT',
        bg: '#ff0000',
      };
    }
    if (previewPlatform === 'Instagram') {
      return {
        name: connections.instagram ? (connections.instagram.account_name.startsWith('@') ? connections.instagram.account_name : `@${connections.instagram.account_name}`) : 'Instagram Account',
        sub: 'Just now',
        initials: connections.instagram ? connections.instagram.account_name.replace('@', '').substring(0, 2).toUpperCase() : 'IG',
        bg: '#e1306c',
      };
    }
    return {
      name: connections.facebook ? connections.facebook.account_name : (selectedWorkspace?.name || 'Facebook Page'),
      sub: 'Just now • Public',
      initials: connections.facebook ? connections.facebook.account_name.substring(0, 2).toUpperCase() : (selectedWorkspace?.name ? selectedWorkspace.name.substring(0, 2).toUpperCase() : 'FB'),
      bg: '#1877f2',
    };
  };

  const previewInfo = getPreviewAccountDetails();

  // Platform previews (B13): YouTube only previews real video content; CTA buttons exist on Facebook only
  const hasVideoMedia = contentType === 'video' && mediaCount > 0;
  const youtubePreviewTitle = youtubeTitleFor(videoTitle, fullMessage);
  const showCtaButton = previewPlatform === 'Facebook' && postCTA && postCTA !== 'No CTA';
  const publishReady = workspaceReady && !loadingIntegrations && hasAnyConnection;
  const actionsDisabled = Boolean(submitting) || !workspaceReady;

  const handleOpenScheduleModal = () => {
    if (actionsDisabled) return;
    if (!scheduleDate) {
      setScheduleDate(getTodayKolkata());
    }
    if (!scheduleTime) {
      setScheduleTime(getDefaultTimeKolkata());
    }
    setShowScheduleModal(true);
  };

  const actionButtons = (
    <>
      <button
        type="button"
        className="btn btn-secondary"
        disabled={Boolean(submitting)}
        onClick={() => handleNewDraft(true)}
        style={{ display: 'inline-flex', alignItems: 'center', gap: '6px' }}
      >
        <Plus size={15} />
        New Draft
      </button>
      <button
        type="button"
        className="btn btn-secondary"
        disabled={actionsDisabled}
        title={workspaceReady ? undefined : 'Select a client workspace first'}
        onClick={() => handleSubmit('draft')}
        style={{ minWidth: '125px', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '6px' }}
      >
        {submitting === 'draft' ? <Loader2 size={15} className="spin" /> : <Save size={15} />}
        {submitting === 'draft' ? 'Saving...' : draftId ? 'Update Draft' : 'Save Draft'}
      </button>
      <button
        type="button"
        className="btn btn-secondary"
        disabled={actionsDisabled}
        title={workspaceReady ? undefined : 'Select a client workspace first'}
        onClick={handleOpenScheduleModal}
        style={{
          minWidth: '135px',
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          gap: '6px',
          color: '#1d4ed8',
          borderColor: '#93c5fd',
          background: '#eff6ff',
        }}
      >
        {submitting === 'scheduled' ? <Loader2 size={15} className="spin" /> : <Clock size={15} />}
        {submitting === 'scheduled' ? 'Scheduling...' : loadedPostStatus === 'scheduled' ? 'Update Schedule' : 'Schedule Post'}
      </button>
      <button
        type="button"
        className="btn btn-primary"
        disabled={actionsDisabled}
        title={workspaceReady ? undefined : 'Select a client workspace first'}
        onClick={() => handleSubmit('publish')}
        style={{
          minWidth: '125px',
          background: '#2457e6',
          color: '#ffffff',
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          gap: '6px',
          boxShadow: '0 2px 4px rgba(36,87,230,0.2)',
        }}
      >
        {submitting === 'publish' ? <Loader2 size={15} className="spin" /> : <Send size={15} />}
        {submitting === 'publish' ? 'Publishing...' : 'Publish'}
      </button>
    </>
  );

  const fieldError = (text) =>
    text ? (
      <div role="alert" style={{ color: '#dc2626', fontSize: '12px', marginTop: '4px', fontWeight: '600', display: 'flex', alignItems: 'center', gap: '4px' }}>
        <AlertCircle size={14} />
        <span>{text}</span>
      </div>
    ) : null;

  const dropzoneStyle = {
    border: isDragging ? '2px dashed #2457e6' : '1px dashed #cbd5e1',
    background: isDragging ? '#eff6ff' : '#f8fafc',
    transition: 'all 0.2s ease',
    cursor: 'pointer',
  };
  const dropzoneEvents = {
    onClick: handleDropzoneClick,
    onDragOver: handleDragOver,
    onDragEnter: handleDragOver,
    onDragLeave: handleDragLeave,
    onDrop: handleDrop,
  };
  const dropPrompt = (label) => ({
    role: 'button',
    tabIndex: 0,
    'aria-label': label,
    onKeyDown: handleDropzoneKeyDown,
  });

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
            <h2>Create Content</h2>
            {publishReady && (
              <span
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '5px',
                  background: '#ecfdf5',
                  color: '#047857',
                  border: '1px solid #a7f3d0',
                  padding: '4px 10px',
                  borderRadius: '8px',
                  fontSize: '11.5px',
                  fontWeight: '700',
                }}
              >
                <CheckCircle2 size={13} />
                Publishing Ready
              </span>
            )}
          </div>
          <p style={{ marginTop: '4px' }}>
            Create content and save drafts or publish to Facebook, Instagram, and YouTube (<strong>{allWorkspaces ? 'No client selected' : (selectedWorkspace?.name || 'Selected Workspace')}</strong>).
          </p>
          {/* View Switcher Tabs */}
          <div style={{ display: 'flex', gap: '8px', marginTop: '12px' }}>
            <button
              type="button"
              aria-pressed={activeTab === 'composer'}
              onClick={() => setActiveTab('composer')}
              style={{
                padding: '6px 14px',
                borderRadius: '8px',
                fontSize: '12.5px',
                fontWeight: '700',
                border: activeTab === 'composer' ? '1px solid #2563eb' : '1px solid #cbd5e1',
                background: activeTab === 'composer' ? '#eff6ff' : '#ffffff',
                color: activeTab === 'composer' ? '#1d4ed8' : '#64748b',
                cursor: 'pointer',
                display: 'inline-flex',
                alignItems: 'center',
                gap: '6px',
              }}
            >
              <LayoutGrid size={14} /> Composer & Drafts
            </button>
            <button
              type="button"
              aria-pressed={activeTab === 'calendar'}
              onClick={() => setActiveTab('calendar')}
              style={{
                padding: '6px 14px',
                borderRadius: '8px',
                fontSize: '12.5px',
                fontWeight: '700',
                border: activeTab === 'calendar' ? '1px solid #2563eb' : '1px solid #cbd5e1',
                background: activeTab === 'calendar' ? '#eff6ff' : '#ffffff',
                color: activeTab === 'calendar' ? '#1d4ed8' : '#64748b',
                cursor: 'pointer',
                display: 'inline-flex',
                alignItems: 'center',
                gap: '6px',
              }}
            >
              <CalendarIcon size={14} /> 📅 Visual Calendar
            </button>
          </div>
        </div>
        <div className="toolbar" style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
          {actionButtons}
        </div>
      </div>

      {statusMsg.text && (
        <div
          role={statusMsg.type === 'success' ? 'status' : 'alert'}
          style={{
            background: statusMsg.type === 'success' ? '#e7f7f0' : (statusMsg.type === 'warning' ? '#fffbeb' : '#fef2f2'),
            color: statusMsg.type === 'success' ? '#11875d' : (statusMsg.type === 'warning' ? '#b45309' : '#dc2626'),
            border: `1px solid ${statusMsg.type === 'success' ? '#a7f3d0' : (statusMsg.type === 'warning' ? '#fde68a' : '#fecaca')}`,
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
          {statusMsg.type === 'success' ? <CheckCircle2 size={18} /> : (statusMsg.type === 'warning' ? <AlertTriangle size={18} /> : <AlertCircle size={18} />)}
          <span>{statusMsg.text}</span>
        </div>
      )}

      {/* Platform-Specific Publishing Breakdown */}
      {publishResults && (
        <div
          style={{
            background: '#ffffff',
            border: '1px solid #e2e8f0',
            borderRadius: '12px',
            padding: '16px',
            marginBottom: '16px',
            boxShadow: '0 1px 3px rgba(0,0,0,0.05)',
          }}
        >
          <h4 style={{ margin: '0 0 10px', fontSize: '13.5px', fontWeight: '700', color: '#0f172a' }}>
            Platform Publishing Results
          </h4>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
            {Object.entries(publishResults).map(([platKey, res]) => {
              const isSuccess = res.status === 'published';
              return (
                <div
                  key={platKey}
                  style={{
                    display: 'flex',
                    alignItems: 'flex-start',
                    gap: '10px',
                    padding: '10px 12px',
                    borderRadius: '8px',
                    background: isSuccess ? '#f0fdf4' : '#fef2f2',
                    border: `1px solid ${isSuccess ? '#bbf7d0' : '#fecaca'}`,
                  }}
                >
                  {isSuccess ? (
                    <CheckCircle2 size={18} color="#16a34a" style={{ flexShrink: 0, marginTop: '2px' }} />
                  ) : (
                    <AlertCircle size={18} color="#dc2626" style={{ flexShrink: 0, marginTop: '2px' }} />
                  )}
                  <div style={{ flex: 1, fontSize: '13px' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                      <strong style={{ color: '#0f172a', textTransform: 'capitalize' }}>{res.platform || platKey}:</strong>
                      <span
                        style={{
                          fontWeight: '700',
                          color: isSuccess ? '#15803d' : '#b91c1c',
                        }}
                      >
                        {isSuccess ? 'Published' : 'Failed'}
                      </span>
                      {res.id && (
                        <span style={{ fontSize: '11.5px', color: '#64748b' }}>
                          (ID: {res.id})
                        </span>
                      )}
                    </div>
                    {!isSuccess && res.error && (
                      <div style={{ color: '#b91c1c', fontSize: '12px', marginTop: '4px' }}>
                        <strong>Reason:</strong> {res.error}
                      </div>
                    )}
                    {isSuccess && res.thumbnail && res.thumbnail.requested && (
                      <div
                        data-thumbnail-status={res.thumbnail.applied ? 'applied' : res.thumbnail.status || 'failed'}
                        style={{ color: res.thumbnail.applied ? '#15803d' : res.thumbnail.status === 'pending' ? '#1d4ed8' : '#b45309', fontSize: '12px', marginTop: '4px', display: 'flex', alignItems: 'flex-start', gap: '4px' }}
                      >
                        {res.thumbnail.applied ? <CheckCircle2 size={13} style={{ marginTop: '1px', flexShrink: 0 }} /> : res.thumbnail.status === 'pending' ? <Loader2 size={13} style={{ marginTop: '1px', flexShrink: 0 }} /> : <AlertTriangle size={13} style={{ marginTop: '1px', flexShrink: 0 }} />}
                        <span>
                          {res.thumbnail.applied
                            ? (platKey === 'instagram' ? 'Custom Reel cover applied.' : platKey === 'youtube' ? 'Custom thumbnail applied on YouTube (confirmed by the YouTube API).' : 'Custom thumbnail applied.')
                            : res.thumbnail.message || 'The custom thumbnail was not applied.'}
                        </span>
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* "All Workspaces" is a viewing mode, not a publishing target (B14) */}
      {!workspaceReady && !loadingIntegrations && (
        <div
          role="alert"
          style={{
            background: '#fffbeb',
            border: '1px solid #fde68a',
            borderRadius: '12px',
            padding: '14px 18px',
            marginBottom: '20px',
            display: 'flex',
            alignItems: 'center',
            gap: '12px',
          }}
        >
          <AlertTriangle size={22} color="#d97706" />
          <div>
            <h4 style={{ margin: '0 0 2px', fontSize: '14px', color: '#92400e', fontWeight: '700' }}>
              {allWorkspaces ? 'Select an individual client workspace' : 'No client workspace selected'}
            </h4>
            <p style={{ margin: 0, fontSize: '12.5px', color: '#b45309' }}>
              {allWorkspaces
                ? 'Content cannot be created, saved or published while "All Workspaces" is selected. Choose one client in the "Current client workspace" menu at the top.'
                : 'Choose a client in the "Current client workspace" menu at the top to create content.'}
            </p>
          </div>
        </div>
      )}

      {/* No Connected Accounts Banner */}
      {workspaceReady && !loadingIntegrations && !hasAnyConnection && (
        <div
          style={{
            background: '#fffbeb',
            border: '1px solid #fde68a',
            borderRadius: '12px',
            padding: '16px 20px',
            marginBottom: '20px',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            gap: '16px',
            flexWrap: 'wrap',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            <AlertTriangle size={24} color="#d97706" />
            <div>
              <h4 style={{ margin: '0 0 2px', fontSize: '14px', color: '#92400e', fontWeight: '700' }}>
                No publishing channels connected for "{selectedWorkspace?.name || 'this workspace'}"
              </h4>
              <p style={{ margin: 0, fontSize: '12.5px', color: '#b45309' }}>
                Connect your Facebook Page, Instagram Account, or YouTube Channel in Integrations to enable live publishing.
              </p>
            </div>
          </div>
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => navigate('/integrations')}
            style={{ fontSize: '12px', padding: '6px 14px', display: 'inline-flex', alignItems: 'center', gap: '6px' }}
          >
            Connect channels <ExternalLink size={14} />
          </button>
        </div>
      )}

      {/* 2. Main Publisher Layout (Form + Preview OR Calendar) */}
      {activeTab === 'calendar' ? (
        <VisualContentCalendar
          onNewPostClick={() => {
            handleNewDraft(false);
            setActiveTab('composer');
          }}
          onSelectPost={(post) => {
            // Drafts, scheduled and failed posts open in the composer; published / publishing ones cannot be edited
            if (!['draft', 'scheduled', 'failed'].includes(post.status)) {
              setStatusMsg({ type: 'warning', text: `This post is ${statusLabel(post.status).toLowerCase()} and can no longer be edited. Use Duplicate in the history to reuse it.` });
              return;
            }
            handleEditDraft(post);
            setActiveTab('composer');
          }}
        />
      ) : (
        <>
          <div className="publisher-layout">
        {/* Left Form Panel */}
        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Create content</h3>
              <p>Prepare and publish content for connected accounts</p>
            </div>
            <span className="pill" style={{ background: '#eff6ff', color: '#1d4ed8', fontWeight: '600' }}>
              {draftId ? (loadedPostStatus === 'scheduled' ? `Scheduled post #${draftId}` : `Editing draft #${draftId}`) : 'New draft'}
            </span>
          </div>

          {loadedPostStatus === 'scheduled' && loadedScheduledAt && (
            <div
              style={{
                margin: '12px 16px 0',
                padding: '10px 14px',
                borderRadius: '8px',
                background: '#eff6ff',
                border: '1px solid #bfdbfe',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: '10px',
                fontSize: '13px',
                color: '#1e40af',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                <Clock size={16} color="#2563eb" />
                <span>
                  <strong>Scheduled:</strong> {new Date(loadedScheduledAt).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', dateStyle: 'medium', timeStyle: 'short' })} (Asia/Kolkata)
                </span>
              </div>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                onClick={handleOpenScheduleModal}
                style={{ padding: '3px 10px', fontSize: '12px', borderColor: '#93c5fd', color: '#1d4ed8' }}
              >
                Change Date/Time
              </button>
            </div>
          )}

          <div className="form-grid">
            {/* 1. Content Type Selector */}
            <div className="form-field full" style={{ marginBottom: '6px' }}>
              <label className="form-label" style={{ marginBottom: '8px', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <span style={{ fontWeight: '700' }}>Content Type</span>
                <span style={{ fontSize: '11.5px', color: '#64748b' }}>
                  {contentType === 'post'
                    ? 'Single image or text'
                    : contentType === 'carousel'
                    ? 'Multiple images (2+)'
                    : 'Video upload'}
                </span>
              </label>
              <div
                style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(3, 1fr)',
                  gap: '8px',
                  background: '#f8fafc',
                  padding: '5px',
                  borderRadius: '12px',
                  border: '1px solid #e2e8f0',
                }}
              >
                {[
                  { id: 'post', label: 'Post', icon: ImageIcon, desc: 'Single image / text' },
                  { id: 'carousel', label: 'Carousel', icon: Layers, desc: 'Multiple images' },
                  { id: 'video', label: 'Video', icon: Video, desc: 'Video file' },
                ].map((type) => {
                  const Icon = type.icon;
                  const isActive = contentType === type.id;
                  return (
                    <button
                      key={type.id}
                      type="button"
                      aria-pressed={isActive}
                      onClick={() => handleContentTypeChange(type.id)}
                      style={{
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        justifyContent: 'center',
                        gap: '4px',
                        padding: '10px 8px',
                        borderRadius: '9px',
                        border: isActive ? '1px solid #2457e6' : '1px solid transparent',
                        background: isActive ? '#ffffff' : 'transparent',
                        color: isActive ? '#2457e6' : '#64748b',
                        boxShadow: isActive ? '0 2px 8px rgba(36, 87, 230, 0.12)' : 'none',
                        cursor: 'pointer',
                        transition: 'all 0.18s ease',
                      }}
                    >
                      <div style={{ display: 'flex', alignItems: 'center', gap: '6px', fontWeight: '700', fontSize: '13.5px' }}>
                        <Icon size={16} color={isActive ? '#2457e6' : '#64748b'} />
                        <span>{type.label}</span>
                      </div>
                      <span style={{ fontSize: '11px', color: isActive ? '#3b82f6' : '#94a3b8' }}>
                        {type.desc}
                      </span>
                    </button>
                  );
                })}
              </div>
            </div>
            {/* Where should this content be posted? (Facebook, Instagram, YouTube) */}
            <div className="form-field full">
              <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>Where should this content be posted? *</span>
                <span style={{ fontSize: '11px', color: '#64748b' }}>Select connected & compatible accounts</span>
              </label>
              <div className="check-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '10px' }}>
                {/* Facebook */}
                {(() => {
                  const compat = getPlatformCompatibility('Facebook');
                  const isConn = Boolean(connections.facebook);
                  const isEnabled = isConn && compat.compatible;
                  return (
                    <label
                      className="check-card"
                      style={!isEnabled ? { border: '1px dashed #cbd5e1', opacity: 0.75, cursor: isEnabled ? 'pointer' : 'not-allowed' } : { cursor: 'pointer' }}
                    >
                      <input
                        type="checkbox"
                        checked={platforms.Facebook && isEnabled}
                        onChange={() => handlePlatformToggle('Facebook')}
                        disabled={!isEnabled}
                      />
                      <span>
                        Facebook{' '}
                        {isConn ? (
                          <small style={{ color: '#16a34a', fontWeight: 'bold' }}>({connections.facebook.account_name})</small>
                        ) : (
                          <small style={{ color: '#d97706' }}>(Not connected)</small>
                        )}
                        {isConn && !compat.compatible && (
                          <small style={{ color: '#dc2626', display: 'block', fontSize: '11px', marginTop: '2px', fontWeight: '500' }}>⚠️ {compat.reason}</small>
                        )}
                      </span>
                    </label>
                  );
                })()}

                {/* Instagram */}
                {(() => {
                  const compat = getPlatformCompatibility('Instagram');
                  const isConn = Boolean(connections.instagram);
                  const isEnabled = isConn && compat.compatible;
                  return (
                    <label
                      className="check-card"
                      style={!isEnabled ? { border: '1px dashed #cbd5e1', opacity: 0.75, cursor: isEnabled ? 'pointer' : 'not-allowed' } : { cursor: 'pointer' }}
                    >
                      <input
                        type="checkbox"
                        checked={platforms.Instagram && isEnabled}
                        onChange={() => handlePlatformToggle('Instagram')}
                        disabled={!isEnabled}
                      />
                      <span>
                        Instagram{' '}
                        {isConn ? (
                          <small style={{ color: '#16a34a', fontWeight: 'bold' }}>({connections.instagram.account_name})</small>
                        ) : (
                          <small style={{ color: '#d97706' }}>(Not connected)</small>
                        )}
                        {isConn && !compat.compatible && (
                          <small style={{ color: '#d97706', display: 'block', fontSize: '11px', marginTop: '2px', fontWeight: '600' }}>⚠️ {compat.reason}</small>
                        )}
                      </span>
                    </label>
                  );
                })()}

                {/* YouTube */}
                {(() => {
                  const compat = getPlatformCompatibility('YouTube');
                  const isConn = Boolean(connections.youtube);
                  const isEnabled = isConn && compat.compatible;
                  return (
                    <label
                      className="check-card"
                      style={!isEnabled ? { border: '1px dashed #cbd5e1', opacity: 0.75, cursor: isEnabled ? 'pointer' : 'not-allowed' } : { cursor: 'pointer' }}
                    >
                      <input
                        type="checkbox"
                        checked={platforms.YouTube && isEnabled}
                        onChange={() => handlePlatformToggle('YouTube')}
                        disabled={!isEnabled}
                      />
                      <span>
                        YouTube{' '}
                        {isConn ? (
                          <small style={{ color: '#16a34a', fontWeight: 'bold' }}>({connections.youtube.channel_name})</small>
                        ) : (
                          <small style={{ color: '#d97706' }}>(Not connected)</small>
                        )}
                        {isConn && !compat.compatible && (
                          <small style={{ color: '#d97706', display: 'block', fontSize: '11px', marginTop: '2px', fontWeight: '600' }}>⚠️ {compat.reason}</small>
                        )}
                      </span>
                    </label>
                  );
                })()}
              </div>
              {fieldError(fieldErrors.platforms)}
              {fieldError(fieldErrors.workspace)}
            </div>

            {/* Internal content title */}
            <div className="form-field full">
              <label htmlFor="postTitle" className="form-label">
                Internal content title (optional)
              </label>
              <input
                id="postTitle"
                className="input"
                maxLength={150}
                value={postTitle}
                onChange={(e) => {
                  setPostTitle(e.target.value);
                  setIsDirty(true);
                }}
                placeholder="e.g. Special Product Launch / Weekend Campaign"
                aria-describedby="postTitleHelp"
              />
              <div id="postTitleHelp" className="form-help">
                For your team only: saved with the draft, never published to Facebook, Instagram or YouTube.
              </div>
            </div>

            {/* Video Title & YouTube Privacy (Only shown for Video content) */}
            {contentType === 'video' && (
              <>
                <div className="form-field full">
                  <label htmlFor="videoTitle" className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <span style={{ fontWeight: '600' }}>YouTube Video Title</span>
                    <span style={{ fontSize: '11px', color: '#64748b' }}>{videoTitle.length} / 100 chars</span>
                  </label>
                  <input
                    id="videoTitle"
                    className="input"
                    maxLength={100}
                    value={videoTitle}
                    onChange={(e) => {
                      setVideoTitle(e.target.value);
                      setIsDirty(true);
                    }}
                    placeholder="Enter title for YouTube video..."
                    aria-describedby="videoTitleHelp"
                  />
                  <div id="videoTitleHelp" className="form-help">
                    Leave blank to use the first 60 characters of your caption as the YouTube title.
                  </div>
                </div>

                <div className="form-field full">
                  <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                    <span style={{ fontWeight: '600' }}>YouTube Privacy Setting</span>
                    <span style={{ fontSize: '11.5px', color: '#047857', fontWeight: '700' }}>Default: Public</span>
                  </label>
                  {!isValidYoutubePrivacy(youtubePrivacy) && (
                    <div role="status" className="form-help" style={{ color: '#92400e', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: '8px', padding: '8px 10px', marginBottom: '8px' }}>
                      {YOUTUBE_PRIVACY_REQUIRED}
                    </div>
                  )}
                  <div role="group" aria-label="YouTube privacy setting" style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: '8px' }}>
                    {YOUTUBE_PRIVACY_OPTIONS.map((item) => (
                      <button
                        key={item.id}
                        type="button"
                        aria-pressed={youtubePrivacy === item.id}
                        onClick={() => {
                          setYoutubePrivacy(item.id);
                          setFieldErrors((prev) => ({ ...prev, youtubePrivacy: '' }));
                          setIsDirty(true);
                        }}
                        style={{
                          padding: '10px 12px',
                          borderRadius: '9px',
                          textAlign: 'left',
                          border: youtubePrivacy === item.id ? '2px solid #2457e6' : '1px solid #e2e8f0',
                          background: youtubePrivacy === item.id ? '#eff6ff' : '#ffffff',
                          cursor: 'pointer',
                          transition: 'all 0.15s ease',
                        }}
                      >
                        <div style={{ fontSize: '13px', fontWeight: '700', color: youtubePrivacy === item.id ? '#1d4ed8' : '#334155' }}>
                          {item.label}
                        </div>
                        <div style={{ fontSize: '10.5px', color: '#64748b', marginTop: '2px' }}>
                          {item.desc}
                        </div>
                      </button>
                    ))}
                  </div>
                  {fieldErrors.youtubePrivacy && !isValidYoutubePrivacy(youtubePrivacy) && (
                    <div className="form-error" role="alert" style={{ color: '#b91c1c', fontSize: '12px', marginTop: '6px' }}>
                      {fieldErrors.youtubePrivacy}
                    </div>
                  )}
                </div>
              </>
            )}

            {/* Caption / post message */}
            <div className="form-field full">
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                  <label htmlFor="postCaption" className="form-label" style={{ marginBottom: 0 }}>
                    Caption / Post message *
                  </label>
                  <button
                    type="button"
                    onClick={() => setShowAiModal(true)}
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: '5px',
                      padding: '3px 10px',
                      borderRadius: '6px',
                      background: 'linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%)',
                      border: '1px solid #bfdbfe',
                      color: '#1d4ed8',
                      fontSize: '11.5px',
                      fontWeight: '700',
                      cursor: 'pointer',
                    }}
                  >
                    <Sparkles size={13} color="#2563eb" /> ✨ AI Caption Assistant
                  </button>
                </div>
                <span
                  style={{
                    fontSize: '11px',
                    fontWeight: '700',
                    color: isOverLimit ? '#dc2626' : isNearLimit ? '#d97706' : '#64748b',
                  }}
                >
                  {totalLength} / {maxCharLimit} chars
                </span>
              </div>
              <textarea
                id="postCaption"
                className="textarea"
                rows={4}
                value={postCaption}
                onChange={(e) => {
                  setPostCaption(e.target.value);
                  setIsDirty(true);
                  if (fieldErrors.caption) setFieldErrors((prev) => ({ ...prev, caption: '' }));
                }}
                placeholder="Write the message your audience should see..."
                aria-invalid={Boolean(fieldErrors.caption) || isOverLimit}
                required
              />
              {fieldError(fieldErrors.caption)}
              {isOverLimit && !fieldErrors.caption && (
                <div role="alert" style={{ color: '#dc2626', fontSize: '11.5px', marginTop: '4px', fontWeight: '500' }}>
                  ⚠️ Exceeds the {maxCharLimit}-character limit for the selected platform(s). Shorten the caption to save.
                </div>
              )}
            </div>

            {/* Hashtags & CTA */}
            <div className="form-field">
              <label htmlFor="postHashtags" className="form-label">
                Hashtags
              </label>
              <input
                id="postHashtags"
                className="input"
                value={postHashtags}
                onChange={(e) => {
                  setPostHashtags(e.target.value);
                  setIsDirty(true);
                  if (fieldErrors.hashtags) setFieldErrors((prev) => ({ ...prev, hashtags: '' }));
                }}
                placeholder="#marketing #business #growth"
                aria-invalid={hashtagIssues.length > 0}
                aria-describedby="postHashtagsHelp"
              />
              <div id="postHashtagsHelp" className="form-help">
                Separate with spaces. Each one starts with # (letters, numbers, _). Instagram allows up to 30.
              </div>
              {postHashtags.trim() !== '' && fieldError(hashtagIssues[0])}
              {fieldError(!hashtagIssues.length || postHashtags.trim() === '' ? fieldErrors.hashtags : '')}
            </div>

            <div className="form-field">
              <label htmlFor="postCTA" className="form-label">
                Call-to-action (CTA)
              </label>
              <select
                id="postCTA"
                className="select"
                value={postCTA}
                onChange={(e) => {
                  setPostCTA(e.target.value);
                  setIsDirty(true);
                }}
              >
                <option value="Learn More">Learn More</option>
                <option value="Book Now">Book Now</option>
                <option value="Contact Us">Contact Us</option>
                <option value="Send Message">Send Message</option>
                <option value="No CTA">No CTA</option>
              </select>
              <div className="form-help">Saved with the draft. Shown as a button in the Facebook preview only.</div>
            </div>

            {/* Media attachments */}
            <div className="form-field full">
              <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>
                  {contentType === 'post'
                    ? 'Media attachment (Single image)'
                    : contentType === 'carousel'
                    ? `Carousel media (${mediaCount} / ${CAROUSEL_MAX} images)`
                    : 'Video attachment (Single video)'}
                </span>
                <span style={{ fontSize: '11.5px', color: '#64748b' }}>
                  {contentType === 'post'
                    ? `Optional for text posts (JPG, PNG, WebP, GIF up to ${MAX_IMAGE_MB} MB)`
                    : contentType === 'carousel'
                    ? `Requires 2–${CAROUSEL_MAX} images (JPG, PNG, WebP, GIF up to ${MAX_IMAGE_MB} MB each)`
                    : `Requires video (MP4, MOV, AVI, MKV up to ${MAX_VIDEO_MB} MB)`}
                </span>
              </label>

              {/* Saved media of a draft that was re-opened */}
              {existingMedia.length > 0 && selectedFiles.length === 0 && (
                <div
                  data-no-pick
                  style={{
                    marginBottom: '10px',
                    padding: '10px 12px',
                    background: '#f0f9ff',
                    border: '1px solid #bae6fd',
                    borderRadius: '8px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '10px',
                    flexWrap: 'wrap',
                  }}
                >
                  <Info size={15} color="#0369a1" />
                  <span style={{ fontSize: '12.5px', color: '#0c4a6e', flex: 1, minWidth: '180px' }}>
                    {existingMedia.length} {existingMedia[0].type === 'video' ? 'video' : `image${existingMedia.length === 1 ? '' : 's'}`} saved with this draft. Upload new media to replace it.
                  </span>
                  <button
                    type="button"
                    className="btn btn-secondary btn-sm"
                    onClick={handleRemoveSavedMedia}
                    style={{ fontSize: '12px', padding: '4px 10px' }}
                  >
                    <X size={12} /> Remove saved media
                  </button>
                </div>
              )}

              {mediaNotice && (
                <div
                  role="status"
                  style={{
                    color: '#92400e',
                    fontSize: '12.5px',
                    fontWeight: '600',
                    marginBottom: '10px',
                    padding: '8px 12px',
                    background: '#fffbeb',
                    border: '1px solid #fde68a',
                    borderRadius: '8px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '6px',
                  }}
                >
                  <Info size={15} />
                  <span>{mediaNotice}</span>
                </div>
              )}

              {mediaError && (
                <div
                  role="alert"
                  style={{
                    color: '#dc2626',
                    fontSize: '12.5px',
                    fontWeight: '600',
                    marginBottom: '10px',
                    padding: '8px 12px',
                    background: '#fef2f2',
                    border: '1px solid #fecaca',
                    borderRadius: '8px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '6px',
                  }}
                >
                  <AlertCircle size={15} />
                  <span>{mediaError}</span>
                </div>
              )}

              {fieldErrors.media && (
                <div
                  role="alert"
                  style={{
                    color: '#dc2626',
                    fontSize: '12.5px',
                    fontWeight: '600',
                    marginBottom: '10px',
                    padding: '8px 12px',
                    background: '#fef2f2',
                    border: '1px solid #fecaca',
                    borderRadius: '8px',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '6px',
                  }}
                >
                  <AlertCircle size={15} />
                  <span>{fieldErrors.media}</span>
                </div>
              )}

              {/* POST MEDIA DROPZONE */}
              {contentType === 'post' && (
                <div className="media-drop" {...dropzoneEvents} style={dropzoneStyle}>
                  <div {...dropPrompt('Choose an image to upload')}>
                    <ImageIcon size={28} style={{ color: '#2457e6', marginBottom: '6px' }} />
                    <strong style={{ display: 'block' }}>Click to browse or drag & drop an image</strong>
                    <div className="form-help">Supports JPG, PNG, WebP, GIF (single image, up to {MAX_IMAGE_MB} MB)</div>
                  </div>
                  <input
                    ref={fileInputRef}
                    type="file"
                    id="mediaFile"
                    aria-label="Upload image"
                    accept="image/*"
                    tabIndex={-1}
                    onChange={handleFileChange}
                    style={SR_ONLY_INPUT}
                  />
                  {isProcessingMedia && (
                    <div style={{ marginTop: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '6px', color: '#2457e6', fontSize: '12.5px', fontWeight: '600' }}>
                      <Loader2 size={16} className="spin" /> Processing image...
                    </div>
                  )}
                  {displayPreviews.length > 0 && (
                    <div data-no-pick style={{ marginTop: '14px', display: 'inline-flex', position: 'relative', cursor: 'default' }}>
                      <img
                        src={displayPreviews[0]}
                        alt="Uploaded post media"
                        style={{
                          width: '120px',
                          height: '120px',
                          borderRadius: '10px',
                          objectFit: 'cover',
                          border: '2px solid #2457e6',
                        }}
                      />
                      <button
                        type="button"
                        onClick={() => (selectedFiles.length > 0 ? handleRemoveMedia(0) : handleRemoveSavedMedia())}
                        aria-label="Remove image"
                        title="Remove image"
                        style={{
                          position: 'absolute',
                          top: '-8px',
                          right: '-8px',
                          background: '#dc2626',
                          color: '#fff',
                          border: 'none',
                          borderRadius: '50%',
                          width: '22px',
                          height: '22px',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          cursor: 'pointer',
                          boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
                        }}
                      >
                        <X size={13} />
                      </button>
                    </div>
                  )}
                </div>
              )}

              {/* CAROUSEL MEDIA DROPZONE */}
              {contentType === 'carousel' && (
                <div className="media-drop" {...dropzoneEvents} style={dropzoneStyle}>
                  <div {...dropPrompt('Choose images to upload')}>
                    <Layers size={28} style={{ color: '#2457e6', marginBottom: '6px' }} />
                    <strong style={{ display: 'block' }}>Click to browse or drag & drop multiple images</strong>
                    <div className="form-help">
                      Attach 2 to {CAROUSEL_MAX} images (JPG, PNG, WebP, up to {MAX_IMAGE_MB} MB each). Duplicates are automatically skipped. Reorder using ← and →.
                    </div>
                  </div>
                  <input
                    ref={fileInputRef}
                    type="file"
                    id="mediaFile"
                    aria-label="Upload carousel images"
                    accept="image/*"
                    multiple
                    tabIndex={-1}
                    onChange={handleFileChange}
                    style={SR_ONLY_INPUT}
                  />
                  {isProcessingMedia && (
                    <div style={{ marginTop: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '6px', color: '#2457e6', fontSize: '12.5px', fontWeight: '600' }}>
                      <Loader2 size={16} className="spin" /> Processing images...
                    </div>
                  )}
                  {displayPreviews.length > 0 && (
                    <div data-no-pick style={{ marginTop: '16px', cursor: 'default' }}>
                      <div
                        style={{
                          fontSize: '12px',
                          fontWeight: '700',
                          color: '#0f172a',
                          textAlign: 'left',
                          marginBottom: '8px',
                          display: 'flex',
                          justifyContent: 'space-between',
                          alignItems: 'center',
                        }}
                      >
                        <span>Carousel Slides ({displayPreviews.length} / {CAROUSEL_MAX} images)</span>
                        <span style={{ fontSize: '11px', color: '#64748b' }}>Slide 1 is the cover. Use arrows to reorder</span>
                      </div>
                      <div style={{ display: 'flex', gap: '12px', overflowX: 'auto', paddingBottom: '8px' }}>
                        {displayPreviews.map((url, idx) => (
                          <div
                            key={idx}
                            style={{
                              position: 'relative',
                              background: '#ffffff',
                              borderRadius: '10px',
                              padding: '6px',
                              border: activeCarouselSlide === idx ? '2px solid #2457e6' : '1px solid #cbd5e1',
                              boxShadow: '0 2px 6px rgba(0,0,0,0.06)',
                              flexShrink: 0,
                              width: '110px',
                              textAlign: 'center',
                            }}
                          >
                            <div
                              style={{
                                fontSize: '11px',
                                fontWeight: '700',
                                color: '#475569',
                                marginBottom: '4px',
                                display: 'flex',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                              }}
                            >
                              <span style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
                                Slide #{idx + 1}
                                {idx === 0 && (
                                  <span style={{ fontSize: '9.5px', fontWeight: '700', color: '#ffffff', background: '#2457e6', borderRadius: '4px', padding: '0 4px' }}>
                                    Cover
                                  </span>
                                )}
                              </span>
                              {selectedFiles.length > 0 && (
                                <button
                                  type="button"
                                  onClick={() => handleRemoveMedia(idx)}
                                  aria-label={`Remove slide ${idx + 1}`}
                                  title="Remove slide"
                                  style={{
                                    background: 'transparent',
                                    border: 'none',
                                    color: '#dc2626',
                                    cursor: 'pointer',
                                    padding: '1px',
                                    display: 'flex',
                                  }}
                                >
                                  <X size={13} />
                                </button>
                              )}
                            </div>
                            <img
                              src={url}
                              alt={`Slide ${idx + 1}`}
                              role="button"
                              tabIndex={0}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                  e.preventDefault();
                                  setActiveCarouselSlide(idx);
                                }
                              }}
                              onClick={() => setActiveCarouselSlide(idx)}
                              style={{
                                width: '100%',
                                height: '80px',
                                borderRadius: '6px',
                                objectFit: 'cover',
                                cursor: 'pointer',
                              }}
                            />
                            {idx > 0 && (
                              <button
                                type="button"
                                onClick={() => handleSetCarouselCover(idx)}
                                aria-label={`Use slide ${idx + 1} as the carousel cover`}
                                title="Move this image to the front so it becomes the cover"
                                style={{
                                  marginTop: '6px',
                                  width: '100%',
                                  padding: '3px 4px',
                                  fontSize: '10.5px',
                                  fontWeight: '600',
                                  borderRadius: '4px',
                                  border: '1px solid #bfdbfe',
                                  background: '#eff6ff',
                                  color: '#1d4ed8',
                                  cursor: 'pointer',
                                }}
                              >
                                Set as cover
                              </button>
                            )}
                            {(selectedFiles.length > 0 || existingMedia.length > 1) && (
                            <div style={{ display: 'flex', justifyContent: 'center', gap: '4px', marginTop: '6px' }}>
                              <button
                                type="button"
                                aria-label={`Move slide ${idx + 1} left`}
                                onClick={() => handleMoveMedia(idx, -1)}
                                disabled={idx === 0}
                                title="Move left"
                                style={{
                                  padding: '3px 8px',
                                  fontSize: '11px',
                                  borderRadius: '4px',
                                  border: '1px solid #cbd5e1',
                                  background: idx === 0 ? '#f1f5f9' : '#ffffff',
                                  cursor: idx === 0 ? 'not-allowed' : 'pointer',
                                  color: idx === 0 ? '#94a3b8' : '#0f172a',
                                  fontWeight: '700',
                                }}
                              >
                                ←
                              </button>
                              <button
                                type="button"
                                onClick={() => handleMoveMedia(idx, 1)}
                                disabled={idx === displayPreviews.length - 1}
                                aria-label={`Move slide ${idx + 1} right`}
                                title="Move right"
                                style={{
                                  padding: '3px 8px',
                                  fontSize: '11px',
                                  borderRadius: '4px',
                                  border: '1px solid #cbd5e1',
                                  background: idx === displayPreviews.length - 1 ? '#f1f5f9' : '#ffffff',
                                  cursor: idx === displayPreviews.length - 1 ? 'not-allowed' : 'pointer',
                                  color: idx === displayPreviews.length - 1 ? '#94a3b8' : '#0f172a',
                                  fontWeight: '700',
                                }}
                              >
                                →
                              </button>
                            </div>
                            )}
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* VIDEO MEDIA DROPZONE */}
              {contentType === 'video' && (
                <div className="media-drop" {...dropzoneEvents} style={dropzoneStyle}>
                  <div {...dropPrompt('Choose a video to upload')}>
                    <Video size={28} style={{ color: '#2457e6', marginBottom: '6px' }} />
                    <strong style={{ display: 'block' }}>Click to browse or drag & drop a video file</strong>
                    <div className="form-help">Supported video formats: MP4, MOV, AVI, MKV (up to {MAX_VIDEO_MB} MB)</div>
                  </div>
                  <input
                    ref={fileInputRef}
                    type="file"
                    id="mediaFile"
                    aria-label="Upload video"
                    accept={VIDEO_ACCEPT}
                    tabIndex={-1}
                    onChange={handleFileChange}
                    style={SR_ONLY_INPUT}
                  />
                  {isProcessingMedia && (
                    <div style={{ marginTop: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '6px', color: '#2457e6', fontSize: '12.5px', fontWeight: '600' }}>
                      <Loader2 size={16} className="spin" /> Processing video...
                    </div>
                  )}
                  {selectedFiles.length > 0 && selectedFiles[0].type.startsWith('video/') && (
                    <div
                      data-no-pick
                      style={{
                        cursor: 'default',
                        marginTop: '16px',
                        background: '#ffffff',
                        borderRadius: '12px',
                        padding: '12px',
                        border: '1px solid #cbd5e1',
                        maxWidth: '380px',
                        margin: '16px auto 0',
                        textAlign: 'left',
                      }}
                    >
                      <video
                        src={mediaPreviews[0]}
                        controls
                        style={{ width: '100%', maxHeight: '180px', borderRadius: '8px', background: '#000' }}
                      />
                      <div style={{ marginTop: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '8px' }}>
                        <div>
                          <strong style={{ fontSize: '12.5px', color: '#0f172a', display: 'block', wordBreak: 'break-all' }}>
                            {selectedFiles[0].name}
                          </strong>
                          <small style={{ color: '#64748b', fontSize: '11px' }}>
                            {(selectedFiles[0].size / (1024 * 1024)).toFixed(2)} MB • {selectedFiles[0].type || 'Video'}
                          </small>
                        </div>
                        <div style={{ display: 'flex', gap: '6px' }}>
                          <button
                            type="button"
                            onClick={() => handleRemoveMedia(0)}
                            style={{
                              background: '#fee2e2',
                              color: '#dc2626',
                              border: 'none',
                              borderRadius: '8px',
                              padding: '6px 10px',
                              fontSize: '12px',
                              fontWeight: '600',
                              cursor: 'pointer',
                              display: 'inline-flex',
                              alignItems: 'center',
                              gap: '4px',
                            }}
                          >
                            <X size={13} /> Remove
                          </button>
                        </div>
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* VIDEO THUMBNAIL / COVER IMAGE (stored separately from the video) */}
              {contentType === 'video' && (
                <div
                  style={{
                    marginTop: '12px',
                    padding: '12px',
                    border: '1px dashed #cbd5e1',
                    borderRadius: '10px',
                    background: '#f8fafc',
                    display: 'flex',
                    gap: '12px',
                    alignItems: 'center',
                    flexWrap: 'wrap',
                  }}
                >
                  <div
                    style={{
                      width: '128px',
                      aspectRatio: '16 / 9',
                      borderRadius: '8px',
                      overflow: 'hidden',
                      background: '#e2e8f0',
                      border: thumbnailSrc ? '2px solid #2457e6' : '1px solid #cbd5e1',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      flexShrink: 0,
                    }}
                  >
                    {thumbnailSrc ? (
                      <img src={thumbnailSrc} alt="Selected video thumbnail" style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
                    ) : (
                      <ImageIcon size={22} color="#94a3b8" />
                    )}
                  </div>
                  <div style={{ flex: 1, minWidth: '200px' }}>
                    <strong style={{ fontSize: '12.5px', color: '#0f172a', display: 'block' }}>Thumbnail / cover image (optional)</strong>
                    <div className="form-help" style={{ marginTop: '2px' }}>
                      JPG or PNG, up to {THUMBNAIL_MAX_MB} MB ({YOUTUBE_THUMBNAIL_MAX_MB} MB for YouTube), at least 320px, between 9:16 and 16:9. Used as the Facebook video thumbnail, the Instagram Reel cover and the YouTube thumbnail. YouTube needs a verified channel for custom thumbnails.
                    </div>
                    {thumbnailError && (
                      <div role="alert" style={{ color: '#dc2626', fontSize: '12px', fontWeight: '600', marginTop: '6px', display: 'flex', alignItems: 'center', gap: '4px' }}>
                        <AlertCircle size={13} /> {thumbnailError}
                      </div>
                    )}
                    <div style={{ display: 'flex', gap: '6px', marginTop: '8px', flexWrap: 'wrap' }}>
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={() => thumbnailInputRef.current?.click()}
                        style={{ fontSize: '12px', padding: '4px 10px' }}
                      >
                        <ImageIcon size={12} /> {thumbnailSrc ? 'Change thumbnail' : 'Upload thumbnail / cover image'}
                      </button>
                      {thumbnailSrc && (
                        <button
                          type="button"
                          className="btn btn-secondary btn-sm"
                          onClick={handleRemoveThumbnail}
                          style={{ fontSize: '12px', padding: '4px 10px' }}
                        >
                          <X size={12} /> Remove
                        </button>
                      )}
                    </div>
                    <input
                      ref={thumbnailInputRef}
                      type="file"
                      id="thumbnailFile"
                      aria-label="Upload thumbnail or cover image"
                      accept={THUMBNAIL_ACCEPT}
                      tabIndex={-1}
                      onChange={handleThumbnailChange}
                      style={SR_ONLY_INPUT}
                    />
                  </div>
                </div>
              )}
            </div>

            {/* Bottom Actions Row — Real Publishing Enabled */}
            <div className="form-field full">
              <div className="flex justify-between items-center gap-12" style={{ marginTop: '12px', flexWrap: 'wrap' }}>
                <span className="form-help">
                  <Shield size={13} style={{ display: 'inline', marginRight: '4px', verticalAlign: '-1px' }} />
                  Save as an internal draft, or publish to the selected accounts (you will be asked to confirm before anything goes live).
                </span>
                <div className="flex gap-8" style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                  {actionButtons}
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* Right Preview Panel */}
        <aside className="panel" style={{ position: 'sticky', top: '96px', alignSelf: 'start' }}>
          <div className="panel-header">
            <div className="panel-title">
              <h3>Live preview</h3>
              <p>Realistic platform presentation</p>
            </div>
            <div style={{ display: 'flex', gap: '4px' }}>
              {['Facebook', 'Instagram', 'YouTube'].map((plat) => (
                <button
                  key={plat}
                  type="button"
                  aria-pressed={previewPlatform === plat}
                  onClick={() => setPreviewPlatform(plat)}
                  style={{
                    padding: '3px 8px',
                    borderRadius: '6px',
                    border: 'none',
                    fontSize: '11px',
                    fontWeight: '600',
                    cursor: 'pointer',
                    background: previewPlatform === plat ? '#0f172a' : '#f1f5f9',
                    color: previewPlatform === plat ? '#ffffff' : '#64748b',
                  }}
                >
                  {plat}
                </button>
              ))}
            </div>
          </div>

          <div className="preview-phone">
            <div className="preview-top"></div>
            <div className="preview-body">
              <div className="preview-profile">
                <div
                  className="initial"
                  style={{
                    background: previewInfo.bg,
                    color: '#fff',
                  }}
                >
                  {previewInfo.initials}
                </div>
                <div>
                  <strong style={{ fontSize: '12px', display: 'block', color: '#0f172a' }}>
                    {previewInfo.name}
                  </strong>
                  <div className="muted" style={{ fontSize: '10px' }}>
                    {previewInfo.sub}
                  </div>
                </div>
              </div>

              {previewPlatform === 'YouTube' && !hasVideoMedia ? (
                <div
                  className="preview-image"
                  id="previewMedia"
                  role="status"
                  style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: '6px', height: '180px', textAlign: 'center', padding: '0 12px' }}
                >
                  <Video size={30} style={{ color: '#94a3b8' }} />
                  <span style={{ fontSize: '12px', fontWeight: '700' }}>No video to preview</span>
                  <small style={{ fontSize: '10.5px', color: '#64748b' }}>
                    YouTube only publishes video.{' '}
                    {contentType === 'video' ? 'Attach a video file to see the YouTube preview.' : 'Choose the Video content type and attach a video file.'}
                  </small>
                </div>
              ) : (
              <>
              {/* Dynamic Live Media Preview based on contentType */}
              {contentType === 'video' ? (
                displayPreviews.length > 0 ? (
                  <div style={{ position: 'relative', width: '100%', marginBottom: '8px' }}>
                    <video
                      key={`${displayPreviews[0]}|${thumbnailSrc}`}
                      src={displayPreviews[0]}
                      poster={thumbnailSrc || undefined}
                      controls
                      style={{
                        width: '100%',
                        maxHeight: '180px',
                        borderRadius: '4px',
                        background: '#000',
                        objectFit: 'contain',
                        display: 'block',
                      }}
                    />
                    {thumbnailSrc && (
                      <span
                        style={{
                          position: 'absolute',
                          top: '6px',
                          left: '6px',
                          background: 'rgba(15, 23, 42, 0.75)',
                          color: '#ffffff',
                          fontSize: '10px',
                          fontWeight: '700',
                          padding: '2px 6px',
                          borderRadius: '8px',
                          pointerEvents: 'none',
                        }}
                      >
                        {previewPlatform === 'Instagram' ? 'Reel cover' : 'Custom thumbnail'}
                      </span>
                    )}
                  </div>
                ) : (
                  <div
                    className="preview-image"
                    id="previewMedia"
                    style={{
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'center',
                      justifyContent: 'center',
                      gap: '4px',
                      height: '180px',
                    }}
                  >
                    <Video size={30} style={{ color: '#94a3b8' }} />
                    <span style={{ fontSize: '12px', fontWeight: '600' }}>Video preview area</span>
                    <small style={{ fontSize: '10px', color: '#94a3b8' }}>Upload MP4, MOV, AVI or MKV</small>
                  </div>
                )
              ) : contentType === 'carousel' ? (
                displayPreviews.length > 0 ? (
                  <div style={{ position: 'relative', width: '100%', marginBottom: '8px' }}>
                    <img
                      src={displayPreviews[activeCarouselSlide] || displayPreviews[0]}
                      alt={`Carousel slide ${(activeCarouselSlide || 0) + 1}`}
                      style={{
                        width: '100%',
                        height: '180px',
                        objectFit: 'cover',
                        borderRadius: '4px',
                        display: 'block',
                      }}
                    />
                    <div
                      style={{
                        position: 'absolute',
                        top: '8px',
                        right: '8px',
                        background: 'rgba(15, 23, 42, 0.75)',
                        color: '#ffffff',
                        fontSize: '10.5px',
                        fontWeight: '700',
                        padding: '2px 7px',
                        borderRadius: '10px',
                        backdropFilter: 'blur(4px)',
                      }}
                    >
                      {activeCarouselSlide + 1} / {displayPreviews.length}
                    </div>
                    {activeCarouselSlide === 0 && (
                      <div
                        style={{
                          position: 'absolute',
                          top: '8px',
                          left: '8px',
                          background: 'rgba(36, 87, 230, 0.9)',
                          color: '#ffffff',
                          fontSize: '10.5px',
                          fontWeight: '700',
                          padding: '2px 7px',
                          borderRadius: '10px',
                        }}
                      >
                        Cover
                      </div>
                    )}
                    {activeCarouselSlide > 0 && (
                      <button
                        type="button"
                        onClick={() => setActiveCarouselSlide((prev) => Math.max(0, prev - 1))}
                        style={{
                          position: 'absolute',
                          left: '6px',
                          top: '50%',
                          transform: 'translateY(-50%)',
                          background: 'rgba(255, 255, 255, 0.9)',
                          border: 'none',
                          borderRadius: '50%',
                          width: '24px',
                          height: '24px',
                          display: 'grid',
                          placeItems: 'center',
                          cursor: 'pointer',
                          boxShadow: '0 2px 4px rgba(0,0,0,0.15)',
                        }}
                        aria-label="Previous slide"
                      >
                        <ChevronLeft size={14} color="#0f172a" />
                      </button>
                    )}
                    {activeCarouselSlide < displayPreviews.length - 1 && (
                      <button
                        type="button"
                        onClick={() => setActiveCarouselSlide((prev) => Math.min(displayPreviews.length - 1, prev + 1))}
                        style={{
                          position: 'absolute',
                          right: '6px',
                          top: '50%',
                          transform: 'translateY(-50%)',
                          background: 'rgba(255, 255, 255, 0.9)',
                          border: 'none',
                          borderRadius: '50%',
                          width: '24px',
                          height: '24px',
                          display: 'grid',
                          placeItems: 'center',
                          cursor: 'pointer',
                          boxShadow: '0 2px 4px rgba(0,0,0,0.15)',
                        }}
                        aria-label="Next slide"
                      >
                        <ChevronRight size={14} color="#0f172a" />
                      </button>
                    )}
                    <div style={{ display: 'flex', justifyContent: 'center', gap: '4px', marginTop: '6px' }}>
                      {displayPreviews.map((_, i) => (
                        <span
                          key={i}
                          onClick={() => setActiveCarouselSlide(i)}
                          style={{
                            width: activeCarouselSlide === i ? '14px' : '5px',
                            height: '5px',
                            borderRadius: '3px',
                            background: activeCarouselSlide === i ? '#2457e6' : '#cbd5e1',
                            cursor: 'pointer',
                            transition: 'all 0.2s ease',
                          }}
                        />
                      ))}
                    </div>
                  </div>
                ) : (
                  <div
                    className="preview-image"
                    id="previewMedia"
                    style={{
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'center',
                      justifyContent: 'center',
                      gap: '4px',
                      height: '180px',
                    }}
                  >
                    <Layers size={30} style={{ color: '#94a3b8' }} />
                    <span style={{ fontSize: '12px', fontWeight: '600' }}>Carousel preview area</span>
                    <small style={{ fontSize: '10px', color: '#94a3b8' }}>Upload 2 or more images</small>
                  </div>
                )
              ) : displayPreviews.length > 0 ? (
                <img
                  src={displayPreviews[0]}
                  alt="Live Preview"
                  style={{
                    width: '100%',
                    height: '180px',
                    objectFit: 'cover',
                    borderRadius: '4px',
                    marginBottom: '8px',
                  }}
                />
              ) : (
                <div
                  className="preview-image"
                  id="previewMedia"
                  style={{
                    display: 'flex',
                    flexDirection: 'column',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: '4px',
                    height: '180px',
                  }}
                >
                  <ImageIcon size={30} style={{ color: '#94a3b8' }} />
                  <span style={{ fontSize: '12px', fontWeight: '600' }}>Post media preview</span>
                  <small style={{ fontSize: '10px', color: '#94a3b8' }}>Attach single image or text-only</small>
                </div>
              )}

              {/* YouTube specific title and privacy indicators */}
              {previewPlatform === 'YouTube' && (
                <div style={{ marginBottom: '8px' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '6px' }}>
                    <h4 style={{ margin: 0, fontSize: '13px', fontWeight: '700', color: '#0f172a', lineHeight: '1.3' }}>
                      {youtubePreviewTitle}
                    </h4>
                    <span
                      style={{
                        fontSize: '9.5px',
                        textTransform: 'uppercase',
                        fontWeight: '700',
                        padding: '1px 5px',
                        borderRadius: '4px',
                        background: '#f1f5f9',
                        color: '#475569',
                        flexShrink: 0,
                      }}
                    >
                      {youtubePrivacyLabel(youtubePrivacy)}
                    </span>
                  </div>
                </div>
              )}

              <div
                className="preview-caption"
                id="previewCaption"
                style={{ fontSize: '12px', color: '#1e293b', whiteSpace: 'pre-wrap', marginTop: previewPlatform === 'YouTube' ? '4px' : '8px' }}
              >
                {postCaption || 'Your post caption preview will appear here...'}
              </div>

              {postHashtags && (
                <div
                  className="preview-tags"
                  id="previewTags"
                  style={{ fontSize: '11.5px', color: '#2563eb', marginTop: '4px' }}
                >
                  {postHashtags}
                </div>
              )}

              {showCtaButton && (
                <button className="btn btn-sm btn-primary btn-block mt-12" type="button" id="previewCTA" tabIndex={-1}>
                  {postCTA}
                </button>
              )}
              </>
              )}
            </div>
          </div>
        </aside>
      </div>

      {/* 3. Scheduled Content Calendar Section */}
      <section className="panel mt-18" style={{ marginTop: '24px' }}>
        <div className="panel-header">
          <div className="panel-title">
            <h3>Scheduled content calendar</h3>
            <p>Upcoming posts for this week ({selectedWorkspace?.name || 'Current client'})</p>
          </div>
          <div className="panel-actions">
            <span className="pill">{weekRangeLabel}</span>
          </div>
        </div>

        {schedulerStatus && schedulerStatus.running === false && (
          <div
            role="status"
            style={{
              margin: '0 0 12px',
              padding: '10px 12px',
              background: '#fffbeb',
              border: '1px solid #fde68a',
              borderRadius: '8px',
              color: '#92400e',
              fontSize: '12.5px',
              display: 'flex',
              alignItems: 'flex-start',
              gap: '8px',
            }}
          >
            <AlertTriangle size={15} style={{ flexShrink: 0, marginTop: '1px' }} />
            <span>
              <strong>Automatic publishing is not running.</strong> Scheduled posts are only published while this page is open
              {schedulerStatus.last_run_at ? ` (scheduler last ran ${new Date(schedulerStatus.last_run_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' })} IST)` : ''}.
              Ask an administrator to start the scheduler (see backend/docs/scheduled-publishing.md).
            </span>
          </div>
        )}

        <div className="calendar-grid">
          {weekDays.map((day) => {
            const dayDateStr = day.toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
            const todayDateStr = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
            const isToday = dayDateStr === todayDateStr;
            const dayLabel = day.toLocaleDateString('en-US', { timeZone: 'Asia/Kolkata', weekday: 'short', day: 'numeric' });

            const dayPosts = allScheduledPosts.filter((p) => {
              if (p.status !== 'scheduled' || !p.scheduled_at) return false;
              const pDateStr = new Date(p.scheduled_at).toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
              return pDateStr === dayDateStr;
            });

            return (
              <div key={dayDateStr} className={`calendar-day ${isToday ? 'is-today' : ''}`}>
                <div className="day-name">
                  <span>{dayLabel}</span>
                  {isToday && <span className="today-badge">Today</span>}
                </div>

                {dayPosts.length === 0 ? (
                  <div className="day-empty">No content</div>
                ) : (
                  <div className="day-posts-list">
                    {dayPosts.map((p) => {
                      const timeLabel = new Date(p.scheduled_at).toLocaleTimeString('en-US', {
                        timeZone: 'Asia/Kolkata',
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true,
                      });
                      const platformList = Array.isArray(p.platform_list) && p.platform_list.length > 0 ? p.platform_list : ['Post'];
                      const platform = platformList[0];
                      const typeLabel = contentTypeLabel(p.post_type, platformList);
                      const preview = postPreviewImage(p);
                      const title = p.content
                        ? (p.content.length > 35 ? p.content.substring(0, 35) + '...' : p.content)
                        : `${typeLabel} post`;

                      return (
                        <div key={p.id} className={`day-card active ${platform.toLowerCase()}`}>
                          <span className="card-time">{timeLabel}</span>
                          <div style={{ display: 'flex', gap: '6px', alignItems: 'center', minWidth: 0 }}>
                            {preview && (
                              <img
                                src={preview}
                                alt=""
                                style={{ width: '28px', height: '28px', borderRadius: '4px', objectFit: 'cover', flexShrink: 0, background: '#e2e8f0' }}
                                onError={(e) => { e.currentTarget.style.display = 'none'; }}
                              />
                            )}
                            <div className="card-title" title={p.content || ''} style={{ minWidth: 0 }}>{title}</div>
                          </div>
                          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '3px', marginTop: '3px' }}>
                            <span className="card-tag" style={{ background: '#e2e8f0', color: '#334155' }}>{typeLabel}</span>
                            {platformList.map((name) => (
                              <span key={name} className={`card-tag tag-${String(name).toLowerCase()}`}>{name}</span>
                            ))}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </section>

      {/* 4. Publishing History & Drafts Section (5 Records per Page) */}
      <section className="panel mt-18" style={{ marginTop: '24px' }}>
        <div className="panel-header">
          <div className="panel-title">
            <h3>Publishing history & drafts</h3>
            <p>
              Showing latest records for {selectedWorkspace?.name || 'this workspace'} (5 per page)
            </p>
          </div>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => {
              loadWorkspaceData();
              fetchHistoryPosts(historyPage);
            }}
            disabled={historyLoading}
          >
            <RotateCw size={13} style={{ marginRight: '4px' }} className={historyLoading ? 'spin' : ''} /> Refresh list
          </button>
        </div>

        <div className="table-wrap" style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '13.5px' }}>
            <thead>
              <tr style={{ borderBottom: '1.5px solid #e5e7eb', textAlign: 'left', background: '#f9fafb' }}>
                <th style={{ padding: '10px 14px' }}>CONTENT</th>
                <th style={{ padding: '10px 14px' }}>PLATFORM</th>
                <th style={{ padding: '10px 14px' }}>STATUS</th>
                <th style={{ padding: '10px 14px' }}>DATE (IST)</th>
                <th style={{ padding: '10px 14px' }}>ACTION</th>
              </tr>
            </thead>
            <tbody>
              {historyLoading ? (
                <tr>
                  <td colSpan={5} style={{ padding: '24px 16px', textAlign: 'center', color: '#6b7280' }}>
                    Loading publishing history...
                  </td>
                </tr>
              ) : historyPosts.length === 0 ? (
                <tr>
                  <td colSpan={5} style={{ padding: '24px 16px', textAlign: 'center', color: '#6b7280' }}>
                    No publishing history or drafts for this workspace.
                  </td>
                </tr>
              ) : (
                historyPosts.map((post) => (
                  <tr key={post.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                    <td style={{ padding: '10px 14px', maxWidth: '320px' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '10px', minWidth: 0 }}>
                        <PostThumbnail post={post} />
                        <div style={{ minWidth: 0 }}>
                          <strong style={{ display: 'block', fontSize: '13.5px', color: '#0f172a' }}>
                            {post.content ? (post.content.length > 60 ? post.content.substring(0, 60) + '...' : post.content) : 'Media Content'}
                          </strong>
                          <span style={{ fontSize: '11.5px', color: '#64748b' }}>
                            {contentTypeLabel(post.post_type, post.platform_list)}
                            {post.post_type === 'multi_image' && Array.isArray(post.media) && post.media.length > 1 ? ` · ${post.media.length} images` : ''}
                          </span>
                        </div>
                      </div>
                    </td>
                    <td style={{ padding: '10px 14px' }}>
                      {post.platform_statuses && typeof post.platform_statuses === 'object' && Object.keys(post.platform_statuses).length > 0 ? (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                          {Object.entries(post.platform_statuses).map(([key, info]) => {
                            const isPub = info?.status === 'published';
                            const isFail = info?.status === 'failed';
                            return (
                              <span
                                key={key}
                                className={`pill ${isPub ? 'success' : isFail ? 'danger' : 'neutral'}`}
                                title={info?.error || (isPub ? `Published ID: ${info?.id || ''}` : '')}
                                style={{ fontSize: '11px', cursor: info?.error ? 'help' : 'default' }}
                              >
                                {info?.platform || key} {isFail ? '(Failed)' : ''}
                              </span>
                            );
                          })}
                          {(() => {
                            const yt = youtubeThumbnailState(post);
                            if (!yt) return null;
                            return (
                              <span
                                className={`pill ${yt.status === 'applied' ? 'success' : yt.status === 'pending' ? 'info' : 'warning'}`}
                                data-yt-thumbnail={yt.status}
                                title={yt.message}
                                style={{ fontSize: '11px', cursor: yt.message ? 'help' : 'default' }}
                              >
                                {yt.label}
                              </span>
                            );
                          })()}
                        </div>
                      ) : (
                        <span className={`pill ${Array.isArray(post.platform_list) && post.platform_list.includes('YouTube') ? 'warning' : 'info'}`}>
                          {Array.isArray(post.platform_list) && post.platform_list.length > 0 ? post.platform_list.join(', ') : '—'}
                        </span>
                      )}
                    </td>
                    <td style={{ padding: '10px 14px' }}>
                      <span
                        className={`pill ${
                          post.status === 'published'
                            ? 'success'
                            : post.status === 'partial'
                            ? 'warning'
                            : post.status === 'scheduled' || post.status === 'processing'
                            ? 'warning'
                            : post.status === 'failed'
                            ? 'danger'
                            : 'neutral'
                        }`}
                        title={post.error_message || ''}
                        style={{ cursor: post.error_message ? 'help' : 'default' }}
                      >
                        • {statusLabel(post.status)}
                      </span>
                      {(post.status === 'failed' || post.status === 'partial' || (post.status === 'scheduled' && post.error_message)) && post.error_message && (
                        <div style={{ fontSize: '11px', color: post.status === 'scheduled' ? '#92400e' : '#b91c1c', marginTop: '4px', maxWidth: '260px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={post.error_message}>
                          {post.error_message}
                        </div>
                      )}
                    </td>
                    <td style={{ padding: '10px 14px', color: '#64748b', fontSize: '12.5px' }}>
                      {post.published_at
                        ? new Date(post.published_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true })
                        : post.scheduled_at
                        ? new Date(post.scheduled_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true })
                        : 'Draft'}
                    </td>
                    <td style={{ padding: '10px 14px' }}>
                      <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                        {youtubeThumbnailState(post)?.canRetry && (
                          <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => handleRetryThumbnail(post)}
                            disabled={retryingThumbnailId === post.id}
                            style={{ padding: '4px 8px', fontSize: '12px' }}
                            title="Apply the uploaded custom thumbnail to the existing YouTube video again (the video is not re-uploaded)"
                          >
                            {retryingThumbnailId === post.id ? <Loader2 size={12} className="spin" /> : <RotateCw size={12} />} Retry thumbnail
                          </button>
                        )}
                        {(post.status === 'failed' || post.status === 'partial') && (
                          <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => handleRetryPost(post)}
                            disabled={retryingPostId === post.id}
                            style={{ padding: '4px 8px', fontSize: '12px' }}
                            title="Publish again to the platforms that failed (platforms that already have the post are skipped)"
                          >
                            {retryingPostId === post.id ? <Loader2 size={12} className="spin" /> : <RotateCw size={12} />} Retry
                          </button>
                        )}
                        {post.status === 'failed' && (
                          <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => handleDuplicatePost(post.id)}
                            style={{ padding: '4px 8px', fontSize: '12px' }}
                            title="Copy failed post to a new draft"
                          >
                            <Copy size={12} /> Copy to Draft
                          </button>
                        )}
                        {(post.status === 'draft' || post.status === 'scheduled') && (
                          <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => handleEditDraft(post)}
                            disabled={loadingDraftId === post.id}
                            style={{ padding: '4px 8px', fontSize: '12px' }}
                            title="Edit draft"
                            aria-label="Edit draft"
                          >
                            {loadingDraftId === post.id ? <Loader2 size={12} className="spin" /> : <Pencil size={12} />} Edit
                          </button>
                        )}
                        <button
                          type="button"
                          className="btn btn-secondary"
                          onClick={() => handleDuplicatePost(post.id)}
                          style={{ padding: '4px 8px', fontSize: '12px' }}
                          title="Duplicate as new draft"
                        >
                          <Copy size={12} /> Duplicate
                        </button>
                        {post.status === 'draft' && (
                          <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => handleDeleteDraft(post.id)}
                            style={{ padding: '4px 8px', fontSize: '12px', color: '#dc2626' }}
                            title="Delete Draft"
                            aria-label="Delete draft"
                          >
                            <Trash2 size={12} />
                          </button>
                        )}
                        {post.status === 'scheduled' && (
                          <button
                            type="button"
                            className="btn btn-secondary"
                            onClick={() => handleDeleteScheduled(post.id)}
                            style={{ padding: '4px 8px', fontSize: '12px', color: '#dc2626' }}
                            title="Cancel Scheduled Post"
                          >
                            <Trash2 size={12} /> Cancel
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* 5-Record Pagination Footer */}
        {historyTotalCount > 0 && (
          <div
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              padding: '14px 14px 4px',
              borderTop: '1px solid #f1f5f9',
              marginTop: '8px',
              flexWrap: 'wrap',
              gap: '12px',
            }}
          >
            <span style={{ fontSize: '12.5px', color: '#64748b' }}>
              Showing <strong>{historyPosts.length > 0 ? (historyPage - 1) * 5 + 1 : 0}</strong>–<strong>{Math.min(historyPage * 5, historyTotalCount)}</strong> of <strong>{historyTotalCount}</strong> records
            </span>

            <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                disabled={historyPage <= 1 || historyLoading}
                onClick={() => setHistoryPage((prev) => Math.max(1, prev - 1))}
                style={{
                  padding: '5px 10px',
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '2px',
                  fontSize: '12px',
                  fontWeight: '600',
                }}
                aria-label="Previous Page"
              >
                <ChevronLeft size={14} />
              </button>

              <span
                style={{
                  fontSize: '13px',
                  fontWeight: '700',
                  padding: '4px 10px',
                  color: '#0f172a',
                  background: '#f8fafc',
                  borderRadius: '6px',
                  border: '1px solid #e2e8f0',
                }}
              >
                {historyPage}
              </span>

              <button
                type="button"
                className="btn btn-secondary btn-sm"
                disabled={historyPage >= historyTotalPages || historyLoading}
                onClick={() => setHistoryPage((prev) => Math.min(historyTotalPages, prev + 1))}
                style={{
                  padding: '5px 10px',
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '2px',
                  fontSize: '12px',
                  fontWeight: '600',
                }}
                aria-label="Next Page"
              >
                <ChevronRight size={14} />
              </button>
            </div>
          </div>
        )}
      </section>
        </>
      )}

      {publishConfirm && (
        <div
          role="presentation"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setPublishConfirm(null);
          }}
          style={{ position: 'fixed', inset: 0, background: 'rgba(15, 23, 42, 0.55)', display: 'grid', placeItems: 'center', zIndex: 1000, padding: '16px' }}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="publishConfirmTitle"
            aria-describedby="publishConfirmDesc"
            style={{ background: '#ffffff', borderRadius: '14px', maxWidth: '480px', width: '100%', padding: '22px', boxShadow: '0 20px 50px rgba(15, 23, 42, 0.35)' }}
          >
            <h3 id="publishConfirmTitle" style={{ margin: '0 0 8px', fontSize: '17px', display: 'flex', alignItems: 'center', gap: '8px', color: '#0f172a' }}>
              <AlertTriangle size={20} color="#d97706" /> Publish this post now?
            </h3>
            <p id="publishConfirmDesc" style={{ margin: '0 0 12px', fontSize: '13.5px', color: '#334155' }}>
              This goes live immediately on the connected accounts of <strong>{selectedWorkspace?.name || 'this client'}</strong>. A published post cannot be recalled from here.
            </p>
            <ul style={{ margin: '0 0 16px', paddingLeft: '18px', fontSize: '13px', color: '#334155', display: 'grid', gap: '4px' }}>
              <li><strong>Platforms:</strong> {publishConfirm.platforms.join(', ')}</li>
              <li>
                <strong>Content:</strong>{' '}
                {contentType === 'video' ? 'Video' : contentType === 'carousel' ? `Carousel (${mediaCount} images)` : mediaCount > 0 ? 'Single image post' : 'Text post'}
              </li>
              {publishConfirm.platforms.includes('YouTube') && (
                <li><strong>YouTube visibility:</strong> {youtubePrivacyLabel(youtubePrivacy)} — title “{youtubePreviewTitle}”</li>
              )}
              <li><strong>Caption:</strong> {fullMessage.length > 90 ? `${fullMessage.slice(0, 90)}…` : fullMessage || '(media only)'}</li>
            </ul>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
              <button type="button" className="btn btn-secondary" autoFocus onClick={() => setPublishConfirm(null)}>
                Cancel
              </button>
              <button
                type="button"
                className="btn btn-primary"
                onClick={() => handleSubmit('publish', true)}
                style={{ background: '#2457e6', color: '#ffffff', display: 'inline-flex', alignItems: 'center', gap: '6px' }}
              >
                <Send size={15} /> Yes, publish now
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Schedule Post Dialog */}
      {showScheduleModal && (
        <div
          role="presentation"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setShowScheduleModal(false);
          }}
          style={{
            position: 'fixed',
            inset: 0,
            background: 'rgba(15, 23, 42, 0.55)',
            display: 'grid',
            placeItems: 'center',
            zIndex: 1000,
            padding: '16px',
          }}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="scheduleModalTitle"
            style={{
              background: '#ffffff',
              borderRadius: '14px',
              maxWidth: '460px',
              width: '100%',
              padding: '24px',
              boxShadow: '0 20px 50px rgba(15, 23, 42, 0.35)',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '8px' }}>
              <h3 id="scheduleModalTitle" style={{ margin: 0, fontSize: '18px', display: 'flex', alignItems: 'center', gap: '8px', color: '#0f172a' }}>
                <Clock size={20} color="#2563eb" /> {loadedPostStatus === 'scheduled' ? 'Reschedule Post' : 'Schedule Post'}
              </h3>
              <button
                type="button"
                onClick={() => setShowScheduleModal(false)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#64748b' }}
                aria-label="Close"
              >
                <X size={18} />
              </button>
            </div>

            <p style={{ margin: '0 0 16px', fontSize: '13px', color: '#475569' }}>
              Select a future date and time to automatically publish this post for <strong>{selectedWorkspace?.name || 'this client'}</strong>.
            </p>

            <div style={{ display: 'grid', gap: '14px', marginBottom: '18px' }}>
              <div>
                <label style={{ display: 'block', fontSize: '12.5px', fontWeight: '700', color: '#1e293b', marginBottom: '6px' }}>
                  Schedule Date
                </label>
                <input
                  type="date"
                  min={getTodayKolkata()}
                  value={scheduleDate}
                  onChange={(e) => setScheduleDate(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '8px 12px',
                    borderRadius: '8px',
                    border: '1px solid #cbd5e1',
                    fontSize: '13.5px',
                    color: '#0f172a',
                    background: '#ffffff',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', fontSize: '12.5px', fontWeight: '700', color: '#1e293b', marginBottom: '6px' }}>
                  Schedule Time
                </label>
                <input
                  type="time"
                  value={scheduleTime}
                  onChange={(e) => setScheduleTime(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '8px 12px',
                    borderRadius: '8px',
                    border: '1px solid #cbd5e1',
                    fontSize: '13.5px',
                    color: '#0f172a',
                    background: '#ffffff',
                  }}
                />
              </div>

              <div
                style={{
                  padding: '10px 12px',
                  background: '#f8fafc',
                  border: '1px solid #e2e8f0',
                  borderRadius: '8px',
                  fontSize: '12px',
                  color: '#475569',
                  display: 'flex',
                  alignItems: 'center',
                  gap: '8px',
                }}
              >
                <Globe size={15} color="#2563eb" />
                <span>
                  <strong>Timezone:</strong> Asia/Kolkata (IST — India Standard Time)
                </span>
              </div>

              {scheduleDate && scheduleTime && !isFutureSchedule(scheduleDate, scheduleTime) && (
                <div
                  role="alert"
                  style={{
                    padding: '8px 12px',
                    background: '#fef2f2',
                    border: '1px solid #fecaca',
                    borderRadius: '8px',
                    fontSize: '12px',
                    color: '#b91c1c',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '6px',
                  }}
                >
                  <AlertCircle size={15} />
                  <span>Please choose a date and time in the future (past scheduling is not permitted).</span>
                </div>
              )}
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
              <button
                type="button"
                className="btn btn-secondary"
                onClick={() => setShowScheduleModal(false)}
              >
                Cancel
              </button>
              <button
                type="button"
                className="btn btn-primary"
                disabled={!isFutureSchedule(scheduleDate, scheduleTime) || submitting === 'scheduled'}
                onClick={() => handleSubmit('scheduled')}
                style={{
                  background: '#2563eb',
                  color: '#ffffff',
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: '6px',
                }}
              >
                {submitting === 'scheduled' ? <Loader2 size={15} className="spin" /> : <Clock size={15} />}
                {loadedPostStatus === 'scheduled' ? 'Confirm Reschedule' : 'Confirm Schedule'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* AI Caption & Hashtag Generator Modal */}
      <AiCaptionModal
        isOpen={showAiModal}
        onClose={() => setShowAiModal(false)}
        workspaceId={selectedWorkspaceId}
        initialTopic={postTitle || postCaption}
        onApplyCaption={({ caption, hashtags }) => {
          setPostCaption(caption);
          if (hashtags) setPostHashtags(hashtags);
          setIsDirty(true);
        }}
      />
    </div>
  );
}
