#!/usr/bin/env bash
set -euo pipefail

profile=${1:?Usage: run-linux-special-contract.sh <linux-arm64|linux-x86_64> <test.php> [image]}
test_file=${2:?PHP test file is required}
case "$profile" in
  linux-arm64) platform=linux/arm64; default_image=gaussdb-php:8.3-arm64-prototype ;;
  linux-x86_64) platform=linux/amd64; default_image=gaussdb-php:8.3-x86_64-prototype ;;
  *) echo "Unsupported profile: $profile" >&2; exit 2 ;;
esac
image=${3:-$default_image}
[[ $test_file =~ ^[a-zA-Z0-9_.-]+\.php$ ]] || { echo "Unsafe test filename: $test_file" >&2; exit 2; }
test -f "tests/$test_file" || { echo "Test not found: tests/$test_file" >&2; exit 2; }

mkdir -p build/test-results
result_name=${test_file%.php}-${profile}.json
docker run --rm --platform "$platform" \
  --network "${GAUSS_DOCKER_NETWORK:-gaussdb_default}" \
  -e GAUSS_TEST_DRIVER=pgsql \
  -e GAUSS_HOST="${GAUSS_HOST:-gaussdb}" \
  -e GAUSS_PORT="${GAUSS_PORT:-5432}" \
  -e GAUSS_DATABASE="${GAUSS_DATABASE:-gdbdrv_m_test}" \
  -e GAUSS_USER="${GAUSS_USER:-gauss_php_test}" \
  -e GAUSS_PASSWORD \
  -e GAUSS_BAD_PASSWORD \
  -e GAUSS_READONLY_USER \
  -e GAUSS_READONLY_PASSWORD \
  -v "$PWD/tests:/tests:ro" \
  "$image" php "/tests/$test_file" |
  tee "build/test-results/$result_name"
