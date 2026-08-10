#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 X86_64_DRIVER_BUNDLE OUTPUT_DIRECTORY" >&2
    exit 2
fi

driver_bundle=$1
output_directory=$2
temporary_directory=$(mktemp -d /tmp/gaussdb-507-windows-odbc.XXXXXX)
trap 'rm -rf "$temporary_directory"' EXIT
mkdir -p "$temporary_directory"/{top,mini,catalog}

tar -xzf "$driver_bundle" -C "$temporary_directory/top"
mini_package=$(find "$temporary_directory/top" -type f \
    -name 'DBS-GaussDB-driver_507.0_*.mini.x86_64.*.tar.gz' -print -quit)
[[ -n "$mini_package" ]] || { echo "GaussDB 507 x86_64 mini package not found" >&2; exit 1; }
tar -xzf "$mini_package" -C "$temporary_directory/mini"

catalog=$(find "$temporary_directory/mini" -type f \
    -path '*/Distributed/odbc_driver.tar.gz' -print -quit)
[[ -n "$catalog" ]] || { echo "Distributed ODBC catalog not found" >&2; exit 1; }
tar -xzf "$catalog" -C "$temporary_directory/catalog"

rm -rf "$output_directory"
mkdir -p "$output_directory"

for bits in X64 X86; do
    case "$bits" in
        X64) expected_sha256=5dd95b7c1cc3f28a9494d8e4acaa678496f5ec82d3730a2d5df6cd970c6af87e ;;
        X86) expected_sha256=0fc17a01570fbdcc34bd1d788e1cf36e16bd386723f1d9dfb637d93992e1a007 ;;
    esac

    wrapper=$(find "$temporary_directory/catalog" -type f \
        -path '*/Euler2.10_X86_64/*' \
        -name "GaussDB-Kernel_507.0.0.B071_Odbc_Windows_${bits}_Distributed.tar.gz" -print -quit)
    [[ -n "$wrapper" ]] || { echo "Windows $bits ODBC wrapper not found" >&2; exit 1; }

    mkdir -p "$temporary_directory/$bits/wrapper" "$temporary_directory/$bits/final"
    tar -xzf "$wrapper" -C "$temporary_directory/$bits/wrapper"
    archive=$(find "$temporary_directory/$bits/wrapper" -type f \
        -name "GaussDB-Kernel_507.0.0_Windows_${bits}_Odbc.tar.gz" -print -quit)
    [[ -n "$archive" ]] || { echo "Windows $bits ODBC archive not found" >&2; exit 1; }
    tar -xzf "$archive" -C "$temporary_directory/$bits/final"

    actual_sha256=$(shasum -a 256 "$temporary_directory/$bits/final/gsqlodbc.exe" | awk '{print $1}')
    [[ "$actual_sha256" == "$expected_sha256" ]] || {
        echo "Unexpected Windows $bits installer SHA-256: $actual_sha256" >&2
        exit 1
    }

    destination=$(printf '%s' "$bits" | tr '[:upper:]' '[:lower:]')
    mkdir -p "$output_directory/$destination"
    cp "$temporary_directory/$bits/final/gsqlodbc.exe" "$output_directory/$destination/"
    printf '%s  %s\n' "$actual_sha256" gsqlodbc.exe > "$output_directory/$destination/SHA256SUMS"
done

echo "Extracted and verified GaussDB 507 Windows X64/X86 ODBC installers to: $output_directory"

