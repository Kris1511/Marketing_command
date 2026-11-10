// Run with: npm test   (uses Node's built-in test runner, no extra dependencies)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  MAX_IMAGE_MB,
  MESSAGES,
  buildMessage,
  captionLimitFor,
  contentTypeFromPostType,
  hashtagProblems,
  isAllWorkspaces,
  isUsableWorkspaceId,
  isFutureSchedule,
  normalizeHashtags,
  splitMessage,
  validateImageFile,
  validateVideoFile,
  youtubeTitleFor,
} from './publishingRules.js';

const MB = 1024 * 1024;
const file = (name, type, mb) => ({ name, type, size: Math.round(mb * MB) });

test('image limit is 20 MB and matches the API message', () => {
  assert.equal(MAX_IMAGE_MB, 20);
  assert.equal(validateImageFile(file('a.jpg', 'image/jpeg', 19.9)), '');
  assert.match(validateImageFile(file('big.jpg', 'image/jpeg', 22)), /Each image must be 20 MB or smaller\./);
  assert.equal(validateImageFile(file('a.txt', 'text/plain', 0.1)), MESSAGES.imageFormat);
});

test('video formats: mp4/mov/avi/mkv accepted, webm and wmv rejected, 500 MB cap', () => {
  for (const [name, type] of [['a.mp4', 'video/mp4'], ['a.mov', 'video/quicktime'], ['a.avi', 'video/x-msvideo'], ['a.mkv', 'video/x-matroska']]) {
    assert.equal(validateVideoFile(file(name, type, 5)), '', name);
  }
  assert.equal(validateVideoFile(file('a.webm', 'video/webm', 5)), MESSAGES.videoFormat);
  assert.equal(validateVideoFile(file('a.wmv', 'video/x-ms-wmv', 5)), MESSAGES.videoFormat);
  assert.match(validateVideoFile(file('a.mp4', 'video/mp4', 501)), /The video must be 500 MB or smaller\./);
});

test('caption limit is the lowest of the selected platforms', () => {
  assert.equal(captionLimitFor(['Facebook']), 63206);
  assert.equal(captionLimitFor(['Facebook', 'Instagram']), 2200);
  assert.equal(captionLimitFor(['YouTube']), 5000);
  assert.equal(captionLimitFor([]), 63206);
});

test('hashtags are normalised and validated', () => {
  assert.equal(normalizeHashtags('#a,  #b   #c'), '#a #b #c');
  assert.deepEqual(hashtagProblems('#ok #fine_2'), []);
  assert.match(hashtagProblems('#ok nohash')[0], /Invalid hashtag "nohash"/);
  assert.match(hashtagProblems('#bad-tag')[0], /Invalid hashtag/);
  const thirty = Array.from({ length: 30 }, (_, i) => `#t${i}`).join(' ');
  assert.deepEqual(hashtagProblems(thirty, { instagram: true }), []);
  assert.match(hashtagProblems(`${thirty} #t31`, { instagram: true })[0], /at most 30 hashtags \(you have 31\)/);
  assert.deepEqual(hashtagProblems(`${thirty} #t31`, { instagram: false }), []);
  assert.match(hashtagProblems('', { instagram: true, caption: `${thirty} #x` })[0], /at most 30/);
});

test('message round-trips between composer fields and the stored draft', () => {
  const msg = buildMessage('Hello world', '#a, #b');
  assert.equal(msg, 'Hello world\n\n#a #b');
  assert.deepEqual(splitMessage(msg, '#a #b'), { caption: 'Hello world', hashtags: '#a #b' });
  assert.deepEqual(splitMessage('plain text', null), { caption: 'plain text', hashtags: '' });
});

test('YouTube preview title follows what the API sends, never the internal title', () => {
  assert.equal(youtubeTitleFor('Launch day', 'caption'), 'Launch day');
  assert.equal(youtubeTitleFor('', 'x'.repeat(100)), 'x'.repeat(60));
  assert.equal(youtubeTitleFor('  ', ''), 'Uploaded Video');
});

test('workspace helpers', () => {
  assert.equal(isAllWorkspaces('all'), true);
  assert.equal(isAllWorkspaces('7'), false);
  assert.equal(isUsableWorkspaceId('7'), true);
  assert.equal(isUsableWorkspaceId('all'), false);
  assert.equal(isUsableWorkspaceId(''), false);
});

test('post type maps back to the composer content type', () => {
  assert.equal(contentTypeFromPostType('video'), 'video');
  assert.equal(contentTypeFromPostType('multi_image'), 'carousel');
  assert.equal(contentTypeFromPostType('single_image'), 'post');
  assert.equal(contentTypeFromPostType('text'), 'post');
});

test('scheduled date and time must be strictly in the future with buffer', () => {
  const baseMs = new Date('2026-11-06T12:00:00+05:30').getTime();
  // 30 mins in future
  assert.equal(isFutureSchedule('2026-11-06', '12:30', baseMs), true);
  // Tomorrow
  assert.equal(isFutureSchedule('2026-11-07', '09:00', baseMs), true);
  // Past (same day earlier)
  assert.equal(isFutureSchedule('2026-11-06', '11:00', baseMs), false);
  // Past (yesterday)
  assert.equal(isFutureSchedule('2026-11-05', '12:00', baseMs), false);
  // Exactly now (less than 60s buffer)
  assert.equal(isFutureSchedule('2026-11-06', '12:00', baseMs), false);
  // Missing inputs
  assert.equal(isFutureSchedule('', '12:00', baseMs), false);
  assert.equal(isFutureSchedule('2026-11-06', '', baseMs), false);
});

test('thumbnail file type and size', async () => {
  const { validateThumbnailFile, THUMBNAIL_MAX_MB } = await import('./publishingRules.js');
  const mb = 1024 * 1024;
  assert.equal(validateThumbnailFile({ name: 'cover.jpg', type: 'image/jpeg', size: 2 * mb }), '');
  assert.equal(validateThumbnailFile({ name: 'cover.PNG', type: 'image/png', size: 1 * mb }), '');
  assert.match(validateThumbnailFile({ name: 'cover.webp', type: 'image/webp', size: mb }), /JPG or PNG/);
  assert.match(validateThumbnailFile({ name: 'cover.gif', type: 'image/gif', size: mb }), /JPG or PNG/);
  assert.match(validateThumbnailFile({ name: 'big.jpg', type: 'image/jpeg', size: (THUMBNAIL_MAX_MB + 1) * mb }), /8 MB or smaller/);
  assert.match(validateThumbnailFile(null), /JPG or PNG/);
});

test('thumbnail dimensions and aspect ratio', async () => {
  const { validateThumbnailDimensions } = await import('./publishingRules.js');
  assert.equal(validateThumbnailDimensions(1280, 720), '');   // 16:9 landscape
  assert.equal(validateThumbnailDimensions(1080, 1920), '');  // 9:16 Reel cover
  assert.equal(validateThumbnailDimensions(1080, 1080), '');  // square
  assert.match(validateThumbnailDimensions(300, 300), /at least 320px/);
  assert.match(validateThumbnailDimensions(2000, 500), /aspect ratio/);
  assert.match(validateThumbnailDimensions(500, 2000), /aspect ratio/);
  assert.match(validateThumbnailDimensions(0, 0), /not a valid image/);
});

test('moveToFront makes a carousel slide the cover and keeps the rest in order', async () => {
  const { moveToFront } = await import('./publishingRules.js');
  assert.deepEqual(moveToFront(['a', 'b', 'c', 'd'], 2), ['c', 'a', 'b', 'd']);
  assert.deepEqual(moveToFront(['a', 'b'], 0), ['a', 'b']);
  assert.deepEqual(moveToFront(['a', 'b'], 5), ['a', 'b']);
});

test('content type labels follow what each platform actually publishes', async () => {
  const { contentTypeLabel } = await import('./publishingRules.js');
  assert.equal(contentTypeLabel('single_image', ['Facebook']), 'Image');
  assert.equal(contentTypeLabel('multi_image', ['Facebook', 'Instagram']), 'Carousel');
  assert.equal(contentTypeLabel('video', ['Facebook']), 'Video');
  assert.equal(contentTypeLabel('video', ['YouTube']), 'Video');
  assert.equal(contentTypeLabel('video', ['Instagram']), 'Reel');
  assert.equal(contentTypeLabel('video', ['Facebook', 'Instagram']), 'Video / Reel');
  assert.equal(contentTypeLabel('text', ['Facebook']), 'Text');
});

test('status labels, preview image and IST day keys', async () => {
  const { statusLabel, postPreviewImage, istDateKey } = await import('./publishingRules.js');
  assert.equal(statusLabel('processing'), 'Publishing');
  assert.equal(statusLabel('partial'), 'Partially published');
  assert.equal(postPreviewImage({ thumbnail_url: 't.jpg', media: [{ media_type: 'image', file_url: 'a.jpg' }] }), 't.jpg');
  assert.equal(postPreviewImage({ media: [{ media_type: 'video', file_url: 'v.mp4' }, { media_type: 'image', file_url: 'a.jpg' }] }), 'a.jpg');
  assert.equal(postPreviewImage({ media: [{ media_type: 'video', file_url: 'v.mp4' }] }), '');
  // 20:30 UTC on 9 Nov is 02:00 IST on 10 Nov
  assert.equal(istDateKey('2026-11-09T20:30:00Z'), '2026-11-10');
  assert.equal(istDateKey(''), '');
});
