#!/usr/bin/env bash

require_file() {
    local path=$1
    [[ -f "$path" ]] || { echo "File does not exist: $path" >&2; exit 2; }
}

find_one() {
    local root=$1
    local description=$2
    shift 2
    local result
    result=$(find "$root" -type f "$@" -print -quit)
    [[ -n "$result" ]] || { echo "$description not found" >&2; exit 1; }
    printf '%s\n' "$result"
}

extract_tar() {
    local archive=$1
    local destination=$2
    mkdir -p "$destination"
    tar -xzf "$archive" -C "$destination"
}

sha256_file() {
    shasum -a 256 "$1" | awk '{print $1}'
}

verify_sha256() {
    local path=$1
    local expected=$2
    local actual
    actual=$(sha256_file "$path")
    if [[ "$actual" != "$expected" ]]; then
        echo "Unexpected SHA-256 for $path" >&2
        echo "Expected: $expected" >&2
        echo "Actual:   $actual" >&2
        exit 1
    fi
    printf '%s\n' "$actual"
}

replace_directory() {
    local source=$1
    local destination=$2
    rm -rf "$destination"
    mkdir -p "$destination"
    cp -R "$source/." "$destination/"
}
