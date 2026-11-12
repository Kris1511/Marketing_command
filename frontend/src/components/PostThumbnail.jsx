import React, { useState } from 'react';
import { Image as ImageIcon, ImageOff, Layers, Loader2, Play, Video } from 'lucide-react';
import { postThumbnail, thumbnailProblem } from '../pages/publishingRules';

const PROBLEM_STATUSES = ['missing', 'expired', 'error'];

/**
 * Fixed-size thumbnail for a Publishing History row: image (custom thumbnail / first image / carousel cover),
 * the first frame of a video when no poster exists, or a placeholder that says why media is unavailable.
 * A load failure is shown as a placeholder (never a broken-image icon) and logged to the console.
 */
export default function PostThumbnail({ post, size = 48 }) {
  const thumb = postThumbnail(post);
  // Keyed by URL so the load state resets when pagination/refresh reuses the row for another post.
  return <ThumbnailView key={`${post?.id ?? ''}|${thumb.url}`} thumb={thumb} postId={post?.id} size={size} />;
}

function ThumbnailView({ thumb, postId, size }) {
  const [state, setState] = useState(thumb.url ? 'loading' : 'empty'); // loading | loaded | error | empty

  const handleError = () => {
    console.warn(`[Publishing history] Thumbnail of post ${postId} failed to load: ${thumb.url}`);
    setState('error');
  };

  const showMedia = Boolean(thumb.url) && state !== 'error';
  const problem = state === 'error' ? 'error' : (!thumb.url ? thumb.status : null);
  const isProblem = PROBLEM_STATUSES.includes(problem);
  const label = isProblem
    ? thumbnailProblem(problem)
    : thumb.isVideo
    ? 'Video'
    : thumb.isCarousel
    ? `Carousel · ${thumb.count} images`
    : thumb.url
    ? 'Image'
    : thumbnailProblem('none');

  const mediaStyle = {
    width: '100%',
    height: '100%',
    objectFit: 'cover',
    display: 'block',
    opacity: state === 'loaded' ? 1 : 0,
    transition: 'opacity 150ms ease',
  };

  const FallbackIcon = isProblem ? ImageOff : thumb.isVideo ? Video : thumb.isCarousel ? Layers : ImageIcon;

  return (
    <div
      role="img"
      aria-label={label}
      title={label}
      data-thumb-state={showMedia ? state : (problem || 'empty')}
      style={{
        position: 'relative',
        width: `${size}px`,
        height: `${size}px`,
        borderRadius: '8px',
        overflow: 'hidden',
        flexShrink: 0,
        background: isProblem ? '#fffbeb' : '#f1f5f9',
        border: `1px solid ${isProblem ? '#fde68a' : '#e2e8f0'}`,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        color: isProblem ? '#b45309' : '#94a3b8',
      }}
    >
      {showMedia && thumb.kind === 'video' && (
        <video
          src={thumb.url}
          muted
          playsInline
          preload="metadata"
          // Videos often open on a blank/fade-in frame, so show a frame ~1 s in (a quarter in for short clips).
          onLoadedMetadata={(e) => {
            const v = e.currentTarget;
            const at = Number.isFinite(v.duration) && v.duration > 0 ? Math.min(1, v.duration / 4) : 0;
            if (at > 0) v.currentTime = at;
            else setState('loaded');
          }}
          onSeeked={() => setState('loaded')}
          onError={handleError}
          style={mediaStyle}
        />
      )}
      {showMedia && thumb.kind === 'image' && (
        <img
          src={thumb.url}
          alt=""
          loading="lazy"
          decoding="async"
          onLoad={() => setState('loaded')}
          onError={handleError}
          style={mediaStyle}
        />
      )}
      {showMedia && state === 'loading' && (
        <Loader2 size={14} className="spin" style={{ position: 'absolute', color: '#94a3b8' }} aria-hidden="true" />
      )}
      {!showMedia && <FallbackIcon size={18} aria-hidden="true" />}

      {thumb.isVideo && showMedia && (
        <span
          aria-hidden="true"
          style={{ position: 'absolute', left: '3px', bottom: '3px', width: '16px', height: '16px', borderRadius: '50%', background: 'rgba(15, 23, 42, 0.72)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center' }}
        >
          <Play size={9} fill="currentColor" />
        </span>
      )}
      {thumb.isCarousel && thumb.count > 1 && (
        <span
          aria-hidden="true"
          style={{ position: 'absolute', right: '3px', top: '3px', padding: '0 4px', minWidth: '16px', height: '16px', borderRadius: '8px', background: 'rgba(15, 23, 42, 0.72)', color: '#fff', fontSize: '10px', fontWeight: 600, lineHeight: '16px', textAlign: 'center' }}
        >
          {thumb.count}
        </span>
      )}
    </div>
  );
}
