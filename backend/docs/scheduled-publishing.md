# Scheduled publishing

## How a scheduled post is published

1. **Schedule** (Publishing page, *Schedule Post*): `POST /api/v1/facebook/publish-post` with `status=scheduled`.
   Each post is its own `facebook_posts` row (`status=scheduled`, `scheduled_at` in UTC). Its media live in
   `facebook_post_media` (`sort_order` = carousel order), and its custom thumbnail is in `thumbnail_path`.
   Caption, hashtags and target platforms (`platforms`) are saved on the row. Instagram media are checked
   against Instagram's format rules at this point.
2. **Scheduler**: `php artisan schedule:run` (every minute) runs `posts:process-scheduled`. That command:
   - finds rows with `status=scheduled` and `scheduled_at <= now`;
   - runs `PublishScheduledPostJob` for each one, oldest first. One post failing does not stop the others.
3. **Job**: locks the row (`status=processing`) and publishes it to each platform with that post's own media,
   caption and thumbnail. Each platform's ID (`fb_post_id`, `ig_media_id`, `yt_video_id`) is saved as soon as
   that platform succeeds, then the post is marked:
   - `published`: every platform succeeded, or some did and the others failed. The failures are in
     `platform_statuses` and `error_message`.
   - `scheduled` again, 5/10/15 minutes later: temporary errors such as rate limits, timeouts, or Instagram still
     processing the video. Up to 3 retries.
   - `failed`: permanent errors such as an expired token, a missing permission or rejected media.

   History is written to `facebook_post_history`.
4. **Retry** (*Retry* button → `POST /api/v1/posts/{id}/retry`): runs the same job again. Platforms that
   already have an ID are skipped, so nothing is published twice.

A delayed queue job is also dispatched when a post is scheduled. It only matters if a queue worker is
running. It checks the post's *current* `scheduled_at`, so a post that was rescheduled is never published
early, and the row lock prevents double publishing when both the worker and the scheduler fire.

## Running the scheduler (required)

Without a scheduler nothing publishes automatically. As a fallback, due posts are published when someone
opens the Publishing page, and that page shows a warning that the scheduler is not running.

| Environment | Setup |
|---|---|
| Windows (this machine) | `powershell -ExecutionPolicy Bypass -File scripts\windows\register-scheduler-task.ps1` registers a Task Scheduler task running `php artisan schedule:run` every minute. Remove it with `-Unregister`. |
| Windows, foreground | `php artisan schedule:work` (keep the window open) |
| Linux server | cron: `* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1` |
| Optional queue worker | `php artisan queue:work --queue=default --tries=1` (supervisor/systemd on Linux) |

Check with `php artisan schedule:list`. The Publishing page shows "Automatic publishing is not running" when
the scheduler has not run in the last 3 minutes.

## Recovery

- A post stuck in `processing` for more than 60 minutes (server stopped mid-publish) is marked `failed` by the
  next scheduler run. The message lists the platforms that already succeeded; *Retry* publishes only the rest.
- An expired or revoked Meta token (error 190) fails immediately with "Reconnect the account in API
  Connections" and is not retried automatically.

## Platform support for scheduled content

| Content | Facebook | Instagram | YouTube |
|---|---|---|---|
| Text | ✅ feed post | ❌ API requires media | ❌ video only |
| Single image | ✅ photo | ✅ image | ❌ |
| Carousel (2–10 images) | ✅ multi-photo post | ✅ carousel | ❌ |
| Video | ✅ page video (`/videos`) | ✅ published as a **Reel** | ✅ upload (YouTube treats vertical videos ≤ 3 min as Shorts) |
| Facebook Reel | ❌ not implemented (needs the separate `/{page-id}/video_reels` upload flow) | — | — |
| Custom thumbnail / cover | ✅ | ✅ Reel `cover_url` | ✅ (verified channels) |
