#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 3 ]]; then
    echo "Usage: $0 DRIVER_BUNDLE OUTPUT_DIRECTORY arm64|x86_64" >&2
    exit 2
fi

driver_bundle=$1
output_directory=$2
architecture=$3
script_directory=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
source "$script_directory/lib/extract-common.sh"

case "$architecture" in
    arm64)
        mini_pattern='DBS-GaussDB-driver_*.mini.aarch64.*.tar.gz'
        platform_directory='Euler2.10_arm_64'
        platform_label='ARM'
        ;;
    x86_64)
        mini_pattern='DBS-GaussDB-driver_*.mini.x86_64.*.tar.gz'
        platform_directory='Euler2.10_X86_64'
        platform_label='X86'
        ;;
    *)
        echo "Unsupported architecture: $architecture" >&2
        exit 2
        ;;
esac

require_file "$driver_bundle"
temporary_directory=$(mktemp -d /tmp/gaussdb-linux-odbc.XXXXXX)
trap 'rm -rf "$temporary_directory"' EXIT
mkdir -p "$temporary_directory"/{top,mini,catalog,wrapper,client}

extract_tar "$driver_bundle" "$temporary_directory/top"
mini_package=$(find_one "$temporary_directory/top" "GaussDB $architecture mini package" -name "$mini_pattern")
extract_tar "$mini_package" "$temporary_directory/mini"

catalog=$(find_one "$temporary_directory/mini" 'Distributed ODBC catalog' -path '*/Distributed/odbc_driver.tar.gz')
extract_tar "$catalog" "$temporary_directory/catalog"

wrapper=$(find_one "$temporary_directory/catalog" "Euler2.10 $architecture ODBC wrapper" \
    -path "*/${platform_directory}/*" \
    -name "GaussDB-Kernel_*_Odbc_${platform_label}_Distributed.tar.gz")
extract_tar "$wrapper" "$temporary_directory/wrapper"

archive=$(find_one "$temporary_directory/wrapper" 'GaussDB Linux ODBC client archive' \
    -name 'GaussDB-Kernel_*_Euler_64bit_Odbc.tar.gz')
extract_tar "$archive" "$temporary_directory/client"

driver_sha256=$(verify_sha256_if_expected "$temporary_directory/client/odbc/lib/gsqlodbcw.so" "${GAUSSDB_EXPECTED_ODBC_SHA256:-}")
libpq_sha256=$(verify_sha256_if_expected "$temporary_directory/client/lib/libpq.so.5.5" "${GAUSSDB_EXPECTED_ODBC_LIBPQ_SHA256:-}")
replace_directory "$temporary_directory/client" "$output_directory"

{
    printf '%s  %s\n' "$driver_sha256" odbc/lib/gsqlodbcw.so
    printf '%s  %s\n' "$libpq_sha256" lib/libpq.so.5.5
} > "$output_directory/SHA256SUMS"

echo "Extracted GaussDB Distributed Linux $architecture ODBC client to: $output_directory"
