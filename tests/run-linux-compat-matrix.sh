#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 arm64|x86_64 IMAGE" >&2
    exit 2
fi

architecture=$1
image=$2
: "${GAUSS_PASSWORD:?GAUSS_PASSWORD is required}"

case "$architecture" in
    arm64) platform='linux/arm64' ;;
    x86_64) platform='linux/amd64' ;;
    *) echo "Unsupported architecture: $architecture" >&2; exit 2 ;;
esac

host=${GAUSS_HOST:-host.docker.internal}
port=${GAUSS_PORT:-5432}
user=${GAUSS_USER:-gauss_php_test}
m_database=${GAUSS_M_DATABASE:-gdbdrv_m_test}
o_database=${GAUSS_O_DATABASE:-gdbdrv_a_test}
result_directory=${GAUSS_RESULT_DIRECTORY:-build/test-results}
mkdir -p "$result_directory"

for target in "M:$m_database" "O:$o_database"; do
    mode=${target%%:*}
    database=${target#*:}
    docker run --rm --platform "$platform" \
        -v "$PWD:/workspace:ro" \
        -e GAUSS_HOST="$host" \
        -e GAUSS_PORT="$port" \
        -e GAUSS_DATABASE="$database" \
        -e GAUSS_MODE="$mode" \
        -e GAUSS_USER="$user" \
        -e GAUSS_PASSWORD \
        "$image" php tests/php_compat_integration.php \
        > "$result_directory/compat-linux-$architecture-$mode.json"
done

echo "M/O compatibility matrix passed for Linux $architecture"
