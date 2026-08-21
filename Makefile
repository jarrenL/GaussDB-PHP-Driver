SHELL := /bin/bash

GAUSSDB_DRIVER_ARCHIVE ?=
ARM64_PHP_IMAGE ?= gaussdb-php:8.3-arm64-prototype
X86_64_PHP_IMAGE ?= gaussdb-php:8.3-x86_64-prototype

.PHONY: help docker-preflight extract-client extract-client-arm64 extract-client-x86_64 extract-windows-odbc build-php build-php-arm64 build-php-x86_64 test-arm64 test-x86_64 test-modes test-auth test-readonly test-text-threshold test-ssl lint

help:
	@echo "make extract-client-arm64 GAUSSDB_DRIVER_ARCHIVE=/path/to/aarch64-driver.tar.gz"
	@echo "make extract-client-x86_64 GAUSSDB_DRIVER_ARCHIVE=/path/to/x86_64-driver.tar.gz"
	@echo "make extract-windows-odbc GAUSSDB_DRIVER_ARCHIVE=/path/to/x86_64-driver.tar.gz"
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
	./scripts/extract-gaussdb-507-client.sh "$(GAUSSDB_DRIVER_ARCHIVE)" build/gaussdb-client/linux-arm64

extract-client-x86_64:
	@test -n "$(GAUSSDB_DRIVER_ARCHIVE)" || (echo "GAUSSDB_DRIVER_ARCHIVE is required" >&2; exit 2)
	./scripts/extract-gaussdb-507-x86_64-client.sh "$(GAUSSDB_DRIVER_ARCHIVE)" build/gaussdb-client/linux-x86_64

extract-windows-odbc:
	@test -n "$(GAUSSDB_DRIVER_ARCHIVE)" || (echo "GAUSSDB_DRIVER_ARCHIVE is required" >&2; exit 2)
	./scripts/extract-gaussdb-507-windows-odbc.sh "$(GAUSSDB_DRIVER_ARCHIVE)" build/gaussdb-client/windows-odbc

build-php: build-php-arm64

build-php-arm64:
	@test -f build/gaussdb-client/linux-arm64/lib/libpq.so.5.5 || (echo "Run make extract-client first" >&2; exit 2)
	docker build --platform linux/arm64 -f packaging/linux-arm64/Dockerfile -t "$(ARM64_PHP_IMAGE)" .

build-php-x86_64:
	@test -f build/gaussdb-client/linux-x86_64/lib/libpq.so.5.5 || (echo "Run make extract-client-x86_64 first" >&2; exit 2)
	docker build --platform linux/amd64 -f packaging/linux-x86_64/Dockerfile -t "$(X86_64_PHP_IMAGE)" .

test-arm64:
	./tests/run-linux-driver-contract.sh linux-arm64 "$(ARM64_PHP_IMAGE)"

test-x86_64:
	./tests/run-linux-driver-contract.sh linux-x86_64 "$(X86_64_PHP_IMAGE)"

test-modes:
	./tests/modes/run-local-mode-matrix.sh

test-auth:
	@test -n "$${GAUSS_BAD_PASSWORD:-}" || (echo "GAUSS_BAD_PASSWORD is required" >&2; exit 2)
	./tests/run-linux-special-contract.sh linux-arm64 php_pdo_auth_negative.php "$(ARM64_PHP_IMAGE)"

test-readonly:
	@test -n "$${GAUSS_READONLY_USER:-}" || (echo "GAUSS_READONLY_USER is required" >&2; exit 2)
	@test -n "$${GAUSS_READONLY_PASSWORD:-}" || (echo "GAUSS_READONLY_PASSWORD is required" >&2; exit 2)
	./tests/run-linux-special-contract.sh linux-arm64 php_pdo_readonly_contract.php "$(ARM64_PHP_IMAGE)"

test-text-threshold:
	@test -n "$${GAUSS_PASSWORD:-}" || (echo "GAUSS_PASSWORD is required" >&2; exit 2)
	./tests/run-linux-special-contract.sh linux-arm64 php_pdo_large_text_threshold.php "$(ARM64_PHP_IMAGE)"

test-ssl:
	@test -n "$${GAUSS_PASSWORD:-}" || (echo "GAUSS_PASSWORD is required" >&2; exit 2)
	@# Probe exit 3 intentionally remains non-zero: a require-SSL gate must fail when TLS is unavailable.
	./tests/run-linux-special-contract.sh linux-arm64 php_pdo_ssl_probe.php "$(ARM64_PHP_IMAGE)"

lint:
	docker run --rm -v "$(CURDIR):/workspace:ro" php:8.3-cli-bookworm sh -eu -c 'for file in /workspace/examples/*.php /workspace/tests/*.php /workspace/tests/modes/*.php; do php -l "$$file"; done'
