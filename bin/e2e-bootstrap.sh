#!/usr/bin/env bash
set -euo pipefail

ALPHA="http://localhost:2283"
BETA="http://localhost:2284"
ADMIN_EMAIL="admin@e2e.test"
ADMIN_PASS="e2e-test-pass"
ADMIN_NAME="E2E Admin"

wait_for() {
    local url="$1"
    local label="$2"
    local tries=120
    echo -n "Waiting for ${label} (${url})..."
    while [ $tries -gt 0 ]; do
        if curl -fsS "${url}/api/server/ping" >/dev/null 2>&1; then
            echo " ready."
            return 0
        fi
        sleep 2
        tries=$((tries - 1))
        echo -n "."
    done
    echo " TIMEOUT"
    exit 1
}

admin_signup() {
    local base="$1"
    curl -fsS -X POST "${base}/api/auth/admin-sign-up" \
        -H 'Content-Type: application/json' \
        -d "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASS}\",\"name\":\"${ADMIN_NAME}\"}" \
        > /dev/null 2>&1 || true
}

admin_login() {
    local base="$1"
    curl -fsS -X POST "${base}/api/auth/login" \
        -H 'Content-Type: application/json' \
        -d "{\"email\":\"${ADMIN_EMAIL}\",\"password\":\"${ADMIN_PASS}\"}"
}

create_api_key() {
    local base="$1"
    local token="$2"
    curl -fsS -X POST "${base}/api/api-keys" \
        -H 'Content-Type: application/json' \
        -H "Authorization: Bearer ${token}" \
        -d '{"name":"e2e-immich-album-sync","permissions":["asset.upload","asset.read","asset.download","album.create","album.read","album.update","albumAsset.create","albumAsset.delete"]}'
}

create_album() {
    local base="$1"
    local key="$2"
    local name="$3"
    curl -fsS -X POST "${base}/api/albums" \
        -H 'Content-Type: application/json' \
        -H "x-api-key: ${key}" \
        -d "{\"albumName\":\"${name}\"}"
}

wait_for "$ALPHA" "alpha"
wait_for "$BETA"  "beta"

echo "Signing up admins on both Immichs..."
admin_signup "$ALPHA"
admin_signup "$BETA"

echo "Logging in to alpha..."
ALPHA_LOGIN=$(admin_login "$ALPHA")
ALPHA_TOKEN=$(echo "$ALPHA_LOGIN" | jq -r .accessToken)
ALPHA_USER_ID=$(echo "$ALPHA_LOGIN" | jq -r .userId)

echo "Provisioning API key on alpha..."
ALPHA_API=$(create_api_key "$ALPHA" "$ALPHA_TOKEN")
ALPHA_KEY=$(echo "$ALPHA_API" | jq -r .secret)

echo "Logging in to beta..."
BETA_LOGIN=$(admin_login "$BETA")
BETA_TOKEN=$(echo "$BETA_LOGIN" | jq -r .accessToken)
BETA_USER_ID=$(echo "$BETA_LOGIN" | jq -r .userId)

echo "Provisioning API key on beta (will also be done by the Connect flow later, but useful for seeding)..."
BETA_API=$(create_api_key "$BETA" "$BETA_TOKEN")
BETA_KEY=$(echo "$BETA_API" | jq -r .secret)

echo "Creating source album on beta..."
BETA_ALBUM=$(create_album "$BETA" "$BETA_KEY" "Vacation Photos")
BETA_ALBUM_ID=$(echo "$BETA_ALBUM" | jq -r .id)

cat <<RESULT
ALPHA_URL=$ALPHA
ALPHA_USER_ID=$ALPHA_USER_ID
ALPHA_KEY=$ALPHA_KEY
BETA_URL=$BETA
BETA_USER_ID=$BETA_USER_ID
BETA_KEY=$BETA_KEY
BETA_ALBUM_ID=$BETA_ALBUM_ID
ADMIN_EMAIL=$ADMIN_EMAIL
ADMIN_PASS=$ADMIN_PASS
RESULT
