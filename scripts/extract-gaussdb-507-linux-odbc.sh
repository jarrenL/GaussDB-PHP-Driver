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

package_series=${GAUSSDB_PACKAGE_SERIES:-507.0}
release_version=${GAUSSDB_RELEASE_VERSION:-507.0.0}
build_version=${GAUSSDB_BUILD_VERSION:-B071}

case "$architecture" in
    arm64)
        mini_pattern="DBS-GaussDB-driver_${package_series}_*.mini.aarch64.*.tar.gz"
        platform_directory='Euler2.10_arm_64'
        platform_label='ARM'
        expected_driver_sha256=${GAUSSDB_EXPECTED_ODBC_SHA256:-89193bd12b99322875594ea6a7cc1c442f970e8dfb0e1c152dc5b0d2fbec437b}
        expected_libpq_sha256=${GAUSSDB_EXPECTED_ODBC_LIBPQ_SHA256:-63b69c4f678da654cd6188d4cff795f2a01afdcc3943cd483f183498e3187760}
        ;;
    x86_64)
        mini_pattern="DBS-GaussDB-driver_${package_series}_*.mini.x86_64.*.tar.gz"
        platform_directory='Euler2.10_X86_64'
        platform_label='X86'
        expected_driver_sha256=${GAUSSDB_EXPECTED_ODBC_SHA256:-365e15a342415566ce339e563b3959af6d3b6906c3098b7a38b92c2c90a66d7f}
        expected_libpq_sha256=${GAUSSDB_EXPECTED_ODBC_LIBPQ_SHA256:-c70f7a14d7777cfb7881c4ba4a236b21b16234a47fc4de03b660c5f1d1827af9}
        ;;
    *)
        echo "Unsupported architecture: $architecture" >&2
        exit 2
        ;;
esac

require_file "$driver_bundle"
temporary_directory=$(mktemp -d /tmp/gaussdb-507-linux-odbc.XXXXXX)
trap 'rm -rf "$temporary_directory"' EXIT
mkdir -p "$temporary_directory"/{top,mini,catalog,wrapper,client}

extract_tar "$driver_bundle" "$temporary_directory/top"
mini_package=$(find_one "$temporary_directory/top" "GaussDB $architecture mini package" -name "$mini_pattern")
extract_tar "$mini_package" "$temporary_directory/mini"

catalog=$(find_one "$temporary_directory/mini" 'Distributed ODBC catalog' -path '*/Distributed/odbc_driver.tar.gz')
extract_tar "$catalog" "$temporary_directory/catalog"

wrapper=$(find_one "$temporary_directory/catalog" "Euler2.10 $architecture ODBC wrapper" \
    -path "*/${platform_directory}/*" \
    -name "GaussDB-Kernel_${release_version}.${build_version}_Odbc_${platform_label}_Distributed.tar.gz")
extract_tar "$wrapper" "$temporary_directory/wrapper"

archive=$(find_one "$temporary_directory/wrapper" 'GaussDB Linux ODBC client archive' \
    -name "GaussDB-Kernel_${release_version}_Euler_64bit_Odbc.tar.gz")
extract_tar "$archive" "$temporary_directory/client"

driver_sha256=$(verify_sha256 "$temporary_directory/client/odbc/lib/gsqlodbcw.so" "$expected_driver_sha256")
libpq_sha256=$(verify_sha256 "$temporary_directory/client/lib/libpq.so.5.5" "$expected_libpq_sha256")
replace_directory "$temporary_directory/client" "$output_directory"

{
    printf '%s  %s\n' "$driver_sha256" odbc/lib/gsqlodbcw.so
    printf '%s  %s\n' "$libpq_sha256" lib/libpq.so.5.5
} > "$output_directory/SHA256SUMS"

echo "Extracted GaussDB 507 Distributed Linux $architecture ODBC client to: $output_directory"
