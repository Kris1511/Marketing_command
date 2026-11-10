# Instagram media publishing: public HTTPS media URLs

Instagram's Content Publishing API does not accept file uploads. We send Meta an `image_url` (or `video_url`)
and **Meta's servers download the file themselves**. That URL must therefore be:

- `https://` on a public internet host. Never `localhost`, `127.0.0.1`, a LAN IP or an intranet name.
- downloadable without logging in, returning **HTTP 200** with `Content-Type: image/jpeg` (or `video/*`).
- an image Instagram accepts: JPEG, at most 8 MB, aspect ratio between 4:5 and 1.91:1.

Facebook publishing is different: it uploads the file bytes directly, so it works without any of this.

## How the app builds the URL

`App\Services\PublicMediaUrlService::resolveForInstagram()` runs for every Instagram image, carousel item
and video, whether the post is published now, from a draft, or by the scheduler:

1. Makes sure the file is on the `public` disk under `storage/app/public/posts/...`. PNG, WebP, GIF and BMP
   files are converted to a JPEG copy (`*.ig.jpg`). Files outside `storage/` are never published.
2. Builds the URL from `PUBLIC_MEDIA_URL`, or from `APP_URL` if that is empty, plus `/storage/<path>`.
   It never uses the host of the incoming request. That host was the original bug: `asset()` returned
   `http://localhost:8000/...`.
3. Rejects non-HTTPS, local and private hosts.
4. Downloads the URL from this server and checks for HTTP 200, `image/jpeg`, size and aspect ratio.
   Disable this with `PUBLIC_MEDIA_VERIFY=false`, but only if the server cannot reach its own public URL.
5. With `PUBLIC_MEDIA_DRIVER=auto`, if Cloudinary is configured and the storage URL fails step 3 or 4,
   the image is uploaded to Cloudinary and that HTTPS URL is used instead.
6. Only then is the Instagram media container created, polled until `FINISHED`, and published.

Media URLs stored on drafts and scheduled posts are never trusted. The URL is rebuilt from the local file
at publish time, so changing the tunnel address or the domain does not break saved posts.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `PUBLIC_MEDIA_URL` | *(empty → `APP_URL`)* | Public HTTPS base that serves `/storage/posts/...` from **this** server |
| `PUBLIC_MEDIA_DRIVER` | `auto` | `auto`: storage, then Cloudinary. `storage`: storage only. `cloudinary`: Cloudinary only |
| `PUBLIC_MEDIA_VERIFY` | `true` | Download-check the URL before calling Meta |
| `PUBLIC_MEDIA_VERIFY_TIMEOUT` | `15` | Seconds allowed for that check |
| `CLOUDINARY_CLOUD_NAME` / `CLOUDINARY_API_KEY` / `CLOUDINARY_API_SECRET` | *(empty)* | Optional fallback host |
| `INSTAGRAM_CONTAINER_POLL_SECONDS` | *(2 for images, 3 for video)* | Seconds between container status checks |

Run `php artisan config:clear` after changing `.env`. If you use `config:cache`, run it again instead.

## Local development with an HTTPS tunnel

`APP_URL` usually points at the production domain, which does **not** have files uploaded on your machine,
so Meta gets a 404. Expose this machine's media through a tunnel instead.

1. Make sure `public/storage` links to `storage/app/public`:
   ```bash
   php artisan storage:link   # if the link is stale, remove public/storage first
   ```
2. Start the media-only server. Run it from `backend/`, and keep `php artisan serve` running on :8000 as usual.
   ```bash
   php -S 127.0.0.1:8001 scripts/public-media-server.php
   ```
   This must be a separate process. `php artisan serve` handles one request at a time on Windows, and Meta
   downloads the image while our publish request is still waiting, so a single process would block. The
   script serves only `GET`/`HEAD` for `/storage/posts/*` images and videos and returns 404 for everything else.
3. Start a tunnel to it. Cloudflare Tunnel is recommended:
   ```bash
   cloudflared tunnel --url http://127.0.0.1:8001
   # prints https://<random-words>.trycloudflare.com
   ```
   ngrok also works (`ngrok http 8001`), but the free plan can serve an HTML "browser warning" page instead of
   the image. The download check reports that as `served as 'text/html'`.
4. Set the tunnel URL in `backend/.env` and reload the config:
   ```dotenv
   PUBLIC_MEDIA_URL=https://<random-words>.trycloudflare.com
   ```
   ```bash
   php artisan config:clear
   ```
   Then restart `php artisan serve` and the queue worker or scheduler if they are running.
5. Verify end to end. This makes no Meta API call:
   ```bash
   php artisan instagram:check-media-url
   php artisan instagram:check-media-url --url=https://<tunnel>/storage/posts/6/<file>.jpeg
   ```

Quick tunnels get a new URL each time they start, so repeat step 4 when that happens. Saved drafts and
scheduled posts pick up the new URL automatically.

## Production

- `APP_URL=https://your-api-domain` (HTTPS with a valid certificate). Leave `PUBLIC_MEDIA_URL` empty, or set
  it to a CDN that serves the same `/storage` files.
- Run `php artisan storage:link` on the server. The web server (nginx/Apache) serves `public/storage`
  statically and must not require auth for `/storage/posts/*`.
- If several app servers write uploads, `storage/app/public` must be shared storage, or use
  `PUBLIC_MEDIA_DRIVER=cloudinary`.
- After deploying: `php artisan config:cache` and `php artisan instagram:check-media-url`.
