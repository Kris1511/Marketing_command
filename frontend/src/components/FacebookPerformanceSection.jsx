import React, { useState, useEffect, useCallback, useRef } from 'react';
import axiosInstance from '../api/axiosInstance';
import {
  Download,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Info,
  MoreHorizontal,
  Image as ImageIcon,
  Camera,
  Layers,
  Video,
  FileText,
  Building2,
  RefreshCw,
  AlertCircle
} from 'lucide-react';

// Format Date & Time: "8 Sep 2026", "07:50 PM"
function formatPostDateTimeParts(dateStr) {
  if (!dateStr) return { date: '—', time: '' };
  try {
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return { date: dateStr, time: '' };

    const dateFormatted = d.toLocaleDateString('en-GB', {
      timeZone: 'Asia/Kolkata',
      day: 'numeric',
      month: 'short',
      year: 'numeric'
    }); // e.g. "8 Sep 2026"

    const timeFormatted = d.toLocaleTimeString('en-US', {
      timeZone: 'Asia/Kolkata',
      hour: '2-digit',
      minute: '2-digit',
      hour12: true
    }); // e.g. "07:50 PM"

    return { date: dateFormatted, time: timeFormatted };
  } catch {
    return { date: dateStr, time: '' };
  }
}

// Interactive header tooltip using existing Info icon
function HeaderInfoTooltip({ text }) {
  const [show, setShow] = useState(false);

  return (
    <span
      style={{
        position: 'relative',
        display: 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: 'pointer',
      }}
      onMouseEnter={() => setShow(true)}
      onMouseLeave={() => setShow(false)}
      onClick={(e) => {
        e.stopPropagation();
        setShow((prev) => !prev);
      }}
      title={text}
    >
      <Info
        size={12}
        color={show ? '#1d4ed8' : '#94a3b8'}
        style={{ transition: 'color 0.15s ease' }}
      />
      {show && (
        <span
          role="tooltip"
          style={{
            position: 'absolute',
            top: 'calc(100% + 6px)',
            left: '50%',
            transform: 'translateX(-50%)',
            background: '#0f172a',
            color: '#f8fafc',
            padding: '7px 11px',
            borderRadius: '6px',
            fontSize: '11.5px',
            fontWeight: '400',
            lineHeight: '1.35',
            width: '220px',
            textAlign: 'left',
            boxShadow: '0 4px 14px rgba(0, 0, 0, 0.18)',
            zIndex: 9999,
            pointerEvents: 'none',
            whiteSpace: 'normal',
          }}
        >
          {text}
          <span
            style={{
              position: 'absolute',
              bottom: '100%',
              left: '50%',
              transform: 'translateX(-50%)',
              borderWidth: '5px',
              borderStyle: 'solid',
              borderColor: 'transparent transparent #0f172a transparent',
            }}
          />
        </span>
      )}
    </span>
  );
}

export default function FacebookPerformanceSection({
  workspaceId,
  startDate,
  endDate,
  refreshTrigger,
}) {
  const [posts, setPosts] = useState([]);
  const [postPage, setPostPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalPosts, setTotalPosts] = useState(0);
  const [hasPrev, setHasPrev] = useState(false);
  const [hasNext, setHasNext] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [filterMode, setFilterMode] = useState('all'); // 'all' | 'date_range'

  const activeRequest = useRef(null);

  // Fetch Facebook Posts strictly for selected workspace and date filter (5 at a time)
  const fetchPosts = useCallback(async (page = 1) => {
    if (!workspaceId) return;
    activeRequest.current?.abort();
    const controller = new AbortController();
    activeRequest.current = controller;
    setLoading(true);
    setError('');

    try {
      const params = {
        workspace_id: workspaceId,
        page,
        per_page: 5,
      };

      if (filterMode === 'date_range' && startDate && endDate) {
        params.start_date = startDate;
        params.end_date = endDate;
      } else {
        params.all_posts = true;
      }

      const res = await axiosInstance.get('/facebook/posts', {
        params,
        signal: controller.signal
      });

      if (controller.signal.aborted) return;

      if (res.data && res.data.success) {
        setPosts(res.data.data || []);
        setPostPage(res.data.current_page || page);
        setTotalPages(res.data.total_pages || 1);
        setTotalPosts(res.data.total || 0);
        setHasPrev(Boolean(res.data.has_prev));
        setHasNext(Boolean(res.data.has_next));
      } else {
        setError(res.data?.message || 'Failed to load Facebook posts.');
      }
    } catch (err) {
      if (controller.signal.aborted) return;
      console.error('[FacebookContent] Fetch posts error:', err);
      setError(err.response?.data?.message || 'Unable to load Facebook posts.');
    } finally {
      if (!controller.signal.aborted) setLoading(false);
    }
  }, [workspaceId, startDate, endDate, filterMode]);

  // When date range, filterMode, or workspace changes, reset pagination to page 1 and fetch
  useEffect(() => {
    setPostPage(1);
    fetchPosts(1);
    return () => activeRequest.current?.abort();
  }, [fetchPosts]);

  // When refresh is triggered from parent
  useEffect(() => {
    if (refreshTrigger) {
      fetchPosts(postPage);
    }
  }, [refreshTrigger, fetchPosts, postPage]);

  // Page change handler
  const handlePageChange = (newPage) => {
    if (newPage < 1 || newPage > totalPages || loading) return;
    setPostPage(newPage);
    fetchPosts(newPage);
  };

  // Export posts to CSV
  const handleExportCSV = () => {
    if (!posts || posts.length === 0) return;
    let csvContent = 'data:text/csv;charset=utf-8,Post,Type,Page,Date Published,Views,Reach,Followers,Likes,Comments\n';
    posts.forEach((p) => {
      const cleanContent = (p.content || '').replace(/[\r\n]+/g, ' ').replace(/"/g, '""');
      const { date, time } = formatPostDateTimeParts(p.published_at);
      const dateStr = `${date} ${time}`.trim();
      const views = p.views_count ?? 0;
      const reach = p.reach_count ?? 0;
      const followers = p.followers_count !== null && p.followers_count !== undefined ? p.followers_count : 'N/A';
      const likes = p.likes_count ?? 0;
      const comments = p.comments_count ?? 0;
      csvContent += `"${cleanContent}","${p.formatted_type || 'Photo'}","${p.page_name || 'Facebook Page'}","${dateStr}",${views},${reach},"${followers}",${likes},${comments}\n`;
    });
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `Facebook_Content_${startDate || 'all'}_${endDate || 'all'}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  return (
    <div style={{
      background: '#ffffff',
      border: '1px solid #e2e8f0',
      borderRadius: '12px',
      boxShadow: '0 1px 3px rgba(0,0,0,0.02)',
      marginBottom: '28px',
      overflow: 'hidden',
    }}>
      {/* Card Header: Facebook Icon, Title, Subtitle, and Export Button */}
      <div style={{
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        padding: '18px 24px',
        borderBottom: '1px solid #f1f5f9',
        flexWrap: 'wrap',
        gap: '12px',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
          <div style={{
            width: '38px',
            height: '38px',
            borderRadius: '50%',
            background: '#1877f2',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            color: '#ffffff',
            flexShrink: 0,
            boxShadow: '0 2px 4px rgba(24,119,242,0.25)',
          }}>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
              <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
          </div>
          <div>
            <h3 style={{
              margin: '0 0 2px 0',
              fontSize: '17px',
              fontWeight: '700',
              color: '#0f172a',
              letterSpacing: '-0.01em',
            }}>
              Facebook Content
            </h3>
            <p style={{
              margin: 0,
              fontSize: '12.5px',
              color: '#64748b',
            }}>
              Latest posts and their performance
            </p>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }}>
          {/* Scope Selector: All Content vs Filtered by Date */}
          <div style={{
            display: 'inline-flex',
            alignItems: 'center',
            background: '#f8fafc',
            border: '1px solid #cbd5e1',
            borderRadius: '6px',
            padding: '2px',
          }}>
            <button
              type="button"
              id="fb-content-all-btn"
              onClick={() => { setFilterMode('all'); setPostPage(1); }}
              style={{
                padding: '5px 12px',
                fontSize: '12px',
                fontWeight: filterMode === 'all' ? '700' : '500',
                borderRadius: '5px',
                border: 'none',
                background: filterMode === 'all' ? '#1877f2' : 'transparent',
                color: filterMode === 'all' ? '#ffffff' : '#475569',
                cursor: 'pointer',
                transition: 'all 0.15s ease',
              }}
            >
              All Content ({filterMode === 'all' ? totalPosts : 137})
            </button>
            <button
              type="button"
              id="fb-content-daterange-btn"
              onClick={() => { setFilterMode('date_range'); setPostPage(1); }}
              style={{
                padding: '5px 12px',
                fontSize: '12px',
                fontWeight: filterMode === 'date_range' ? '700' : '500',
                borderRadius: '5px',
                border: 'none',
                background: filterMode === 'date_range' ? '#1877f2' : 'transparent',
                color: filterMode === 'date_range' ? '#ffffff' : '#475569',
                cursor: 'pointer',
                transition: 'all 0.15s ease',
              }}
            >
              {startDate && endDate ? `${startDate} to ${endDate}` : 'Filtered by Date'}
            </button>
          </div>

          {/* Export Button matching screenshot */}
          <button
            type="button"
            onClick={handleExportCSV}
            title="Export posts performance data"
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '6px',
              padding: '6px 14px',
              fontSize: '12.5px',
              fontWeight: '500',
              color: '#1e293b',
              background: '#ffffff',
              border: '1px solid #cbd5e1',
              borderRadius: '6px',
              cursor: 'pointer',
              boxShadow: '0 1px 2px rgba(0,0,0,0.03)',
              transition: 'all 0.15s ease',
            }}
            onMouseOver={(e) => { e.currentTarget.style.borderColor = '#94a3b8'; e.currentTarget.style.background = '#f8fafc'; }}
            onMouseOut={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#ffffff'; }}
          >
            <Download size={13} color="#475569" />
            <span>Export</span>
            <ChevronDown size={13} color="#64748b" />
          </button>
        </div>
      </div>

      {/* Error Message */}
      {error && (
        <div style={{
          background: '#fef2f2',
          border: '1px solid #fecaca',
          borderRadius: '8px',
          padding: '12px 16px',
          color: '#b91c1c',
          fontSize: '13px',
          margin: '16px 24px',
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
        }}>
          <AlertCircle size={16} />
          <span>{error}</span>
        </div>
      )}

      {/* Table Container */}
      <div style={{ overflowX: 'auto', width: '100%' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', minWidth: '850px' }}>
          <thead>
            <tr style={{ borderBottom: '1px solid #e2e8f0', background: '#ffffff' }}>
              <th style={{ padding: '14px 24px', fontSize: '12.5px', fontWeight: '600', color: '#334155', width: '38%' }}>
                Post
              </th>
              <th style={{ padding: '14px 16px', fontSize: '12.5px', fontWeight: '600', color: '#334155', width: '17%' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
                  Date Published
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#2563eb" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <polyline points="19 12 12 19 5 12"></polyline>
                  </svg>
                </span>
              </th>
              <th style={{ padding: '14px 12px', fontSize: '12.5px', fontWeight: '600', color: '#334155', textAlign: 'center', width: '8%' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '4px' }}>
                  Views
                  <HeaderInfoTooltip text="Views are available from Meta for video posts. Static photo and carousel posts do not have a public view count." />
                </span>
              </th>
              <th style={{ padding: '14px 12px', fontSize: '12.5px', fontWeight: '600', color: '#334155', textAlign: 'center', width: '8%' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '4px' }}>
                  Reach
                  <HeaderInfoTooltip text="Post-level reach is not available with the current Meta API access." />
                </span>
              </th>
              <th style={{ padding: '14px 12px', fontSize: '12.5px', fontWeight: '600', color: '#334155', textAlign: 'center', width: '9%' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '4px' }}>
                  Followers
                  <HeaderInfoTooltip text="Reliable organic post-level follower attribution is not available through the current Meta API." />
                </span>
              </th>
              <th style={{ padding: '14px 12px', fontSize: '12.5px', fontWeight: '600', color: '#334155', textAlign: 'center', width: '8%' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', cursor: 'help' }} title="Post reactions and likes count from Meta Graph API">
                  Likes
                  <Info size={12} color="#94a3b8" />
                </span>
              </th>
              <th style={{ padding: '14px 12px', fontSize: '12.5px', fontWeight: '600', color: '#334155', textAlign: 'center', width: '8%' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', cursor: 'help' }} title="Post comments count from Meta Graph API">
                  Comments
                  <Info size={12} color="#94a3b8" />
                </span>
              </th>
              <th style={{ padding: '14px 20px', width: '5%', textAlign: 'center' }}></th>
            </tr>
          </thead>

          <tbody>
            {loading ? (
              <tr>
                <td colSpan={8} style={{ padding: '40px 24px', textAlign: 'center', color: '#64748b' }}>
                  <RefreshCw size={22} className="spin" style={{ margin: '0 auto 8px auto', display: 'block', color: '#1877f2' }} />
                  <span style={{ fontSize: '13px' }}>Loading Facebook posts...</span>
                </td>
              </tr>
            ) : posts.length === 0 ? (
              <tr>
                <td colSpan={8} style={{ padding: '40px 24px', textAlign: 'center', color: '#64748b' }}>
                  No Facebook posts found for the selected date range.
                </td>
              </tr>
            ) : (
              posts.map((post) => {
                const { date, time } = formatPostDateTimeParts(post.published_at);
                const isCarousel = post.formatted_type === 'Carousel' || post.post_type === 'multi_image';
                const isVideo = post.formatted_type === 'Video' || post.post_type === 'video';

                return (
                  <tr
                    key={post.id}
                    style={{
                      borderBottom: '1px solid #f1f5f9',
                      transition: 'background 0.1s ease',
                    }}
                    onMouseOver={(e) => { e.currentTarget.style.background = '#f8fafc'; }}
                    onMouseOut={(e) => { e.currentTarget.style.background = 'transparent'; }}
                  >
                    {/* Post Column: Thumbnail + Caption + Type / Page */}
                    <td style={{ padding: '14px 24px' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                        {/* Thumbnail */}
                        <div style={{
                          width: '46px',
                          height: '46px',
                          borderRadius: '6px',
                          overflow: 'hidden',
                          background: '#f1f5f9',
                          border: '1px solid #e2e8f0',
                          flexShrink: 0,
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                        }}>
                          {post.image_url ? (
                            <img
                              src={post.image_url}
                              alt=""
                              style={{ width: '100%', height: '100%', objectFit: 'cover' }}
                              onError={(e) => {
                                e.currentTarget.style.display = 'none';
                                if (e.currentTarget.nextSibling) {
                                  e.currentTarget.nextSibling.style.display = 'flex';
                                }
                              }}
                            />
                          ) : null}
                          <div style={{
                            display: post.image_url ? 'none' : 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            width: '100%',
                            height: '100%',
                            color: '#94a3b8'
                          }}>
                            {isVideo ? <Video size={18} /> : isCarousel ? <Layers size={18} /> : <Camera size={18} />}
                          </div>
                        </div>

                        {/* Title & Type / Page */}
                        <div style={{ minWidth: 0, flex: 1 }}>
                          <div
                            style={{
                              fontWeight: '600',
                              fontSize: '13.5px',
                              color: '#0f172a',
                              lineHeight: '1.3',
                              maxWidth: '340px',
                              overflow: 'hidden',
                              textOverflow: 'ellipsis',
                              whiteSpace: 'nowrap',
                            }}
                            title={post.content || ''}
                          >
                            {post.content ? post.content.replace(/[\r\n]+/g, ' ') : 'Media post'}
                          </div>

                          <div style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: '6px',
                            marginTop: '3px',
                            fontSize: '12px',
                            color: '#64748b'
                          }}>
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: '3px' }}>
                              {isVideo ? (
                                <Video size={12} color="#64748b" />
                              ) : isCarousel ? (
                                <Layers size={12} color="#64748b" />
                              ) : (
                                <Camera size={12} color="#64748b" />
                              )}
                              <span>{post.formatted_type || 'Photo'}</span>
                            </span>

                            <span>•</span>

                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: '4px' }}>
                              <span style={{
                                width: '12px',
                                height: '12px',
                                borderRadius: '50%',
                                background: '#dc2626',
                                display: 'inline-flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                color: '#ffffff',
                                fontSize: '8px',
                                fontWeight: 'bold',
                              }}>
                                R
                              </span>
                              <span>{post.page_name || 'Redmind Technologies'}</span>
                            </span>
                          </div>
                        </div>
                      </div>
                    </td>

                    {/* Date Published: Date on top, Time on bottom */}
                    <td style={{ padding: '14px 16px' }}>
                      <div style={{ fontWeight: '500', fontSize: '13px', color: '#0f172a', lineHeight: '1.2' }}>
                        {date}
                      </div>
                      {time && (
                        <div style={{ fontSize: '12px', color: '#64748b', marginTop: '2px' }}>
                          {time}
                        </div>
                      )}
                    </td>

                    {/* Views */}
                    <td style={{ padding: '14px 12px', textAlign: 'center', fontSize: '13.5px', color: '#334155' }}>
                      {post.views_count !== null && post.views_count !== undefined ? (
                        post.views_count
                      ) : (
                        <span
                          title="Views are available from Meta for video posts. Static photo and carousel posts do not have a public view count."
                          style={{ cursor: 'help', color: '#94a3b8' }}
                        >
                          —
                        </span>
                      )}
                    </td>

                    {/* Reach */}
                    <td style={{ padding: '14px 12px', textAlign: 'center', fontSize: '13.5px', color: '#334155' }}>
                      {post.reach_count !== null && post.reach_count !== undefined ? (
                        post.reach_count
                      ) : (
                        <span
                          title="Post-level reach is not available with the current Meta API access."
                          style={{ cursor: 'help', color: '#94a3b8' }}
                        >
                          —
                        </span>
                      )}
                    </td>

                    {/* Followers: Unavailable state as Meta Graph API doesn't support post-level attribution */}
                    <td style={{ padding: '14px 12px', textAlign: 'center', fontSize: '13.5px', color: '#64748b' }}>
                      {post.followers_count !== null && post.followers_count !== undefined ? (
                        post.followers_count
                      ) : (
                        <span
                          title="Reliable organic post-level follower attribution is not available through the current Meta API."
                          style={{ cursor: 'help', color: '#94a3b8' }}
                        >
                          —
                        </span>
                      )}
                    </td>

                    {/* Likes */}
                    <td style={{ padding: '14px 12px', textAlign: 'center', fontSize: '13.5px', color: '#334155' }}>
                      {post.likes_count ?? 0}
                    </td>

                    {/* Comments */}
                    <td style={{ padding: '14px 12px', textAlign: 'center', fontSize: '13.5px', color: '#334155' }}>
                      {post.comments_count ?? 0}
                    </td>

                    {/* Action Button: Pill with 3 dots */}
                    <td style={{ padding: '14px 20px', textAlign: 'center' }}>
                      <button
                        type="button"
                        style={{
                          display: 'inline-flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          width: '32px',
                          height: '26px',
                          borderRadius: '6px',
                          border: '1px solid #e2e8f0',
                          background: '#ffffff',
                          color: '#64748b',
                          cursor: 'pointer',
                          transition: 'all 0.15s ease',
                        }}
                        onMouseOver={(e) => { e.currentTarget.style.borderColor = '#cbd5e1'; e.currentTarget.style.background = '#f8fafc'; }}
                        onMouseOut={(e) => { e.currentTarget.style.borderColor = '#e2e8f0'; e.currentTarget.style.background = '#ffffff'; }}
                        title="Post options"
                      >
                        <MoreHorizontal size={14} />
                      </button>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {/* Card Footer: Pagination Controls matching screenshot */}
      <div style={{
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        padding: '16px 24px',
        borderTop: '1px solid #f1f5f9',
        flexWrap: 'wrap',
        gap: '12px',
      }}>
        {/* Left: Showing X-Y of Z posts */}
        <div id="fb-posts-count-info" style={{ fontSize: '13px', color: '#64748b' }}>
          {totalPosts > 0 ? (
            <>
              Showing <strong>{(postPage - 1) * 5 + 1}</strong>–<strong>{Math.min(postPage * 5, totalPosts)}</strong> of <strong>{totalPosts}</strong> posts
            </>
          ) : (
            'Showing 0 posts'
          )}
        </div>

        {/* Right: < Previous | 1 / 4 | Next > */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
          {/* Previous Button */}
          <button
            id="fb-posts-prev-btn"
            type="button"
            disabled={postPage <= 1 || loading}
            onClick={() => handlePageChange(postPage - 1)}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '4px',
              padding: '6px 14px',
              borderRadius: '6px',
              fontSize: '13px',
              fontWeight: '600',
              border: '1px solid',
              borderColor: (postPage <= 1 || loading) ? '#e2e8f0' : '#cbd5e1',
              background: (postPage <= 1 || loading) ? '#f8fafc' : '#ffffff',
              color: (postPage <= 1 || loading) ? '#94a3b8' : '#334155',
              cursor: (postPage <= 1 || loading) ? 'not-allowed' : 'pointer',
              transition: 'all 0.15s ease',
            }}
          >
            <ChevronLeft size={14} />
            <span>Previous</span>
          </button>

          {/* Page Badge: "1 / 4" */}
          <div
            id="fb-posts-page-badge"
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: '1px solid #e2e8f0',
              background: '#ffffff',
              color: '#0f172a',
              fontSize: '13px',
              fontWeight: '700',
              minWidth: '58px',
              textAlign: 'center',
            }}
          >
            {postPage} / {totalPages}
          </div>

          {/* Next Button */}
          <button
            id="fb-posts-next-btn"
            type="button"
            disabled={!hasNext || postPage >= totalPages || loading}
            onClick={() => handlePageChange(postPage + 1)}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: '4px',
              padding: '6px 16px',
              borderRadius: '6px',
              fontSize: '13px',
              fontWeight: '600',
              border: '1px solid',
              borderColor: (!hasNext || postPage >= totalPages || loading) ? '#e2e8f0' : '#bfdbfe',
              background: (!hasNext || postPage >= totalPages || loading) ? '#f8fafc' : '#ffffff',
              color: (!hasNext || postPage >= totalPages || loading) ? '#94a3b8' : '#2563eb',
              cursor: (!hasNext || postPage >= totalPages || loading) ? 'not-allowed' : 'pointer',
              boxShadow: (!hasNext || postPage >= totalPages || loading) ? 'none' : '0 1px 2px rgba(37,99,235,0.06)',
              transition: 'all 0.15s ease',
            }}
          >
            <span>Next</span>
            <ChevronRight size={14} />
          </button>
        </div>
      </div>
    </div>
  );
}
