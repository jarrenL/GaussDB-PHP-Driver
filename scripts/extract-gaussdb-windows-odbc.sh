#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 X86_64_DRIVER_BUNDLE OUTPUT_DIRECTORY" >&2
    exit 2
fi

driver_bundle=$1
output_directory=$2
script_directory=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
source "$script_directory/lib/extract-common.sh"
require_file "$driver_bundle"
temporary_directory=$(mktemp -d /tmp/gaussdb-windows-odbc.XXXXXX)
trap 'rm -rf "$temporary_directory"' EXIT
mkdir -p "$temporary_directory"/{top,mini,catalog}

extract_tar "$driver_bundle" "$temporary_directory/top"
mini_package=$(find_one "$temporary_directory/top" "GaussDB x86_64 mini package" \
    -name 'DBS-GaussDB-driver_*.mini.x86_64.*.tar.gz')
extract_tar "$mini_package" "$temporary_directory/mini"

catalog=$(find_one "$temporary_directory/mini" "Distributed ODBC catalog" \
    -path '*/Distributed/odbc_driver.tar.gz')
extract_tar "$catalog" "$temporary_directory/catalog"

rm -rf "$output_directory"
mkdir -p "$output_directory"

for bits in X64 X86; do
    case "$bits" in
        X64) expected_sha256=${GAUSSDB_EXPECTED_ODBC_X64_SHA256:-} ;;
        X86) expected_sha256=${GAUSSDB_EXPECTED_ODBC_X86_SHA256:-} ;;
    esac

    wrapper=$(find_one "$temporary_directory/catalog" "Windows $bits ODBC wrapper" \
        -path '*/Euler2.10_X86_64/*' \
        -name "GaussDB-Kernel_*_Odbc_Windows_${bits}_Distributed.tar.gz")

    mkdir -p "$temporary_directory/$bits/wrapper" "$temporary_directory/$bits/final"
    extract_tar "$wrapper" "$temporary_directory/$bits/wrapper"
    archive=$(find_one "$temporary_directory/$bits/wrapper" "Windows $bits ODBC archive" \
        -name "GaussDB-Kernel_*_Windows_${bits}_Odbc.tar.gz")
    extract_tar "$archive" "$temporary_directory/$bits/final"

    actual_sha256=$(verify_sha256_if_expected "$temporary_directory/$bits/final/gsqlodbc.exe" "$expected_sha256")

    destination=$(printf '%s' "$bits" | tr '[:upper:]' '[:lower:]')
    mkdir -p "$output_directory/$destination"
    cp "$temporary_directory/$bits/final/gsqlodbc.exe" "$output_directory/$destination/"
    printf '%s  %s\n' "$actual_sha256" gsqlodbc.exe > "$output_directory/$destination/SHA256SUMS"
done

echo "Extracted GaussDB Windows X64/X86 ODBC installers to: $output_directory"
