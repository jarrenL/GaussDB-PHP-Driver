FROM php:8.3-cli-bookworm AS php-builder

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install -j2 pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

FROM php:8.3-cli-bookworm

COPY --from=php-builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=php-builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY build/gaussdb-client/linux-arm64/lib/ /opt/gaussdb-client/lib/

ENV LD_LIBRARY_PATH=/opt/gaussdb-client/lib

RUN rm -f /opt/gaussdb-client/lib/libstdc++.so* \
        /opt/gaussdb-client/lib/libcurl.so* \
    && ldd "$(php-config --extension-dir)/pdo_pgsql.so" \
    && php -r 'if (!extension_loaded("pdo_pgsql")) { exit(1); } echo PDO::getAvailableDrivers()[0] ?? "";'

WORKDIR /workspace
