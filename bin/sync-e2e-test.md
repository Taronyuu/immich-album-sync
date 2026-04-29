# Sync E2E Test Flow

End-to-end verification that an album actually mirrors between two real Immich instances using the v0.2.4 user-supplied-API-key login path.

## What you need to provide

| Variable | Description |
|---|---|
| `IMMICH_SOURCE_URL` | Immich whose album we'll mirror **from**. Reachable from the host running the app. |
| `IMMICH_TARGET_URL` | Immich we'll log into Immich Album Sync with. The mirror lands here. |
| `IMMICH_SOURCE_KEY` | API key on **source** with scopes: `user.read`, `album.read`, `asset.read`, `asset.download`. (See note below if the source album is owned by a different user.) |
| `IMMICH_TARGET_KEY` | API key on **target** with the 8 sync scopes + `user.read`: `user.read`, `asset.upload`, `asset.read`, `asset.download`, `album.create`, `album.read`, `album.update`, `albumAsset.create`, `albumAsset.delete`. |
| `IMMICH_SOURCE_ALBUM_ID` | UUID of the source album. Must contain ≥ 1 asset. |
| `IMMICH_SOURCE_EMAIL` | Email of the user whose `IMMICH_SOURCE_KEY` belongs to (any string for the local user record). |
| `IMMICH_TARGET_EMAIL` | Email of the user whose `IMMICH_TARGET_KEY` belongs to (used to log in). |

> If the source album is **shared with** the source-key's user (not owned by them), the source key still needs `album.read` and the album must appear in `GET /api/albums?shared=true`.

Export them all in one block, e.g.:
```bash
export IMMICH_SOURCE_URL="https://immich-a.example.com"
export IMMICH_TARGET_URL="https://immich-b.example.com"
export IMMICH_SOURCE_KEY="<paste>"
export IMMICH_TARGET_KEY="<paste>"
export IMMICH_SOURCE_ALBUM_ID="<uuid>"
export IMMICH_SOURCE_EMAIL="alice@a.example.com"
export IMMICH_TARGET_EMAIL="bob@b.example.com"
```

## Step 0 — Pre-flight (no app yet)

Verify both keys + the source album are reachable. **Do this first** — saves 5 minutes if a key is missing a scope.

```bash
echo "=== source /me ==="
curl -fsS "$IMMICH_SOURCE_URL/api/users/me" -H "x-api-key: $IMMICH_SOURCE_KEY" | jq -c '{id, email}'

echo "=== source album ==="
curl -fsS "$IMMICH_SOURCE_URL/api/albums/$IMMICH_SOURCE_ALBUM_ID" -H "x-api-key: $IMMICH_SOURCE_KEY" \
    | jq -c '{albumName, assetCount, ownerEmail: .owner.email}'

echo "=== target /me ==="
curl -fsS "$IMMICH_TARGET_URL/api/users/me" -H "x-api-key: $IMMICH_TARGET_KEY" | jq -c '{id, email}'

echo "=== target dry-run upload-check (proves asset.read scope) ==="
curl -fsS -X POST "$IMMICH_TARGET_URL/api/assets/bulk-upload-check" \
    -H 'Content-Type: application/json' \
    -H "x-api-key: $IMMICH_TARGET_KEY" \
    -d '{"assets":[{"id":"x","checksum":"dGVzdA=="}]}' | jq -c .
```

All four must respond with non-error JSON. If any fails, stop and fix scopes.

## Step 1 — Local app: clean DB, start server

```bash
cd /Users/zander/Documents/Workspace/imferry
php artisan migrate:fresh --force
php artisan optimize:clear
php artisan serve --host=127.0.0.1 --port=47286 > /tmp/app-serve.log 2>&1 &
echo $! > /tmp/app-serve.pid
sleep 3
curl -sS -o /dev/null -w "login HTTP %{http_code}\n" http://127.0.0.1:47286/login
```

## Step 2 — Log in via API key (Playwright drive)

I drive this with the playwright MCP tools:
1. `browser_navigate` → `http://127.0.0.1:47286/login`
2. Fill `data.immich_base_url` = `$IMMICH_TARGET_URL`
3. Fill `data.email` = `$IMMICH_TARGET_EMAIL`
4. Fill `form.immich_api_key` = `$IMMICH_TARGET_KEY`
5. Press Tab (triggers `live(onBlur)` so the password's HTML5 `required` toggles off)
6. Click `button[type=submit]`
7. Wait for navigation to `/`

**Verify after login:**
```bash
php artisan tinker --execute='
$u = \App\Models\User::query()->first();
echo "id: ".$u->immich_user_id." | base_url: ".$u->immich_base_url." | key prefix: ".substr(\Illuminate\Support\Facades\Crypt::decryptString($u->immich_api_key_encrypted), 0, 12)."...".PHP_EOL;
'
```

The decrypted key prefix must match `${IMMICH_TARGET_KEY:0:12}`.

## Step 3 — Create the Album

I drive this with playwright too. Easier than poking the database:

1. `browser_navigate` → `http://127.0.0.1:47286/albums/create`
2. Fill **Basics** tab:
   - Name = `E2E Sync Test`
   - Schedule = `*/5 * * * *` (every 5 min — won't actually fire because we'll Run now manually)
3. Switch to **Source** tab:
   - Source type = `Connected Immich account`
   - Remote URL = `$IMMICH_SOURCE_URL`
   - Email = `$IMMICH_SOURCE_EMAIL`
   - Then **two paths**, depending on whether the source-account form supports api-key (currently it requires email + password for the Connect flow):
     - **3a (preferred):** if `Source: Connected Immich account` accepts an API key, paste `$IMMICH_SOURCE_KEY` and click Connect → pick album from dropdown.
     - **3b (fallback):** insert the album row directly via tinker (see "Tinker fallback" below) so we don't depend on the source-account form's auth being password-based.
4. Switch to **Target** tab:
   - Target album name = `E2E Sync Test (mirror)`
   - On remote delete = `remove-from-album` (default)
   - Direction = `pull`
   - Active = `true`
5. Save.

### Tinker fallback (path 3b) — bypass the source-account form

```bash
php artisan tinker --execute='
$user = \App\Models\User::query()->first();
\App\Models\Album::create([
    "user_id" => $user->id,
    "name" => "E2E Sync Test",
    "schedule" => "*/5 * * * *",
    "source_type" => "immich-api-key",
    "source_base_url" => getenv("IMMICH_SOURCE_URL"),
    "source_api_key" => getenv("IMMICH_SOURCE_KEY"),
    "source_album_id" => getenv("IMMICH_SOURCE_ALBUM_ID"),
    "target_album_name" => "E2E Sync Test (mirror)",
    "on_remote_delete" => "remove-from-album",
    "direction" => "pull",
    "is_active" => true,
]);
echo "Created album id=".\App\Models\Album::query()->first()->id.PHP_EOL;
'
```

(The encrypted source-API-key column is `source_api_key` mapped via the `'encrypted'` cast — set raw, store encrypted.)

## Step 4 — Run the sync

```bash
php artisan sync:run --once --album=1
```

Or via the UI: navigate to the album view, click **Run now**, wait for the JobRun's status to flip queued → running → succeeded (poll-refresh every ~5s).

## Step 5 — Verify the mirror

### 5a. Status + counts

```bash
php artisan tinker --execute='
$run = \App\Models\JobRun::query()->latest()->first();
echo "status: ".$run->status." | trigger: ".$run->trigger.PHP_EOL;
echo "downloaded: ".$run->downloaded_count." | deduped: ".$run->deduped_count." | added_to_album: ".$run->added_to_album_count.PHP_EOL;
echo "error: ".($run->error_message ?? "(none)").PHP_EOL;
echo "log tail: ".PHP_EOL.implode(PHP_EOL, array_slice(explode(PHP_EOL, (string) $run->log), -10)).PHP_EOL;
'
```

`status: succeeded` is required. `downloaded_count + deduped_count` should equal the source album's asset count.

### 5b. Asset-checksum diff (the smoking-gun test)

```bash
SRC=$(curl -fsS "$IMMICH_SOURCE_URL/api/albums/$IMMICH_SOURCE_ALBUM_ID" \
    -H "x-api-key: $IMMICH_SOURCE_KEY" | jq -r '.assets[].checksum' | sort)

TGT_ID=$(curl -fsS "$IMMICH_TARGET_URL/api/albums?shared=false" \
    -H "x-api-key: $IMMICH_TARGET_KEY" \
    | jq -r '.[] | select(.albumName == "E2E Sync Test (mirror)") | .id')

TGT=$(curl -fsS "$IMMICH_TARGET_URL/api/albums/$TGT_ID" \
    -H "x-api-key: $IMMICH_TARGET_KEY" | jq -r '.assets[].checksum' | sort)

echo "source assets: $(echo "$SRC" | wc -l) | target assets: $(echo "$TGT" | wc -l)"
diff <(echo "$SRC") <(echo "$TGT") && echo "✅ checksums match" || echo "❌ checksums diverge"
```

Empty diff = every source asset landed in the mirror with the right bytes.

## Step 6 — Idempotency

Run the sync again:
```bash
php artisan sync:run --once --album=1
php artisan tinker --execute='
$run = \App\Models\JobRun::query()->latest()->first();
echo "downloaded: ".$run->downloaded_count." (expect 0) | already_mapped: ".$run->skipped_already_mapped_count.PHP_EOL;
'
```

Second run: `downloaded_count = 0`, `skipped_already_mapped_count = source asset count`. Proves the `mappings` table is doing its job.

## Step 7 — Optional: delete-policy check

Delete one asset from the source album in Immich's UI (or `DELETE /api/albums/$IMMICH_SOURCE_ALBUM_ID/assets`). Run sync again. Behavior depends on the album's `on_remote_delete`:
- **remove-from-album** (default): asset disappears from the mirror album but stays in target Immich's library.
- **trash**: asset goes to target trash.
- **ignore**: nothing happens, mapping becomes orphaned.

## Step 8 — Tear down

```bash
kill "$(cat /tmp/app-serve.pid)" 2>/dev/null
rm -f /tmp/app-serve.pid /tmp/app-serve.log
# Optional: keep the mirrored album in target Immich, or delete it via curl.
```

## Pass criteria (all must hold)

- [ ] Step 0: all 4 pre-flight calls return success
- [ ] Step 2: Filament dashboard loads after API-key login; stored key matches what was pasted (no provisioning)
- [ ] Step 4: `sync:run` exits 0
- [ ] Step 5a: latest `JobRun.status = succeeded`, no `error_message`
- [ ] Step 5b: `diff` is empty (source and mirror checksums match)
- [ ] Step 6: second run reports `downloaded_count = 0`

If all six tick, the v0.2.4 user-supplied-API-key path is **proven end-to-end** — login, store, sync, idempotency. That's everything Morten's chat surfaced + everything we couldn't verify against a mock.
