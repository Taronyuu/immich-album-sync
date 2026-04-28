#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

IMAGE="ghcr.io/taronyuu/immich-album-sync"
VERSION="$(cat VERSION)"
PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
BUILDER="${BUILDER:-multiarch-builder}"

if [[ -z "${VERSION}" ]]; then
    echo "VERSION file is empty" >&2
    exit 1
fi

if ! docker buildx inspect "${BUILDER}" >/dev/null 2>&1; then
    echo "Creating buildx builder: ${BUILDER}"
    docker buildx create --name "${BUILDER}" --driver docker-container --bootstrap
fi

if [[ "${1:-}" == "--push" ]]; then
    echo "Building and pushing ${IMAGE}:${VERSION} + :latest for ${PLATFORMS}"
    docker buildx build \
        --builder "${BUILDER}" \
        --platform "${PLATFORMS}" \
        --tag "${IMAGE}:${VERSION}" \
        --tag "${IMAGE}:latest" \
        --push \
        .
else
    echo "Building ${IMAGE}:${VERSION} for the host platform only (use --push for multi-arch + push)"
    docker build \
        --tag "${IMAGE}:${VERSION}" \
        --tag "${IMAGE}:latest" \
        .
fi

echo "Done."
