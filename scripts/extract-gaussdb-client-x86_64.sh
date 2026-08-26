#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 DRIVER_BUNDLE OUTPUT_DIRECTORY" >&2
    exit 2
fi

driver_bundle=$1
output_directory=$2
script_directory=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
source "$script_directory/lib/extract-common.sh"
require_file "$driver_bundle"

temporary_directory=$(mktemp -d /tmp/gaussdb-x86-client.XXXXXX)
trap 'rm -rf "$temporary_directory"' EXIT
mkdir -p "$temporary_directory"/{top,mini,catalog,wrapper,client}

extract_tar "$driver_bundle" "$temporary_directory/top"
mini_package=$(find_one "$temporary_directory/top" "GaussDB x86_64 mini package" \
    -name 'DBS-GaussDB-driver_*.mini.x86_64.*.tar.gz')
extract_tar "$mini_package" "$temporary_directory/mini"

catalog=$(find_one "$temporary_directory/mini" "Distributed libpq catalog" \
    -path '*/Distributed/libpq_driver.tar.gz')
extract_tar "$catalog" "$temporary_directory/catalog"

wrapper=$(find_one "$temporary_directory/catalog" "Euler2.10 x86_64 Distributed libpq wrapper" \
    -path '*/Euler2.10_X86_64/*' \
    -name 'GaussDB-Kernel_*_Libpq_X86_Distributed.tar.gz')
extract_tar "$wrapper" "$temporary_directory/wrapper"

archive=$(find_one "$temporary_directory/wrapper" "Euler 64-bit libpq client archive" \
    -name 'GaussDB-Kernel_*_Euler_64bit_Libpq.tar.gz')
extract_tar "$archive" "$temporary_directory/client"

actual_sha256=$(verify_sha256_if_expected "$temporary_directory/client/lib/libpq.so.5.5" "${GAUSSDB_EXPECTED_LIBPQ_SHA256:-}")

replace_directory "$temporary_directory/client" "$output_directory"
echo "Extracted GaussDB Distributed x86_64 client to: $output_directory"
echo "Verified libpq.so.5.5 SHA-256: $actual_sha256"
