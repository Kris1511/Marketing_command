import React from "react";
import { STATUS_STYLES, rowStatuses, statusTooltip } from "./overviewStatus";

function Pill({ s, compact = false }) {
  const style = STATUS_STYLES[s.status] || STATUS_STYLES.unknown;
  return (
    <span
      title={statusTooltip(s)}
      data-status={s.status}
      data-platform={s.key}
      style={{
        display: "inline-flex",
        alignItems: "center",
        gap: "4px",
        background: style.bg,
        color: style.color,
        border: `1px solid ${style.border}`,
        borderRadius: "20px",
        padding: compact ? "1px 7px" : "3px 10px",
        fontSize: compact ? "10.5px" : "11.5px",
        fontWeight: 600,
        whiteSpace: "nowrap",
        cursor: s.message ? "help" : "default",
        textDecoration: s.status === "deleted" && compact ? "line-through" : "none",
      }}
    >
      {compact ? `${s.short} · ${s.label}` : s.label}
    </span>
  );
}

/**
 * Platform-specific status of a Latest Posts row: the section's platform as the main badge, plus small chips
 * for the post's other platforms (e.g. "IG · Deleted"). Hover shows the reason and the last check time.
 */
export default function PostStatusBadge({ post, platformKey }) {
  const { primary, others } = rowStatuses(post, platformKey);
  return (
    <div style={{ display: "flex", flexDirection: "column", alignItems: "flex-start", gap: "4px" }}>
      <Pill s={primary} />
      {others.length > 0 && (
        <div style={{ display: "flex", flexWrap: "wrap", gap: "3px" }}>
          {others.map((o) => <Pill key={o.key} s={o} compact />)}
        </div>
      )}
    </div>
  );
}
