import React, { useState, useEffect, useCallback, useRef } from "react";
import axiosInstance from "../api/axiosInstance";
import {
  Users,
  ThumbsUp,
  MessageCircle,
  FileText,
  RefreshCw,
  AlertCircle,
  ChevronLeft,
  ChevronRight,
  Info,
  Video,
  Camera,
  Layers,
  Film,
} from "lucide-react";

function formatNum(n) {
  if (n === null || n === undefined || n === "") return "—";
  const num = Number(n);
  if (isNaN(num)) return "—";
  if (num >= 1000000) return (num / 1000000).toFixed(1) + "M";
  if (num >= 1000) return (num / 1000).toFixed(1) + "K";
  return num.toLocaleString();
}

function formatPostDateTimeParts(dateStr) {
  if (!dateStr) return { date: "—", time: "" };
  try {
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return { date: dateStr, time: "" };
    const dateFormatted = d.toLocaleDateString("en-GB", {
      timeZone: "Asia/Kolkata",
      day: "numeric",
      month: "short",
      year: "numeric",
    });
    const timeFormatted = d.toLocaleTimeString("en-US", {
      timeZone: "Asia/Kolkata",
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
    });
    return { date: dateFormatted, time: timeFormatted };
  } catch (e) {
    return { date: dateStr, time: "" };
  }
}

function Tooltip({ text }) {
  const [show, setShow] = useState(false);
  return (
    <span
      style={{ position: "relative", display: "inline-flex", alignItems: "center", cursor: "pointer" }}
      onMouseEnter={() => setShow(true)}
      onMouseLeave={() => setShow(false)}
    >
      <Info size={12} color={show ? "#1d4ed8" : "#94a3b8"} style={{ transition: "color 0.15s" }} />
      {show && (
        <span
          role="tooltip"
          style={{
            position: "absolute",
            top: "calc(100% + 6px)",
            left: "50%",
            transform: "translateX(-50%)",
            background: "#0f172a",
            color: "#f8fafc",
            padding: "7px 11px",
            borderRadius: "6px",
            fontSize: "11.5px",
            lineHeight: "1.4",
            width: "220px",
            textAlign: "left",
            boxShadow: "0 4px 14px rgba(0,0,0,0.18)",
            zIndex: 9999,
            pointerEvents: "none",
            whiteSpace: "normal",
          }}
        >
          {text}
          <span style={{
            position: "absolute", bottom: "100%", left: "50%",
            transform: "translateX(-50%)", borderWidth: "5px",
            borderStyle: "solid", borderColor: "transparent transparent #0f172a transparent",
          }} />
        </span>
      )}
    </span>
  );
}

function BarChart({ labels, counts, accentColor = "#2563eb" }) {
  if (!labels || labels.length === 0) {
    return (
      <div style={{ display: "flex", alignItems: "center", justifyContent: "center", height: "160px", color: "#94a3b8", fontSize: "13px" }}>
        No posts in this date range
      </div>
    );
  }
  const maxVal = Math.max(...counts, 1);
  const viewW = 540;
  const chartH = 160;
  const barAreaH = chartH - 26;
  const n = labels.length;
  const padX = 14;
  const usableW = viewW - padX * 2;
  const slotW = usableW / n;
  const barW = Math.max(2.5, Math.min(20, slotW - (slotW > 6 ? 2 : 0.5)));

  // Pick ~5 to 6 legible date labels across any range (prevents text overlapping)
  const targetLabelCount = 6;
  const labelStep = n <= 8 ? 1 : Math.ceil(n / targetLabelCount);

  return (
    <svg
      viewBox={`0 0 ${viewW} ${chartH}`}
      style={{ width: "100%", height: "160px", overflow: "visible" }}
    >
      {[0, 0.25, 0.5, 0.75, 1].map((frac, i) => {
        const y = barAreaH - frac * barAreaH;
        return (
          <line
            key={i}
            x1={padX}
            y1={y}
            x2={viewW - padX}
            y2={y}
            stroke="#f1f5f9"
            strokeWidth={1}
            strokeDasharray={i === 0 ? "none" : "3,3"}
          />
        );
      })}
      {counts.map((val, i) => {
        const barH = maxVal > 0 ? (val / maxVal) * (barAreaH - 4) : 0;
        const x = padX + i * slotW + (slotW - barW) / 2;
        const y = barAreaH - barH;
        return (
          <g key={i}>
            <rect
              x={x}
              y={y}
              width={barW}
              height={Math.max(barH, val > 0 ? 3 : 0)}
              rx={barW > 4 ? 2 : 1}
              fill={val > 0 ? accentColor : "#e2e8f0"}
              opacity={val > 0 ? 0.9 : 0.3}
              style={{ transition: "opacity 0.15s, fill 0.15s", cursor: "pointer" }}
            >
              <title>{`${labels[i]}: ${val} ${val === 1 ? "post" : "posts"}`}</title>
            </rect>
            {val > 0 && barH > 16 && barW >= 12 && (
              <text
                x={x + barW / 2}
                y={y + 11}
                textAnchor="middle"
                fontSize={9}
                fill="#ffffff"
                fontWeight="700"
                style={{ pointerEvents: "none" }}
              >
                {val}
              </text>
            )}
          </g>
        );
      })}
      {labels.map((label, i) => {
        if (i % labelStep !== 0 && i !== n - 1) return null;
        const x = padX + i * slotW + slotW / 2;
        return (
          <text
            key={i}
            x={x}
            y={chartH - 4}
            textAnchor="middle"
            fontSize={10}
            fill="#94a3b8"
            fontWeight="500"
          >
            {label}
          </text>
        );
      })}
    </svg>
  );
}

function DonutChart({ photo, video, reel, carousel, other, total }) {
  const size = 140;
  const cx = size / 2;
  const cy = size / 2;
  const r = 52;
  const innerR = 32;
  const gap = 0.03;
  if (total === 0) {
    return (
      <div style={{ display: "flex", alignItems: "center", justifyContent: "center", height: size + "px", color: "#94a3b8", fontSize: "13px" }}>
        No posts
      </div>
    );
  }
  const allItems = [
    { label: "Photo",    value: photo,    color: "#3b82f6" },
    { label: "Video",    value: video,    color: "#22c55e" },
    { label: "Reel",     value: reel,     color: "#ec4899" },
    { label: "Carousel", value: carousel, color: "#a855f7" },
    { label: "Other",    value: other,    color: "#f59e0b" },
  ];
  const segments = allItems.filter(s => s.value > 0);
  let currentAngle = -Math.PI / 2;
  const paths = segments.map((seg) => {
    const fraction = seg.value / total;
    const angle = fraction * 2 * Math.PI - gap;
    const startAngle = currentAngle + gap / 2;
    const endAngle = startAngle + angle;
    currentAngle = endAngle + gap / 2;
    const x1 = cx + r * Math.cos(startAngle);
    const y1 = cy + r * Math.sin(startAngle);
    const x2 = cx + r * Math.cos(endAngle);
    const y2 = cy + r * Math.sin(endAngle);
    const xi1 = cx + innerR * Math.cos(endAngle);
    const yi1 = cy + innerR * Math.sin(endAngle);
    const xi2 = cx + innerR * Math.cos(startAngle);
    const yi2 = cy + innerR * Math.sin(startAngle);
    const largeArc = angle > Math.PI ? 1 : 0;
    const d = "M " + x1 + " " + y1 + " A " + r + " " + r + " 0 " + largeArc + " 1 " + x2 + " " + y2 + " L " + xi1 + " " + yi1 + " A " + innerR + " " + innerR + " 0 " + largeArc + " 0 " + xi2 + " " + yi2 + " Z";
    return { label: seg.label, value: seg.value, color: seg.color, d };
  });
  const svgVb = "0 0 " + size + " " + size;
  return (
    <div style={{ display: "flex", alignItems: "center", gap: "20px" }}>
      <div style={{ position: "relative", flexShrink: 0 }}>
        <svg width={size} height={size} viewBox={svgVb}>
          {paths.map((p, i) => <path key={i} d={p.d} fill={p.color} />)}
          <text x={cx} y={cy - 5} textAnchor="middle" fontSize={18} fontWeight="700" fill="#0f172a">{total}</text>
          <text x={cx} y={cy + 12} textAnchor="middle" fontSize={9} fill="#64748b">Posts</text>
        </svg>
      </div>
      <div style={{ display: "flex", flexDirection: "column", gap: "8px", flex: 1 }}>
        {allItems.map((item) => {
          const pct = total > 0 ? ((item.value / total) * 100).toFixed(1) : "0.0";
          return (
            <div key={item.label} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: "8px" }}>
              <div style={{ display: "flex", alignItems: "center", gap: "7px" }}>
                <span style={{ width: "10px", height: "10px", borderRadius: "50%", background: item.color, flexShrink: 0, display: "inline-block",
                  opacity: item.value > 0 ? 1 : 0.35 }} />
                <span style={{ fontSize: "13px", color: item.value > 0 ? "#374151" : "#94a3b8" }}>{item.label}</span>
              </div>
              <span style={{ fontSize: "13px", fontWeight: "600", color: item.value > 0 ? "#111827" : "#94a3b8" }}>{pct}%</span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function SummaryCard({ icon, iconBg, value, label, subtitle, tooltip, loading }) {
  return (
    <div
      style={{
        background: "#ffffff",
        border: "1px solid #e2e8f0",
        borderRadius: "12px",
        padding: "18px 20px",
        display: "flex",
        alignItems: "center",
        gap: "14px",
        flex: "1 1 0",
        minWidth: "160px",
        boxShadow: "0 1px 3px rgba(0,0,0,0.03)",
        transition: "box-shadow 0.15s",
      }}
      onMouseOver={e => e.currentTarget.style.boxShadow = "0 4px 12px rgba(0,0,0,0.07)"}
      onMouseOut={e => e.currentTarget.style.boxShadow = "0 1px 3px rgba(0,0,0,0.03)"}
    >
      <div style={{ width: "46px", height: "46px", borderRadius: "12px", background: iconBg, display: "flex", alignItems: "center", justifyContent: "center", flexShrink: 0 }}>
        {icon}
      </div>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: "flex", alignItems: "baseline", gap: "8px", flexWrap: "wrap" }}>
          <div style={{ fontSize: "24px", fontWeight: "700", color: "#0f172a", lineHeight: 1.1 }}>
            {loading ? <span style={{ color: "#94a3b8" }}>...</span> : formatNum(value)}
          </div>
          {subtitle && !loading && (
            <span
              style={{
                fontSize: "11px",
                fontWeight: "600",
                color: "#15803d",
                background: "#f0fdf4",
                border: "1px solid #bbf7d0",
                padding: "1px 6px",
                borderRadius: "12px",
                whiteSpace: "nowrap",
              }}
              title="Followers gained during the selected date range"
            >
              {subtitle}
            </span>
          )}
        </div>
        <div style={{ fontSize: "12.5px", color: "#64748b", marginTop: "3px", display: "flex", alignItems: "center", gap: "4px" }}>
          <span>{label}</span>
          {tooltip && <Tooltip text={tooltip} />}
        </div>
      </div>
    </div>
  );
}

const typeBadgeStyle = {
  Photo:    { bg: "#dbeafe", color: "#1d4ed8" },
  Video:    { bg: "#dcfce7", color: "#15803d" },
  Reel:     { bg: "#fce7f3", color: "#be185d" },
  Carousel: { bg: "#f3e8ff", color: "#7e22ce" },
  Text:     { bg: "#f1f5f9", color: "#475569" },
  Link:     { bg: "#fef9c3", color: "#854d0e" },
  Other:    { bg: "#fffbeb", color: "#b45309" },
};

function TypeBadge({ type }) {
  const style = typeBadgeStyle[type] || { bg: "#f1f5f9", color: "#475569" };
  return (
    <span style={{ background: style.bg, color: style.color, borderRadius: "20px", padding: "3px 10px", fontSize: "11.5px", fontWeight: "600", display: "inline-block" }}>
      {type}
    </span>
  );
}

const platformConfig = {
  facebook: {
    name: "Facebook",
    statsEndpoint: "/facebook/dashboard-stats",
    postsEndpoint: "/facebook/posts",
    accent: "#1877f2",
    followersColor: "#1d4ed8",
    followersBg: "#dbeafe",
    followersTooltip: "Current overall total followers from Meta API. Badge shows real followers gained during the selected date range via Meta Page Insights.",
    likesTooltip: "Total likes and reactions received across all Facebook posts published within the selected date range from Meta Graph API.",
    commentsTooltip: "Total comments received across all Facebook posts published within the selected date range from Meta Graph API.",
    postsTooltip: "Number of posts published within the selected date range.",
    viewsTooltip: "Views from Meta's post_media_view metric when available for the post.",
    emptyPosts: "No Facebook posts found for this workspace.",
  },
  instagram: {
    name: "Instagram",
    statsEndpoint: "/instagram/dashboard-stats",
    postsEndpoint: "/instagram/posts",
    accent: "#e1306c",
    followersColor: "#be185d",
    followersBg: "#fce7f3",
    followersTooltip: "Current overall total followers from the connected Instagram account. Badge shows followers gained during the selected date range when Meta provides the metric.",
    likesTooltip: "Total likes received across Instagram media published within the selected date range from Instagram Graph API.",
    commentsTooltip: "Total comments received across Instagram media published within the selected date range from Instagram Graph API.",
    postsTooltip: "Number of Instagram posts published within the selected date range.",
    viewsTooltip: "Views from Instagram media insights when Meta provides the metric for the post.",
    emptyPosts: "No Instagram posts found for this workspace.",
  },
};

const fbStatsModuleCache = new Map();
const fbPostsModuleCache = new Map();
const fbInFlightRequests = new Map();

const igStatsModuleCache = new Map();
const igPostsModuleCache = new Map();
const igInFlightRequests = new Map();

export default function FacebookDashboardSection({ workspaceId, startDate, endDate, refreshTrigger, onViewAll, platform = "facebook" }) {
  const cfg = platformConfig[platform] || platformConfig.facebook;
  const isInstagram = platform === "instagram";
  const [stats, setStats] = useState(null);
  const [statsLoading, setStatsLoading] = useState(true);
  const [statsError, setStatsError] = useState("");
  const [posts, setPosts] = useState([]);
  const [postPage, setPostPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalPosts, setTotalPosts] = useState(0);
  const [hasPrev, setHasPrev] = useState(false);
  const [hasNext, setHasNext] = useState(false);
  const [postsLoading, setPostsLoading] = useState(true);
  const [postsError, setPostsError] = useState("");
  const statsAbort = useRef(null);
  const postsAbort = useRef(null);
  const statsCache = useRef(new Map());
  const postsCache = useRef(new Map());
  const prevRefreshRef = useRef(refreshTrigger);

  const fetchStats = useCallback(async (force = false, silent = false) => {
    if (!workspaceId) return;
    const isFb = platform === "facebook";
    const cacheKey = `${platform}|${workspaceId}|${startDate || ""}|${endDate || ""}`;
    const activeCache = isFb ? fbStatsModuleCache : igStatsModuleCache;
    const activeInFlight = isFb ? fbInFlightRequests : igInFlightRequests;
    const cached = activeCache.get(cacheKey);
    const cacheTtl = silent ? 25000 : 300000;
    if (!force && cached && Date.now() - cached.timestamp < cacheTtl) {
      setStats(cached.data);
      if (!silent) setStatsLoading(false);
      setStatsError("");
      return;
    }

    if (activeInFlight.has(`stats_${cacheKey}`)) {
      try {
        const resData = await activeInFlight.get(`stats_${cacheKey}`);
        if (resData && resData.success) {
          setStats(resData);
          if (!silent) setStatsLoading(false);
          setStatsError("");
          return;
        }
      } catch (e) {}
    }

    if (statsAbort.current) statsAbort.current.abort();
    const ctrl = new AbortController();
    statsAbort.current = ctrl;
    if (!silent) {
      setStatsLoading(true);
      setStatsError("");
    }
    try {
      const params = { workspace_id: workspaceId };
      if (startDate) params.start_date = startDate;
      if (endDate) params.end_date = endDate;
      if (!startDate && !endDate) params.all_posts = true;
      if (force) params.force_refresh = 1;
      if (silent) {
        params.auto_sync = 1;
        params.live_sync = 1;
      }
      const requestPromise = axiosInstance.get(cfg.statsEndpoint, { params, signal: ctrl.signal });
      activeInFlight.set(`stats_${cacheKey}`, requestPromise.then(r => r.data).catch(() => null));
      const res = await requestPromise;
      activeInFlight.delete(`stats_${cacheKey}`);
      if (ctrl.signal.aborted) return;
      if (res.data && res.data.success) {
        setStats(res.data);
        activeCache.set(cacheKey, { data: res.data, timestamp: Date.now() });
      } else if (!silent) {
        setStatsError((res.data && res.data.message) || `Failed to load ${cfg.name} stats.`);
      }
    } catch (err) {
      activeInFlight.delete(`stats_${cacheKey}`);
      if (ctrl.signal.aborted) return;
      if (!silent) {
        setStatsError((err.response && err.response.data && err.response.data.message) || `Unable to load ${cfg.name} stats.`);
      }
    } finally {
      if (!ctrl.signal.aborted && !silent) setStatsLoading(false);
    }
  }, [workspaceId, startDate, endDate, platform, cfg.statsEndpoint, cfg.name]);

  const fetchPosts = useCallback(async (pg, force = false, silent = false) => {
    const page = pg || 1;
    if (!workspaceId) return;
    const isFb = platform === "facebook";
    const cacheKey = `${platform}|${workspaceId}|${startDate || ""}|${endDate || ""}|${page}`;
    const activeCache = isFb ? fbPostsModuleCache : igPostsModuleCache;
    const activeInFlight = isFb ? fbInFlightRequests : igInFlightRequests;
    const cached = activeCache.get(cacheKey);
    const cacheTtl = silent ? 25000 : 300000;
    if (!force && cached && Date.now() - cached.timestamp < cacheTtl) {
      setPosts(cached.data.data || []);
      setPostPage(cached.data.current_page || page);
      setTotalPages(cached.data.total_pages || 1);
      setTotalPosts(cached.data.total || 0);
      setHasPrev(Boolean(cached.data.has_prev));
      setHasNext(Boolean(cached.data.has_next));
      if (!silent) setPostsLoading(false);
      setPostsError("");
      return;
    }

    if (activeInFlight.has(`posts_${cacheKey}`)) {
      try {
        const resData = await activeInFlight.get(`posts_${cacheKey}`);
        if (resData && resData.success) {
          setPosts(resData.data || []);
          setPostPage(resData.current_page || page);
          setTotalPages(resData.total_pages || 1);
          setTotalPosts(resData.total || 0);
          setHasPrev(Boolean(resData.has_prev));
          setHasNext(Boolean(resData.has_next));
          if (!silent) setPostsLoading(false);
          setPostsError("");
          return;
        }
      } catch (e) {}
    }

    if (postsAbort.current) postsAbort.current.abort();
    const ctrl = new AbortController();
    postsAbort.current = ctrl;
    if (!silent) {
      setPostsLoading(true);
      setPostsError("");
    }
    try {
      const params = {
        workspace_id: workspaceId,
        page: page,
        per_page: 5,
      };
      if (startDate) params.start_date = startDate;
      if (endDate) params.end_date = endDate;
      if (!startDate && !endDate) params.all_posts = true;
      if (force) params.force_refresh = 1;
      if (silent) {
        params.auto_sync = 1;
        params.live_sync = 1;
      }
      const requestPromise = axiosInstance.get(cfg.postsEndpoint, { params, signal: ctrl.signal });
      activeInFlight.set(`posts_${cacheKey}`, requestPromise.then(r => r.data).catch(() => null));
      const res = await requestPromise;
      activeInFlight.delete(`posts_${cacheKey}`);
      if (ctrl.signal.aborted) return;
      if (res.data && res.data.success) {
        setPosts(res.data.data || []);
        setPostPage(res.data.current_page || page);
        setTotalPages(res.data.total_pages || 1);
        setTotalPosts(res.data.total || 0);
        setHasPrev(Boolean(res.data.has_prev));
        setHasNext(Boolean(res.data.has_next));
        activeCache.set(cacheKey, { data: res.data, timestamp: Date.now() });
      } else if (!silent) {
        setPostsError((res.data && res.data.message) || "Failed to load posts.");
      }
    } catch (err) {
      activeInFlight.delete(`posts_${cacheKey}`);
      if (ctrl.signal.aborted) return;
      if (!silent) {
        setPostsError((err.response && err.response.data && err.response.data.message) || "Unable to load posts.");
      }
    } finally {
      if (!ctrl.signal.aborted && !silent) setPostsLoading(false);
    }
  }, [workspaceId, startDate, endDate, platform, cfg.postsEndpoint]);

  useEffect(() => {
    fetchStats();
    return () => { if (statsAbort.current) statsAbort.current.abort(); };
  }, [fetchStats]);

  useEffect(() => {
    setPostPage(1);
    fetchPosts(1);
    return () => { if (postsAbort.current) postsAbort.current.abort(); };
  }, [fetchPosts]);

  useEffect(() => {
    if (refreshTrigger && refreshTrigger !== prevRefreshRef.current) {
      prevRefreshRef.current = refreshTrigger;
      fetchStats(true);
      fetchPosts(postPage, true);
    }
  }, [refreshTrigger, fetchStats, fetchPosts, postPage]);

  // Auto-sync polling interval (every 30 seconds) in near real-time
  useEffect(() => {
    let intervalId = null;

    const runAutoSync = () => {
      if (document.hidden) return;
      fetchStats(false, true);
      fetchPosts(postPage, false, true);
    };

    intervalId = setInterval(runAutoSync, 30000);

    const handleVisibilityChange = () => {
      if (!document.hidden) {
        runAutoSync();
      }
    };
    document.addEventListener("visibilitychange", handleVisibilityChange);

    return () => {
      if (intervalId) clearInterval(intervalId);
      document.removeEventListener("visibilitychange", handleVisibilityChange);
    };
  }, [fetchStats, fetchPosts, postPage]);

  const handlePageChange = (newPage) => {
    if (newPage < 1 || newPage > totalPages || postsLoading) return;
    setPostPage(newPage);
    fetchPosts(newPage);
  };

  const summary = (stats && stats.summary) || {};
  const postTypes = (stats && stats.post_types) || {};
  const chartData = (stats && stats.posts_chart) || { labels: [], counts: [] };
  const totalTyped = (postTypes.photo || 0) + (postTypes.video || 0) + (postTypes.reel || 0) + (postTypes.carousel || 0) + (postTypes.other || 0);

  const EM_DASH = String.fromCharCode(8212);

  return (
    <div style={{ marginBottom: "28px" }}>
      <div style={{ display: "flex", gap: "16px", flexWrap: "wrap", marginBottom: "20px" }}>
        <SummaryCard
          loading={statsLoading}
          icon={<Users size={22} color={cfg.followersColor} />}
          iconBg={cfg.followersBg}
          value={summary.total_followers ?? summary.followers}
          label="Followers"
          subtitle={
            summary.period_followers !== undefined && summary.period_followers !== null
              ? (summary.period_followers > 0 ? `+${summary.period_followers} in period` : `${summary.period_followers} in period`)
              : null
          }
          tooltip={cfg.followersTooltip}
        />
        <SummaryCard
          loading={statsLoading}
          icon={<ThumbsUp size={22} color="#15803d" />}
          iconBg="#dcfce7"
          value={summary.likes ?? summary.page_likes}
          label="Likes"
          tooltip={cfg.likesTooltip}
        />
        <SummaryCard
          loading={statsLoading}
          icon={<MessageCircle size={22} color="#7e22ce" />}
          iconBg="#f3e8ff"
          value={summary.comments}
          label="Comments"
          tooltip={cfg.commentsTooltip}
        />
        <SummaryCard
          loading={statsLoading}
          icon={<FileText size={22} color="#b45309" />}
          iconBg="#fef3c7"
          value={summary.total_posts}
          label="Posts Published"
          tooltip={cfg.postsTooltip}
        />
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "16px", marginBottom: "20px" }}>
        <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "20px 22px", boxShadow: "0 1px 3px rgba(0,0,0,0.03)" }}>
          <h4 style={{ margin: "0 0 16px 0", fontSize: "14px", fontWeight: "700", color: "#0f172a" }}>Posts Published</h4>
          {statsLoading ? (
            <div style={{ display: "flex", alignItems: "center", justifyContent: "center", height: "160px", color: "#94a3b8" }}>
              <RefreshCw size={20} className="spin" style={{ marginRight: 8 }} /> Loading...
            </div>
          ) : (
            <BarChart labels={chartData.labels} counts={chartData.counts} accentColor={cfg.accent || "#2563eb"} />
          )}
        </div>

        <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: "12px", padding: "20px 22px", boxShadow: "0 1px 3px rgba(0,0,0,0.03)" }}>
          <h4 style={{ margin: "0 0 16px 0", fontSize: "14px", fontWeight: "700", color: "#0f172a" }}>Post Types</h4>
          {statsLoading ? (
            <div style={{ display: "flex", alignItems: "center", justifyContent: "center", height: "140px", color: "#94a3b8" }}>
              <RefreshCw size={20} className="spin" style={{ marginRight: 8 }} /> Loading...
            </div>
          ) : (
            <DonutChart
              photo={postTypes.photo || 0}
              video={postTypes.video || 0}
              reel={postTypes.reel || 0}
              carousel={postTypes.carousel || 0}
              other={postTypes.other || 0}
              total={totalTyped || summary.total_posts || 0}
            />
          )}
        </div>
      </div>

      <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: "12px", overflow: "hidden", boxShadow: "0 1px 3px rgba(0,0,0,0.03)" }}>
        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "16px 22px", borderBottom: "1px solid #f1f5f9" }}>
          <h4 style={{ margin: 0, fontSize: "14px", fontWeight: "700", color: "#0f172a" }}>Latest Posts</h4>
          {onViewAll && (
            <button type="button" onClick={onViewAll} style={{ background: "none", border: "none", padding: 0, fontSize: "13px", fontWeight: "600", color: "#1d4ed8", cursor: "pointer" }}>
              View all
            </button>
          )}
        </div>

        {postsError && (
          <div style={{ background: "#fef2f2", border: "1px solid #fecaca", borderRadius: "8px", padding: "12px 16px", color: "#b91c1c", fontSize: "13px", margin: "12px 22px", display: "flex", alignItems: "center", gap: "8px" }}>
            <AlertCircle size={16} /> {postsError}
          </div>
        )}

        <div style={{ overflowX: "auto" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", minWidth: "700px" }}>
            <thead>
              <tr style={{ background: "#f8fafc", borderBottom: "1px solid #e2e8f0" }}>
                <th style={{ padding: "11px 22px", fontSize: "12px", fontWeight: "600", color: "#64748b", textAlign: "left", width: "36%" }}>Post</th>
                <th style={{ padding: "11px 14px", fontSize: "12px", fontWeight: "600", color: "#64748b", textAlign: "left", width: "14%" }}>Date</th>
                <th style={{ padding: "11px 10px", fontSize: "12px", fontWeight: "600", color: "#64748b", textAlign: "center", width: "10%" }}>Type</th>
                <th style={{ padding: "11px 10px", fontSize: "12px", fontWeight: "600", color: "#64748b", textAlign: "center", width: "10%" }}>
                  <span style={{ display: "inline-flex", alignItems: "center", gap: "3px" }}>
                    Views <Tooltip text={cfg.viewsTooltip} />
                  </span>
                </th>
                <th style={{ padding: "11px 10px", fontSize: "12px", fontWeight: "600", color: "#64748b", textAlign: "center", width: "10%" }}>Likes</th>
                <th style={{ padding: "11px 10px", fontSize: "12px", fontWeight: "600", color: "#64748b", textAlign: "center", width: "10%" }}>Comments</th>
                <th style={{ padding: "11px 10px", fontSize: "12px", fontWeight: "600", color: "#64748b", textAlign: "center", width: "10%" }}>Shares</th>
              </tr>
            </thead>
            <tbody>
              {postsLoading ? (
                <tr>
                  <td colSpan={7} style={{ padding: "36px", textAlign: "center", color: "#64748b" }}>
                    <RefreshCw size={20} className="spin" style={{ margin: "0 auto 8px", display: "block", color: cfg.accent || "#1877f2" }} />
                    <span style={{ fontSize: "13px" }}>Loading posts...</span>
                  </td>
                </tr>
              ) : posts.length === 0 ? (
                <tr>
                  <td colSpan={7} style={{ padding: "36px", textAlign: "center", color: "#94a3b8", fontSize: "13px" }}>
                    {cfg.emptyPosts}
                  </td>
                </tr>
              ) : (
                posts.map((post) => {
                  const dtParts = formatPostDateTimeParts(post.published_at);
                  const date = dtParts.date;
                  const time = dtParts.time;
                  const isVideo = post.formatted_type === "Video" || post.post_type === "video";
                  const isReel = post.formatted_type === "Reel" || post.post_type === "reel";
                  const isCarousel = post.formatted_type === "Carousel" || post.post_type === "multi_image";
                  const viewsValue = post.views ?? post.views_count;
                  return (
                    <tr key={post.id} style={{ borderBottom: "1px solid #f1f5f9", transition: "background 0.1s" }}
                      onMouseOver={e => { e.currentTarget.style.background = "#f8fafc"; }}
                      onMouseOut={e => { e.currentTarget.style.background = "transparent"; }}
                    >
                      <td style={{ padding: "13px 22px" }}>
                        <div style={{ display: "flex", alignItems: "center", gap: "11px" }}>
                          <div style={{ width: "42px", height: "42px", borderRadius: "6px", overflow: "hidden", background: "#f1f5f9", border: "1px solid #e2e8f0", flexShrink: 0, display: "flex", alignItems: "center", justifyContent: "center" }}>
                            {post.image_url ? (
                              <img src={post.image_url} alt="" style={{ width: "100%", height: "100%", objectFit: "cover" }}
                                onError={e => { e.currentTarget.style.display = "none"; if (e.currentTarget.nextSibling) e.currentTarget.nextSibling.style.display = "flex"; }}
                              />
                            ) : null}
                            <div style={{ display: post.image_url ? "none" : "flex", alignItems: "center", justifyContent: "center", width: "100%", height: "100%", color: "#94a3b8" }}>
                              {isReel ? <Film size={16} /> : isVideo ? <Video size={16} /> : isCarousel ? <Layers size={16} /> : <Camera size={16} />}
                            </div>
                          </div>
                          {post.permalink || post.permalink_url ? (
                            <a
                              href={post.permalink || post.permalink_url}
                              target="_blank"
                              rel="noopener noreferrer"
                              style={{
                                fontSize: "13px",
                                fontWeight: "500",
                                color: "#0f172a",
                                lineHeight: "1.3",
                                maxWidth: "280px",
                                overflow: "hidden",
                                textOverflow: "ellipsis",
                                whiteSpace: "nowrap",
                                textDecoration: "none",
                                cursor: "pointer",
                              }}
                              title={post.content || "View on Facebook"}
                              onMouseOver={(e) => (e.currentTarget.style.color = "#1877f2")}
                              onMouseOut={(e) => (e.currentTarget.style.color = "#0f172a")}
                            >
                              {post.content ? post.content.replace(/[\r\n]+/g, " ") : "Media post"}
                            </a>
                          ) : (
                            <span style={{ fontSize: "13px", fontWeight: "500", color: "#0f172a", lineHeight: "1.3", maxWidth: "280px", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }} title={post.content || ""}>
                              {post.content ? post.content.replace(/[\r\n]+/g, " ") : "Media post"}
                            </span>
                          )}
                        </div>
                      </td>
                      <td style={{ padding: "13px 14px" }}>
                        <div style={{ fontSize: "13px", fontWeight: "500", color: "#0f172a" }}>{date}</div>
                        {time && <div style={{ fontSize: "11.5px", color: "#94a3b8", marginTop: "2px" }}>{time}</div>}
                      </td>
                      <td style={{ padding: "13px 10px", textAlign: "center" }}>
                        <TypeBadge type={post.formatted_type || "Photo"} />
                      </td>
                      <td style={{ padding: "13px 10px", textAlign: "center", fontSize: "13.5px", color: "#334155" }}>
                        {viewsValue !== null && viewsValue !== undefined ? formatNum(viewsValue) : (
                          <span title={cfg.viewsTooltip} style={{ color: "#94a3b8", cursor: "help" }}>{EM_DASH}</span>
                        )}
                      </td>
                      <td style={{ padding: "13px 10px", textAlign: "center", fontSize: "13.5px", color: "#334155" }}>
                        {post.likes_count != null ? formatNum(post.likes_count) : <span style={{ color: "#94a3b8" }}>{EM_DASH}</span>}
                      </td>
                      <td style={{ padding: "13px 10px", textAlign: "center", fontSize: "13.5px", color: "#334155" }}>
                        {post.comments_count != null ? formatNum(post.comments_count) : <span style={{ color: "#94a3b8" }}>{EM_DASH}</span>}
                      </td>
                      <td style={{ padding: "13px 10px", textAlign: "center", fontSize: "13.5px", color: "#334155" }}>
                        {post.shares_count != null ? formatNum(post.shares_count) : <span style={{ color: "#94a3b8" }}>{EM_DASH}</span>}
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "14px 22px", borderTop: "1px solid #f1f5f9", flexWrap: "wrap", gap: "10px" }}>
          <div style={{ fontSize: "13px", color: "#64748b" }}>
            {totalPosts > 0 ? (
              <span>
                Showing <strong>{(postPage - 1) * 5 + 1}–{Math.min(postPage * 5, totalPosts)}</strong> of <strong>{totalPosts}</strong> posts
              </span>
            ) : (
              <span>Showing 0–0 of 0 posts</span>
            )}
          </div>
          <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
            <button
              id={`${platform}-dash-prev-btn`}
              type="button"
              disabled={postPage <= 1 || postsLoading}
              onClick={() => handlePageChange(postPage - 1)}
              style={{
                display: "inline-flex",
                alignItems: "center",
                gap: "4px",
                padding: "6px 14px",
                borderRadius: "6px",
                fontSize: "13px",
                fontWeight: "600",
                border: "1px solid",
                cursor: postPage <= 1 || postsLoading ? "not-allowed" : "pointer",
                borderColor: postPage <= 1 || postsLoading ? "#e2e8f0" : "#cbd5e1",
                background: postPage <= 1 || postsLoading ? "#f8fafc" : "#ffffff",
                color: postPage <= 1 || postsLoading ? "#94a3b8" : "#334155",
                transition: "all 0.15s",
              }}
              onMouseOver={(e) => {
                if (postPage > 1 && !postsLoading) {
                  e.currentTarget.style.borderColor = "#94a3b8";
                  e.currentTarget.style.background = "#f1f5f9";
                }
              }}
              onMouseOut={(e) => {
                if (postPage > 1 && !postsLoading) {
                  e.currentTarget.style.borderColor = "#cbd5e1";
                  e.currentTarget.style.background = "#ffffff";
                }
              }}
            >
              <ChevronLeft size={14} /> Previous
            </button>
            <div
              style={{
                padding: "6px 14px",
                borderRadius: "6px",
                border: "1px solid #e2e8f0",
                background: "#ffffff",
                color: "#0f172a",
                fontSize: "13px",
                fontWeight: "700",
                minWidth: "56px",
                textAlign: "center",
              }}
            >
              {postPage} / {Math.max(1, totalPages)}
            </div>
            <button
              id={`${platform}-dash-next-btn`}
              type="button"
              disabled={postPage >= totalPages || postsLoading}
              onClick={() => handlePageChange(postPage + 1)}
              style={{
                display: "inline-flex",
                alignItems: "center",
                gap: "4px",
                padding: "6px 16px",
                borderRadius: "6px",
                fontSize: "13px",
                fontWeight: "600",
                border: "1px solid",
                cursor: postPage >= totalPages || postsLoading ? "not-allowed" : "pointer",
                borderColor: postPage >= totalPages || postsLoading ? "#e2e8f0" : "#cbd5e1",
                background: postPage >= totalPages || postsLoading ? "#f8fafc" : "#ffffff",
                color: postPage >= totalPages || postsLoading ? "#94a3b8" : "#334155",
                transition: "all 0.15s",
              }}
              onMouseOver={(e) => {
                if (postPage < totalPages && !postsLoading) {
                  e.currentTarget.style.borderColor = "#94a3b8";
                  e.currentTarget.style.background = "#f1f5f9";
                }
              }}
              onMouseOut={(e) => {
                if (postPage < totalPages && !postsLoading) {
                  e.currentTarget.style.borderColor = "#cbd5e1";
                  e.currentTarget.style.background = "#ffffff";
                }
              }}
            >
              Next <ChevronRight size={14} />
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
