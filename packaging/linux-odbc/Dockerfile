ARG PHP_BASE_IMAGE=php:8.3-cli-bookworm
FROM ${PHP_BASE_IMAGE}

ARG CLIENT_ARCH
ARG USE_DEBIAN_ARCHIVE=0
COPY build/gaussdb-client/linux-${CLIENT_ARCH}-odbc/ /opt/gaussdb-odbc/

RUN if [ "$USE_DEBIAN_ARCHIVE" = 1 ]; then \
        printf '%s\n' 'deb http://archive.debian.org/debian buster main' > /etc/apt/sources.list; \
        printf '%s\n' 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99archive; \
    fi \
    && apt-get update \
    && apt-get install -y --no-install-recommends odbcinst patchelf unixodbc unixodbc-dev \
    && docker-php-ext-configure pdo_odbc --with-pdo-odbc=unixODBC,/usr \
    && docker-php-ext-install -j2 pdo_odbc \
    && rm -f /opt/gaussdb-odbc/lib/libodbc.so* \
        /opt/gaussdb-odbc/lib/libodbcinst.so* \
        /opt/gaussdb-odbc/lib/libodbccr.so* \
        /opt/gaussdb-odbc/lib/libstdc++.so* \
    && for library in /opt/gaussdb-odbc/lib/*.so*; do \
        test -e "$library" || continue; \
        patchelf --set-rpath /opt/gaussdb-odbc/lib "$library"; \
       done \
    && patchelf --set-rpath /opt/gaussdb-odbc/lib /opt/gaussdb-odbc/odbc/lib/gsqlodbcw.so \
    && patchelf --set-rpath /opt/gaussdb-odbc/lib /opt/gaussdb-odbc/lib/libpq.so.5.5 \
    && test "$(patchelf --print-rpath /opt/gaussdb-odbc/odbc/lib/gsqlodbcw.so)" = /opt/gaussdb-odbc/lib \
    && test "$(patchelf --print-rpath /opt/gaussdb-odbc/lib/libpq.so.5.5)" = /opt/gaussdb-odbc/lib \
    && printf '%s\n' \
        '[GaussDB Unicode]' \
        'Description=GaussDB Unicode ODBC Driver' \
        'Driver=/opt/gaussdb-odbc/odbc/lib/gsqlodbcw.so' \
        'Setup=/opt/gaussdb-odbc/odbc/lib/gsqlodbcw.so' \
        'FileUsage=1' \
        > /etc/odbcinst.ini \
    && odbcinst -q -d -n 'GaussDB Unicode' \
    && ldd /opt/gaussdb-odbc/odbc/lib/gsqlodbcw.so > /tmp/odbc-ldd \
    && cat /tmp/odbc-ldd \
    && ! grep -q 'not found' /tmp/odbc-ldd \
    && php -r 'exit(extension_loaded("pdo_odbc") && in_array("odbc", PDO::getAvailableDrivers(), true) ? 0 : 1);' \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /workspace
