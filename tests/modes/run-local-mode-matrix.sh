#!/usr/bin/env bash
set -euo pipefail

: "${GAUSS_PASSWORD:?GAUSS_PASSWORD is required}"

database_container=${GAUSS_DATABASE_CONTAINER:-gaussdb-507}
php_image=${GAUSS_PHP_IMAGE:-gaussdb-php:8.3-arm64-prototype}
php_platform=${GAUSS_PHP_PLATFORM:-linux/arm64}
docker_network=${GAUSS_DOCKER_NETWORK:-gaussdb_default}
database_user=${GAUSS_USER:-gauss_php_test}
result_dir=${GAUSS_RESULT_DIR:-build/test-results/modes}
gsql='export LD_LIBRARY_PATH=/opt/gaussdb/app/lib; /opt/gaussdb/app/bin/gsql'
created_databases=()

for identifier in "$database_container" "$docker_network"; do
  if [[ ! $identifier =~ ^[A-Za-z0-9_.-]+$ ]]; then
    echo "Unsafe identifier: $identifier" >&2
    exit 2
  fi
done
if [[ ! $database_user =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
  echo "GAUSS_USER must be a simple SQL identifier" >&2
  exit 2
fi
if [[ ! ${GAUSS_M_DATABASE:-gdbdrv_m_test} =~ ^[A-Za-z][A-Za-z0-9_]*$ ]]; then
  echo "GAUSS_M_DATABASE must be a simple SQL identifier" >&2
  exit 2
fi

mkdir -p "$result_dir"

cleanup() {
  local database
  for database in "${created_databases[@]}"; do
    docker exec "$database_container" bash -lc \
      "$gsql -d postgres -p 5432 -v ON_ERROR_STOP=1 -c \"DROP DATABASE IF EXISTS $database\"" \
      >/dev/null 2>&1 || true
  done
}
trap cleanup EXIT

run_mode() {
  local label=$1
  local create_mode=$2
  local expected_mode=$3
  local database="gdbdrv_php_${label}_probe"

  created_databases+=("$database")
  docker exec "$database_container" bash -lc \
    "$gsql -d postgres -p 5432 -v ON_ERROR_STOP=1 -c \"DROP DATABASE IF EXISTS $database\" -c \"CREATE DATABASE $database OWNER $database_user DBCOMPATIBILITY '$create_mode'\"" \
    >/dev/null
  docker exec "$database_container" bash -lc \
    "$gsql -d $database -p 5432 -v ON_ERROR_STOP=1 -c \"GRANT ALL ON SCHEMA public TO $database_user\"" \
    >/dev/null

  docker run --rm --platform "$php_platform" --network "$docker_network" \
    -e GAUSS_DATABASE="$database" \
    -e GAUSS_EXPECTED_MODE="$expected_mode" \
    -e GAUSS_HOST="$database_container" \
    -e GAUSS_USER="$database_user" \
    -e GAUSS_PASSWORD \
    -v "$PWD/tests/modes:/tests/modes:ro" \
    "$php_image" php /tests/modes/php_mode_contract.php |
    tee "$result_dir/${label}.json"

  docker exec "$database_container" bash -lc \
    "$gsql -d postgres -p 5432 -v ON_ERROR_STOP=1 -c \"DROP DATABASE $database\"" \
    >/dev/null
}

run_existing_mode() {
  local label=$1
  local database=$2
  local expected_mode=$3

  docker run --rm --platform "$php_platform" --network "$docker_network" \
    -e GAUSS_DATABASE="$database" \
    -e GAUSS_EXPECTED_MODE="$expected_mode" \
    -e GAUSS_HOST="$database_container" \
    -e GAUSS_USER="$database_user" \
    -e GAUSS_PASSWORD \
    -v "$PWD/tests/modes:/tests/modes:ro" \
    "$php_image" php /tests/modes/php_mode_contract.php |
    tee "$result_dir/${label}.json"
}

run_mode ora ORA ORA
run_mode mysql MYSQL MYSQL
run_mode pg PG PG
run_existing_mode m "${GAUSS_M_DATABASE:-gdbdrv_m_test}" M

c_database=gdbdrv_php_c_probe
if docker exec "$database_container" bash -lc \
  "$gsql -d postgres -p 5432 -v ON_ERROR_STOP=1 -c \"CREATE DATABASE $c_database OWNER $database_user DBCOMPATIBILITY 'C'\"" \
  >/dev/null 2>"$result_dir/c-create-error.txt"; then
  docker exec "$database_container" bash -lc \
    "$gsql -d postgres -p 5432 -v ON_ERROR_STOP=1 -c \"DROP DATABASE $c_database\"" >/dev/null
  run_mode c C C
else
  printf '%s\n' '{"mode":"C","status":"unsupported-by-local-kernel"}' >"$result_dir/c.json"
fi

python3 - "$result_dir" <<'PY'
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
rows = []
for name in ("ora", "mysql", "pg", "m", "c"):
    data = json.loads((root / f"{name}.json").read_text(encoding="utf-8"))
    rows.append({"profile": name, "mode": data.get("mode"), "status": data.get("status", "tested"), "summary": data.get("summary")})
(root / "summary.json").write_text(json.dumps(rows, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps(rows, ensure_ascii=False, indent=2))
PY
