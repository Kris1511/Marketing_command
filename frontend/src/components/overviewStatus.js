// Overview → Latest Posts: per-platform status helpers (pure; tested with node:test).
// Statuses come from the backend (OverviewPostStatus): published | scheduled | failed | deleted | unknown,
// plus "partial" for a post overall.

export const STATUS_STYLES = {
  published: { bg: "#ecfdf5", color: "#047857", border: "#a7f3d0" },
  scheduled: { bg: "#eff6ff", color: "#1d4ed8", border: "#bfdbfe" },
  failed: { bg: "#fef2f2", color: "#b91c1c", border: "#fecaca" },
  partial: { bg: "#fff7ed", color: "#c2410c", border: "#fed7aa" },
  deleted: { bg: "#f1f5f9", color: "#334155", border: "#94a3b8" },
  unknown: { bg: "#fffbeb", color: "#92400e", border: "#fde68a" },
};

export const STATUS_LABELS = {
  published: "Published",
  scheduled: "Scheduled",
  failed: "Failed",
  partial: "Partially published",
  deleted: "Deleted",
  unknown: "Unable to verify",
};

export const PLATFORM_SHORT = { facebook: "FB", instagram: "IG", youtube: "YT" };
const PLATFORM_NAMES = { facebook: "Facebook", instagram: "Instagram", youtube: "YouTube" };

function normalise(entry, key) {
  if (!entry || typeof entry !== "object") return null;
  const status = STATUS_LABELS[entry.status] ? entry.status : "unknown";
  return {
    key,
    platform: entry.platform || PLATFORM_NAMES[key] || key,
    short: PLATFORM_SHORT[key] || key,
    status,
    label: entry.label || STATUS_LABELS[status],
    message: entry.message || "",
    verifiedAt: entry.verified_at || null,
  };
}

/**
 * Status of a Latest Posts row for the section's platform (`primary`) and the post's other platforms
 * (`others`, e.g. a Facebook row whose Instagram copy was deleted). Rows from older API responses without
 * statuses are shown as Published (they were only ever listed when published).
 */
export function rowStatuses(post, platformKey) {
  const statuses = (post && post.statuses && typeof post.statuses === "object") ? post.statuses : {};
  const primary = normalise(post && post.platform_status, platformKey)
    || normalise(statuses[platformKey], platformKey)
    || { key: platformKey, platform: PLATFORM_NAMES[platformKey] || platformKey, short: PLATFORM_SHORT[platformKey] || platformKey, status: "published", label: STATUS_LABELS.published, message: "", verifiedAt: null };
  const others = Object.keys(statuses)
    .filter((k) => k !== platformKey)
    .map((k) => normalise(statuses[k], k))
    .filter(Boolean);
  const overall = post && STATUS_LABELS[post.status] ? post.status : primary.status;
  return { primary, others, overall };
}

/** Tooltip: "Instagram: Deleted - <reason> (checked 10/11/2026, 4:05 pm IST)". */
export function statusTooltip(s) {
  if (!s) return "";
  let text = `${s.platform}: ${s.label}`;
  if (s.message) text += ` - ${s.message}`;
  if (s.verifiedAt) {
    const d = new Date(s.verifiedAt);
    if (!Number.isNaN(d.getTime())) {
      text += ` (last confirmed ${d.toLocaleString("en-IN", { timeZone: "Asia/Kolkata", day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", hour12: true })} IST)`;
    }
  }
  return text;
}

/** Thumbnail input for <PostThumbnail>: the backend `thumbnail` block, or the old image_url field. */
export function thumbnailPost(post) {
  const preview = post && post.thumbnail && typeof post.thumbnail === "object"
    ? post.thumbnail
    : post && post.image_url
    ? { url: post.image_url, kind: "image", status: "ok", media_count: post.media_count ?? null }
    : { url: null, kind: "none", status: "none", media_count: post && post.media_count ? post.media_count : 0 };
  return { id: post && post.id, post_type: post && post.post_type, media: [], preview };
}
