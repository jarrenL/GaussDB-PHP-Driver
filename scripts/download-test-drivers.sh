#!/usr/bin/env bash

set -euo pipefail

if [[ $# -gt 1 ]]; then
    echo "Usage: $0 [OUTPUT_DIRECTORY]" >&2
    exit 2
fi

output_directory=${1:-build/gaussdb-client}
asset_name='gaussdb-php-test-drivers-v1.zip'
asset_url=${GAUSSDB_TEST_DRIVER_URL:-https://github.com/jarrenL/GaussDB-PHP-Driver/releases/download/test-assets-v1/$asset_name}
expected_sha256=${GAUSSDB_TEST_DRIVER_SHA256:-bdb5d1c4a1d1e9b18422814d353ceaa31677c5d2a92423ca126525f43b46e2a3}
script_directory=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
source "$script_directory/lib/extract-common.sh"

command -v curl >/dev/null 2>&1 || { echo 'curl is required' >&2; exit 127; }
command -v unzip >/dev/null 2>&1 || { echo 'unzip is required' >&2; exit 127; }

temporary_directory=$(mktemp -d /tmp/gaussdb-test-drivers.XXXXXX)
trap 'rm -rf "$temporary_directory"' EXIT
archive="$temporary_directory/$asset_name"
extracted="$temporary_directory/extracted"

curl --fail --location --silent --show-error "$asset_url" --output "$archive"
verify_sha256 "$archive" "$expected_sha256" >/dev/null
mkdir -p "$extracted"
unzip -q "$archive" -d "$extracted"

require_file "$extracted/linux-arm64-odbc/odbc/lib/gsqlodbcw.so"
require_file "$extracted/linux-x86_64-odbc/odbc/lib/gsqlodbcw.so"
require_file "$extracted/windows-odbc/x64/gsqlodbc.exe"
require_file "$extracted/windows-odbc/x86/gsqlodbc.exe"

replace_directory "$extracted/linux-arm64-odbc" "$output_directory/linux-arm64-odbc"
replace_directory "$extracted/linux-x86_64-odbc" "$output_directory/linux-x86_64-odbc"
replace_directory "$extracted/windows-odbc" "$output_directory/windows-odbc"

echo "Verified and installed test drivers in: $output_directory"
