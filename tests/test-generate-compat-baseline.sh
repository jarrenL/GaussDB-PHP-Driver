#!/usr/bin/env bash

set -euo pipefail

temporary_directory=$(mktemp -d)
trap 'rm -rf "$temporary_directory"' EXIT
result_directory="$temporary_directory/results"
mkdir -p "$result_directory"
contract_id=$(php -r 'require "tests/CompatibilityContract.php"; echo CompatibilityContract::ID;')
contract_driver=$(php -r 'require "tests/CompatibilityContract.php"; echo CompatibilityContract::DRIVER;')

cat > "$result_directory/odbc.json" <<JSON
{
  "contract": "$contract_id",
  "driver": "$contract_driver",
  "mode": "MYSQL",
  "php": "8.3.0",
  "os": "Linux",
  "architecture": "x86_64",
  "summary": {"pass": 1, "fail": 0},
  "tests": [{"name": "fixture", "status": "pass"}]
}
JSON

cat > "$result_directory/legacy-pgsql.json" <<'JSON'
{
  "driver": "pgsql",
  "mode": "M",
  "php": "8.3.0",
  "os": "Linux",
  "architecture": "x86_64",
  "summary": {"pass": 1, "fail": 0},
  "tests": [{"name": "legacy fixture", "status": "pass"}]
}
JSON

php tests/generate-compat-baseline.php "$result_directory" "$temporary_directory/summary.json"
php -r '
$summary = json_decode(file_get_contents($argv[1]), true);
if (count($summary["targets"]) !== 1 || $summary["summary"] !== array("pass" => 1, "fail" => 0)) {
    fwrite(STDERR, "Legacy result was not excluded from generated baseline\n");
    exit(1);
}
' "$temporary_directory/summary.json"

cat > "$result_directory/invalid-current-contract.json" <<JSON
{
  "contract": "$contract_id",
  "driver": "pgsql",
  "mode": "M",
  "php": "8.3.0",
  "os": "Linux",
  "architecture": "arm64",
  "summary": {"pass": 1, "fail": 0},
  "tests": [{"name": "fixture", "status": "pass"}]
}
JSON

if php tests/generate-compat-baseline.php "$result_directory" "$temporary_directory/invalid-summary.json" >/dev/null 2>&1; then
    echo "Current contract accepted a non-ODBC result" >&2
    exit 1
fi

echo "compat baseline generator tests passed"
