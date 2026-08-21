import React, { useState, useEffect, useMemo } from 'react';
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
  Calendar as CalendarIcon
} from 'lucide-react';

export default function PublishingPage() {
  const { selectedWorkspaceId, selectedWorkspace } = useWorkspace();

  // Form states
  const [platforms, setPlatforms] = useState({
    Facebook: true,
    Instagram: true,
    YouTube: false,
    LinkedIn: false,
    X: false,
  });

  const [postTitle, setPostTitle] = useState('');
  const [postCaption, setPostCaption] = useState('');
  const [postHashtags, setPostHashtags] = useState('');
  const [postCTA, setPostCTA] = useState('');
  const [publishType, setPublishType] = useState('now'); // 'schedule', 'now', 'draft'
  // Default to 1 hour from now in LOCAL time (not UTC) so the datetime-local
  // picker pre-fills with the correct IST time instead of the UTC equivalent.
  const [scheduleAt, setScheduleAt] = useState(() => {
    const d = new Date(Date.now() + 3600000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  });

  // Media states
  const [selectedFiles, setSelectedFiles] = useState([]);
  const [mediaPreviews, setMediaPreviews] = useState([]);

  // Preview toggle
  const [previewPlatform, setPreviewPlatform] = useState('Facebook');

  // Status & Submit
  const [submitting, setSubmitting] = useState(false);
  const [statusMsg, setStatusMsg] = useState({ type: '', text: '' });

  // History & Calendar data
  const [historyPosts, setHistoryPosts] = useState([]);
  const [scheduledPosts, setScheduledPosts] = useState([]);
  const [draftPosts, setDraftPosts] = useState([]);
  const [connectedPage, setConnectedPage] = useState(null);
  const [connectedYouTube, setConnectedYouTube] = useState(null);
  const [connectedTwitter, setConnectedTwitter] = useState(null);

  const totalLength = postCaption.length + (postHashtags ? postHashtags.length + 2 : 0);
  const isOverTwitterLimit = platforms.X && totalLength > 280;

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

  // Combine and deduplicate scheduled posts from both scheduled-posts API and history
  const allScheduledPosts = useMemo(() => {
    const map = new Map();
    (scheduledPosts || []).forEach((p) => {
      if (p.status === 'scheduled') map.set(p.id, p);
    });
    (historyPosts || []).forEach((p) => {
      if (p.status === 'scheduled') map.set(p.id, p);
    });
    return Array.from(map.values()).sort((a, b) => new Date(a.scheduled_at) - new Date(b.scheduled_at));
  }, [scheduledPosts, historyPosts]);

  useEffect(() => {
    fetchPageAndData();
  }, [selectedWorkspaceId]);

  const fetchPageAndData = async () => {
    try {
      // Fetch Connected FB Page
      const pageRes = await axiosInstance.get('/facebook/pages', { params: { workspace_id: selectedWorkspaceId } });
      if (pageRes.data.success && pageRes.data.data.length > 0) {
        setConnectedPage(pageRes.data.data[0]);
      } else {
        setConnectedPage(null);
      }

      // Fetch Connected YouTube Channel
      try {
        const ytRes = await axiosInstance.get('/youtube/status');
        if (ytRes.data.success && ytRes.data.connected && ytRes.data.data) {
          setConnectedYouTube(ytRes.data.data);
        } else {
          setConnectedYouTube(null);
        }
      } catch (e) {
        setConnectedYouTube(null);
      }

      // Fetch Connected X (Twitter) Profile
      try {
        const twitterRes = await axiosInstance.get('/twitter/status', { params: { workspace_id: selectedWorkspaceId } });
        if (twitterRes.data.success && twitterRes.data.connected && twitterRes.data.data) {
          setConnectedTwitter(twitterRes.data.data);
        } else {
          setConnectedTwitter(null);
        }
      } catch (e) {
        setConnectedTwitter(null);
      }

      // Fetch Drafts
      const draftsRes = await axiosInstance.get('/drafts', { params: { workspace_id: selectedWorkspaceId } });
      if (draftsRes.data.success) setDraftPosts(draftsRes.data.data);

      // Fetch Scheduled
      const schedRes = await axiosInstance.get('/scheduled-posts', { params: { workspace_id: selectedWorkspaceId } });
      if (schedRes.data.success) setScheduledPosts(schedRes.data.data);

      // Fetch History / Recent
      const dashRes = await axiosInstance.get('/dashboard/metrics', { params: { workspace_id: selectedWorkspaceId } });
      if (dashRes.data.success && dashRes.data.data.recent_posts) {
        setHistoryPosts(dashRes.data.data.recent_posts);
      }
    } catch (err) {
      console.error('Error loading publishing data:', err);
    }
  };

  const handlePlatformToggle = (key) => {
    if (key === 'LinkedIn') return;
    setPlatforms((prev) => ({ ...prev, [key]: !prev[key] }));
  };

  const handleFileChange = (e) => {
    const files = Array.from(e.target.files);
    if (!files.length) return;
    setSelectedFiles(files);
    const urls = files.map((f) => URL.createObjectURL(f));
    setMediaPreviews(urls);
  };

  const handleSubmit = async (overrideStatus = null) => {
    const targetStatus = overrideStatus || (publishType === 'now' ? 'published' : publishType === 'draft' ? 'draft' : 'scheduled');

    const selectedPlatformsList = Object.keys(platforms).filter((key) => platforms[key]);
    if (selectedPlatformsList.length === 0) {
      setStatusMsg({ type: 'error', text: 'Please select at least one platform to publish to (e.g. YouTube, Facebook, Instagram).' });
      return;
    }

    if (!postCaption.trim() && !selectedFiles.length) {
      setStatusMsg({ type: 'error', text: 'Please enter a post caption or select media.' });
      return;
    }

    if (targetStatus === 'scheduled' && !scheduleAt) {
      setStatusMsg({ type: 'error', text: 'Please select a valid date and time for scheduling.' });
      return;
    }

    const requiresFB = selectedPlatformsList.includes('Facebook');
    const requiresIG = selectedPlatformsList.includes('Instagram');
    const requiresYT = selectedPlatformsList.includes('YouTube');
    const requiresTwitter = selectedPlatformsList.includes('X');

    if (requiresFB && !connectedPage && selectedPlatformsList.length === 1) {
      setStatusMsg({ type: 'error', text: 'No connected Facebook Page found for this workspace. Please connect a Facebook Page first in Integrations.' });
      return;
    }

    if (requiresIG && selectedFiles.length === 0) {
      setStatusMsg({ type: 'error', text: 'Instagram Graph API requires an image or video to create a post. Please attach media.' });
      return;
    }

    if (requiresYT && !connectedYouTube && selectedPlatformsList.length === 1) {
      setStatusMsg({ type: 'error', text: 'No connected YouTube Channel found for this workspace. Please connect YouTube first in Integrations.' });
      return;
    }

    if (requiresTwitter && !connectedTwitter && selectedPlatformsList.length === 1) {
      setStatusMsg({ type: 'error', text: 'No connected X (Twitter) account found for this workspace. Please connect X first in Integrations.' });
      return;
    }

    if (requiresTwitter && totalLength > 280) {
      setStatusMsg({ type: 'error', text: `Your post exceeds X (Twitter)'s 280-character limit (${totalLength} characters). Please shorten your post.` });
      return;
    }

    setSubmitting(true);
    setStatusMsg({ type: '', text: '' });

    try {
      const fullMessage = postHashtags ? `${postCaption}\n\n${postHashtags}` : postCaption;

      const formData = new FormData();
      formData.append('workspace_id', selectedWorkspaceId);
      formData.append('message', fullMessage);
      formData.append('status', targetStatus);
      // Append explicit IST offset (+05:30) so the backend can unambiguously
      // parse the user-selected local time as Asia/Kolkata, not as UTC.
      if (scheduleAt) {
        console.log('[SCHEDULE DEBUG] Frontend selected time:', scheduleAt);
        const scheduleAtIST = scheduleAt + '+05:30';
        console.log('[SCHEDULE DEBUG] Payload scheduled_at (with IST offset):', scheduleAtIST);
        formData.append('scheduled_at', scheduleAtIST);
      }

      selectedPlatformsList.forEach((p) => {
        formData.append('platforms[]', p);
      });

      if (selectedFiles.length === 1 && selectedFiles[0].type.startsWith('video/')) {
        formData.append('video', selectedFiles[0]);
        formData.append('post_type', 'video');
      } else if (selectedFiles.length > 0) {
        selectedFiles.forEach((file) => {
          formData.append('images[]', file);
        });
        formData.append('post_type', selectedFiles.length === 1 ? 'single_image' : 'multi_image');
      } else {
        formData.append('post_type', 'text');
      }

      const res = await axiosInstance.post('/facebook/publish-post', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      if (res.data.success) {
        setStatusMsg({ type: 'success', text: res.data.message });
        if (targetStatus === 'published') {
          if (platforms.X) {
            // Bypass API and use Twitter Web Intent to let the user post to their real account directly
            const tweetUrl = `https://twitter.com/intent/tweet?text=${encodeURIComponent(fullMessage)}`;
            window.open(tweetUrl, '_blank', 'width=600,height=500,noopener,noreferrer');
          }
          setPostCaption('');
          setSelectedFiles([]);
          setMediaPreviews([]);
        }
        fetchPageAndData();
      } else {
        setStatusMsg({ type: 'error', text: res.data.message || 'Action failed.' });
      }
    } catch (err) {
      console.error('Publish error:', err);
      const errText = err.response?.data?.message || err.message || 'Network error executing request.';
      setStatusMsg({ type: 'error', text: errText });
    } finally {
      setSubmitting(false);
    }
  };

  const handleRetryPost = async (id) => {
    try {
      const res = await axiosInstance.post(`/posts/${id}/retry`);
      if (res.data.success) {
        setStatusMsg({ type: 'success', text: 'Post retried and published successfully!' });
        fetchPageAndData();
      } else {
        alert(res.data.message || 'Retry failed');
      }
    } catch (err) {
      alert(err.response?.data?.message || 'Error retrying post');
    }
  };

  const handleDuplicatePost = async (id) => {
    try {
      const res = await axiosInstance.post(`/posts/${id}/duplicate`);
      if (res.data.success) {
        setStatusMsg({ type: 'success', text: 'Post duplicated as a draft!' });
        fetchPageAndData();
      }
    } catch (err) {
      alert('Error duplicating post');
    }
  };

  const handleDeleteDraft = async (id) => {
    if (!window.confirm('Delete this draft?')) return;
    try {
      await axiosInstance.delete(`/drafts/${id}`);
      fetchPageAndData();
    } catch (err) {
      alert('Error deleting draft');
    }
  };

  const handleDeleteScheduled = async (id) => {
    if (!window.confirm('Cancel this scheduled post?')) return;
    try {
      await axiosInstance.delete(`/scheduled-posts/${id}`);
      fetchPageAndData();
    } catch (err) {
      alert('Error cancelling scheduled post');
    }
  };

  const clientInitials = connectedPage ? connectedPage.account_name.substring(0, 2).toUpperCase() : (selectedWorkspace?.name ? selectedWorkspace.name.substring(0, 2).toUpperCase() : 'WS');
  const pageDisplayName = connectedPage ? connectedPage.account_name : (selectedWorkspace?.name || 'Workspace');

  return (
    <div>
      {/* 1. Header Section */}
      <div className="section-head">
        <div>
          <h2>Content publishing</h2>
          <p>Create once, choose channels, and publish immediately or schedule for later.</p>
        </div>
        <div className="toolbar">
          <button
            type="button"
            className="btn btn-secondary"
            disabled={submitting}
            onClick={() => handleSubmit('draft')}
          >
            Save draft
          </button>
          <button
            type="button"
            className="btn btn-primary"
            disabled={submitting}
            onClick={() => handleSubmit()}
          >
            {submitting ? 'Processing...' : (publishType === 'now' ? 'Publish Now' : 'Publish / Schedule')}
          </button>
        </div>
      </div>

      {statusMsg.text && (
        <div
          style={{
            background: statusMsg.type === 'success' ? '#e7f7f0' : '#fef2f2',
            color: statusMsg.type === 'success' ? '#11875d' : '#dc2626',
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
          {statusMsg.type === 'success' ? <CheckCircle2 size={18} /> : <AlertCircle size={18} />}
          <span>{statusMsg.text}</span>
        </div>
      )}

      {/* 2. Main Publisher Layout (Form + Preview) */}
      <div className="publisher-layout">
        {/* Left Form Panel */}
        <section className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Create content</h3>
              <p>Plain-language form suitable for daily marketing operations</p>
            </div>
            <span className="pill info">Auto-saved locally</span>
          </div>

          <div className="form-grid">
            {/* Where should this content be posted? */}
            <div className="form-field full">
              <label className="form-label">Where should this content be posted?</label>
              <div className="check-grid">
                <label className="check-card">
                  <input
                    type="checkbox"
                    checked={platforms.Facebook}
                    onChange={() => handlePlatformToggle('Facebook')}
                  />{' '}
                  Facebook
                </label>
                <label className="check-card">
                  <input
                    type="checkbox"
                    checked={platforms.Instagram}
                    onChange={() => handlePlatformToggle('Instagram')}
                  />{' '}
                  Instagram
                </label>
                <label className="check-card">
                  <input
                    type="checkbox"
                    checked={platforms.YouTube}
                    onChange={() => handlePlatformToggle('YouTube')}
                  />{' '}
                  YouTube
                </label>
                <label className="check-card" style={{ opacity: 0.6 }}>
                  <input type="checkbox" disabled checked={false} /> LinkedIn <small>(Phase 2)</small>
                </label>
                <label className="check-card" style={!connectedTwitter ? { border: '1px dashed #cbd5e1' } : {}}>
                  <input
                    type="checkbox"
                    checked={platforms.X}
                    onChange={() => handlePlatformToggle('X')}
                  />{' '}
                  X / Twitter {!connectedTwitter && <small style={{ color: '#b45309' }}>(Not Connected)</small>}
                </label>
              </div>
              {platforms.X && !connectedTwitter && (
                <div style={{ marginTop: '8px', color: '#b45309', fontSize: '12px', fontWeight: '500' }}>
                  ⚠️ X / Twitter is selected but not connected. <a href="/integrations" style={{ color: '#2563eb', textDecoration: 'underline' }}>Connect X now in Integrations</a> to enable publishing.
                </div>
              )}
            </div>

            {/* Internal content title */}
            <div className="form-field full">
              <label htmlFor="postTitle" className="form-label">
                Internal content title
              </label>
              <input
                id="postTitle"
                className="input"
                value={postTitle}
                onChange={(e) => setPostTitle(e.target.value)}
                placeholder="Example: August Wellness Offer"
              />
              <div className="form-help">
                Used by your team to identify the post. It is not always visible to customers.
              </div>
            </div>

            {/* Caption / post message */}
            <div className="form-field full">
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }} className="flex justify-between items-center">
                <label htmlFor="postCaption" className="form-label mb-0" style={{ marginBottom: 0 }}>
                  Caption / post message
                </label>
                <span style={{
                  fontSize: '11px',
                  fontWeight: '600',
                  color: isOverTwitterLimit ? '#dc2626' : (totalLength > 240 ? '#d97706' : '#64748b')
                }}>
                  {platforms.X ? `${totalLength} / 280 chars` : `${postCaption.length} chars`}
                </span>
              </div>
              <textarea
                id="postCaption"
                className="textarea"
                rows={4}
                value={postCaption}
                onChange={(e) => setPostCaption(e.target.value)}
                placeholder="Write the message customers should see"
              />
              {isOverTwitterLimit && (
                <div style={{ color: '#dc2626', fontSize: '11.5px', marginTop: '4px', fontWeight: '500' }}>
                  ⚠️ Exceeds X (Twitter)'s 280-character limit. Please shorten the caption or hashtags.
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
                onChange={(e) => setPostHashtags(e.target.value)}
              />
            </div>

            <div className="form-field">
              <label htmlFor="postCTA" className="form-label">
                Call-to-action
              </label>
              <select
                id="postCTA"
                className="select"
                value={postCTA}
                onChange={(e) => setPostCTA(e.target.value)}
              >
                <option value="Learn More">Learn More</option>
                <option value="Book Now">Book Now</option>
                <option value="Contact Us">Contact Us</option>
                <option value="Send Message">Send Message</option>
                <option value="No CTA">No CTA</option>
              </select>
            </div>

            {/* Image or Video */}
            <div className="form-field full">
              <label className="form-label">Image or video</label>
              <div className="media-drop">
                <strong>Drop media here or click to choose</strong>
                <div className="form-help">Images, videos, multiple media, YouTube thumbnails</div>
                <input
                  type="file"
                  id="mediaFile"
                  accept="image/*,video/*"
                  multiple
                  onChange={handleFileChange}
                  style={{ marginTop: '12px', maxWidth: '100%' }}
                />
                {mediaPreviews.length > 0 && (
                  <div style={{ display: 'flex', gap: '8px', marginTop: '12px', overflowX: 'auto' }}>
                    {mediaPreviews.map((url, idx) => (
                      <img
                        key={idx}
                        src={url}
                        alt="Selected media"
                        style={{ width: '70px', height: '70px', borderRadius: '8px', objectFit: 'cover', border: '1px solid #cbd5e1' }}
                      />
                    ))}
                  </div>
                )}
              </div>
            </div>

            {/* When should it go live & Scheduled date and time */}
            <div className="form-field">
              <label htmlFor="publishType" className="form-label">
                When should it go live?
              </label>
              <select
                id="publishType"
                className="select"
                value={publishType}
                onChange={(e) => setPublishType(e.target.value)}
              >
                <option value="schedule">Schedule for later</option>
                <option value="now">Publish immediately</option>
                <option value="draft">Save as draft</option>
              </select>
            </div>

            {publishType === 'schedule' && (
              <div className="form-field" id="scheduleField">
                <label htmlFor="scheduleAt" className="form-label">
                  Scheduled date and time
                </label>
                <input
                  type="datetime-local"
                  id="scheduleAt"
                  className="input"
                  value={scheduleAt}
                  onChange={(e) => setScheduleAt(e.target.value)}
                />
              </div>
            )}

            {/* Bottom Actions Row */}
            <div className="form-field full">
              <div className="flex justify-between items-center gap-12" style={{ marginTop: '8px' }}>
                <span className="form-help">Platform-specific fields can be edited after selecting YouTube.</span>
                <div className="flex gap-8">
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={submitting}
                    onClick={() => handleSubmit('draft')}
                  >
                    Save draft
                  </button>
                  <button
                    type="button"
                    className="btn btn-primary"
                    disabled={submitting}
                    onClick={() => handleSubmit()}
                  >
                    {submitting ? 'Processing...' : (publishType === 'now' ? 'Publish Now' : 'Publish / Schedule')}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </section>

        {/* Right Preview Panel */}
        <aside className="panel">
          <div className="panel-header">
            <div className="panel-title">
              <h3>Live preview</h3>
              <p>How the content may appear on social media</p>
            </div>
            <span
              className="pill"
              style={{ cursor: 'pointer' }}
              onClick={() =>
                setPreviewPlatform((prev) => {
                  if (prev === 'Facebook') return 'Instagram';
                  if (prev === 'Instagram') return 'YouTube';
                  if (prev === 'YouTube') return 'X';
                  return 'Facebook';
                })
              }
            >
              {previewPlatform}
            </span>
          </div>

          <div className="preview-phone">
            <div className="preview-top"></div>
            <div className="preview-body">
              <div className="preview-profile">
                <div
                  className="initial"
                  style={{
                    background: previewPlatform === 'YouTube' ? '#ff0000' : previewPlatform === 'X' ? '#000000' : '#1877f2',
                    color: '#fff',
                  }}
                >
                  {previewPlatform === 'YouTube'
                    ? (connectedYouTube ? connectedYouTube.channel_name.substring(0, 2).toUpperCase() : 'YT')
                    : previewPlatform === 'X'
                    ? (connectedTwitter ? connectedTwitter.account_name.substring(0, 3).toUpperCase() : 'X')
                    : clientInitials}
                </div>
                <div>
                  <strong style={{ fontSize: '12px' }}>
                    {previewPlatform === 'YouTube'
                      ? (connectedYouTube ? connectedYouTube.channel_name : 'YouTube Channel')
                      : previewPlatform === 'X'
                      ? (connectedTwitter ? connectedTwitter.account_name : 'X Account')
                      : pageDisplayName.toLowerCase().replace(/\s+/g, '_')}
                  </strong>
                  <div className="muted" style={{ fontSize: '10px' }}>
                    {previewPlatform === 'YouTube'
                      ? (connectedYouTube ? `${connectedYouTube.subscriber_count ? connectedYouTube.subscriber_count.toLocaleString() : 0} subscribers` : 'YouTube Channel')
                      : previewPlatform === 'Instagram'
                      ? 'Sponsored'
                      : previewPlatform === 'X'
                      ? 'Just now • X.com'
                      : 'Just now • Public'}
                  </div>
                </div>
              </div>

              {mediaPreviews.length > 0 ? (
                <img
                  src={mediaPreviews[0]}
                  alt="Live Preview"
                  style={{ width: '100%', height: '180px', objectFit: 'cover', borderRadius: '4px', marginBottom: '8px' }}
                />
              ) : (
                <div className="preview-image" id="previewMedia">
                  Media preview area
                </div>
              )}

              <div className="preview-caption" id="previewCaption" style={{ fontSize: '12px', color: '#1e293b', whiteSpace: 'pre-wrap', marginTop: '8px' }}>
                {postCaption || 'Your post caption preview will appear here...'}
              </div>

              <div className="preview-tags" id="previewTags" style={{ fontSize: '11.5px', color: '#2563eb', marginTop: '4px' }}>
                {postHashtags}
              </div>

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
            <p>Upcoming posts for this week</p>
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

      {/* 4. Publishing History & Drafts Section */}
      <section className="panel mt-18" style={{ marginTop: '24px' }}>
        <div className="panel-header">
          <div className="panel-title">
            <h3>Published & scheduled posts history</h3>
            <p>Recent social media posts stored in backend database</p>
          </div>
          <button type="button" className="btn btn-secondary btn-sm" onClick={fetchPageAndData}>
            Refresh List
          </button>
        </div>

        <div className="table-wrap" style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '13.5px' }}>
            <thead>
              <tr style={{ borderBottom: '1.5px solid #e5e7eb', textAlign: 'left', background: '#f9fafb' }}>
                <th style={{ padding: '10px 14px' }}>CONTENT</th>
                <th style={{ padding: '10px 14px' }}>PLATFORM</th>
                <th style={{ padding: '10px 14px' }}>STATUS</th>
                <th style={{ padding: '10px 14px' }}>PUBLISHED / SCHEDULED DATE</th>
                <th style={{ padding: '10px 14px' }}>ACTION</th>
              </tr>
            </thead>
            <tbody>
              {historyPosts.length === 0 ? (
                <tr>
                  <td colSpan={5} style={{ padding: '16px', textAlign: 'center', color: '#6b7280' }}>
                    No posts found in database history.
                  </td>
                </tr>
              ) : (
                historyPosts.map((post) => (
                  <tr key={post.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                    <td style={{ padding: '10px 14px', maxWidth: '280px' }}>
                      <strong style={{ display: 'block', fontSize: '13.5px' }}>
                        {post.content ? (post.content.length > 55 ? post.content.substring(0, 55) + '...' : post.content) : 'Media Content'}
                      </strong>
                    </td>
                    <td style={{ padding: '10px 14px' }}>
                      <span className={`pill ${post.platform_list?.includes('YouTube') || (!post.facebook_page_id && !post.platform_list) ? 'warning' : 'info'}`}>
                        {post.platform_list
                          ? (Array.isArray(post.platform_list) ? post.platform_list.join(', ') : post.platform_list)
                          : (post.facebook_page_id ? 'Facebook' : 'YouTube')}
                      </span>
                    </td>
                    <td style={{ padding: '10px 14px' }}>
                      <span className={`pill ${post.status === 'published' ? 'success' : post.status === 'scheduled' ? 'warning' : 'neutral'}`}>
                        • {post.status}
                      </span>
                    </td>
                    <td style={{ padding: '10px 14px' }}>
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
                            onClick={() => handleRetryPost(post.id)}
                            style={{ padding: '4px 8px', fontSize: '12px' }}
                          >
                            <RotateCw size={12} /> Retry
                          </button>
                        )}
                        <button
                          type="button"
                          className="btn btn-secondary"
                          onClick={() => handleDuplicatePost(post.id)}
                          style={{ padding: '4px 8px', fontSize: '12px' }}
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
      </section>
    </div>
  );
}
