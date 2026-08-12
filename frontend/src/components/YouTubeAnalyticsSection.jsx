import React, { useState, useEffect } from 'react';
import axiosInstance from '../api/axiosInstance';
import {
  Eye,
  ThumbsUp,
  MessageSquare,
  Share2,
  RefreshCw,
  AlertCircle,
  Video,
  Users,
  Calendar,
  ExternalLink,
  CheckCircle2,
  Lock
} from 'lucide-react';

/**
 * Format numbers with compact notation (e.g. 1.2K, 125K, 1.2M)
 */
const formatMetricNumber = (num) => {
  if (num === null || num === undefined) return '0';
  const val = Number(num);
  if (isNaN(val)) return '0';

  if (val >= 1_000_000) {
    return (val / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
  }
  if (val >= 1_000) {
    return (val / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
  }
  return val.toLocaleString();
};

/**
 * Helper to compute date range strings
 */
const getDateRangePresets = () => {
  const today = new Date();
  const formatDate = (d) => d.toISOString().split('T')[0];

  const subDays = (days) => {
    const d = new Date(today);
    d.setDate(d.getDate() - days);
    return formatDate(d);
  };

  const todayStr = formatDate(today);

  return {
    today: { startDate: todayStr, endDate: todayStr, label: 'Today' },
    last7: { startDate: subDays(7), endDate: todayStr, label: 'Last 7 days' },
    last28: { startDate: subDays(28), endDate: todayStr, label: 'Last 28 days' },
    last30: { startDate: subDays(30), endDate: todayStr, label: 'Last 30 days' },
    last90: { startDate: subDays(90), endDate: todayStr, label: 'Last 90 days' },
  };
};

export default function YouTubeAnalyticsSection() {
  const presets = getDateRangePresets();
  const [selectedRangeKey, setSelectedRangeKey] = useState('last28');
  const [customStartDate, setCustomStartDate] = useState(presets.last28.startDate);
  const [customEndDate, setCustomEndDate] = useState(presets.last28.endDate);

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [data, setData] = useState(null);

  const fetchAnalytics = (start, end) => {
    setLoading(true);
    setError(null);

    axiosInstance
      .get('/youtube/analytics/overview', {
        params: {
          start_date: start,
          end_date: end,
        },
      })
      .then((res) => {
        if (res.data) {
          setData(res.data);
        }
      })
      .catch((err) => {
        console.error('Error fetching YouTube analytics overview:', err);
        const msg = err.response?.data?.message || err.message || 'Failed to load YouTube Analytics.';
        setError(msg);
      })
      .finally(() => {
        setLoading(false);
      });
  };

  // Trigger fetch when date range selection changes
  useEffect(() => {
    let start = customStartDate;
    let end = customEndDate;

    if (selectedRangeKey !== 'custom' && presets[selectedRangeKey]) {
      start = presets[selectedRangeKey].startDate;
      end = presets[selectedRangeKey].endDate;
      setCustomStartDate(start);
      setCustomEndDate(end);
    }

    fetchAnalytics(start, end);
  }, [selectedRangeKey]);

  const handleApplyCustomRange = (e) => {
    e.preventDefault();
    if (customStartDate && customEndDate) {
      fetchAnalytics(customStartDate, customEndDate);
    }
  };

  const handleConnectClick = () => {
    const width = 600;
    const height = 700;
    const left = window.screen.width / 2 - width / 2;
    const top = window.screen.height / 2 - height / 2;

    window.open(
      'http://localhost:8000/api/youtube/connect',
      'YouTube OAuth Login',
      `width=${width},height=${height},top=${top},left=${left}`
    );
  };

  const channel = data?.channel;
  const isConnected = data?.connected !== false;
  const isReauthRequired = data?.reauthorization_required === true;
  const views = data?.views ?? data?.data?.views ?? 0;
  const likes = data?.likes ?? data?.data?.likes ?? 0;
  const comments = data?.comments ?? data?.data?.comments ?? 0;
  const shares = data?.shares ?? data?.data?.shares ?? 0;

  return (
    <div className="panel mb-18" style={{ marginBottom: '24px' }}>
      {/* Header & Controls */}
      <div className="panel-header" style={{ flexWrap: 'wrap', gap: '12px' }}>
        <div className="panel-title" style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
          <div
            style={{
              width: '36px',
              height: '36px',
              borderRadius: '8px',
              background: '#fee2e2',
              color: '#dc2626',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontWeight: 'bold',
            }}
          >
            ▶
          </div>
          <div>
            <h3 style={{ margin: 0, fontSize: '16px', display: 'flex', alignItems: 'center', gap: '8px' }}>
              YouTube Performance Overview
              {channel?.channel_name && (
                <span
                  style={{
                    fontSize: '12px',
                    fontWeight: 'normal',
                    color: '#4b5563',
                    background: '#f3f4f6',
                    padding: '2px 8px',
                    borderRadius: '12px',
                  }}
                >
                  {channel.channel_name}
                </span>
              )}
            </h3>
            <p style={{ margin: 0, fontSize: '12px', color: '#6b7280' }}>
              YouTube Analytics API & Data API v3 statistics
            </p>
          </div>
        </div>

        {/* Date Filter & Refresh */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
            <Calendar size={15} color="#6b7280" />
            <select
              className="select"
              value={selectedRangeKey}
              onChange={(e) => setSelectedRangeKey(e.target.value)}
              style={{ width: '140px', padding: '6px 10px', fontSize: '13px' }}
            >
              <option value="today">Today</option>
              <option value="last7">Last 7 days</option>
              <option value="last28">Last 28 days</option>
              <option value="last30">Last 30 days</option>
              <option value="last90">Last 90 days</option>
              <option value="custom">Custom range</option>
            </select>
          </div>

          {selectedRangeKey === 'custom' && (
            <form onSubmit={handleApplyCustomRange} style={{ display: 'flex', gap: '6px', alignItems: 'center' }}>
              <input
                type="date"
                className="input"
                value={customStartDate}
                onChange={(e) => setCustomStartDate(e.target.value)}
                style={{ width: '130px', padding: '4px 8px', fontSize: '12px' }}
              />
              <span style={{ fontSize: '12px', color: '#6b7280' }}>to</span>
              <input
                type="date"
                className="input"
                value={customEndDate}
                onChange={(e) => setCustomEndDate(e.target.value)}
                style={{ width: '130px', padding: '4px 8px', fontSize: '12px' }}
              />
              <button type="submit" className="btn btn-secondary" style={{ padding: '4px 10px', fontSize: '12px' }}>
                Apply
              </button>
            </form>
          )}

          <button
            type="button"
            className="btn btn-outline-white"
            onClick={() => fetchAnalytics(customStartDate, customEndDate)}
            disabled={loading}
            style={{ padding: '6px 10px', display: 'flex', alignItems: 'center', gap: '4px' }}
            title="Refresh YouTube Analytics"
          >
            <RefreshCw size={14} className={loading ? 'spin' : ''} />
          </button>
        </div>
      </div>

      {/* Date Range & Lifetime Distinction Banner (Requirement #14) */}
      {channel && (
        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            background: '#f9fafb',
            border: '1px solid #e5e7eb',
            borderRadius: '8px',
            padding: '8px 14px',
            marginBottom: '16px',
            fontSize: '12px',
            color: '#374151',
            flexWrap: 'wrap',
            gap: '8px',
          }}
        >
          <div>
            <strong style={{ color: '#dc2626' }}>Analytics Period:</strong>{' '}
            {data?.start_date || customStartDate} to {data?.end_date || customEndDate}
          </div>
          <div style={{ display: 'flex', gap: '16px', color: '#4b5563' }}>
            <span style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
              <Users size={14} color="#dc2626" />
              <strong>{formatMetricNumber(channel.subscriber_count)}</strong> Subscribers
            </span>
            <span style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
              <Eye size={14} color="#dc2626" />
              <strong>{formatMetricNumber(channel.lifetime_views)}</strong> Lifetime Total Views
            </span>
            <span style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
              <Video size={14} color="#dc2626" />
              <strong>{formatMetricNumber(channel.video_count)}</strong> Videos
            </span>
          </div>
        </div>
      )}

      {/* Warning/Reauthorization Banner (Requirement #5) */}
      {isReauthRequired && (
        <div
          style={{
            padding: '12px 16px',
            background: '#fffbe6',
            border: '1px solid #ffe58f',
            borderRadius: '8px',
            marginBottom: '16px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '12px',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
            <Lock size={20} color="#d48806" />
            <div>
              <strong style={{ color: '#d48806', fontSize: '13px', display: 'block' }}>
                Reauthorization Required for YouTube Analytics
              </strong>
              <span style={{ fontSize: '12px', color: '#595959' }}>
                Your YouTube channel is connected, but requires permission (<code>yt-analytics.readonly</code>) to load Shares, Views, Likes, and Comments.
              </span>
            </div>
          </div>
          <button
            type="button"
            className="btn btn-primary"
            onClick={handleConnectClick}
            style={{ fontSize: '12px', padding: '6px 14px', whiteSpace: 'nowrap', background: '#d48806', borderColor: '#d48806' }}
          >
            Reconnect YouTube
          </button>
        </div>
      )}

      {/* Error Banner */}
      {error && !isReauthRequired && (
        <div
          style={{
            padding: '12px 16px',
            background: '#fef2f2',
            border: '1px solid #fecaca',
            borderRadius: '8px',
            marginBottom: '16px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            color: '#991b1b',
            fontSize: '13px',
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <AlertCircle size={18} />
            <span>{error}</span>
          </div>
          <button
            type="button"
            className="btn btn-outline-white"
            onClick={() => fetchAnalytics(customStartDate, customEndDate)}
            style={{ fontSize: '12px', padding: '4px 10px' }}
          >
            Retry
          </button>
        </div>
      )}

      {/* Not Connected State */}
      {!isConnected && !loading && (
        <div
          style={{
            padding: '32px 16px',
            textAlign: 'center',
            background: '#f9fafb',
            border: '1px dashed #d1d5db',
            borderRadius: '10px',
          }}
        >
          <div style={{ fontSize: '28px', marginBottom: '8px' }}>▶</div>
          <h4 style={{ margin: '0 0 4px 0', fontSize: '15px', color: '#111827' }}>YouTube Channel Not Connected</h4>
          <p style={{ margin: '0 0 16px 0', fontSize: '13px', color: '#6b7280' }}>
            Connect your YouTube channel to monitor Views, Likes, Comments, and Shares.
          </p>
          <button type="button" className="btn btn-primary" onClick={handleConnectClick}>
            Connect YouTube Channel
          </button>
        </div>
      )}

      {/* Four Metric Cards (Requirement #12) */}
      {(isConnected || loading) && (
        <div
          className="metric-grid"
          style={{
            gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
            opacity: loading ? 0.6 : 1,
            transition: 'opacity 0.2s',
          }}
        >
          {/* Card 1: Views */}
          <div className="metric-card" style={{ borderLeft: '4px solid #dc2626' }}>
            <div className="metric-top">
              <span className="metric-label" style={{ fontWeight: '600', color: '#374151' }}>
                👁 Views
              </span>
              <div className="metric-icon" style={{ background: '#fee2e2' }}>
                <Eye size={20} color="#dc2626" />
              </div>
            </div>
            <div className="metric-value" style={{ fontSize: '24px', color: '#111827' }} title={views.toLocaleString()}>
              {formatMetricNumber(views)}
            </div>
            <div className="metric-foot">
              <span style={{ fontSize: '11px', color: '#6b7280' }}>
                Period Views ({data?.start_date || customStartDate} - {data?.end_date || customEndDate})
              </span>
            </div>
          </div>

          {/* Card 2: Likes */}
          <div className="metric-card" style={{ borderLeft: '4px solid #2563eb' }}>
            <div className="metric-top">
              <span className="metric-label" style={{ fontWeight: '600', color: '#374151' }}>
                👍 Likes
              </span>
              <div className="metric-icon" style={{ background: '#dbeafe' }}>
                <ThumbsUp size={20} color="#2563eb" />
              </div>
            </div>
            <div className="metric-value" style={{ fontSize: '24px', color: '#111827' }} title={likes.toLocaleString()}>
              {formatMetricNumber(likes)}
            </div>
            <div className="metric-foot">
              <span style={{ fontSize: '11px', color: '#6b7280' }}>
                Period Video Likes
              </span>
            </div>
          </div>

          {/* Card 3: Comments */}
          <div className="metric-card" style={{ borderLeft: '4px solid #059669' }}>
            <div className="metric-top">
              <span className="metric-label" style={{ fontWeight: '600', color: '#374151' }}>
                💬 Comments
              </span>
              <div className="metric-icon" style={{ background: '#d1fae5' }}>
                <MessageSquare size={20} color="#059669" />
              </div>
            </div>
            <div className="metric-value" style={{ fontSize: '24px', color: '#111827' }} title={comments.toLocaleString()}>
              {formatMetricNumber(comments)}
            </div>
            <div className="metric-foot">
              <span style={{ fontSize: '11px', color: '#6b7280' }}>
                Period Comments Received
              </span>
            </div>
          </div>

          {/* Card 4: Shares */}
          <div className="metric-card" style={{ borderLeft: '4px solid #7c3aed' }}>
            <div className="metric-top">
              <span className="metric-label" style={{ fontWeight: '600', color: '#374151' }}>
                ↗ Shares
              </span>
              <div className="metric-icon" style={{ background: '#ede9fe' }}>
                <Share2 size={20} color="#7c3aed" />
              </div>
            </div>
            <div className="metric-value" style={{ fontSize: '24px', color: '#111827' }} title={shares.toLocaleString()}>
              {formatMetricNumber(shares)}
            </div>
            <div className="metric-foot">
              <span style={{ fontSize: '11px', color: '#6b7280' }}>
                YouTube Analytics Shares
              </span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
