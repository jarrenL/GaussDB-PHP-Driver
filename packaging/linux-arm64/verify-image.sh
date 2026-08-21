#!/usr/bin/env bash

set -euo pipefail

image=${1:-gaussdb-php:8.3-arm64-prototype}

docker run --rm --platform linux/arm64 "$image" bash -lc '
    extension=$(php-config --extension-dir)/pdo_pgsql.so
    loaded_libpq=$(ldd "$extension" | awk "/libpq.so.5/ {print \$3}")
    test "$loaded_libpq" = /opt/gaussdb-client/lib/libpq.so.5
    test "$(patchelf --print-rpath "$extension")" = /opt/gaussdb-client/lib
    test "$(patchelf --print-rpath /opt/gaussdb-client/lib/libpq.so.5.5)" = /opt/gaussdb-client/lib
    test -z "${LD_LIBRARY_PATH:-}"
    php -r '\''
        if (!extension_loaded("pdo_pgsql")) {
            fwrite(STDERR, "pdo_pgsql is not loaded\n");
            exit(1);
        }
        if (!in_array("pgsql", PDO::getAvailableDrivers(), true)) {
            fwrite(STDERR, "pgsql PDO driver is unavailable\n");
            exit(1);
        }
        if (!extension_loaded("openssl") || !str_starts_with(OPENSSL_VERSION_TEXT, "OpenSSL")) {
            fwrite(STDERR, "system OpenSSL extension is unavailable\n");
            exit(1);
        }
        echo "GaussDB libpq and PDO_PGSQL are ready\n";
    '\''
'
