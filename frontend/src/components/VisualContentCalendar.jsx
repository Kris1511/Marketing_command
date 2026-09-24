import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
  Calendar as CalendarIcon,
  ChevronLeft,
  ChevronRight,
  Clock,
  CheckCircle2,
  AlertCircle,
  Layers,
  Image as ImageIcon,
  Video,
  ExternalLink,
  Plus,
  Filter,
  Loader2,
  Sparkles,
} from 'lucide-react';
import axiosInstance from '../api/axiosInstance';
import { useWorkspace } from '../context/WorkspaceContext';

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function VisualContentCalendar({ onSelectPost, onNewPostClick }) {
  const { selectedWorkspaceId, workspaces } = useWorkspace();
  const [currentDate, setCurrentDate] = useState(new Date());
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(false);
  const [filterPlatform, setFilterPlatform] = useState('all');
  const [filterStatus, setFilterStatus] = useState('all');

  const year = currentDate.getFullYear();
  const month = currentDate.getMonth(); // 0 - 11
  const monthString = `${year}-${String(month + 1).padStart(2, '0')}`;

  // Fetch calendar posts
  const fetchCalendar = useCallback(async () => {
    setLoading(true);
    try {
      const res = await axiosInstance.get('/publishing/calendar', {
        params: {
          workspace_id: selectedWorkspaceId || 0,
          month: monthString,
        },
      });
      if (res.data?.success && Array.isArray(res.data?.events)) {
        setEvents(res.data.events);
      }
    } catch (err) {
      console.error('Failed to load calendar events', err);
    } finally {
      setLoading(false);
    }
  }, [selectedWorkspaceId, monthString]);

  useEffect(() => {
    fetchCalendar();
  }, [fetchCalendar]);

  // Navigate months
  const handlePrevMonth = () => {
    setCurrentDate(new Date(year, month - 1, 1));
  };

  const handleNextMonth = () => {
    setCurrentDate(new Date(year, month + 1, 1));
  };

  const handleToday = () => {
    setCurrentDate(new Date());
  };

  // Calendar grid math
  const firstDayIndex = new Date(year, month, 1).getDay();
  const totalDaysInMonth = new Date(year, month + 1, 0).getDate();
  const prevMonthDays = new Date(year, month, 0).getDate();

  const calendarDays = useMemo(() => {
    const days = [];

    // Prev month padding
    for (let i = firstDayIndex - 1; i >= 0; i--) {
      const d = prevMonthDays - i;
      const dateStr = `${month === 0 ? year - 1 : year}-${String(month === 0 ? 12 : month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
      days.push({ day: d, dateStr, isCurrentMonth: false });
    }

    // Current month days
    for (let d = 1; d <= totalDaysInMonth; d++) {
      const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
      days.push({ day: d, dateStr, isCurrentMonth: true });
    }

    // Next month padding (to complete 35 or 42 grid cells)
    const remaining = (7 - (days.length % 7)) % 7;
    for (let d = 1; d <= remaining; d++) {
      const dateStr = `${month === 11 ? year + 1 : year}-${String(month === 11 ? 1 : month + 2).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
      days.push({ day: d, dateStr, isCurrentMonth: false });
    }

    return days;
  }, [year, month, firstDayIndex, totalDaysInMonth, prevMonthDays]);

  // Group events by date string "YYYY-MM-DD"
  const eventsByDate = useMemo(() => {
    const map = {};
    events.forEach((ev) => {
      if (!ev.date) return;
      const dateKey = ev.date.substring(0, 10);
      if (!map[dateKey]) map[dateKey] = [];

      // Filter check
      if (filterPlatform !== 'all' && !ev.platforms?.includes(filterPlatform)) return;
      if (filterStatus !== 'all' && ev.status !== filterStatus) return;

      map[dateKey].push(ev);
    });
    return map;
  }, [events, filterPlatform, filterStatus]);

  const monthName = currentDate.toLocaleString('default', { month: 'long', year: 'numeric' });
  const todayStr = new Date().toISOString().substring(0, 10);

  return (
    <div style={{ background: '#ffffff', borderRadius: '14px', border: '1px solid #e2e8f0', overflow: 'hidden' }}>
      {/* Calendar Header */}
      <div
        style={{
          padding: '16px 20px',
          borderBottom: '1px solid #e2e8f0',
          display: 'flex',
          flexWrap: 'wrap',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: '12px',
          background: '#f8fafc',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
            <button
              onClick={handlePrevMonth}
              style={{
                background: '#ffffff',
                border: '1px solid #cbd5e1',
                borderRadius: '8px',
                padding: '6px 10px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
              }}
            >
              <ChevronLeft size={16} />
            </button>
            <button
              onClick={handleNextMonth}
              style={{
                background: '#ffffff',
                border: '1px solid #cbd5e1',
                borderRadius: '8px',
                padding: '6px 10px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
              }}
            >
              <ChevronRight size={16} />
            </button>
            <button
              onClick={handleToday}
              style={{
                background: '#ffffff',
                border: '1px solid #cbd5e1',
                borderRadius: '8px',
                padding: '6px 12px',
                fontSize: '12.5px',
                fontWeight: '600',
                cursor: 'pointer',
                color: '#334155',
              }}
            >
              Today
            </button>
          </div>
          <h3 style={{ margin: 0, fontSize: '18px', fontWeight: '700', color: '#0f172a' }}>
            {monthName}
          </h3>
          {loading && <Loader2 size={16} className="animate-spin" color="#2563eb" />}
        </div>

        {/* Filters & Actions */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
          <select
            value={filterPlatform}
            onChange={(e) => setFilterPlatform(e.target.value)}
            style={{
              padding: '6px 10px',
              borderRadius: '8px',
              border: '1px solid #cbd5e1',
              fontSize: '12.5px',
              background: '#ffffff',
              color: '#334155',
            }}
          >
            <option value="all">All Platforms</option>
            <option value="facebook">Facebook</option>
            <option value="instagram">Instagram</option>
            <option value="youtube">YouTube</option>
          </select>

          <select
            value={filterStatus}
            onChange={(e) => setFilterStatus(e.target.value)}
            style={{
              padding: '6px 10px',
              borderRadius: '8px',
              border: '1px solid #cbd5e1',
              fontSize: '12.5px',
              background: '#ffffff',
              color: '#334155',
            }}
          >
            <option value="all">All Statuses</option>
            <option value="scheduled">Scheduled</option>
            <option value="published">Published</option>
            <option value="draft">Drafts</option>
          </select>

          {onNewPostClick && (
            <button
              onClick={onNewPostClick}
              style={{
                background: '#2563eb',
                color: '#ffffff',
                border: 'none',
                padding: '6px 14px',
                borderRadius: '8px',
                fontSize: '12.5px',
                fontWeight: '700',
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                cursor: 'pointer',
              }}
            >
              <Plus size={14} /> Schedule Post
            </button>
          )}
        </div>
      </div>

      {/* Weekday Header */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(7, 1fr)',
          background: '#f1f5f9',
          borderBottom: '1px solid #e2e8f0',
          textAlign: 'center',
          fontWeight: '700',
          fontSize: '12px',
          color: '#475569',
          padding: '8px 0',
        }}
      >
        {DAYS.map((d) => (
          <div key={d}>{d}</div>
        ))}
      </div>

      {/* Month Days Grid */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(7, 1fr)',
          gridAutoRows: 'minmax(110px, auto)',
          background: '#e2e8f0',
          gap: '1px',
        }}
      >
        {calendarDays.map((cell, idx) => {
          const isToday = cell.dateStr === todayStr;
          const dayEvents = eventsByDate[cell.dateStr] || [];

          return (
            <div
              key={idx}
              style={{
                background: cell.isCurrentMonth ? '#ffffff' : '#f8fafc',
                padding: '6px 8px',
                display: 'flex',
                flexDirection: 'column',
                minHeight: '110px',
                opacity: cell.isCurrentMonth ? 1 : 0.65,
              }}
            >
              {/* Date Header */}
              <div
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  marginBottom: '4px',
                }}
              >
                <span
                  style={{
                    fontSize: '12px',
                    fontWeight: isToday ? '800' : '600',
                    color: isToday ? '#ffffff' : cell.isCurrentMonth ? '#1e293b' : '#94a3b8',
                    background: isToday ? '#2563eb' : 'transparent',
                    borderRadius: isToday ? '50%' : '0',
                    width: isToday ? '22px' : 'auto',
                    height: isToday ? '22px' : 'auto',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                  }}
                >
                  {cell.day}
                </span>

                {dayEvents.length > 0 && (
                  <span style={{ fontSize: '10px', color: '#64748b', fontWeight: '700' }}>
                    {dayEvents.length} post{dayEvents.length > 1 ? 's' : ''}
                  </span>
                )}
              </div>

              {/* Event Cards */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', flex: 1, overflowY: 'auto' }}>
                {dayEvents.map((ev) => {
                  const isScheduled = ev.status === 'scheduled';
                  const isPublished = ev.status === 'published';
                  const bg = isScheduled ? '#eff6ff' : isPublished ? '#f0fdf4' : '#f8fafc';
                  const border = isScheduled ? '#bfdbfe' : isPublished ? '#bbf7d0' : '#e2e8f0';
                  const textColor = isScheduled ? '#1d4ed8' : isPublished ? '#15803d' : '#475569';

                  return (
                    <div
                      key={ev.id}
                      onClick={() => onSelectPost && onSelectPost(ev)}
                      style={{
                        padding: '4px 6px',
                        borderRadius: '6px',
                        background: bg,
                        border: `1px solid ${border}`,
                        fontSize: '11px',
                        cursor: 'pointer',
                        transition: 'transform 0.1s ease',
                      }}
                      title={ev.content}
                    >
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '4px' }}>
                        <span style={{ fontWeight: '700', color: textColor, textTransform: 'capitalize', fontSize: '10.5px' }}>
                          {ev.platforms?.join(', ') || 'Post'}
                        </span>
                        <span
                          style={{
                            fontSize: '9.5px',
                            fontWeight: '600',
                            padding: '1px 4px',
                            borderRadius: '4px',
                            background: isPublished ? '#dcfce7' : isScheduled ? '#dbeafe' : '#e2e8f0',
                            color: textColor,
                          }}
                        >
                          {ev.status}
                        </span>
                      </div>
                      <p
                        style={{
                          margin: '2px 0 0 0',
                          color: '#334155',
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                          whiteSpace: 'nowrap',
                          fontSize: '11px',
                        }}
                      >
                        {ev.title}
                      </p>
                    </div>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
