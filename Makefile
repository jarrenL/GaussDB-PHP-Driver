SHELL := /bin/bash

GAUSSDB_DRIVER_ARCHIVE ?=
ARM64_PHP_IMAGE ?= gaussdb-php:8.3-arm64-prototype
X86_64_PHP_IMAGE ?= gaussdb-php:8.3-x86_64-prototype
ARM64_ODBC_IMAGE ?= gaussdb-php:8.3-arm64-odbc
X86_64_ODBC_IMAGE ?= gaussdb-php:8.3-x86_64-odbc
ARM64_PHP72_ODBC_IMAGE ?= gaussdb-php:7.2.34-arm64-odbc
X86_64_PHP72_ODBC_IMAGE ?= gaussdb-php:7.2.34-x86_64-odbc
COMPAT_RESULT_DIRECTORY ?= build/test-results
COMPAT_BASELINE_OUTPUT ?= build/test-results/compat-generated-matrix.json

.PHONY: help docker-preflight extract-client extract-client-arm64 extract-client-x86_64 extract-odbc-arm64 extract-odbc-x86_64 extract-windows-odbc build-php build-php-arm64 build-php-x86_64 build-odbc-arm64 build-odbc-x86_64 build-php72-odbc-arm64 build-php72-odbc-x86_64 test-compat-unit test-compat-unit-php72 test-compat-arm64 test-compat-x86_64 test-compat-php72-arm64 test-compat-php72-x86_64 generate-compat-baseline test-arm64 test-x86_64 test-modes test-auth test-readonly test-text-threshold test-ssl lint lint-php72

help:
	@echo "make extract-client-arm64 GAUSSDB_DRIVER_ARCHIVE=/path/to/aarch64-driver.tar.gz"
	@echo "make extract-client-x86_64 GAUSSDB_DRIVER_ARCHIVE=/path/to/x86_64-driver.tar.gz"
	@echo "make extract-windows-odbc GAUSSDB_DRIVER_ARCHIVE=/path/to/x86_64-driver.tar.gz"
	@echo "make extract-odbc-arm64 GAUSSDB_DRIVER_ARCHIVE=/path/to/aarch64-driver.tar.gz"
	@echo "make extract-odbc-x86_64 GAUSSDB_DRIVER_ARCHIVE=/path/to/x86_64-driver.tar.gz"
	@echo "make build-odbc-arm64"
	@echo "make build-odbc-x86_64"
	@echo "make build-php72-odbc-arm64"
	@echo "make build-php72-odbc-x86_64"
	@echo "make test-compat-unit"
	@echo "make test-compat-unit-php72"
	@echo "GAUSS_PASSWORD=... make test-compat-arm64"
	@echo "GAUSS_PASSWORD=... make test-compat-x86_64"
	@echo "make generate-compat-baseline COMPAT_RESULT_DIRECTORY=build/test-results COMPAT_BASELINE_OUTPUT=build/test-results/compat-generated-matrix.json"
	@echo "make build-php-arm64"
	@echo "make build-php-x86_64"
	@echo "GAUSS_PASSWORD=... make test-arm64"
	@echo "GAUSS_PASSWORD=... make test-x86_64"
	@echo "GAUSS_PASSWORD=... make test-modes"
	@echo "GAUSS_BAD_PASSWORD=... make test-auth"
	@echo "GAUSS_READONLY_USER=... GAUSS_READONLY_PASSWORD=... make test-readonly"
	@echo "GAUSS_PASSWORD=... make test-text-threshold"
	@echo "GAUSS_PASSWORD=... make test-ssl (SSL unavailable => non-zero failure; probe exit 3 propagates to Make/CI)"
	@echo "make lint"

docker-preflight:
	./docker/preflight.sh

extract-client: extract-client-arm64

extract-client-arm64:
	@test -n "$(GAUSSDB_DRIVER_ARCHIVE)" || (echo "GAUSSDB_DRIVER_ARCHIVE is required" >&2; exit 2)
	./scripts/extract-gaussdb-client-arm64.sh "$(GAUSSDB_DRIVER_ARCHIVE)" build/gaussdb-client/linux-arm64

extract-client-x86_64:
	@test -n "$(GAUSSDB_DRIVER_ARCHIVE)" || (echo "GAUSSDB_DRIVER_ARCHIVE is required" >&2; exit 2)
	./scripts/extract-gaussdb-client-x86_64.sh "$(GAUSSDB_DRIVER_ARCHIVE)" build/gaussdb-client/linux-x86_64

extract-windows-odbc:
	@test -n "$(GAUSSDB_DRIVER_ARCHIVE)" || (echo "GAUSSDB_DRIVER_ARCHIVE is required" >&2; exit 2)
	./scripts/extract-gaussdb-windows-odbc.sh "$(GAUSSDB_DRIVER_ARCHIVE)" build/gaussdb-client/windows-odbc

extract-odbc-arm64:
	@test -n "$(GAUSSDB_DRIVER_ARCHIVE)" || (echo "GAUSSDB_DRIVER_ARCHIVE is required" >&2; exit 2)
	./scripts/extract-gaussdb-linux-odbc.sh "$(GAUSSDB_DRIVER_ARCHIVE)" build/gaussdb-client/linux-arm64-odbc arm64

extract-odbc-x86_64:
	@test -n "$(GAUSSDB_DRIVER_ARCHIVE)" || (echo "GAUSSDB_DRIVER_ARCHIVE is required" >&2; exit 2)
	./scripts/extract-gaussdb-linux-odbc.sh "$(GAUSSDB_DRIVER_ARCHIVE)" build/gaussdb-client/linux-x86_64-odbc x86_64

build-php: build-php-arm64

build-php-arm64:
	@test -f build/gaussdb-client/linux-arm64/lib/libpq.so.5.5 || (echo "Run make extract-client first" >&2; exit 2)
	docker build --platform linux/arm64 -f packaging/linux-arm64/Dockerfile -t "$(ARM64_PHP_IMAGE)" .

build-php-x86_64:
	@test -f build/gaussdb-client/linux-x86_64/lib/libpq.so.5.5 || (echo "Run make extract-client-x86_64 first" >&2; exit 2)
	docker build --platform linux/amd64 -f packaging/linux-x86_64/Dockerfile -t "$(X86_64_PHP_IMAGE)" .

build-odbc-arm64:
	@test -f build/gaussdb-client/linux-arm64-odbc/odbc/lib/gsqlodbcw.so || (echo "Run make extract-odbc-arm64 first" >&2; exit 2)
	docker build --platform linux/arm64 --build-arg CLIENT_ARCH=arm64 -f packaging/linux-odbc/Dockerfile -t "$(ARM64_ODBC_IMAGE)" .

build-odbc-x86_64:
	@test -f build/gaussdb-client/linux-x86_64-odbc/odbc/lib/gsqlodbcw.so || (echo "Run make extract-odbc-x86_64 first" >&2; exit 2)
	docker build --platform linux/amd64 --build-arg CLIENT_ARCH=x86_64 -f packaging/linux-odbc/Dockerfile -t "$(X86_64_ODBC_IMAGE)" .

build-php72-odbc-arm64:
	@test -f build/gaussdb-client/linux-arm64-odbc/odbc/lib/gsqlodbcw.so || (echo "Run make extract-odbc-arm64 first" >&2; exit 2)
	docker build --platform linux/arm64 --build-arg CLIENT_ARCH=arm64 --build-arg PHP_BASE_IMAGE=php:7.2-cli --build-arg USE_DEBIAN_ARCHIVE=1 -f packaging/linux-odbc/Dockerfile -t "$(ARM64_PHP72_ODBC_IMAGE)" .

build-php72-odbc-x86_64:
	@test -f build/gaussdb-client/linux-x86_64-odbc/odbc/lib/gsqlodbcw.so || (echo "Run make extract-odbc-x86_64 first" >&2; exit 2)
	docker build --platform linux/amd64 --build-arg CLIENT_ARCH=x86_64 --build-arg PHP_BASE_IMAGE=php:7.2-cli --build-arg USE_DEBIAN_ARCHIVE=1 -f packaging/linux-odbc/Dockerfile -t "$(X86_64_PHP72_ODBC_IMAGE)" .

test-compat-unit:
	docker run --rm -v "$(CURDIR):/workspace:ro" -w /workspace php:8.3-cli-bookworm php tests/php_compat_unit.php

test-compat-unit-php72:
	docker run --rm -v "$(CURDIR):/workspace:ro" -w /workspace php:7.2-cli php tests/php_compat_unit.php

test-compat-arm64:
	./tests/run-linux-compat-matrix.sh arm64 "$(ARM64_ODBC_IMAGE)"

test-compat-x86_64:
	./tests/run-linux-compat-matrix.sh x86_64 "$(X86_64_ODBC_IMAGE)"

test-compat-php72-arm64:
	GAUSS_RESULT_PREFIX=compat-php72 ./tests/run-linux-compat-matrix.sh arm64 "$(ARM64_PHP72_ODBC_IMAGE)"

test-compat-php72-x86_64:
	GAUSS_RESULT_PREFIX=compat-php72 ./tests/run-linux-compat-matrix.sh x86_64 "$(X86_64_PHP72_ODBC_IMAGE)"

generate-compat-baseline:
	php tests/generate-compat-baseline.php "$(COMPAT_RESULT_DIRECTORY)" "$(COMPAT_BASELINE_OUTPUT)"

test-arm64:
	./tests/run-linux-driver-contract.sh linux-arm64 "$(ARM64_PHP_IMAGE)"

test-x86_64:
	./tests/run-linux-driver-contract.sh linux-x86_64 "$(X86_64_PHP_IMAGE)"

test-modes:
	./tests/modes/run-local-mode-matrix.sh

test-auth:
	@test -n "$${GAUSS_BAD_PASSWORD:-}" || (echo "GAUSS_BAD_PASSWORD is required" >&2; exit 2)
	GAUSS_TEST_DRIVER=odbc ./tests/run-linux-special-contract.sh linux-arm64 php_pdo_auth_negative.php "$(ARM64_ODBC_IMAGE)"

test-readonly:
	@test -n "$${GAUSS_READONLY_USER:-}" || (echo "GAUSS_READONLY_USER is required" >&2; exit 2)
	@test -n "$${GAUSS_READONLY_PASSWORD:-}" || (echo "GAUSS_READONLY_PASSWORD is required" >&2; exit 2)
	GAUSS_TEST_DRIVER=odbc ./tests/run-linux-special-contract.sh linux-arm64 php_pdo_readonly_contract.php "$(ARM64_ODBC_IMAGE)"

test-text-threshold:
	@test -n "$${GAUSS_PASSWORD:-}" || (echo "GAUSS_PASSWORD is required" >&2; exit 2)
	GAUSS_TEST_DRIVER=odbc ./tests/run-linux-special-contract.sh linux-arm64 php_pdo_large_text_threshold.php "$(ARM64_ODBC_IMAGE)"

test-ssl:
	@test -n "$${GAUSS_PASSWORD:-}" || (echo "GAUSS_PASSWORD is required" >&2; exit 2)
	@# Probe exit 3 intentionally remains non-zero: a require-SSL gate must fail when TLS is unavailable.
	GAUSS_TEST_DRIVER=odbc GAUSS_SSLMODE=require ./tests/run-linux-special-contract.sh linux-arm64 php_pdo_ssl_probe.php "$(ARM64_ODBC_IMAGE)"

lint:
	docker run --rm -v "$(CURDIR):/workspace:ro" php:8.3-cli-bookworm sh -eu -c 'for file in /workspace/src/*.php /workspace/examples/*.php /workspace/tests/*.php /workspace/tests/modes/*.php; do php -l "$$file"; done'

lint-php72:
	docker run --rm -v "$(CURDIR):/workspace:ro" -w /workspace php:7.2-cli sh -eu -c 'for file in src/*.php examples/compat_odbc.php tests/php_compat_unit.php tests/php_compat_integration.php tests/generate-compat-baseline.php; do php -l "$$file"; done'
