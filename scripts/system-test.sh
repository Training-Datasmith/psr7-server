#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE_TAG="nyholm-psr7-server-system-test:php7.2.34"
FLOOR_IMAGE="php:7.1-cli@sha256:1e4671c8e26df6ae1d99b0e0059cdaa2fe72351c20c136e787dc463a165104e5"
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
    "${FLOOR_IMAGE}" \
    bash -c 'set -euo pipefail
lint_failed=0
for file in /app/src/Server_Request_Creator.php /app/src/Server_Request_Creator_Interface.php; do
    if ! line="$(php -l "${file}" 2>&1)"; then
        echo "${line}" >&2
        lint_failed=1
        continue
    fi
    echo "${line}"
    if ! grep -Eq "^No syntax errors detected in .+" <<<"${line}"; then
        lint_failed=1
    fi
done
if [[ ${lint_failed} -ne 0 ]]; then
    exit 1
fi
echo "php-lint: OK (2 files)"'

docker_cmd run --rm \
    -v "${REPO_ROOT}:/app" \
    -w /app \
    "${IMAGE_TAG}" \
    bash /app/scripts/system-test-inner.sh
