#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 DRIVER_BUNDLE OUTPUT_DIRECTORY" >&2
    exit 2
fi

driver_bundle=$1
output_directory=$2
expected_libpq_sha256=7960663fe291eb290204a4a2c0caa956b71948e4d30ea3f4442ea46b0eb1cfb7

if [[ ! -f "$driver_bundle" ]]; then
    echo "Driver bundle does not exist: $driver_bundle" >&2
    exit 2
fi

temporary_directory=$(mktemp -d /tmp/gaussdb-507-client.XXXXXX)
cleanup() {
    rm -rf "$temporary_directory"
}
trap cleanup EXIT

mkdir -p "$temporary_directory/top" "$temporary_directory/mini" \
    "$temporary_directory/catalog" "$temporary_directory/wrapper" \
    "$temporary_directory/client"

tar -xzf "$driver_bundle" -C "$temporary_directory/top"

mini_package=$(find "$temporary_directory/top" -type f \
    -name 'DBS-GaussDB-driver_507.0_*.mini.aarch64.*.tar.gz' -print -quit)
if [[ -z "$mini_package" ]]; then
    echo "The bundle does not contain a GaussDB 507 ARM64 mini driver package" >&2
    exit 1
fi

tar -xzf "$mini_package" -C "$temporary_directory/mini"

libpq_catalog=$(find "$temporary_directory/mini" -type f \
    -path '*/Distributed/libpq_driver.tar.gz' -print -quit)
if [[ -z "$libpq_catalog" ]]; then
    echo "The bundle does not contain Distributed/libpq_driver.tar.gz" >&2
    exit 1
fi

tar -xzf "$libpq_catalog" -C "$temporary_directory/catalog"

libpq_wrapper=$(find "$temporary_directory/catalog" -type f \
    -path '*/Euler2.10_arm_64/*' \
    -name 'GaussDB-Kernel_507.0.0.B071_Libpq_ARM_Distributed.tar.gz' \
    -print -quit)
if [[ -z "$libpq_wrapper" ]]; then
    echo "The bundle does not contain the expected 507 B071 Euler2.10 ARM64 Distributed libpq wrapper" >&2
    exit 1
fi

tar -xzf "$libpq_wrapper" -C "$temporary_directory/wrapper"

libpq_archive=$(find "$temporary_directory/wrapper" -type f \
    -name 'GaussDB-Kernel_507.0.0_Euler_64bit_Libpq.tar.gz' -print -quit)
if [[ -z "$libpq_archive" ]]; then
    echo "The wrapper does not contain the expected libpq client archive" >&2
    exit 1
fi

tar -xzf "$libpq_archive" -C "$temporary_directory/client"

actual_libpq_sha256=$(shasum -a 256 \
    "$temporary_directory/client/lib/libpq.so.5.5" | awk '{print $1}')
if [[ "$actual_libpq_sha256" != "$expected_libpq_sha256" ]]; then
    echo "Unexpected libpq.so.5.5 SHA-256" >&2
    echo "Expected: $expected_libpq_sha256" >&2
    echo "Actual:   $actual_libpq_sha256" >&2
    exit 1
fi

rm -rf "$output_directory"
mkdir -p "$output_directory"
cp -R "$temporary_directory/client/." "$output_directory/"

echo "Extracted GaussDB 507 Distributed ARM64 client to: $output_directory"
echo "Verified libpq.so.5.5 SHA-256: $actual_libpq_sha256"

