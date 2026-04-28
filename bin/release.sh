#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

IMAGE="ghcr.io/taronyuu/imferry"
VERSION="$(cat VERSION)"

if [[ -z "${VERSION}" ]]; then
    echo "VERSION file is empty" >&2
    exit 1
fi

echo "Building ${IMAGE}:${VERSION}"
docker build \
    --tag "${IMAGE}:${VERSION}" \
    --tag "${IMAGE}:latest" \
    .

if [[ "${1:-}" == "--push" ]]; then
    echo "Pushing ${IMAGE}:${VERSION} and :latest"
    docker push "${IMAGE}:${VERSION}"
    docker push "${IMAGE}:latest"
fi

echo "Done."
