# Immich Album Sync

Mirror an Immich shared album into your own Immich, on a schedule you control.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4)
![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20)

Immich Album Sync is a small self-hosted companion to [Immich](https://immich.app). Point it at someone's shared album — yours or a friend's — and it keeps a mirror of that album in your own Immich, fully indexed by your existing setup (machine learning, faces, mobile sync, the lot). New photos in the source show up in your library on the next tick. Removed photos follow whichever policy you pick.

Each user logs in with their **own Immich credentials**. Immich Album Sync never stores your password — it round-trips them once to provision a scoped API key on your Immich, encrypts that key at rest, and uses it for all sync work.

> **Status: v0.2.1** — Two-way sync against any Immich account is shipping. Phase 2 (Google Photos shared-link source) is on the roadmap.

---

## Features

- **Mirror any Immich shared album.** Public links (with or without password) and API-key + album ID flows are both supported.
- **Per-album cron schedule.** Every 15 minutes, hourly, daily — whatever you set.
- **Run history with mid-run progress.** Every sync writes a `JobRun` row with structured logs, asset counts that update during the run, and a one-click re-run.
- **Cross-album dedup.** If an asset already exists somewhere in your Immich (matched by checksum), Immich Album Sync just adds it to the mirror album without re-uploading.
- **Configurable delete policy.** When the source removes a photo: keep it in your album, take it out of just this album, or send it to your Immich trash.
- **Two-way capable.** Connect to a remote Immich account and push your local additions back to its album, in addition to pulling.
- **Multi-user.** Each user gets a scoped panel; they only see their own albums and runs.
- **Single container.** One Docker image runs nginx + php-fpm + the scheduler + the queue worker, all under s6-overlay. No Redis, no Postgres, no microservices.
- **SQLite-only state.** Everything Immich Album Sync knows lives in one file you can back up.

---

## Quick start (any Docker host)

```bash
mkdir immich-album-sync && cd immich-album-sync
curl -O https://raw.githubusercontent.com/Taronyuu/immich-album-sync/main/docker-compose.yml

export APP_KEY="base64:$(openssl rand -base64 32)"
docker compose up -d

open http://localhost:47283
```

That's the whole thing. Immich Album Sync creates the SQLite database on first boot, runs migrations, brings up the panel.

If you'd rather pin a version, swap `:latest` for `:v0.2.1` in `docker-compose.yml`.

### About `APP_KEY`

This is the encryption key Immich Album Sync uses to seal the per-user Immich API keys it stores. **Set it once and keep it.** If you lose it, all stored API keys become unreadable — every user just logs back in to re-provision and you're whole again, but it's an avoidable round trip.

Persist it in a `.env` file next to `docker-compose.yml`:

```bash
echo "APP_KEY=base64:$(openssl rand -base64 32)" > .env
docker compose up -d
```

### Permissions on Linux hosts

The container runs as a non-root user (UID `9999` by default). If your `./data` directory is owned by a different UID, the container can't write the SQLite file on first boot. Two fixes:

- **Set `PUID` and `PGID`** to match your host user in `docker-compose.yml` (or `.env`).
- **Or** `sudo chown -R 9999:9999 ./data` once after creation.

---

## Run on TrueNAS as a Custom App

1. **Create a dataset** for Immich Album Sync's persistent state:

   ```
   Datasets → tank (or your pool) → Add Dataset
   Name: immich-album-sync
   Result: /mnt/tank/immich-album-sync
   ```

2. **Generate an `APP_KEY`** on any machine, or use a throwaway container:

   ```bash
   docker run --rm serversideup/php:8.4-cli \
     php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
   ```

3. **Apps → Discover Apps → Custom App**, paste the compose YAML below, and edit:

   - Replace `${APP_KEY}` with the key you generated.
   - Set `APP_URL` to where you'll reach the panel (e.g. `http://truenas.local:47283`, your Tailscale hostname, or a reverse-proxied domain).
   - Set the volume host path to your dataset (e.g. `/mnt/tank/immich-album-sync`).
   - Set `PUID` and `PGID` to `568:568` (the TrueNAS `apps` user) so the container can write to the dataset.

   ```yaml
   services:
     app:
       image: ghcr.io/taronyuu/immich-album-sync:latest
       restart: unless-stopped
       ports:
         - "47283:8080"
       volumes:
         - /mnt/tank/immich-album-sync:/var/www/html/database/data
       environment:
         APP_NAME: "Immich Album Sync"
         APP_ENV: production
         APP_DEBUG: "false"
         APP_URL: http://truenas.local:47283
         APP_KEY: "<paste your generated key>"
         DB_CONNECTION: sqlite
         DB_DATABASE: /var/www/html/database/data/immich-album-sync.sqlite
         SESSION_DRIVER: database
         QUEUE_CONNECTION: database
         CACHE_STORE: database
         AUTORUN_ENABLED: "true"
         AUTORUN_LARAVEL_MIGRATION: "true"
         AUTORUN_LARAVEL_STORAGE_LINK: "true"
         PHP_OPCACHE_ENABLE: "1"
         SSL_MODE: "off"
         PUID: "568"
         PGID: "568"
   ```

4. **Save.** TrueNAS pulls the image, runs migrations on first boot via `AUTORUN_LARAVEL_MIGRATION`, and exposes the panel on `:47283`.

5. **Browse to it** and log in with your Immich URL + email + password.

> HTTPS is intentionally out of scope. Tailnet users have transport encryption already; LAN users can front the panel with TrueNAS's built-in reverse proxy or any ingress they already run.

---

## How authentication works

When you log in:

1. Immich Album Sync calls `POST /api/auth/login` on the Immich URL you provided, with your email and password.
2. On success, a local user record is created (or fetched) keyed off `(immich_base_url, immich_user_id)`.
3. **First login only** — `POST /api/api-keys` provisions a scoped key named *"Immich Album Sync (auto-provisioned)"* on your Immich, with these eight permissions:

   `asset.upload`, `asset.read`, `asset.download`, `album.create`, `album.read`, `album.update`, `albumAsset.create`, `albumAsset.delete`

4. The API key is encrypted (AES-256, derived from your `APP_KEY`) and stored on the user record. Your password is never persisted.

You can revoke the key any time from Immich's "API Keys" page. The next sync will fail with a clear message; log out and back in to provision a fresh one.

---

## Creating a sync

The fastest way is the **Quick Add** card on the dashboard: paste a shared-link URL like `https://your-immich.example.com/share/AbCdEf-...`, hit save, and the album form is pre-filled from the link. The new album lands inactive — you can fill in the share password (if any) and confirm the schedule before flipping it on.

If you'd rather configure manually, the **Albums** page has a full form:

| Field | What it means |
|---|---|
| **Source: Public shared link** | Paste the share key (the random string from a public album link). Optional password if the link is password-protected. Read-only — `direction` must be `pull`. |
| **Source: Connected Immich account** | Enter the remote URL + your email + password and click Connect; an API key is auto-provisioned and stored encrypted. Read+write capable. |
| **Sync direction** | `Pull` (default), `Push`, or `Two-way`. Push and Two-way are only available for the connected-account source. |
| **Schedule** | Cron expression (default `*/15 * * * *`). |
| **Target album name** | The local album that will be created (lazily, on first sync) and continually updated. |
| **When source removes a photo** | `remove-from-album` (default), `trash`, or `ignore`. Applies to the pull direction; deletes are never propagated outward in v0.2.x. |

Click **Run now** on a row to dispatch immediately instead of waiting for the next tick.

---

## Updating

```bash
docker compose pull
docker compose up -d
```

Your data volume (`./data` or the TrueNAS dataset) is preserved across updates. Migrations run automatically on container start.

---

## Backup

Everything stateful — albums, mappings, run history, sessions, queued jobs, encrypted API keys — lives in one SQLite file at `<your-data-dir>/immich-album-sync.sqlite`. Back up that directory like any other.

---

## Troubleshooting

- **"Could not decrypt" errors after a restart** — your `APP_KEY` changed or was lost. Re-set it to the original value, or have each user log in again to re-provision their API key.
- **A scheduled run was skipped** — check the album is active (the toggle on the row); inactive albums skip scheduled runs but still allow manual "Run now".
- **A run failed with an HTTP 401 from the source** — the share-link password changed or the link expired. Edit the album, fix the credentials, run again.
- **A run failed with HTTP 401 on your own Immich** — your auto-provisioned API key was revoked. Log out and back in to provision a fresh one.

---

## Architecture

PHP 8.4 + Laravel 13 + Filament 4 + SQLite. A single Docker container runs nginx, php-fpm, the Laravel scheduler, and a queue worker side-by-side under [s6-overlay](https://github.com/just-containers/s6-overlay). All sync work hits Immich's REST API directly — no shared filesystem, no plugin layer, no Immich modifications.

A `SourceBackend` interface keeps the sync engine decoupled from the source: today there are two implementations (Immich shared link, Immich API key); Phase 2 adds a Google Photos one as a drop-in.

For a deeper tour of the codebase — Eloquent models, the sync engine, Filament resources, gotchas — see [`CLAUDE.md`](CLAUDE.md).

---

## Development

```bash
cp .env.example .env
docker compose -f docker-compose.dev.yml up -d
docker compose -f docker-compose.dev.yml exec app php artisan migrate
open http://localhost:47283
```

The dev compose runs three sibling containers (app, scheduler, queue) with a bind-mount of `./` so file changes show up live. Tests run on the host:

```bash
php artisan test
```

26 tests, 69 assertions covering the auth flow, sync engine (pull + push passes), album scoping, share-URL parsing, and the remote-account connector.

Useful Artisan commands inside the dev container:

```bash
php artisan sync:run --once --album=<id>   # run an album sync inline
php artisan sync:run --album=<id>          # dispatch via the queue
php artisan sync:run --all                 # ignore schedule, dispatch every active album
```

---

## Roadmap

- **Phase 1 (now)** — Immich → Immich shared albums, with public-link, password-protected-link, and connected-account sources, including two-way sync. Done.
- **Phase 2** — Google Photos shared-link source via public-page scraping. (Google deprecated the OAuth API for shared albums in 2025; scraping is the only viable path.)
- **Future** — anything that proves itself useful: notifications on failure, conflict policies for two-way sync, etc.

---

## Contributing

Bug reports and PRs welcome. For codebase orientation, the architecture map, and the trade-offs already made, read [`CLAUDE.md`](CLAUDE.md) first — it'll save you reading time.

---

## License

[MIT](LICENSE) — Zander Meer, 2026.
