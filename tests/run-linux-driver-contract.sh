#!/usr/bin/env bash
set -euo pipefail

profile=${1:?Usage: run-linux-driver-contract.sh <linux-arm64|linux-x86_64> [image]}
case "$profile" in
  linux-arm64)
    platform=linux/arm64
    default_image=gaussdb-php:8.3-arm64-prototype
    ;;
  linux-x86_64)
    platform=linux/amd64
    default_image=gaussdb-php:8.3-x86_64-prototype
    ;;
  *)
    echo "Unsupported profile: $profile" >&2
    exit 2
    ;;
esac

image=${2:-$default_image}
: "${GAUSS_PASSWORD:?GAUSS_PASSWORD is required}"
mkdir -p build/test-results

docker run --rm --platform "$platform" \
  --network "${GAUSS_DOCKER_NETWORK:-gaussdb_default}" \
  -e GAUSS_TEST_PROFILE="$profile" \
  -e GAUSS_TEST_DRIVER=pgsql \
  -e GAUSS_HOST="${GAUSS_HOST:-gaussdb}" \
  -e GAUSS_PORT="${GAUSS_PORT:-5432}" \
  -e GAUSS_DATABASE="${GAUSS_DATABASE:-gdbdrv_m_test}" \
  -e GAUSS_USER="${GAUSS_USER:-gauss_php_test}" \
  -e GAUSS_PASSWORD \
  -v "$PWD/tests:/tests:ro" \
  "$image" php /tests/php_pdo_contract.php |
  tee "build/test-results/${profile}.json"
