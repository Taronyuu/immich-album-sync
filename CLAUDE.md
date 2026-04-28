# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this app is

Mirrors a shared album from another Immich instance into the user's own Immich on a schedule. Each user logs in with **their own Immich credentials**; the app validates against `POST /api/auth/login` on the user's Immich and auto-provisions a scoped API key via `POST /api/api-keys` on first login. Passwords are never persisted; the API key is encrypted at rest (AES-256, `APP_KEY`-derived) and powers all sync work.

Phase 2 (later) adds a Google Photos shared-link source — the existing `SourceBackend` interface accepts that as a drop-in.

## Stack

- **PHP 8.4** + **Laravel 13** + **Filament 4** + **Livewire 3**
- **SQLite** via Eloquent (single file at `database/database.sqlite`, also stores Laravel sessions + queue jobs)
- **`serversideup/php:8.4-fpm-nginx`** Docker image (extended via `Dockerfile` to add the `intl` PHP extension required by Filament number formatting)
- **Guzzle** via Laravel's `Http` facade for the Immich REST client
- **`dragonmantank/cron-expression`** for cron parsing in the scheduler dispatcher

## Running it

```bash
docker compose up -d
open http://localhost:47283
```

Three containers, all from the same image, sharing a bind-mount of `./` to `/var/www/html`:
- **app** (port `47283:8080`, runs nginx + php-fpm via s6-overlay; auto-runs migrations on boot via `AUTORUN_LARAVEL_MIGRATION`)
- **scheduler** (`php artisan schedule:work` — ticks every minute)
- **queue** (`php artisan queue:work --tries=3 --timeout=1800 --sleep=3`)

Port `47283` is intentional — far from anything common (not 80/443, 3306/5432/6379, 8000/8080/3000/5173, 2283 = Immich web).

The local `.env` uses Laravel's default SQLite path (`database/database.sqlite`) so host-side `php artisan` commands work without container env. The container overrides `DB_DATABASE` via `docker-compose.yml` env.

`.env.testing` is loaded automatically when `APP_ENV=testing` (set in `phpunit.xml`) — uses `:memory:` SQLite + `sync` queue + `array` cache/session for fast tests.

## Architecture

```
┌────────────────────────────┐                    ┌────────────────────────┐
│ Filament UI (logged in)    │ ── POST /login ──▶ │  User's own Immich     │
│ /login                     │ ◀── accessToken    │                        │
│ /                          │ ── POST /api-keys ─▶│                       │
│ /albums                    │ ◀── secret (encrypted in DB)                │
│ /albums/{id} (View)        │                                              │
│ /albums/{id}/edit          │                                              │
│ /job-runs                  │                                              │
└────────────────────────────┘                    └────────────────────────┘
            │
            │  user creates / runs Albums (sync_jobs table renamed → albums)
            ▼
┌────────────────────────────┐
│ albums table               │
└────────────────────────────┘
            ▲
            │  scheduler (cron */1 *) → sync:run dispatches RunSyncJob per due album
            │  manual run-now also dispatches RunSyncJob via dispatchForAlbum()
            │
┌────────────────────────────┐                    ┌────────────────────────┐
│ queue worker               │                    │ SourceBackend          │
│ RunSyncJob → SyncEngine    │ ◀── HTTP ────────▶ │  (ImmichSharedLinkSource│
│   creates JobRun row       │                    │   ImmichApiKeySource)   │
│   writes log/counts to it  │                    │                        │
└────────────────────────────┘                    └────────────────────────┘
            │
            │   POST /assets, POST /albums/{id}/assets etc.
            ▼
┌────────────────────────────┐
│ User's own Immich          │
└────────────────────────────┘
```

## Key concepts

### Album (`App\Models\Album`, table `albums`)
The user-facing primary entity. One row per *configured mirror*. Columns: `name`, `schedule` (cron), `source_type` (`immich-shared-link` | `immich-api-key`), `source_*` config (encrypted: share_key, share_password, api_key), `target_album_name`, `target_album_id` (lazily populated on first run), `on_remote_delete` policy (`remove-from-album` | `trash` | `ignore`), `is_active`, `last_*` snapshot fields. Scoped per-user via `user_id` FK. The encrypted columns use Eloquent's built-in `'encrypted'` cast — **don't** use accessor methods returning `Attribute` for column-level encryption (Eloquent's reflection picks them up as accessors for nonexistent columns; this bit us once during the rename).

### JobRun (`App\Models\JobRun`, table `job_runs`)
One row per *execution of a sync*. Columns: `album_id`, `status` (`queued` | `running` | `succeeded` | `failed`), `trigger` (`scheduled` | `manual`), `started_at`, `finished_at`, count fields, `error_message`, `log` (newline-separated structured lines). Surfaced as the "Jobs" tab. Read-only in the UI (no create/edit pages).

### Mapping (`App\Models\Mapping`, table `mappings`)
Composite-PK row tracking `(album_id, remote_id) → local_asset_id`. Drives idempotency: re-running a sync skips already-mapped assets. Also used to detect orphans (mapping exists but remote no longer returns the asset → apply `on_remote_delete`).

## Sync flow (one run)

`SyncEngine::run(Album, JobRun)` orchestrates:

1. Mark `JobRun.status = running`, `Album.last_status = running`. Initialize `RunLogger` (buffers log lines, flushes every 2s or 20 lines).
2. `SourceFactory::for($album)` returns a `SourceBackend` (one per `source_type`).
3. Ensure target album exists on user's Immich (`POST /api/albums` if `target_album_id` null, otherwise verify it still exists via `GET /api/albums/{id}`; recreate if 404).
4. `$source->listAssets()` returns `RemoteAsset` DTOs.
5. Filter against `mappings` table — drop ones already imported.
6. For unknown ones with checksums, `POST /api/assets/bulk-upload-check` on the **target** to detect cross-album duplicates → record mapping but skip upload (`deduped` count).
7. For genuinely new ones: `$source->downloadAsset()` → temp file → `POST /api/assets` (multipart, `X-Immich-Checksum` header, `fileCreatedAt`, `fileModifiedAt`). Stream via Guzzle `attach()`/`sink()` — no full-file buffering.
8. Bulk-add the new asset IDs to the target album: `PUT /api/albums/{id}/assets`.
9. Apply delete policy on orphans (mapping exists but remote no longer sees it).
10. Persist final counts on JobRun, mark `succeeded`. On exception: `failed` with `error_message`.

Counts are persisted **mid-run** every 5 assets so the polling Filament view shows progress. Logs are buffered + flushed in `RunLogger`.

## Source backends

Interface in `app/Sync/Sources/SourceBackend.php`:
```php
interface SourceBackend {
    public function listAssets(): iterable;       // RemoteAsset DTOs
    public function downloadAsset(string $remoteId): string;  // returns temp file path; caller unlinks
}
```

**Critical detail**: the `AbstractImmichSource` caches its `ImmichClient` instance via `client()` so the Guzzle CookieJar persists across `listAssets() → downloadAsset()`. This is required for password-protected shared links — `POST /api/shared-links/login` sets a `SharedLinkToken` cookie that subsequent `GET /api/shared-links/me` and `GET /api/assets/{id}/original` calls need. Don't refactor this to construct a new client per call.

Implementations:
- `ImmichSharedLinkSource` — calls `getMySharedLink()` to get the album's UUID, then `getAlbum(id)` to enumerate assets (the `/me` endpoint returns the album metadata but not the asset list — Immich strips it for payload size).
- `ImmichApiKeySource` — direct `getAlbum(id)` with API-key auth.

For Phase 2, add a `GooglePhotosSharedLinkSource` that scrapes the public album page (Google killed the OAuth API for shared albums in March 2025 — only viable path).

## Auth flow

`config/auth.php` registers a custom `'immich'` provider. `AppServiceProvider::boot()` binds it to `App\Auth\ImmichUserProvider`.

`ImmichUserProvider::retrieveByCredentials()`:
1. Calls `POST {immich_base_url}/api/auth/login` with email + password
2. On 200: `User::firstOrNew(['immich_base_url', 'immich_user_id'])`, populates email/name/admin flag
3. **First login only** (no existing API key): `POST /api/api-keys` with `Authorization: Bearer {accessToken}` and the 8 sync scopes (`asset.upload/read/download`, `album.create/read/update`, `albumAsset.create/delete`). The `secret` is encrypted via `Crypt::encryptString` and stored on `users.immich_api_key_encrypted`.
4. Returns the User. Discards password and accessToken.

`ImmichUserProvider::validateCredentials()` is a no-op match check — the real validation already happened in `retrieveByCredentials()`. Don't try to re-verify the password here.

`User::canAccessPanel()` returns `true` for any authenticated user (no admin gating).
`User::getFilamentName()` is required by Filament's `HasName` interface and avoids the missing-`name`-column issue.

## Filament panel layout

`app/Providers/Filament/AdminPanelProvider.php` — single panel at root path (`/`), brand "Immich Album Sync", custom login page.

Resources (each has `getEloquentQuery()` scoped to `auth()->id()`):
- `App\Filament\Resources\Albums\AlbumResource` — full CRUD + ViewAlbum page with header actions (Open in Immich, Run now, View, Edit, Delete) + tabbed form (Basics / Source / Target).
- `App\Filament\Resources\JobRuns\JobRunResource` — read-only list + ViewJobRun (Re-run action, polling 5s, log viewer).

Widgets (`app/Filament/Widgets/`):
- `QuickAddAlbumWidget` — Livewire-based; one URL field, parses with `AlbumForm::parseImmichShareUrl()`, creates Album with derived defaults (`is_active=false` so user fills password before scheduling), redirects to edit.
- `OverviewStatsWidget` — 4 cards (Albums, Photos synced, Runs today, Last sync) with a 7-day uploads sparkline.
- `RecentJobsTableWidget` — last 10 JobRuns, polling 5s.

Custom login page: `App\Filament\Pages\Auth\ImmichLogin` — adds the `immich_base_url` field above email/password.

## Common commands

### Inside the container

```bash
docker compose exec app php artisan migrate                     # apply new migrations
docker compose exec app php artisan optimize:clear              # clear all caches (after model/route changes)
docker compose exec app php artisan tinker                      # REPL
docker compose exec app php artisan sync:run --once --album=1   # run a sync inline
docker compose exec app php artisan sync:run --all              # dispatch all active albums to the queue
docker compose exec app php artisan queue:flush                 # clear failed/queued jobs
docker compose exec app php artisan migrate:status
```

### From the host

```bash
php artisan test                                                 # full test suite (uses .env.testing → :memory: SQLite)
php artisan test --filter=JobRunTest
composer require <package>                                       # add dep; remember to docker compose down/up if it changes vendor/
```

After any change to model classes, route files, or service providers, run `docker compose exec app php artisan optimize:clear` — Filament aggressively caches routes/views/configs.

## Tests

Located in `tests/Feature/` and `tests/Unit/`. As of last count: 15 tests, 36 assertions.

Key tests:
- `AuthFlowTest` — successful login provisions API key, failed login doesn't create user, second login is idempotent.
- `JobRunTest` — engine creates succeeded JobRun with log + counts; engine marks JobRun failed when source throws.
- `AlbumScopingTest` — multi-user isolation across `AlbumResource` and `JobRunResource`.
- `ParseImmichShareUrlTest` — URL parsing edge cases (custom port, trailing slash, invalid input).

The `Http::fake()` setup mocks Immich endpoints with sequence-based responses for upload calls.

## Gotchas / non-obvious bits

- **Run-now bypasses `is_active`** for `trigger=manual` runs. Scheduled runs respect `is_active=false` and exit early (marking the JobRun failed with a clear message). See `RunSyncJob::handle()`.
- **`is_active` defaults to `false` for quick-added albums** — the QuickAdd widget creates with `is_active=false` so users have a chance to set the share-link password before the scheduler picks it up.
- **The Eloquent `Attribute`-returning method trap**: don't write a helper `protected function encryptedAttribute(string $col): Attribute`. Eloquent treats all `Attribute`-returning methods as accessors, so it tries to call `encryptedAttribute()` with zero args and crashes. Use the built-in `'encrypted'` cast instead.
- **Filament v4 `<env>` in `phpunit.xml` isn't enough** — Laravel reads `.env` first. Use `.env.testing` (loaded automatically when `APP_ENV=testing`).
- **The `intl` PHP extension is required** — Filament's number formatting calls `Number::format()`. Not in the base serversideup image; added via `Dockerfile`.
- **The `RunSyncJob` queue job class name is intentional** despite the rename to "Albums" — it's an internal queue job, not user-facing terminology.
- **`Mapping` is a no-PK pivot-style model** (composite primary key). Don't add `$primaryKey` or `incrementing=true`.
- **`Album::source_share_key` masks via the `'encrypted'` cast**. To inspect raw stored values for debugging, query `albums` directly via `DB::table()` (skips the cast).

## Things deliberately NOT in scope (for now)

- Bidirectional sync / writing back to source.
- Email/Slack notifications on failure.
- Multi-tenant invitations or admin role.
- Mobile app integration (the synced album is a regular Immich album — works in Immich's mobile app already).
- Modifications to Immich itself.

## Reference points in the Immich source

The Immich source code lives at `~/Documents/Workspace/immich`. Useful references when adjusting our HTTP client:

- Asset upload + checksum dedup: `server/src/controllers/asset-media.controller.ts:51-90` and `:180-192` (`bulk-upload-check`).
- Shared-link auth: `server/src/controllers/shared-link.controller.ts:69-100` (the `POST /api/shared-links/login` returns a `SharedLinkToken` cookie that `GET /api/shared-links/me` consumes).
- Album asset bulk add: `server/src/controllers/album.controller.ts:112-125`.
- API-key permissions enum: `server/src/enum.ts` (the `Permission` enum — our scopes match its values).
- Login response shape: `server/src/dtos/auth.dto.ts:29-40` (`accessToken`, `userId`, `userEmail`, `name`, `isAdmin`, ...).
