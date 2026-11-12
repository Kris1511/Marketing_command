import React, { useCallback, useEffect, useRef, useState } from "react";
import { AlertCircle, ChevronLeft, ChevronRight, RefreshCw } from "lucide-react";
import axiosInstance from "../api/axiosInstance";
import PostThumbnail from "./PostThumbnail";
import PostStatusBadge from "./PostStatusBadge";
import { thumbnailPost } from "./overviewStatus";

const EM_DASH = String.fromCharCode(8212);
const PER_PAGE = 5;

function formatNum(n) {
  const v = Number(n);
  if (!Number.isFinite(v)) return EM_DASH;
  if (v >= 1000000) return `${(v / 1000000).toFixed(1)}M`;
  if (v >= 1000) return `${(v / 1000).toFixed(1)}K`;
  return String(v);
}

function dateParts(iso) {
  if (!iso) return { date: EM_DASH, time: "" };
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return { date: EM_DASH, time: "" };
  return {
    date: d.toLocaleDateString("en-IN", { timeZone: "Asia/Kolkata", day: "2-digit", month: "short", year: "numeric" }),
    time: d.toLocaleTimeString("en-IN", { timeZone: "Asia/Kolkata", hour: "2-digit", minute: "2-digit", hour12: true }),
  };
}

const th = { padding: "11px 10px", fontSize: "12px", fontWeight: "600", color: "#64748b", textAlign: "center" };
const td = { padding: "13px 10px", textAlign: "center", fontSize: "13.5px", color: "#334155" };

/**
 * Overview → YouTube: videos and Shorts published from Marketing Command, with their thumbnail and status
 * (Published / Scheduled / Failed / Deleted / Unable to verify). Same layout as the Facebook / Instagram
 * Latest Posts table. Metrics come from the last background check (YouTube has no share count).
 */
export default function YouTubeLatestPostsSection({ workspaceId, startDate, endDate, refreshTrigger }) {
  const [posts, setPosts] = useState([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const abortRef = useRef(null);

  const load = useCallback(async (targetPage) => {
    if (!workspaceId) return;
    if (abortRef.current) abortRef.current.abort();
    const ctrl = new AbortController();
    abortRef.current = ctrl;
    setLoading(true);
    setError("");
    try {
      const params = { workspace_id: workspaceId, page: targetPage, per_page: PER_PAGE };
      if (startDate) params.start_date = startDate;
      if (endDate) params.end_date = endDate;
      if (!startDate && !endDate) params.all_posts = 1;
      const res = await axiosInstance.get("/youtube/published-posts", { params, signal: ctrl.signal });
      if (ctrl.signal.aborted) return;
      if (res.data && res.data.success) {
        setPosts(res.data.data || []);
        setTotalPages(res.data.total_pages || 1);
        setTotal(res.data.total || 0);
      } else {
        setError((res.data && res.data.message) || "Could not load YouTube posts.");
      }
    } catch (err) {
      if (err && (err.name === "CanceledError" || err.code === "ERR_CANCELED")) return;
      setError((err && err.response && err.response.data && err.response.data.message) || "Could not load YouTube posts.");
    } finally {
      if (!ctrl.signal.aborted) setLoading(false);
    }
  }, [workspaceId, startDate, endDate]);

  useEffect(() => {
    load(page);
  }, [load, page, refreshTrigger]);

  return (
    <div style={{ background: "#ffffff", border: "1px solid #e2e8f0", borderRadius: "12px", overflow: "hidden", boxShadow: "0 1px 3px rgba(0,0,0,0.03)", marginTop: "24px" }}>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "16px 22px", borderBottom: "1px solid #f1f5f9" }}>
        <h4 style={{ margin: 0, fontSize: "14px", fontWeight: "700", color: "#0f172a" }}>Latest Posts</h4>
        <span style={{ fontSize: "12px", color: "#64748b" }}>Published from Marketing Command</span>
      </div>

      {error && (
        <div style={{ background: "#fef2f2", border: "1px solid #fecaca", borderRadius: "8px", padding: "12px 16px", color: "#b91c1c", fontSize: "13px", margin: "12px 22px", display: "flex", alignItems: "center", gap: "8px" }}>
          <AlertCircle size={16} /> {error}
        </div>
      )}

      <div className="dash-table-wrap">
        <table className="dash-table">
          <thead>
            <tr style={{ background: "#f8fafc", borderBottom: "1px solid #e2e8f0" }}>
              <th style={{ ...th, padding: "11px 22px", textAlign: "left", width: "32%" }}>Post</th>
              <th className="dash-col-md" style={{ ...th, padding: "11px 14px", textAlign: "left", width: "14%" }}>Date</th>
              <th className="dash-col-md" style={{ ...th, width: "9%" }}>Type</th>
              <th style={{ ...th, textAlign: "left", width: "13%" }}>Status</th>
              <th style={{ ...th, width: "8%" }}>Views</th>
              <th style={{ ...th, width: "8%" }}>Likes</th>
              <th style={{ ...th, width: "8%" }}>Comments</th>
              <th className="dash-col-md" style={{ ...th, width: "8%" }} title="The YouTube Data API does not report shares">Shares</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={8} className="dash-span-all" style={{ padding: "36px", textAlign: "center", color: "#64748b" }}>
                  <RefreshCw size={20} className="spin" style={{ margin: "0 auto 8px", display: "block", color: "#ff0000" }} />
                  <span style={{ fontSize: "13px" }}>Loading posts...</span>
                </td>
              </tr>
            ) : posts.length === 0 ? (
              <tr>
                <td colSpan={8} className="dash-span-all" style={{ padding: "36px", textAlign: "center", color: "#94a3b8", fontSize: "13px" }}>
                  No YouTube videos published from Marketing Command in this period.
                </td>
              </tr>
            ) : (
              posts.map((post) => {
                const { date, time } = dateParts(post.published_at);
                const title = post.title || post.content || "YouTube video";
                return (
                  <tr key={post.id} style={{ borderBottom: "1px solid #f1f5f9" }}>
                    <td className="dash-post-cell" style={{ padding: "13px 22px" }}>
                      <div style={{ display: "flex", alignItems: "center", gap: "11px", minWidth: 0 }}>
                        <PostThumbnail post={thumbnailPost(post)} size={42} />
                        <div className="dash-post-text" style={{ minWidth: 0 }}>
                          {post.permalink ? (
                            <a href={post.permalink} target="_blank" rel="noopener noreferrer" title={title}
                              style={{ fontSize: "13px", fontWeight: "500", color: "#0f172a", display: "block", maxWidth: "280px", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", textDecoration: "none" }}>
                              {title}
                            </a>
                          ) : (
                            <span title={title} style={{ fontSize: "13px", fontWeight: "500", color: "#0f172a", display: "block", maxWidth: "280px", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{title}</span>
                          )}
                          <div className="dash-post-meta">{[date, post.formatted_type].filter(Boolean).join(" · ")}</div>
                        </div>
                      </div>
                    </td>
                    <td className="dash-col-md dash-date-cell" style={{ padding: "13px 14px" }}>
                      <div style={{ fontSize: "13px", fontWeight: "500", color: "#0f172a" }}>{date}</div>
                      {time && <div style={{ fontSize: "11.5px", color: "#94a3b8", marginTop: "2px" }}>{time}</div>}
                      {post.date_kind === "scheduled" && <div style={{ fontSize: "11px", color: "#1d4ed8", marginTop: "2px" }}>Scheduled</div>}
                    </td>
                    <td className="dash-col-md" style={{ ...td }}>
                      <span style={{ background: post.formatted_type === "Short" ? "#fee2e2" : "#f1f5f9", color: post.formatted_type === "Short" ? "#b91c1c" : "#475569", borderRadius: "20px", padding: "3px 10px", fontSize: "11.5px", fontWeight: 600 }}>
                        {post.formatted_type || "Video"}
                      </span>
                    </td>
                    <td data-label="Status" className="dash-status-cell" style={{ padding: "13px 10px" }}>
                      <PostStatusBadge post={post} platformKey="youtube" />
                    </td>
                    <td data-label="Views" style={td}>{post.views_count != null ? formatNum(post.views_count) : <span style={{ color: "#94a3b8" }}>{EM_DASH}</span>}</td>
                    <td data-label="Likes" style={td}>{post.likes_count != null ? formatNum(post.likes_count) : <span style={{ color: "#94a3b8" }}>{EM_DASH}</span>}</td>
                    <td data-label="Comments" style={td}>{post.comments_count != null ? formatNum(post.comments_count) : <span style={{ color: "#94a3b8" }}>{EM_DASH}</span>}</td>
                    <td className="dash-col-md" style={td}><span style={{ color: "#94a3b8" }} title="Not available from the YouTube Data API">{EM_DASH}</span></td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "14px 22px", borderTop: "1px solid #f1f5f9", flexWrap: "wrap", gap: "10px" }}>
        <div style={{ fontSize: "13px", color: "#64748b" }}>
          {total > 0
            ? <span>Showing <strong>{(page - 1) * PER_PAGE + 1}–{Math.min(page * PER_PAGE, total)}</strong> of <strong>{total}</strong> posts</span>
            : <span>Showing 0–0 of 0 posts</span>}
        </div>
        <div style={{ display: "flex", gap: "8px" }}>
          <button type="button" aria-label="Previous page" disabled={page <= 1 || loading} onClick={() => setPage((p) => Math.max(1, p - 1))}
            className="btn btn-secondary" style={{ padding: "5px 9px" }}><ChevronLeft size={14} /></button>
          <button type="button" aria-label="Next page" disabled={page >= totalPages || loading} onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
            className="btn btn-secondary" style={{ padding: "5px 9px" }}><ChevronRight size={14} /></button>
        </div>
      </div>
    </div>
  );
}
