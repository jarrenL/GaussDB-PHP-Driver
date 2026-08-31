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
docker_network=${GAUSS_DOCKER_NETWORK:-}
m_database=${GAUSS_M_DATABASE:-gdbdrv_m_test}
o_database=${GAUSS_O_DATABASE:-gdbdrv_a_test}
result_directory=${GAUSS_RESULT_DIRECTORY:-build/test-results}
result_prefix=${GAUSS_RESULT_PREFIX:-compat}
mkdir -p "$result_directory"

docker_arguments=(--rm --platform "$platform")
if [[ -n $docker_network ]]; then
    docker_arguments+=(--network "$docker_network")
elif [[ $host == host.docker.internal ]]; then
    docker_arguments+=(--add-host host.docker.internal:host-gateway)
fi

for target in "M:$m_database" "O:$o_database"; do
    mode=${target%%:*}
    database=${target#*:}
    docker run "${docker_arguments[@]}" \
        -v "$PWD:/workspace:ro" \
        -e GAUSS_HOST="$host" \
        -e GAUSS_PORT="$port" \
        -e GAUSS_DATABASE="$database" \
        -e GAUSS_MODE="$mode" \
        -e GAUSS_USER="$user" \
        -e GAUSS_PASSWORD \
        "$image" php tests/php_compat_integration.php \
        > "$result_directory/$result_prefix-linux-$architecture-$mode.json"
done

echo "M/O compatibility matrix passed for Linux $architecture"
