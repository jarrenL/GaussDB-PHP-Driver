#!/bin/bash
set -euo pipefail

export GAUSSHOME=/opt/gaussdb/app
export LD_LIBRARY_PATH=/opt/gaussdb/app/lib
export PATH=/opt/gaussdb/app/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

data_directory=${GAUSSDB_DATA_DIRECTORY:-/opt/gaussdb/data}
admin_user=${GAUSSDB_ADMIN_USER:-gausscore}

if [[ $(id -u) -eq 0 ]]; then
    if [[ ! -x /usr/sbin/chroot ]]; then
        echo "GaussDB image must provide /usr/sbin/chroot for the root-to-UID-1000 privilege drop" >&2
        exit 1
    fi
    mkdir -p "$data_directory"
    chown 1000:1000 "$data_directory"
    entrypoint_path=$(readlink -f "$0")
    exec /usr/sbin/chroot --userspec=1000:1000 / "$entrypoint_path" "$@"
fi

if [[ ! -f "$data_directory/PG_VERSION" ]]; then
    password_file=${GAUSSDB_INIT_PASSWORD_FILE:-}
    if [[ -z "$password_file" || ! -r "$password_file" ]]; then
        echo "Uninitialized data directory: set GAUSSDB_INIT_PASSWORD_FILE to a mounted, user-readable secret file" >&2
        exit 1
    fi

    chmod 700 "$data_directory"
    gs_initdb \
        -D "$data_directory" \
        --nodename="${GAUSSDB_NODE_NAME:-gaussdb_node}" \
        --username="$admin_user" \
        --pwfile="$password_file" \
        --auth-local=trust \
        --auth-host=sha256

    {
        echo "listen_addresses = '*'"
        echo "port = 5432"
    } >> "$data_directory/postgresql.conf"

    gaussdb --single_node -D "$data_directory" &
    server_pid=$!
    stop_initial_server() {
        gs_ctl stop -D "$data_directory" -m fast >/dev/null 2>&1 || kill "$server_pid" >/dev/null 2>&1 || true
        wait "$server_pid" 2>/dev/null || true
    }
    trap stop_initial_server EXIT

    for _ in $(seq 1 60); do
        if gsql -2 -U "$admin_user" -d postgres -p 5432 -c 'SELECT 1' < "$password_file" >/dev/null 2>&1; then
            break
        fi
        if ! kill -0 "$server_pid" 2>/dev/null; then
            echo "GaussDB stopped during first-start initialization" >&2
            exit 1
        fi
        sleep 1
    done
    gsql -2 -U "$admin_user" -d postgres -p 5432 -c 'SELECT 1' < "$password_file" >/dev/null

    if [[ -d /docker-entrypoint-initdb.d ]]; then
        while IFS= read -r -d '' init_file; do
            echo "Running initialization SQL: $init_file"
            gsql -2 -v ON_ERROR_STOP=1 -U "$admin_user" -d postgres -p 5432 -f "$init_file" < "$password_file"
        done < <(find /docker-entrypoint-initdb.d -maxdepth 1 -type f -name '*.sql' -print0 | sort -z)
    fi

    stop_initial_server
    trap - EXIT
fi

exec /opt/gaussdb/app/bin/gaussdb --single_node -D "$data_directory"
