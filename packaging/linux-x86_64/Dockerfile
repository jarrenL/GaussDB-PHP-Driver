FROM php:8.3-cli-bookworm AS php-builder

COPY build/gaussdb-client/linux-x86_64/ /opt/gaussdb-client/

RUN apt-get update \
    && apt-get install -y --no-install-recommends binutils patchelf \
    && rm -f /opt/gaussdb-client/lib/libstdc++.so* /opt/gaussdb-client/lib/libcurl.so* \
    && LD_LIBRARY_PATH=/opt/gaussdb-client/lib docker-php-ext-configure pdo_pgsql --with-pdo-pgsql=/opt/gaussdb-client \
    && LD_LIBRARY_PATH=/opt/gaussdb-client/lib docker-php-ext-configure pgsql --with-pgsql=/opt/gaussdb-client \
    && LD_LIBRARY_PATH=/opt/gaussdb-client/lib docker-php-ext-install -j2 pdo_pgsql pgsql \
    && patchelf --set-rpath /opt/gaussdb-client/lib "$(php-config --extension-dir)/pdo_pgsql.so" \
    && patchelf --set-rpath /opt/gaussdb-client/lib "$(php-config --extension-dir)/pgsql.so" \
    && nm -D --defined-only /opt/gaussdb-client/lib/libpq.so.5.5 | awk '{print $3}' | sort -u > /tmp/libpq-symbols \
    && for extension in pdo_pgsql pgsql; do \
        nm -D --undefined-only "$(php-config --extension-dir)/${extension}.so" | awk '{print $2}' | grep '^PQ' | sort -u > /tmp/required-symbols; \
        missing="$(comm -23 /tmp/required-symbols /tmp/libpq-symbols)"; \
        test -z "$missing" || { echo "Missing GaussDB libpq symbols required by ${extension}: $missing" >&2; exit 1; }; \
       done \
    && rm -rf /var/lib/apt/lists/*

FROM php:8.3-cli-bookworm

COPY --from=php-builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY build/gaussdb-client/linux-x86_64/ /opt/gaussdb-client/

RUN apt-get update \
    && apt-get install -y --no-install-recommends patchelf \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /opt/gaussdb-client/lib/libstdc++.so* \
        /opt/gaussdb-client/lib/libcurl.so* \
    && find /opt/gaussdb-client/lib -type f -name '*.so*' -exec patchelf --set-rpath /opt/gaussdb-client/lib {} \; \
    && test "$(patchelf --print-rpath "$(php-config --extension-dir)/pdo_pgsql.so")" = /opt/gaussdb-client/lib \
    && test "$(patchelf --print-rpath /opt/gaussdb-client/lib/libpq.so.5.5)" = /opt/gaussdb-client/lib \
    && ldd "$(php-config --extension-dir)/pdo_pgsql.so" \
    && php -r 'if (!extension_loaded("pdo_pgsql")) { exit(1); } if (!in_array("pgsql", PDO::getAvailableDrivers(), true)) { exit(1); }'

WORKDIR /workspace
