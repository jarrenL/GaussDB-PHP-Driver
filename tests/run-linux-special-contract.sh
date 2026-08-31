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
test_driver=${GAUSS_TEST_DRIVER:-pgsql}
[[ $test_file =~ ^[a-zA-Z0-9_.-]+\.php$ ]] || { echo "Unsafe test filename: $test_file" >&2; exit 2; }
test -f "tests/$test_file" || { echo "Test not found: tests/$test_file" >&2; exit 2; }

case "$test_driver" in
  pgsql|odbc) ;;
  *) echo "Unsupported GAUSS_TEST_DRIVER: $test_driver" >&2; exit 2 ;;
esac

host=${GAUSS_HOST:-gaussdb}
port=${GAUSS_PORT:-5432}
database=${GAUSS_DATABASE:-gdbdrv_m_test}
odbc_connection_string="Driver={GaussDB Unicode};Servername=${host};Port=${port};Database=${database};SSLmode=${GAUSS_SSLMODE:-prefer};ConnSettings=set client_encoding=UTF8;BoolsAsChar=0;ByteaAsLongVarBinary=1"

docker_arguments=(--rm --platform "$platform")
if [[ -n ${GAUSS_DOCKER_NETWORK:-} ]]; then
  docker_arguments+=(--network "$GAUSS_DOCKER_NETWORK")
elif [[ $host == gaussdb ]]; then
  docker_arguments+=(--network gaussdb_default)
elif [[ $host == host.docker.internal ]]; then
  docker_arguments+=(--add-host host.docker.internal:host-gateway)
fi

mkdir -p build/test-results
result_name=${test_file%.php}-${profile}.json
docker run "${docker_arguments[@]}" \
  -e GAUSS_TEST_DRIVER="$test_driver" \
  -e GAUSS_HOST="$host" \
  -e GAUSS_PORT="$port" \
  -e GAUSS_DATABASE="$database" \
  -e GAUSS_ODBC_CONNECTION_STRING="$odbc_connection_string" \
  -e GAUSS_USER="${GAUSS_USER:-gauss_php_test}" \
  -e GAUSS_PASSWORD \
  -e GAUSS_BAD_PASSWORD \
  -e GAUSS_READONLY_USER \
  -e GAUSS_READONLY_PASSWORD \
  -v "$PWD/tests:/tests:ro" \
  "$image" php "/tests/$test_file" |
  tee "build/test-results/$result_name"
