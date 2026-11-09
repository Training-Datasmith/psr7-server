#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE_TAG="nyholm-psr7-server-system-test:php7.2.34"
USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"

docker_cmd() {
    if [[ "${USE_SUDO_DOCKER}" == "1" ]]; then
        sudo docker "$@"
    else
        docker "$@"
    fi
}

docker_cmd build -f "${REPO_ROOT}/docker/system-test/Dockerfile" -t "${IMAGE_TAG}" "${REPO_ROOT}"

docker_cmd run --rm \
    -v "${REPO_ROOT}:/app" \
    -w /app \
    "${IMAGE_TAG}" \
    bash /app/scripts/system-test-inner.sh
