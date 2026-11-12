// Run with: npm test
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rowStatuses, statusTooltip, thumbnailPost, STATUS_LABELS } from './overviewStatus.js';

test('row statuses: primary for the section platform, others listed separately', () => {
  const post = {
    status: 'partial',
    platform_status: { platform: 'Facebook', status: 'published', label: 'Published', message: 'Confirmed on Facebook.' },
    statuses: {
      facebook: { platform: 'Facebook', status: 'published', label: 'Published' },
      instagram: { platform: 'Instagram', status: 'deleted', label: 'Deleted', message: 'Deleted from Instagram' },
      youtube: { platform: 'YouTube', status: 'unknown', label: 'Unable to verify', message: 'token expired' },
    },
  };
  const r = rowStatuses(post, 'facebook');
  assert.equal(r.primary.status, 'published');
  assert.deepEqual(r.others.map((o) => [o.short, o.status]), [['IG', 'deleted'], ['YT', 'unknown']]);
  assert.equal(r.overall, 'partial');
});

test('row statuses: older payloads without statuses show Published; unknown values are not trusted', () => {
  assert.equal(rowStatuses({ id: 1 }, 'instagram').primary.status, 'published');
  assert.equal(rowStatuses({ platform_status: { status: 'weird' } }, 'facebook').primary.status, 'unknown');
  assert.deepEqual(Object.keys(STATUS_LABELS), ['published', 'scheduled', 'failed', 'partial', 'deleted', 'unknown']);
});

test('tooltip includes the reason and IST check time', () => {
  const t = statusTooltip({ platform: 'Instagram', label: 'Deleted', message: 'Deleted from Instagram', verifiedAt: '2026-11-10T10:30:00Z' });
  assert.match(t, /^Instagram: Deleted - Deleted from Instagram \(last confirmed 10\/11\/2026, 04:00 pm IST\)$/i);
  assert.equal(statusTooltip(null), '');
});

test('thumbnail input prefers the backend block and falls back to image_url', () => {
  const block = { url: 'u', kind: 'image', status: 'ok', source: 'custom', media_count: 1 };
  assert.equal(thumbnailPost({ id: 1, post_type: 'video', thumbnail: block }).preview, block);
  assert.deepEqual(thumbnailPost({ id: 2, image_url: 'x.jpg', media_count: 3 }).preview, { url: 'x.jpg', kind: 'image', status: 'ok', media_count: 3 });
  assert.equal(thumbnailPost({ id: 3 }).preview.kind, 'none');
});
