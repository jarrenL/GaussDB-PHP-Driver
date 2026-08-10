#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 DRIVER_BUNDLE OUTPUT_DIRECTORY" >&2
    exit 2
fi

driver_bundle=$1
output_directory=$2
expected_libpq_sha256=6d7876294a11f5b66676a51556ab3f94d8be58eaa57519b21c2d1ad193eee743

if [[ ! -f "$driver_bundle" ]]; then
    echo "Driver bundle does not exist: $driver_bundle" >&2
    exit 2
fi

temporary_directory=$(mktemp -d /tmp/gaussdb-507-x86-client.XXXXXX)
trap 'rm -rf "$temporary_directory"' EXIT
mkdir -p "$temporary_directory"/{top,mini,catalog,wrapper,client}

tar -xzf "$driver_bundle" -C "$temporary_directory/top"
mini_package=$(find "$temporary_directory/top" -type f \
    -name 'DBS-GaussDB-driver_507.0_*.mini.x86_64.*.tar.gz' -print -quit)
[[ -n "$mini_package" ]] || { echo "GaussDB 507 x86_64 mini package not found" >&2; exit 1; }
tar -xzf "$mini_package" -C "$temporary_directory/mini"

catalog=$(find "$temporary_directory/mini" -type f \
    -path '*/Distributed/libpq_driver.tar.gz' -print -quit)
[[ -n "$catalog" ]] || { echo "Distributed libpq catalog not found" >&2; exit 1; }
tar -xzf "$catalog" -C "$temporary_directory/catalog"

wrapper=$(find "$temporary_directory/catalog" -type f \
    -path '*/Euler2.10_X86_64/*' \
    -name 'GaussDB-Kernel_507.0.0.B071_Libpq_X86_Distributed.tar.gz' -print -quit)
[[ -n "$wrapper" ]] || { echo "507 B071 Euler2.10 x86_64 Distributed libpq wrapper not found" >&2; exit 1; }
tar -xzf "$wrapper" -C "$temporary_directory/wrapper"

archive=$(find "$temporary_directory/wrapper" -type f \
    -name 'GaussDB-Kernel_507.0.0_Euler_64bit_Libpq.tar.gz' -print -quit)
[[ -n "$archive" ]] || { echo "libpq client archive not found" >&2; exit 1; }
tar -xzf "$archive" -C "$temporary_directory/client"

actual_sha256=$(shasum -a 256 "$temporary_directory/client/lib/libpq.so.5.5" | awk '{print $1}')
if [[ "$actual_sha256" != "$expected_libpq_sha256" ]]; then
    echo "Unexpected libpq.so.5.5 SHA-256: $actual_sha256" >&2
    exit 1
fi

rm -rf "$output_directory"
mkdir -p "$output_directory"
cp -R "$temporary_directory/client/." "$output_directory/"
echo "Extracted GaussDB 507 Distributed x86_64 client to: $output_directory"
echo "Verified libpq.so.5.5 SHA-256: $actual_sha256"

