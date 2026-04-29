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

The container runs as `www-data` — UID `33` and GID `33` — baked into the image. If your `./data` directory is owned by a different UID, the container can't write the SQLite file on first boot. Two fixes:

- **`sudo chown -R 33:33 ./data`** once after creation. Simplest for most hosts.
- **Or build the image with your own UID/GID** (see [Building with a custom UID/GID](#building-with-a-custom-uidgid) below). Useful when the host has a fixed UID you can't change (e.g. TrueNAS apps user `568`, Kubernetes pod `securityContext.runAsUser`).

### Building with a custom UID/GID

```bash
git clone https://github.com/Taronyuu/immich-album-sync.git
cd immich-album-sync
docker build --build-arg USER_ID=568 --build-arg GROUP_ID=568 -t immich-album-sync:custom .
```

Then point your `docker-compose.yml` at `image: immich-album-sync:custom` instead of the published `ghcr.io/...` tag. The `www-data` user inside the container is remapped to the UID/GID you passed, and `/etc/nginx`, `/var/www`, and friends are chowned to match.

If you run the container under Kubernetes with `securityContext.runAsUser` set to anything other than `33`, you **must** build with matching `USER_ID` / `GROUP_ID` build args — otherwise the nginx config init will fail with `cannot create /etc/nginx/nginx.conf: Permission denied`.

### Behind a reverse proxy (Kubernetes ingress, Traefik, Caddy, Cloudflare Tunnel, …)

Set `TRUSTED_PROXIES` in the container env so Laravel honors the `X-Forwarded-Proto` / `X-Forwarded-Host` headers your proxy sends. Without it, the panel loads but Filament's CSS and JS get generated with the wrong scheme (`http://` on an `https://` page → mixed-content blocked) or the wrong host, and you'll see the login page rendered as unstyled HTML.

```yaml
environment:
  APP_URL: https://immich-sync.example.com
  TRUSTED_PROXIES: "*"
```

`*` trusts every upstream proxy — safe inside a private network (Kubernetes cluster, your home LAN). For stricter control, list CIDR ranges instead: `TRUSTED_PROXIES: "10.0.0.0/8,172.16.0.0/12,192.168.0.0/16"`. Make sure `APP_URL` matches the **external** URL you actually browse to (scheme included).

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

3. **`chown` the dataset to UID `33`** so the container's `www-data` user can write the SQLite file:

   ```bash
   sudo chown -R 33:33 /mnt/tank/immich-album-sync
   ```

   If your TrueNAS deployment forces apps to run as UID `568` (TrueCharts and similar wrappers do this), the published image won't work — you need to build a custom image with `--build-arg USER_ID=568 --build-arg GROUP_ID=568` and push it to a registry the cluster can pull from. See [Building with a custom UID/GID](#building-with-a-custom-uidgid).

4. **Apps → Discover Apps → Custom App**, paste the compose YAML below, and edit:

   - Replace `${APP_KEY}` with the key you generated.
   - Set `APP_URL` to where you'll reach the panel (e.g. `http://truenas.local:47283`, your Tailscale hostname, or a reverse-proxied domain).
   - Set the volume host path to your dataset (e.g. `/mnt/tank/immich-album-sync`).

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
   ```

4. **Save.** TrueNAS pulls the image, runs migrations on first boot via `AUTORUN_LARAVEL_MIGRATION`, and exposes the panel on `:47283`.

5. **Browse to it** and log in with your Immich URL + email + password.

> HTTPS is intentionally out of scope. Tailnet users have transport encryption already; LAN users can front the panel with TrueNAS's built-in reverse proxy or any ingress they already run.

---

## How authentication works

The login form has two paths — pick whichever fits your Immich account.

**Path A — email + password** (the default)

1. Immich Album Sync calls `POST /api/auth/login` on the Immich URL you provided, with your email and password.
2. On success, a local user record is created (or fetched) keyed off `(immich_base_url, immich_user_id)`.
3. **First login only** — `POST /api/api-keys` provisions a scoped key named *"Immich Album Sync (auto-provisioned)"* on your Immich.
4. The API key is encrypted (AES-256, derived from your `APP_KEY`) and stored on the user record. Your password is never persisted.

**Path B — API key** (for OIDC users who don't have a password)

If your Immich users sign in via OIDC, leave the password field blank and paste an Immich API key into the *"Or sign in with an Immich API key"* field instead. The bootstrap key needs two scopes — `user.read` (so we can confirm who you are) and `apiKey.create` (so we can provision a sync-scoped key).

1. Immich Album Sync calls `GET /api/users/me` with `x-api-key: <your-key>` to validate the key and read your user info.
2. The local user record is created the same way as Path A.
3. **First login only** — uses your bootstrap key to call `POST /api/api-keys` and provisions the same sync-scoped key as Path A. The bootstrap key is discarded; only the freshly-provisioned key is stored.

Either path ends with the same eight scopes on the stored key:

`asset.upload`, `asset.read`, `asset.download`, `album.create`, `album.read`, `album.update`, `albumAsset.create`, `albumAsset.delete`

The Immich URL is remembered per browser via a 30-day cookie, so you only need to type it once.

You can revoke the stored key any time from Immich's "API Keys" page. The next sync will fail with a clear message; log out and back in to provision a fresh one.

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

## Using Postgres or MySQL instead of SQLite

SQLite is the default and what the included `docker-compose.yml` uses — it's the simplest option (zero extra services, one file backup). If you'd rather run Postgres or MySQL — say, to share a database server you already operate — Laravel and Imferry both support it without code changes. Set the right env vars and migrations run against your engine.

The container ships with `pdo_sqlite`, `pdo_pgsql`, and `pdo_mysql` already enabled.

### Postgres

Add a Postgres service alongside the app and point Imferry at it. Minimal extra block in `docker-compose.yml`:

```yaml
services:
  app:
    image: ghcr.io/taronyuu/immich-album-sync:latest
    # ... ports, volumes, restart, etc., as usual ...
    environment:
      APP_KEY: ${APP_KEY}
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: immich_album_sync
      DB_USERNAME: immich_album_sync
      DB_PASSWORD: ${DB_PASSWORD}
      SESSION_DRIVER: database
      QUEUE_CONNECTION: database
      CACHE_STORE: database
      AUTORUN_LARAVEL_MIGRATION: "true"
    depends_on:
      db:
        condition: service_healthy

  db:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_USER: immich_album_sync
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_DB: immich_album_sync
    volumes:
      - ./pgdata:/var/lib/postgresql/data
    healthcheck:
      test: pg_isready -U immich_album_sync
      interval: 5s
      timeout: 5s
      retries: 12
```

Drop the `volumes: ./data:/var/www/html/database/data` and `DB_DATABASE: …/immich-album-sync.sqlite` lines from the SQLite-based example — they're not needed when Postgres is the store.

### MySQL

Same shape, just swap the engine:

```yaml
services:
  app:
    # ... as above, but ...
    environment:
      DB_CONNECTION: mysql
      DB_HOST: db
      DB_PORT: 3306
      # ... rest unchanged ...

  db:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: immich_album_sync
      MYSQL_USER: immich_album_sync
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
    volumes:
      - ./mysqldata:/var/lib/mysql
    healthcheck:
      test: mysqladmin -uimmich_album_sync -p${DB_PASSWORD} ping
      interval: 5s
      timeout: 5s
      retries: 12
```

### Migrating from SQLite to Postgres/MySQL

The schema is identical across engines, so you can move data with any tool that handles cross-engine SQLite → Postgres dumps (e.g. `pgloader`, or `sqlite3 .dump | psql` for a manual approach if you're handy with regex). The encrypted columns survive verbatim as long as `APP_KEY` doesn't change.

For a clean slate (preserving nothing): point at the new database, let `AUTORUN_LARAVEL_MIGRATION=true` create the schema, and have each user log in to re-provision their Immich API key.

### Verifying

The repo ships two phpunit configurations for engine-specific test runs:

```bash
php artisan test --configuration=phpunit.pgsql.xml   # 26 tests against Postgres
php artisan test --configuration=phpunit.mysql.xml   # 26 tests against MySQL
php artisan test                                     # default: SQLite in-memory
```

These expect a database listening on the host ports `54330` (Postgres) / `33060` (MySQL) with credentials `imferry/imferry` and a database named `imferry_test`. Spin one up with:

```bash
docker run -d --name immich-album-sync-pgsql-test \
  -e POSTGRES_USER=imferry -e POSTGRES_PASSWORD=imferry \
  -e POSTGRES_DB=imferry -p 54330:5432 \
  postgres:16-alpine
docker exec immich-album-sync-pgsql-test \
  psql -U imferry -d imferry -c 'CREATE DATABASE imferry_test;'
```

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
