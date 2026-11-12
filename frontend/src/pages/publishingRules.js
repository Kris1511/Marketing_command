// Publishing composer rules. These values and messages mirror backend/app/Support/PublishingRules.php
// so the UI and the API always enforce (and report) the same limits.

export const MAX_IMAGE_MB = 20;
export const MAX_VIDEO_MB = 500;
export const CAROUSEL_MIN = 2;
export const CAROUSEL_MAX = 10;
export const INSTAGRAM_MAX_HASHTAGS = 30;

export const CAPTION_LIMITS = {
  Facebook: 63206,
  Instagram: 2200,
  YouTube: 5000,
};

// Formats the API accepts (mimes:mp4,mov,avi,mkv). WebM is intentionally not offered.
export const VIDEO_EXTENSIONS = ['.mp4', '.mov', '.avi', '.mkv'];
export const VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/avi', 'video/x-matroska'];
export const VIDEO_ACCEPT = [...VIDEO_MIME_TYPES, ...VIDEO_EXTENSIONS].join(',');

export const MESSAGES = {
  imageTooLarge: `Each image must be ${MAX_IMAGE_MB} MB or smaller.`,
  videoTooLarge: `The video must be ${MAX_VIDEO_MB} MB or smaller.`,
  videoFormat: 'The video must be an MP4, MOV, AVI or MKV file.',
  imageFormat: 'Only image files (JPG, PNG, GIF, WebP) can be attached as images.',
  carouselMin: `A carousel needs at least ${CAROUSEL_MIN} images.`,
  carouselMax: `A carousel can contain at most ${CAROUSEL_MAX} images.`,
  emptyPost: 'Add a caption or attach media before saving.',
  allWorkspaces: 'Please select an individual client workspace. "All Workspaces" cannot be used for publishing.',
};

const MB = 1024 * 1024;
export const formatMb = (bytes) => (bytes / MB).toFixed(1);

export function isAllWorkspaces(workspaceId) {
  return String(workspaceId ?? '').toLowerCase() === 'all';
}

export function isUsableWorkspaceId(workspaceId) {
  return /^\d+$/.test(String(workspaceId ?? '')) && Number(workspaceId) > 0;
}

export function validateImageFile(file) {
  if (!file || !String(file.type || '').startsWith('image/')) return MESSAGES.imageFormat;
  if (file.size > MAX_IMAGE_MB * MB) {
    return `"${file.name}" is ${formatMb(file.size)} MB. ${MESSAGES.imageTooLarge}`;
  }
  return '';
}

export function validateVideoFile(file) {
  if (!file) return MESSAGES.videoFormat;
  const ext = '.' + (String(file.name || '').split('.').pop() || '').toLowerCase();
  const typeOk = VIDEO_MIME_TYPES.includes(file.type);
  const extOk = VIDEO_EXTENSIONS.includes(ext);
  if (!typeOk && !extOk) return MESSAGES.videoFormat;
  if (file.size > MAX_VIDEO_MB * MB) {
    return `"${file.name}" is ${formatMb(file.size)} MB. ${MESSAGES.videoTooLarge}`;
  }
  return '';
}

// Custom video thumbnail / Reel cover (mirrors PublishingRules::THUMBNAIL_* in the API).
export const THUMBNAIL_MAX_MB = 8;
export const THUMBNAIL_MIN_SIDE = 320;
export const THUMBNAIL_MIN_RATIO = 9 / 16;
export const THUMBNAIL_MAX_RATIO = 16 / 9;
export const THUMBNAIL_ACCEPT = 'image/jpeg,image/png,.jpg,.jpeg,.png';
const THUMBNAIL_TYPES = ['image/jpeg', 'image/png'];
const THUMBNAIL_EXTENSIONS = ['jpg', 'jpeg', 'png'];

/** Type and size check for a thumbnail file (dimensions are checked once the image is decoded). */
export function validateThumbnailFile(file) {
  if (!file) return 'Choose a JPG or PNG image for the thumbnail.';
  const ext = (String(file.name || '').split('.').pop() || '').toLowerCase();
  if (!THUMBNAIL_TYPES.includes(file.type) || !THUMBNAIL_EXTENSIONS.includes(ext)) {
    return 'The thumbnail must be a JPG or PNG image.';
  }
  if (file.size > THUMBNAIL_MAX_MB * MB) {
    return `"${file.name}" is ${formatMb(file.size)} MB. The thumbnail must be ${THUMBNAIL_MAX_MB} MB or smaller.`;
  }
  return '';
}

/** Dimension / aspect-ratio check for a decoded thumbnail image. */
export function validateThumbnailDimensions(width, height) {
  if (!width || !height) return 'The thumbnail is not a valid image.';
  if (Math.min(width, height) < THUMBNAIL_MIN_SIDE) {
    return `The thumbnail is ${width}x${height}px. Each side must be at least ${THUMBNAIL_MIN_SIDE}px (1280x720 or 1080x1920 recommended).`;
  }
  const ratio = width / height;
  if (ratio < THUMBNAIL_MIN_RATIO - 0.01 || ratio > THUMBNAIL_MAX_RATIO + 0.01) {
    return `The thumbnail is ${width}x${height}px. Use an aspect ratio between 9:16 (portrait Reel cover) and 16:9 (landscape video thumbnail).`;
  }
  return '';
}

/**
 * Human label for what a post is: Text / Image / Carousel / Video / Reel. Instagram publishes every video as a
 * Reel, so a video going only to Instagram is a "Reel" and a video going to Instagram plus others is "Video / Reel".
 */
export function contentTypeLabel(postType, platforms = []) {
  const list = Array.isArray(platforms) ? platforms.map((p) => String(p).toLowerCase()) : [];
  switch (postType) {
    case 'single_image':
    case 'photo':
      return 'Image';
    case 'multi_image':
    case 'carousel':
      return 'Carousel';
    case 'video':
    case 'reel':
      if (list.includes('instagram')) return list.length === 1 ? 'Reel' : 'Video / Reel';
      return 'Video';
    case 'text':
    case 'link':
      return 'Text';
    default:
      return 'Post';
  }
}

/** Status shown to users ('processing' is the job running right now). */
export function statusLabel(status) {
  const labels = { scheduled: 'Scheduled', processing: 'Publishing', published: 'Published', partial: 'Partially published', failed: 'Failed', draft: 'Draft' };
  return labels[status] || status || '';
}

/** Best preview image of a saved post: its custom thumbnail, else its first image (never a video file). */
export function postPreviewImage(post) {
  if (!post) return '';
  if (post.thumbnail_url) return post.thumbnail_url;
  const media = Array.isArray(post.media) ? post.media : [];
  const firstImage = media.find((m) => m && m.media_type === 'image' && m.file_url);
  return firstImage ? firstImage.file_url : '';
}

const IMAGE_FILE = /\.(jpe?g|png|webp|gif)(\?|#|$)/i;

/**
 * Thumbnail of a history row: { url, kind: 'image'|'video'|'none', status, isVideo, isCarousel, count }.
 * Uses the backend `preview` block (URLs rebuilt from the stored file, expired/missing media flagged); older
 * responses without it fall back to the stored URLs.
 */
export function postThumbnail(post) {
  const media = post && Array.isArray(post.media)
    ? [...post.media].filter(Boolean).sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || (a.id ?? 0) - (b.id ?? 0))
    : [];
  const type = post?.post_type || '';
  const isVideo = type === 'video' || type === 'reel' || media.some((m) => m.media_type === 'video');
  const isCarousel = type === 'multi_image' || (!isVideo && media.length > 1);
  const base = { isVideo, isCarousel, count: media.length };
  if (!post) return { ...base, url: '', kind: 'none', status: 'none' };

  const p = post.preview;
  if (p && typeof p === 'object') {
    return {
      ...base,
      count: Number.isFinite(p.media_count) ? p.media_count : media.length,
      url: p.url || '',
      kind: p.url ? (p.kind === 'video' ? 'video' : 'image') : 'none',
      status: p.status || (p.url ? 'ok' : 'none'),
    };
  }

  const urlOf = (m) => m.display_url || m.file_url || '';
  if (post.thumbnail_url) return { ...base, url: post.thumbnail_url, kind: 'image', status: 'ok' };
  // An imported video's stored file is often its poster image, so look at the file, not only media_type.
  const image = media.find((m) => urlOf(m) && (m.media_type === 'image' || IMAGE_FILE.test(urlOf(m))));
  if (image) return { ...base, url: urlOf(image), kind: 'image', status: 'ok' };
  const video = media.find((m) => urlOf(m) && m.media_type === 'video');
  if (video) return { ...base, url: urlOf(video), kind: 'video', status: 'ok' };
  return { ...base, url: '', kind: 'none', status: media.length ? 'missing' : 'none' };
}

/** Tooltip text for a thumbnail placeholder. */
export function thumbnailProblem(status) {
  if (status === 'missing') return 'The original media file is no longer available on the server.';
  if (status === 'expired') return 'The Facebook image link has expired and no saved copy is available yet.';
  if (status === 'error') return 'The media file could not be loaded.';
  return 'No media attached.';
}

/** "YYYY-MM-DD" of an instant in IST (the app's scheduling timezone). */
export function istDateKey(value) {
  if (!value) return '';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-CA', { timeZone: 'Asia/Kolkata' });
}

/** Moves the item at `index` to the front (carousel cover), keeping the order of the others. */
export function moveToFront(list, index) {
  if (!Array.isArray(list) || index <= 0 || index >= list.length) return list;
  return [list[index], ...list.slice(0, index), ...list.slice(index + 1)];
}

/** Lowest caption limit of the selected platforms (falls back to Facebook's when none selected). */
export function captionLimitFor(platformNames) {
  const limits = (platformNames || []).map((p) => CAPTION_LIMITS[p]).filter(Boolean);
  return limits.length ? Math.min(...limits) : CAPTION_LIMITS.Facebook;
}

export function hashtagTokens(text) {
  return String(text || '')
    .split(/[\s,]+/u)
    .filter(Boolean);
}

/** Normalised form that is sent to the API and appended to the caption: "#a, #b   #c" -> "#a #b #c". */
export function normalizeHashtags(text) {
  return hashtagTokens(text).join(' ');
}

const HASHTAG = /^#[\p{L}\p{N}_]{1,100}$/u;

/** Returns a list of human-readable problems (empty when the hashtags are fine). */
export function hashtagProblems(text, { instagram = false, caption = '' } = {}) {
  const problems = [];
  const tokens = hashtagTokens(text);
  const bad = tokens.find((t) => !HASHTAG.test(t));
  if (bad) {
    problems.push(`Invalid hashtag "${bad.slice(0, 40)}". Use #word with letters, numbers or underscores only.`);
  }
  if (instagram) {
    const inCaption = (String(caption).match(/(?<![\p{L}\p{N}_#])#[\p{L}\p{N}_]+/gu) || []).length;
    const count = Math.max(tokens.length, inCaption);
    if (count > INSTAGRAM_MAX_HASHTAGS) {
      problems.push(`Instagram allows at most ${INSTAGRAM_MAX_HASHTAGS} hashtags (you have ${count}).`);
    }
  }
  return problems;
}

/** Caption + hashtags exactly as the API will receive them in `message`. */
export function buildMessage(caption, hashtags) {
  const tags = normalizeHashtags(hashtags);
  return tags ? `${caption}\n\n${tags}` : caption;
}

/** Inverse of buildMessage for a saved draft: splits the stored message back into caption and hashtags. */
export function splitMessage(content, hashtags) {
  const text = String(content || '');
  const tags = String(hashtags || '').trim();
  if (tags && text.endsWith(`\n\n${tags}`)) {
    return { caption: text.slice(0, -(tags.length + 2)), hashtags: tags };
  }
  if (tags && text === tags) {
    return { caption: '', hashtags: tags };
  }
  return { caption: text, hashtags: tags };
}

/** Title YouTube will actually receive: explicit video title, else first 60 chars of the message (as the API does). */
export function youtubeTitleFor(videoTitle, message) {
  const explicit = String(videoTitle || '').trim();
  if (explicit) return explicit;
  const text = String(message || '').trim();
  return text ? Array.from(text).slice(0, 60).join('') : 'Uploaded Video';
}

export function contentTypeFromPostType(postType) {
  if (postType === 'video') return 'video';
  if (postType === 'multi_image') return 'carousel';
  return 'post';
}

/**
 * Validates that a scheduled date and time is strictly in the future (Asia/Kolkata +05:30).
 * Requires at least 60 seconds buffer from now.
 */
export function isFutureSchedule(dateStr, timeStr, nowMs = Date.now()) {
  if (!dateStr || !timeStr) return false;
  const isoStr = `${dateStr}T${timeStr}:00+05:30`;
  const timestamp = new Date(isoStr).getTime();
  if (isNaN(timestamp)) return false;
  return timestamp > nowMs + 60 * 1000;
}

/** YouTube visibility offered in Publishing. Unlisted is no longer offered. Mirrors PublishingRules::YOUTUBE_PRIVACY. */
export const YOUTUBE_PRIVACY_OPTIONS = [
  { id: 'public', label: 'Public', desc: 'Anyone on YouTube can watch' },
  { id: 'private', label: 'Private', desc: 'Only you can watch' },
];
export const DEFAULT_YOUTUBE_PRIVACY = 'public';
export const YOUTUBE_PRIVACY_REQUIRED =
  'This post was saved as Unlisted, which is no longer available. Choose Public or Private for YouTube.';

export function isValidYoutubePrivacy(value) {
  return value === 'public' || value === 'private';
}

/**
 * Composer value for a saved post: its Public/Private choice; '' (the user must choose) for a YouTube post saved
 * as Unlisted, so it never becomes Public without the user confirming; Public otherwise (posts that never
 * targeted YouTube only carry the old column default).
 */
export function youtubePrivacyFromSaved(saved, platformList) {
  if (isValidYoutubePrivacy(saved)) return saved;
  const targetsYouTube = Array.isArray(platformList) && platformList.includes('YouTube');
  return saved === 'unlisted' && targetsYouTube ? '' : DEFAULT_YOUTUBE_PRIVACY;
}

export function youtubePrivacyLabel(value) {
  return value === 'public' ? 'Public' : value === 'private' ? 'Private' : 'Not chosen';
}

/** YouTube's own custom-thumbnail size limit (PublishingRules::YOUTUBE_THUMBNAIL_MAX_MB). */
export const YOUTUBE_THUMBNAIL_MAX_MB = 2;

/** Why YouTube would reject this thumbnail file ('' when it is fine). Only checked when YouTube is a target. */
export function youtubeThumbnailProblem(file) {
  if (!file || typeof file.size !== 'number') return '';
  return file.size > YOUTUBE_THUMBNAIL_MAX_MB * MB
    ? `YouTube accepts custom thumbnails up to ${YOUTUBE_THUMBNAIL_MAX_MB} MB; "${file.name}" is ${formatMb(file.size)} MB. Choose a smaller image or untick YouTube.`
    : '';
}

/**
 * Custom-thumbnail outcome of a post's published YouTube video, from platform_statuses.youtube.thumbnail:
 * { status: 'applied'|'pending'|'failed', label, message, canRetry }, or null when no thumbnail was requested.
 */
export function youtubeThumbnailState(post) {
  const yt = post && post.platform_statuses && post.platform_statuses.youtube;
  const t = yt && yt.thumbnail;
  if (!yt || yt.status !== 'published' || !t || !t.requested) return null;
  const status = t.applied ? 'applied' : t.status === 'pending' ? 'pending' : 'failed';
  const label = { applied: 'YouTube thumbnail applied', pending: 'YouTube thumbnail pending', failed: 'YouTube thumbnail failed' }[status];
  return { status, label, message: t.message || '', canRetry: status !== 'applied' };
}
