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
  Clock,
  Save,
  Send,
  Calendar as CalendarIcon,
  Image as ImageIcon,
  Video,
  Layers,
  Shield,
  Play,
  X,
  ExternalLink,
  Plus,
  AlertTriangle,
  ChevronLeft,
  ChevronRight,
  Loader2,
  Sparkles,
  LayoutGrid,
} from 'lucide-react';
import AiCaptionModal from '../components/AiCaptionModal';
import VisualContentCalendar from '../components/VisualContentCalendar';

export default function PublishingPage() {
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspace();
  const [activeTab, setActiveTab] = useState('composer'); // 'composer' | 'calendar'
  const [showAiModal, setShowAiModal] = useState(false);
  const navigate = useNavigate();

  // Publishing is enabled for this workspace
  const publishingEnabled = true;

  // Connected accounts for this specific workspace (Facebook, Instagram, YouTube)
  const [connections, setConnections] = useState({
    facebook: null,
    instagram: null,
    youtube: null,
  });
  const [loadingIntegrations, setLoadingIntegrations] = useState(true);

  // Form states - Strictly scoped to Facebook, Instagram, YouTube
  const [platforms, setPlatforms] = useState({
    Facebook: false,
    Instagram: false,
    YouTube: false,
  });

  const [postTitle, setPostTitle] = useState('');
  const [videoTitle, setVideoTitle] = useState('');
  const [youtubePrivacy, setYoutubePrivacy] = useState('unlisted'); // Default Unlisted for safe testing
  const [publishResults, setPublishResults] = useState(null); // Platform-level publish breakdown
  const [postCaption, setPostCaption] = useState('');
  const [postHashtags, setPostHashtags] = useState('');
  const [postCTA, setPostCTA] = useState('Learn More');
  const [publishType, setPublishType] = useState('draft'); // Draft Mode default

  // Content type selector: 'post' | 'carousel' | 'video'
  const [contentType, setContentType] = useState('post');
  const [activeCarouselSlide, setActiveCarouselSlide] = useState(0);
  const [mediaError, setMediaError] = useState('');

  // Media & drag state
  const [selectedFiles, setSelectedFiles] = useState([]);
  const [mediaPreviews, setMediaPreviews] = useState([]);
  const [isDragging, setIsDragging] = useState(false);
  const [isProcessingMedia, setIsProcessingMedia] = useState(false);

  // Field validation errors
  const [fieldErrors, setFieldErrors] = useState({
    caption: '',
    media: '',
    platforms: '',
  });

  // Drafts & History state
  const [draftPosts, setDraftPosts] = useState([]);
  const [scheduledPosts, setScheduledPosts] = useState([]);
  const [historyPosts, setHistoryPosts] = useState([]);
  const [historyPage, setHistoryPage] = useState(1);
  const [historyTotalPages, setHistoryTotalPages] = useState(1);
  const [historyTotalCount, setHistoryTotalCount] = useState(0);
  const [historyLoading, setHistoryLoading] = useState(false);

  // Unsaved changes & submit locks
  const [isDirty, setIsDirty] = useState(false);
  const isSavingRef = useRef(false);
  const activeObjectUrlsRef = useRef(new Set());
  const currentWorkspaceIdRef = useRef(selectedWorkspaceId);

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
      } catch (e) {}
      activeObjectUrlsRef.current.delete(url);
    }
  }, []);

  // Cleanup all object URLs when unmounting
  useEffect(() => {
    return () => {
      activeObjectUrlsRef.current.forEach((url) => {
        try {
          URL.revokeObjectURL(url);
        } catch (e) {}
      });
      activeObjectUrlsRef.current.clear();
    };
  }, []);

  // Default to 1 hour from now in LOCAL IST time
  const [scheduleAt, setScheduleAt] = useState(() => {
    const d = new Date(Date.now() + 3600000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  });

  // Preview toggle
  const [previewPlatform, setPreviewPlatform] = useState('Facebook');

  // Status & Submit
  const [submitting, setSubmitting] = useState(false);
  const [statusMsg, setStatusMsg] = useState({ type: '', text: '' });

  // Platform compatibility rules (Facebook, Instagram, YouTube)
  const getPlatformCompatibility = useCallback((key) => {
    if (key === 'YouTube') {
      if (contentType !== 'video') {
        return { compatible: false, reason: 'YouTube requires video content.' };
      }
      return { compatible: true, reason: '' };
    }
    if (key === 'Instagram') {
      if (contentType === 'post' && selectedFiles.length === 0) {
        return { compatible: false, reason: 'Instagram requires media.' };
      }
      return { compatible: true, reason: '' };
    }
    if (key === 'Facebook') {
      return { compatible: true, reason: '' };
    }
    return { compatible: false, reason: 'Unsupported platform' };
  }, [contentType, selectedFiles.length]);

  // Character limit calculations
  const totalLength = postCaption.length + (postHashtags ? postHashtags.length + 2 : 0);

  const maxCharLimit = useMemo(() => {
    const isIGActive = platforms.Instagram && getPlatformCompatibility('Instagram').compatible;
    if (isIGActive) return 2200;
    return 5000;
  }, [platforms, getPlatformCompatibility]);

  const isOverLimit = totalLength > maxCharLimit;
  const isNearLimit = totalLength > maxCharLimit * 0.85;

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

  // Explicit New Draft reset
  const handleNewDraft = useCallback((confirm = true) => {
    if (confirm && isDirty && !window.confirm('Start a new draft? Any unsaved changes in the current draft will be discarded.')) {
      return;
    }
    // Clean up all tracked object URLs
    activeObjectUrlsRef.current.forEach((url) => {
      try {
        URL.revokeObjectURL(url);
      } catch (e) {}
    });
    activeObjectUrlsRef.current.clear();

    setPostTitle('');
    setVideoTitle('');
    setYoutubePrivacy('unlisted');
    setPublishResults(null);
    setPostCaption('');
    setPostHashtags('');
    setPostCTA('Learn More');
    setSelectedFiles([]);
    setMediaPreviews([]);
    setActiveCarouselSlide(0);
    setMediaError('');
    setFieldErrors({ caption: '', media: '', platforms: '' });
    setStatusMsg({ type: '', text: '' });
    setIsDirty(false);
  }, [isDirty]);

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

  // Reload everything when client/workspace switches with workspace protection
  useEffect(() => {
    if (currentWorkspaceIdRef.current && currentWorkspaceIdRef.current !== selectedWorkspaceId && isDirty) {
      const confirmDiscard = window.confirm(
        'You have unsaved draft changes in the previous workspace. Do you want to discard them?'
      );
      if (confirmDiscard) {
        handleNewDraft(false);
      }
    }
    currentWorkspaceIdRef.current = selectedWorkspaceId;
    setHistoryPage(1);
    fetchWorkspaceData();
    fetchHistoryPosts(1);
  }, [selectedWorkspaceId]);

  // Fetch paginated history when historyPage changes
  useEffect(() => {
    if (selectedWorkspaceId) {
      fetchHistoryPosts(historyPage);
    }
  }, [historyPage]);

  const fetchWorkspaceData = async () => {
    if (!selectedWorkspaceId) return;
    setLoadingIntegrations(true);
    setStatusMsg({ type: '', text: '' });

    try {
      // 1. Fetch real integrations status for this workspace
      const integRes = await axiosInstance.get('/integrations/status', {
        params: { workspace_id: selectedWorkspaceId },
      });

      const connMap = {
        facebook: null,
        instagram: null,
        youtube: null,
      };

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
      } catch (e) {}

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
      } catch (e) {}

      setConnections(connMap);

      // Automatically enable platforms that are actually connected for this workspace
      const newPlatforms = {
        Facebook: Boolean(connMap.facebook),
        Instagram: Boolean(connMap.instagram),
        YouTube: false, // Default unselected unless publishing video
      };
      setPlatforms(newPlatforms);

      // Set default preview platform to first connected platform
      if (connMap.facebook) setPreviewPlatform('Facebook');
      else if (connMap.instagram) setPreviewPlatform('Instagram');
      else if (connMap.youtube) setPreviewPlatform('YouTube');
      else setPreviewPlatform('Facebook');

      // 2. Fetch Drafts
      const draftsRes = await axiosInstance.get('/drafts', {
        params: { workspace_id: selectedWorkspaceId },
      });
      if (draftsRes.data.success) setDraftPosts(draftsRes.data.data || []);

      // 3. Fetch Scheduled Posts
      const schedRes = await axiosInstance.get('/scheduled-posts', {
        params: { workspace_id: selectedWorkspaceId },
      });
      if (schedRes.data.success) setScheduledPosts(schedRes.data.data || []);

    } catch (err) {
      console.error('Error loading publishing data:', err);
    } finally {
      setLoadingIntegrations(false);
    }
  };

  const fetchHistoryPosts = async (page = 1) => {
    if (!selectedWorkspaceId) return;
    setHistoryLoading(true);
    try {
      const res = await axiosInstance.get('/posts/history', {
        params: {
          workspace_id: selectedWorkspaceId,
          page,
          per_page: 5,
        },
      });
      if (res.data.success) {
        setHistoryPosts(res.data.data || []);
        setHistoryPage(res.data.current_page || page);
        setHistoryTotalPages(res.data.last_page || 1);
        setHistoryTotalCount(res.data.total || 0);
      }
    } catch (err) {
      console.error('Error fetching paginated history:', err);
    } finally {
      setHistoryLoading(false);
    }
  };

  const handlePlatformToggle = (key) => {
    if (key === 'LinkedIn') return;
    setPlatforms((prev) => ({ ...prev, [key]: !prev[key] }));
    setIsDirty(true);
    setFieldErrors((prev) => ({ ...prev, platforms: '' }));
  };

  const handleContentTypeChange = (newType) => {
    if (newType === contentType) return;
    setContentType(newType);
    setMediaError('');
    setFieldErrors({ caption: '', media: '', platforms: '' });
    setActiveCarouselSlide(0);

    // Adjust selected files to match the new type cleanly with tracked URL cleanup
    if (newType === 'video') {
      const videoFiles = selectedFiles.filter((f) => f.type.startsWith('video/'));
      mediaPreviews.forEach((url) => revokeTrackedObjectURL(url));

      if (videoFiles.length > 0) {
        const keptFile = videoFiles[0];
        const newUrl = createTrackedObjectURL(keptFile);
        setSelectedFiles([keptFile]);
        setMediaPreviews([newUrl]);
      } else {
        setSelectedFiles([]);
        setMediaPreviews([]);
      }
    } else if (newType === 'post') {
      const imageFiles = selectedFiles.filter((f) => f.type.startsWith('image/'));
      mediaPreviews.forEach((url) => revokeTrackedObjectURL(url));

      if (imageFiles.length > 0) {
        const keptFile = imageFiles[0];
        const newUrl = createTrackedObjectURL(keptFile);
        setSelectedFiles([keptFile]);
        setMediaPreviews([newUrl]);
      } else {
        setSelectedFiles([]);
        setMediaPreviews([]);
      }
    } else if (newType === 'carousel') {
      const imageFiles = selectedFiles.filter((f) => f.type.startsWith('image/'));
      mediaPreviews.forEach((url) => revokeTrackedObjectURL(url));

      if (imageFiles.length > 0) {
        const newUrls = imageFiles.map((f) => createTrackedObjectURL(f));
        setSelectedFiles(imageFiles);
        setMediaPreviews(newUrls);
      } else {
        setSelectedFiles([]);
        setMediaPreviews([]);
      }
    }
  };

  // Centralized media selection handler (used by file inputs & drag-drop)
  const handleFilesSelected = (files) => {
    if (!files || !files.length) return;
    setIsProcessingMedia(true);
    setMediaError('');
    setFieldErrors((prev) => ({ ...prev, media: '' }));

    if (contentType === 'video') {
      const file = files[0];
      const validVideoTypes = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska', 'video/avi'];
      const validExtensions = ['.mp4', '.mov', '.webm', '.mkv', '.avi'];
      const ext = '.' + (file.name.split('.').pop() || '').toLowerCase();

      const isValid = validVideoTypes.includes(file.type) || validExtensions.includes(ext);
      if (!isValid) {
        setMediaError('Invalid video format. Supported formats: MP4, MOV, WebM, MKV, AVI.');
        setIsProcessingMedia(false);
        return;
      }

      if (file.size > 500 * 1024 * 1024) {
        setMediaError(`File is too large (${(file.size / (1024 * 1024)).toFixed(1)} MB). Maximum allowed video size is 500 MB.`);
        setIsProcessingMedia(false);
        return;
      }

      // Cleanup previous video preview URL
      mediaPreviews.forEach((url) => revokeTrackedObjectURL(url));

      const newUrl = createTrackedObjectURL(file);
      setSelectedFiles([file]);
      setMediaPreviews([newUrl]);
      setIsDirty(true);
      setIsProcessingMedia(false);
    } else if (contentType === 'post') {
      const file = files[0];
      if (!file.type.startsWith('image/')) {
        setMediaError('Please select a valid image file (JPG, PNG, WebP) for a standard post.');
        setIsProcessingMedia(false);
        return;
      }

      if (file.size > 25 * 1024 * 1024) {
        setMediaError(`Image size (${(file.size / (1024 * 1024)).toFixed(1)} MB) exceeds 25 MB limit.`);
        setIsProcessingMedia(false);
        return;
      }

      // Cleanup previous image preview URL
      mediaPreviews.forEach((url) => revokeTrackedObjectURL(url));

      const newUrl = createTrackedObjectURL(file);
      setSelectedFiles([file]);
      setMediaPreviews([newUrl]);
      setIsDirty(true);
      setIsProcessingMedia(false);
    } else if (contentType === 'carousel') {
      // Validate all are images
      const nonImage = files.find((f) => !f.type.startsWith('image/'));
      if (nonImage) {
        setMediaError('Carousel supports image formats only (JPG, PNG, WebP).');
        setIsProcessingMedia(false);
        return;
      }

      // Validate file size per image
      const oversized = files.find((f) => f.size > 25 * 1024 * 1024);
      if (oversized) {
        setMediaError(`Image "${oversized.name}" exceeds the 25 MB limit.`);
        setIsProcessingMedia(false);
        return;
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
        setMediaError(`All ${duplicateCount} selected image(s) have already been added to the carousel.`);
        setIsProcessingMedia(false);
        return;
      }

      // Max 10 images limit
      let filesToAdd = uniqueNewFiles;
      if (selectedFiles.length + uniqueNewFiles.length > 10) {
        const allowedCount = Math.max(0, 10 - selectedFiles.length);
        filesToAdd = uniqueNewFiles.slice(0, allowedCount);
        setMediaError(`Maximum 10 images allowed for carousel. Added ${allowedCount} image(s); excess were skipped.`);
      } else if (duplicateCount > 0) {
        setMediaError(`Added ${uniqueNewFiles.length} image(s); skipped ${duplicateCount} duplicate image(s).`);
      }

      const newUrls = filesToAdd.map((f) => createTrackedObjectURL(f));
      setSelectedFiles((prev) => [...prev, ...filesToAdd]);
      setMediaPreviews((prev) => [...prev, ...newUrls]);
      setIsDirty(true);
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

  const handleRemoveMedia = (index) => {
    if (mediaPreviews[index]) {
      revokeTrackedObjectURL(mediaPreviews[index]);
    }
    const newFiles = selectedFiles.filter((_, idx) => idx !== index);
    setSelectedFiles(newFiles);
    const newPreviews = mediaPreviews.filter((_, idx) => idx !== index);
    setMediaPreviews(newPreviews);
    setIsDirty(true);
    if (activeCarouselSlide >= newPreviews.length) {
      setActiveCarouselSlide(Math.max(0, newPreviews.length - 1));
    }
  };

  const handleMoveMedia = (index, direction) => {
    const targetIndex = index + direction;
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

    if (activeCarouselSlide === index) {
      setActiveCarouselSlide(targetIndex);
    } else if (activeCarouselSlide === targetIndex) {
      setActiveCarouselSlide(index);
    }
  };


  const hasAnyConnection = Boolean(
    connections.facebook || connections.instagram || connections.youtube
  );

  // Form Submission — Supports both internal 'draft' and live 'publish'
  const handleSubmit = async (action = 'publish') => {
    // 1. Prevent double clicks / concurrent requests
    if (isSavingRef.current || submitting) return;

    // 2. Smart Validation
    const errors = { caption: '', media: '', platforms: '' };
    let hasValidationErrors = false;

    // A. Validate platform compatibility
    const selectedCompatiblePlatforms = Object.keys(platforms).filter(
      (key) => platforms[key] && getPlatformCompatibility(key).compatible
    );

    if (selectedCompatiblePlatforms.length === 0) {
      errors.platforms = 'Please select at least one compatible platform for this content type.';
      hasValidationErrors = true;
    }

    // B. If publishing for real (status: 'published'), validate required connected account/token availability
    if (action === 'publish' && selectedCompatiblePlatforms.length > 0) {
      const missingPlatforms = [];
      if (selectedCompatiblePlatforms.includes('Facebook') && !connections.facebook) {
        missingPlatforms.push('Facebook Page');
      }
      if (selectedCompatiblePlatforms.includes('Instagram') && !connections.instagram && !connections.facebook) {
        missingPlatforms.push('Instagram Account');
      }
      if (selectedCompatiblePlatforms.includes('YouTube') && !connections.youtube) {
        missingPlatforms.push('YouTube Channel');
      }

      if (missingPlatforms.length > 0) {
        errors.platforms = `Cannot publish: No connected account found for ${missingPlatforms.join(', ')}. Please connect them in API Connections or unselect them.`;
        hasValidationErrors = true;
      }
    }

    // C. Validate caption & length
    if (!postCaption.trim() && selectedFiles.length === 0) {
      errors.caption = 'Please enter a post caption or attach media.';
      hasValidationErrors = true;
    } else if (isOverLimit) {
      errors.caption = `Exceeds the ${maxCharLimit}-character limit for the selected platform(s).`;
      hasValidationErrors = true;
    }

    // D. Validate media based on content type
    if (contentType === 'carousel') {
      if (selectedFiles.length < 2) {
        errors.media = `Carousel requires at least 2 images (currently ${selectedFiles.length}). Please attach more images or select Post.`;
        hasValidationErrors = true;
      }
    } else if (contentType === 'video') {
      if (selectedFiles.length === 0) {
        errors.media = 'Please attach a video file for video content.';
        hasValidationErrors = true;
      }
    }

    // E. Instagram specific requirement: cannot be text-only
    if (action === 'publish' && selectedCompatiblePlatforms.includes('Instagram') && selectedFiles.length === 0) {
      errors.media = 'Instagram requires an image or video to create a post. Please attach media.';
      hasValidationErrors = true;
    }

    setFieldErrors(errors);

    if (hasValidationErrors) {
      setStatusMsg({
        type: 'error',
        text: `Please resolve the highlighted validation errors before ${action === 'publish' ? 'publishing' : 'saving draft'}.`,
      });
      return;
    }

    isSavingRef.current = true;
    setSubmitting(action);
    setStatusMsg({ type: '', text: '' });
    setPublishResults(null);

    try {
      const fullMessage = postHashtags ? `${postCaption}\n\n${postHashtags}` : postCaption;

      const formData = new FormData();
      formData.append('workspace_id', selectedWorkspaceId);
      formData.append('message', fullMessage);
      formData.append('status', action === 'publish' ? 'published' : 'draft');

      selectedCompatiblePlatforms.forEach((p) => {
        formData.append('platforms[]', p);
      });

      if (contentType === 'video' && selectedFiles.length > 0 && selectedFiles[0].type.startsWith('video/')) {
        formData.append('video', selectedFiles[0]);
        formData.append('post_type', 'video');
        if (videoTitle.trim()) {
          formData.append('video_title', videoTitle.trim());
        }
        formData.append('youtube_privacy', youtubePrivacy);
      } else if (contentType === 'carousel' && selectedFiles.length > 0) {
        selectedFiles.forEach((file) => {
          formData.append('images[]', file);
        });
        formData.append('post_type', 'multi_image');
      } else if (selectedFiles.length > 0) {
        selectedFiles.forEach((file) => {
          formData.append('images[]', file);
        });
        formData.append('post_type', 'single_image');
      } else {
        formData.append('post_type', 'text');
      }

      const res = await axiosInstance.post('/facebook/publish-post', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      if (res.data.success) {
        setPublishResults(res.data.platform_statuses || null);
        const isPartial = Boolean(res.data.partial);
        setStatusMsg({
          type: isPartial ? 'warning' : 'success',
          text: action === 'publish'
            ? (res.data.message || 'Post published successfully!')
            : 'Draft saved successfully!',
        });
        // Preserves form data per requirements!
        setIsDirty(false);
        fetchWorkspaceData();
        fetchHistoryPosts(1);
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
      const errText = err.response?.data?.message || err.message || `Network error while ${action === 'publish' ? 'publishing' : 'saving draft'}.`;
      setStatusMsg({ type: 'error', text: errText });
      // Preserves form data on error so user can retry!
    } finally {
      setSubmitting(false);
      isSavingRef.current = false;
    }
  };

  const handleRetryPost = async (id) => {
    try {
      const res = await axiosInstance.post(`/posts/${id}/retry`);
      if (res.data.success) {
        setStatusMsg({ type: 'success', text: 'Post retried and published successfully!' });
        fetchWorkspaceData();
        fetchHistoryPosts(historyPage);
      } else {
        setStatusMsg({ type: 'error', text: res.data.message || 'Retry failed.' });
      }
    } catch (err) {
      const errMsg = err.response?.data?.message || err.message || 'Error retrying post';
      setStatusMsg({ type: 'error', text: errMsg });
    }
  };

  const handleDuplicatePost = async (id) => {
    try {
      const res = await axiosInstance.post(`/posts/${id}/duplicate`);
      if (res.data.success) {
        setStatusMsg({ type: 'success', text: 'Post duplicated as a new draft!' });
        fetchWorkspaceData();
        fetchHistoryPosts(1);
      }
    } catch (err) {
      alert('Error duplicating post');
    }
  };

  const handleDeleteDraft = async (id) => {
    if (!window.confirm('Delete this draft?')) return;
    try {
      await axiosInstance.delete(`/drafts/${id}`);
      fetchWorkspaceData();
      fetchHistoryPosts(historyPage);
    } catch (err) {
      alert('Error deleting draft');
    }
  };

  const handleDeleteScheduled = async (id) => {
    if (!window.confirm('Cancel this scheduled post?')) return;
    try {
      await axiosInstance.delete(`/scheduled-posts/${id}`);
      fetchWorkspaceData();
      fetchHistoryPosts(historyPage);
    } catch (err) {
      alert('Error cancelling scheduled post');
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
    if (previewPlatform === 'X') {
      return {
        name: connections.twitter ? connections.twitter.account_name : 'X Account',
        sub: connections.twitter ? 'Just now • X.com' : 'Not Connected',
        initials: connections.twitter ? connections.twitter.account_name.substring(0, 2).toUpperCase() : 'X',
        bg: '#000000',
      };
    }
    if (previewPlatform === 'Instagram') {
      return {
        name: connections.instagram ? (connections.instagram.account_name.startsWith('@') ? connections.instagram.account_name : `@${connections.instagram.account_name}`) : 'Instagram Account',
        sub: 'Sponsored',
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

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
            <h2>Create Content</h2>
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
              Production Publishing Ready
            </span>
          </div>
          <p style={{ marginTop: '4px' }}>
            Create and publish content across Facebook, Instagram, and YouTube (<strong>{selectedWorkspace?.name || 'Selected Workspace'}</strong>).
          </p>
          {/* View Switcher Tabs */}
          <div style={{ display: 'flex', gap: '8px', marginTop: '12px' }}>
            <button
              type="button"
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
            disabled={Boolean(submitting)}
            onClick={() => handleSubmit('draft')}
            style={{ minWidth: '125px', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '6px' }}
          >
            {submitting === 'draft' ? <Loader2 size={15} className="spin" /> : <Save size={15} />}
            {submitting === 'draft' ? 'Saving...' : 'Save Draft'}
          </button>
          <button
            type="button"
            className="btn btn-primary"
            disabled={Boolean(submitting)}
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
        </div>
      </div>

      {statusMsg.text && (
        <div
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
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* No Connected Accounts Banner */}
      {!loadingIntegrations && !hasAnyConnection && (
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
                Connect your Facebook Page, Instagram Account, YouTube Channel, or X account in Integrations to enable live publishing.
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
          onNewPostClick={() => setActiveTab('composer')}
          onSelectPost={(post) => {
            if (post.content) setPostCaption(post.content);
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
            <span className="pill" style={{ background: '#eff6ff', color: '#1d4ed8', fontWeight: '600' }}>Live & Draft Ready</span>
          </div>

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
                        checked={platforms.Facebook}
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
                        checked={platforms.Instagram}
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
                        checked={platforms.YouTube}
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
              {fieldErrors.platforms && (
                <div style={{ color: '#dc2626', fontSize: '12px', marginTop: '6px', fontWeight: '600', display: 'flex', alignItems: 'center', gap: '4px' }}>
                  <AlertCircle size={14} />
                  <span>{fieldErrors.platforms}</span>
                </div>
              )}
            </div>

            {/* Internal content title */}
            <div className="form-field full">
              <label htmlFor="postTitle" className="form-label">
                Internal content title (optional)
              </label>
              <input
                id="postTitle"
                className="input"
                value={postTitle}
                onChange={(e) => {
                  setPostTitle(e.target.value);
                  setIsDirty(true);
                }}
                placeholder="e.g. Special Product Launch / Weekend Campaign"
              />
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
                  />
                </div>

                <div className="form-field full">
                  <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
                    <span style={{ fontWeight: '600' }}>YouTube Privacy Setting</span>
                    <span style={{ fontSize: '11.5px', color: '#047857', fontWeight: '700' }}>Default: Unlisted (Safe for Testing)</span>
                  </label>
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '8px' }}>
                    {[
                      { id: 'unlisted', label: 'Unlisted (Test)', desc: 'Only people with the link can view' },
                      { id: 'public', label: 'Public', desc: 'Anyone on YouTube can watch' },
                      { id: 'private', label: 'Private', desc: 'Only you can watch' },
                    ].map((item) => (
                      <button
                        key={item.id}
                        type="button"
                        onClick={() => {
                          setYoutubePrivacy(item.id);
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
                required
              />
              {fieldErrors.caption && (
                <div style={{ color: '#dc2626', fontSize: '12px', marginTop: '4px', fontWeight: '600', display: 'flex', alignItems: 'center', gap: '4px' }}>
                  <AlertCircle size={14} />
                  <span>{fieldErrors.caption}</span>
                </div>
              )}
              {isOverLimit && (
                <div style={{ color: '#dc2626', fontSize: '11.5px', marginTop: '4px', fontWeight: '500' }}>
                  ⚠️ Exceeds the {maxCharLimit}-character limit for the selected platform(s). Shorten caption to save draft.
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
                }}
                placeholder="#marketing #business #growth"
              />
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
            </div>

            {/* Media attachments */}
            <div className="form-field full">
              <label className="form-label" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span>
                  {contentType === 'post'
                    ? 'Media attachment (Single image)'
                    : contentType === 'carousel'
                    ? `Carousel media (${selectedFiles.length} / 10 images)`
                    : 'Video attachment (Single video)'}
                </span>
                <span style={{ fontSize: '11.5px', color: '#64748b' }}>
                  {contentType === 'post'
                    ? 'Optional for text posts (JPG, PNG, WebP)'
                    : contentType === 'carousel'
                    ? 'Requires min 2 images (JPG, PNG, WebP)'
                    : 'Requires video (MP4, MOV, WebM up to 500MB)'}
                </span>
              </label>

              {mediaError && (
                <div
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
                <div
                  className="media-drop"
                  onDragOver={handleDragOver}
                  onDragEnter={handleDragOver}
                  onDragLeave={handleDragLeave}
                  onDrop={handleDrop}
                  style={{
                    border: isDragging ? '2px dashed #2457e6' : '1px dashed #cbd5e1',
                    background: isDragging ? '#eff6ff' : '#f8fafc',
                    transition: 'all 0.2s ease',
                  }}
                >
                  <ImageIcon size={28} style={{ color: '#2457e6', marginBottom: '6px' }} />
                  <strong>Click to browse or drag & drop an image</strong>
                  <div className="form-help">Supports JPG, PNG, WebP, GIF (Single image, up to 25MB)</div>
                  <input
                    type="file"
                    id="mediaFile"
                    accept="image/*"
                    onChange={handleFileChange}
                    style={{ marginTop: '12px', maxWidth: '100%' }}
                  />
                  {isProcessingMedia && (
                    <div style={{ marginTop: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '6px', color: '#2457e6', fontSize: '12.5px', fontWeight: '600' }}>
                      <Loader2 size={16} className="spin" /> Processing image...
                    </div>
                  )}
                  {mediaPreviews.length > 0 && (
                    <div style={{ marginTop: '14px', display: 'inline-flex', position: 'relative' }}>
                      <img
                        src={mediaPreviews[0]}
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
                        onClick={() => handleRemoveMedia(0)}
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
                <div
                  className="media-drop"
                  onDragOver={handleDragOver}
                  onDragEnter={handleDragOver}
                  onDragLeave={handleDragLeave}
                  onDrop={handleDrop}
                  style={{
                    border: isDragging ? '2px dashed #2457e6' : '1px dashed #cbd5e1',
                    background: isDragging ? '#eff6ff' : '#f8fafc',
                    transition: 'all 0.2s ease',
                  }}
                >
                  <Layers size={28} style={{ color: '#2457e6', marginBottom: '6px' }} />
                  <strong>Click to browse or drag & drop multiple images</strong>
                  <div className="form-help">
                    Attach 2 to 10 images (JPG, PNG, WebP). Duplicates are automatically skipped. Reorder using ← and →.
                  </div>
                  <input
                    type="file"
                    id="mediaFile"
                    accept="image/*"
                    multiple
                    onChange={handleFileChange}
                    style={{ marginTop: '12px', maxWidth: '100%' }}
                  />
                  {isProcessingMedia && (
                    <div style={{ marginTop: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '6px', color: '#2457e6', fontSize: '12.5px', fontWeight: '600' }}>
                      <Loader2 size={16} className="spin" /> Processing images...
                    </div>
                  )}
                  {mediaPreviews.length > 0 && (
                    <div style={{ marginTop: '16px' }}>
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
                        <span>Carousel Slides ({mediaPreviews.length} / 10 images)</span>
                        <span style={{ fontSize: '11px', color: '#64748b' }}>Use arrows to reorder</span>
                      </div>
                      <div style={{ display: 'flex', gap: '12px', overflowX: 'auto', paddingBottom: '8px' }}>
                        {mediaPreviews.map((url, idx) => (
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
                              <span>Slide #{idx + 1}</span>
                              <button
                                type="button"
                                onClick={() => handleRemoveMedia(idx)}
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
                            </div>
                            <img
                              src={url}
                              alt={`Slide ${idx + 1}`}
                              onClick={() => setActiveCarouselSlide(idx)}
                              style={{
                                width: '100%',
                                height: '80px',
                                borderRadius: '6px',
                                objectFit: 'cover',
                                cursor: 'pointer',
                              }}
                            />
                            <div style={{ display: 'flex', justifyContent: 'center', gap: '4px', marginTop: '6px' }}>
                              <button
                                type="button"
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
                                disabled={idx === mediaPreviews.length - 1}
                                title="Move right"
                                style={{
                                  padding: '3px 8px',
                                  fontSize: '11px',
                                  borderRadius: '4px',
                                  border: '1px solid #cbd5e1',
                                  background: idx === mediaPreviews.length - 1 ? '#f1f5f9' : '#ffffff',
                                  cursor: idx === mediaPreviews.length - 1 ? 'not-allowed' : 'pointer',
                                  color: idx === mediaPreviews.length - 1 ? '#94a3b8' : '#0f172a',
                                  fontWeight: '700',
                                }}
                              >
                                →
                              </button>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* VIDEO MEDIA DROPZONE */}
              {contentType === 'video' && (
                <div
                  className="media-drop"
                  onDragOver={handleDragOver}
                  onDragEnter={handleDragOver}
                  onDragLeave={handleDragLeave}
                  onDrop={handleDrop}
                  style={{
                    border: isDragging ? '2px dashed #2457e6' : '1px dashed #cbd5e1',
                    background: isDragging ? '#eff6ff' : '#f8fafc',
                    transition: 'all 0.2s ease',
                  }}
                >
                  <Video size={28} style={{ color: '#2457e6', marginBottom: '6px' }} />
                  <strong>Click to browse or drag & drop a video file</strong>
                  <div className="form-help">Supported video formats: MP4, MOV, WebM, MKV, AVI (up to 500MB)</div>
                  <input
                    type="file"
                    id="mediaFile"
                    accept="video/mp4,video/quicktime,video/webm,video/x-matroska,video/avi"
                    onChange={handleFileChange}
                    style={{ marginTop: '12px', maxWidth: '100%' }}
                  />
                  {isProcessingMedia && (
                    <div style={{ marginTop: '12px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '6px', color: '#2457e6', fontSize: '12.5px', fontWeight: '600' }}>
                      <Loader2 size={16} className="spin" /> Processing video...
                    </div>
                  )}
                  {selectedFiles.length > 0 && selectedFiles[0].type.startsWith('video/') && (
                    <div
                      style={{
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
            </div>

            {/* Bottom Actions Row — Real Publishing Enabled */}
            <div className="form-field full">
              <div className="flex justify-between items-center gap-12" style={{ marginTop: '12px', flexWrap: 'wrap' }}>
                <span className="form-help">
                  <Shield size={13} style={{ display: 'inline', marginRight: '4px', verticalAlign: '-1px' }} />
                  Content Publishing: Save as internal draft or publish directly to connected social accounts.
                </span>
                <div className="flex gap-8" style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
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
                    disabled={Boolean(submitting)}
                    onClick={() => handleSubmit('draft')}
                    style={{ minWidth: '125px', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '6px' }}
                  >
                    {submitting === 'draft' ? <Loader2 size={15} className="spin" /> : <Save size={15} />}
                    {submitting === 'draft' ? 'Saving...' : 'Save Draft'}
                  </button>
                  <button
                    type="button"
                    className="btn btn-primary"
                    disabled={Boolean(submitting)}
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

              {/* Dynamic Live Media Preview based on contentType */}
              {contentType === 'video' ? (
                mediaPreviews.length > 0 ? (
                  <video
                    src={mediaPreviews[0]}
                    controls
                    style={{
                      width: '100%',
                      maxHeight: '180px',
                      borderRadius: '4px',
                      background: '#000',
                      objectFit: 'contain',
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
                    <Video size={30} style={{ color: '#94a3b8' }} />
                    <span style={{ fontSize: '12px', fontWeight: '600' }}>Video preview area</span>
                    <small style={{ fontSize: '10px', color: '#94a3b8' }}>Upload MP4, MOV, or WebM</small>
                  </div>
                )
              ) : contentType === 'carousel' ? (
                mediaPreviews.length > 0 ? (
                  <div style={{ position: 'relative', width: '100%', marginBottom: '8px' }}>
                    <img
                      src={mediaPreviews[activeCarouselSlide] || mediaPreviews[0]}
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
                      {activeCarouselSlide + 1} / {mediaPreviews.length}
                    </div>
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
                    {activeCarouselSlide < mediaPreviews.length - 1 && (
                      <button
                        type="button"
                        onClick={() => setActiveCarouselSlide((prev) => Math.min(mediaPreviews.length - 1, prev + 1))}
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
                      {mediaPreviews.map((_, i) => (
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
              ) : mediaPreviews.length > 0 ? (
                <img
                  src={mediaPreviews[0]}
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
                      {videoTitle || postTitle || (postCaption ? postCaption.slice(0, 50) : 'Video Title')}
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
                      {youtubePrivacy}
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

              {postCTA !== 'No CTA' && (
                <button className="btn btn-sm btn-primary btn-block mt-12" type="button" id="previewCTA">
                  {postCTA}
                </button>
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
                      const platform = Array.isArray(p.platform_list) && p.platform_list.length > 0
                        ? p.platform_list[0]
                        : (p.facebook_page_id ? 'Facebook' : 'Instagram');
                      const title = p.content
                        ? (p.content.length > 35 ? p.content.substring(0, 35) + '...' : p.content)
                        : (p.post_type === 'video' ? 'Video Post' : 'Media Post');

                      return (
                        <div key={p.id} className={`day-card active ${platform.toLowerCase()}`}>
                          <span className="card-time">{timeLabel}</span>
                          <div className="card-title" title={p.content || ''}>{title}</div>
                          <span className={`card-tag tag-${platform.toLowerCase()}`}>{platform}</span>
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
              fetchWorkspaceData();
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
                    <td style={{ padding: '10px 14px', maxWidth: '280px' }}>
                      <strong style={{ display: 'block', fontSize: '13.5px', color: '#0f172a' }}>
                        {post.content ? (post.content.length > 60 ? post.content.substring(0, 60) + '...' : post.content) : 'Media Content'}
                      </strong>
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
                        </div>
                      ) : (
                        <span className={`pill ${post.platform_list?.includes('YouTube') || (!post.facebook_page_id && !post.platform_list) ? 'warning' : 'info'}`}>
                          {post.platform_list
                            ? (Array.isArray(post.platform_list) ? post.platform_list.join(', ') : post.platform_list)
                            : (post.facebook_page_id ? 'Facebook' : 'Instagram')}
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
                            : post.status === 'scheduled'
                            ? 'warning'
                            : post.status === 'failed'
                            ? 'danger'
                            : 'neutral'
                        }`}
                        title={post.error_message || ''}
                        style={{ cursor: post.error_message ? 'help' : 'default' }}
                      >
                        • {post.status}
                      </span>
                    </td>
                    <td style={{ padding: '10px 14px', color: '#64748b', fontSize: '12.5px' }}>
                      {post.published_at
                        ? new Date(post.published_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true })
                        : post.scheduled_at
                        ? new Date(post.scheduled_at).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: true })
                        : 'Draft'}
                    </td>
                    <td style={{ padding: '10px 14px' }}>
                      <div style={{ display: 'flex', gap: '6px' }}>
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

      {/* AI Caption & Hashtag Generator Modal */}
      <AiCaptionModal
        isOpen={showAiModal}
        onClose={() => setShowAiModal(false)}
        workspaceId={selectedWorkspaceId}
        initialTopic={postTitle || postCaption}
        onApplyCaption={({ caption, hashtags, fullText }) => {
          setPostCaption(caption);
          if (hashtags) setPostHashtags(hashtags);
          setIsDirty(true);
        }}
      />
    </div>
  );
}
