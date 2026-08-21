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
package_series=${GAUSSDB_PACKAGE_SERIES:-507.0}
release_version=${GAUSSDB_RELEASE_VERSION:-507.0.0}
build_version=${GAUSSDB_BUILD_VERSION:-B071}
expected_libpq_sha256=${GAUSSDB_EXPECTED_LIBPQ_SHA256:-7960663fe291eb290204a4a2c0caa956b71948e4d30ea3f4442ea46b0eb1cfb7}

require_file "$driver_bundle"

temporary_directory=$(mktemp -d /tmp/gaussdb-507-client.XXXXXX)
cleanup() {
    rm -rf "$temporary_directory"
}
trap cleanup EXIT

mkdir -p "$temporary_directory/top" "$temporary_directory/mini" \
    "$temporary_directory/catalog" "$temporary_directory/wrapper" \
    "$temporary_directory/client"

extract_tar "$driver_bundle" "$temporary_directory/top"

mini_package=$(find_one "$temporary_directory/top" "GaussDB ARM64 mini driver package" \
    -name "DBS-GaussDB-driver_${package_series}_*.mini.aarch64.*.tar.gz")

extract_tar "$mini_package" "$temporary_directory/mini"

libpq_catalog=$(find_one "$temporary_directory/mini" "Distributed libpq catalog" \
    -path '*/Distributed/libpq_driver.tar.gz')

extract_tar "$libpq_catalog" "$temporary_directory/catalog"

libpq_wrapper=$(find_one "$temporary_directory/catalog" "Euler2.10 ARM64 Distributed libpq wrapper" \
    -path '*/Euler2.10_arm_64/*' \
    -name "GaussDB-Kernel_${release_version}.${build_version}_Libpq_ARM_Distributed.tar.gz")

extract_tar "$libpq_wrapper" "$temporary_directory/wrapper"

libpq_archive=$(find_one "$temporary_directory/wrapper" "Euler 64-bit libpq client archive" \
    -name "GaussDB-Kernel_${release_version}_Euler_64bit_Libpq.tar.gz")

extract_tar "$libpq_archive" "$temporary_directory/client"

actual_libpq_sha256=$(verify_sha256 "$temporary_directory/client/lib/libpq.so.5.5" "$expected_libpq_sha256")

replace_directory "$temporary_directory/client" "$output_directory"

echo "Extracted GaussDB 507 Distributed ARM64 client to: $output_directory"
echo "Verified libpq.so.5.5 SHA-256: $actual_libpq_sha256"
