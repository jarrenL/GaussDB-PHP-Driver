# GaussDB 507 M 模式 PHP 接入使用手册

## 1. 适用范围

本文档面向使用 PHP PDO 连接 **GaussDB Kernel 507.0.0 M 模式**的开发、测试和运维人员。当前已验证的客户端组合为：

| 客户端平台 | PHP 接口 | 底层客户端 | 当前 DSN |
|---|---|---|---|
| Linux ARM64 | PDO_PGSQL | GaussDB 507 ARM64 `libpq.so.5.5` | `pgsql:` |
| Linux x86_64 | PDO_PGSQL | GaussDB 507 x86_64 `libpq.so.5.5` | `pgsql:` |
| Windows x64/x86 | PDO_ODBC | GaussDB 507 ODBC | `odbc:` |

> 当前没有交付独立的 `pdo_gaussdb.so`、`pdo_gaussdb.dll` 或 `gaussdb:` DSN。Linux 上使用 `pgsql:`，Windows 上使用 `odbc:`。

当前完整实测版本为 PHP 8.3。PHP 8.1、8.2 和 8.4 尚未纳入兼容性承诺。

## 2. 连接前准备

向 GaussDB 管理员获取以下信息：

- GaussDB 主机名或 IP。
- 数据库端口，本项目实测为 `5432`。
- M 模式数据库名。
- 用户名和密码。
- 用户对目标 schema 的访问权限。
- 客户端到 GaussDB 端口的网络连通性。

测试环境可参考 [`docker/init-test-user.sql.example`](docker/init-test-user.sql.example) 创建 `gdbdrv_m_test` 和 `gauss_php_test`。不要将真实密码写入 Git 或 PHP 源码。

## 3. Linux 客户使用

### 3.1 获取匹配架构的镜像

交付方使用已授权的 GaussDB 507 驱动包构建镜像。驱动二进制不在本仓库重新分发。

ARM64：

```bash
make extract-client-arm64 \
  GAUSSDB_DRIVER_ARCHIVE='/secure/path/DBS-GaussDB-driver_aarch64_....tar.gz'
make build-php-arm64
./packaging/linux-arm64/verify-image.sh
```

x86_64：

```bash
make extract-client-x86_64 \
  GAUSSDB_DRIVER_ARCHIVE='/secure/path/DBS-GaussDB-driver_x86_64_....tar.gz'
make build-php-x86_64
./packaging/linux-x86_64/verify-image.sh
```

默认镜像名：

- ARM64：`gaussdb-php:8.3-arm64-prototype`
- x86_64：`gaussdb-php:8.3-x86_64-prototype`

镜像内的 `pdo_pgsql.so` 直接使用 GaussDB 507 头文件和 `libpq.so.5.5` 构建，并通过 RPATH 加载镜像内的 GaussDB 客户端库。

### 3.2 PHP 连接代码

```php
<?php

declare(strict_types=1);

$password = getenv('GAUSS_PASSWORD');
if ($password === false || $password === '') {
    throw new RuntimeException('GAUSS_PASSWORD is required');
}

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s',
    getenv('GAUSS_HOST') ?: '127.0.0.1',
    getenv('GAUSS_PORT') ?: '5432',
    getenv('GAUSS_DATABASE') ?: 'gdbdrv_m_test'
);

$pdo = new PDO(
    $dsn,
    getenv('GAUSS_USER') ?: 'gauss_php_test',
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$statement = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
$statement->execute([1]);
$row = $statement->fetch();
```

业务 SQL 中的值应使用 `prepare()` 和参数绑定，不要直接拼接外部输入。DECIMAL 建议按字符串处理，避免转换成 PHP `float` 后丢失精度。

### 3.3 在镜像中运行客户 PHP 程序

假设客户程序为当前目录的 `app.php`：

```bash
docker run --rm \
  --platform linux/arm64 \
  -v "$PWD:/workspace:ro" \
  -w /workspace \
  -e GAUSS_HOST='gaussdb.example.com' \
  -e GAUSS_PORT='5432' \
  -e GAUSS_DATABASE='gdbdrv_m_test' \
  -e GAUSS_USER='gauss_php_test' \
  -e GAUSS_PASSWORD \
  gaussdb-php:8.3-arm64-prototype \
  php app.php
```

x86_64 环境将 `--platform` 改为 `linux/amd64`，镜像改为 `gaussdb-php:8.3-x86_64-prototype`。如果 GaussDB 位于另一 Docker 网络，还需通过 `--network <network>` 加入该网络。

### 3.4 连接验证

```bash
docker run --rm \
  --platform linux/arm64 \
  -v "$PWD/tests:/tests:ro" \
  -e GAUSS_HOST='gaussdb.example.com' \
  -e GAUSS_PORT='5432' \
  -e GAUSS_DATABASE='gdbdrv_m_test' \
  -e GAUSS_USER='gauss_php_test' \
  -e GAUSS_PASSWORD \
  gaussdb-php:8.3-arm64-prototype \
  php /tests/php_pdo_pgsql_smoke.php
```

检查扩展和底层库：

```bash
php -r 'var_dump(extension_loaded("pdo_pgsql"), PDO::getAvailableDrivers());'
ldd "$(php-config --extension-dir)/pdo_pgsql.so"
```

`PDO::getAvailableDrivers()` 应包含 `pgsql`，`ldd` 结果应加载镜像中 `/opt/gaussdb-client/lib/` 下的 GaussDB 客户端库。

### 3.5 不建议直接使用系统 PHP-PGSQL 包

系统包管理器安装的 `php-pgsql` 通常链接 PostgreSQL 自带 `libpq`，不一定兼容 GaussDB 507 的认证和私有依赖。当出现以下错误时，首先检查动态链接：

```text
none of the server's SASL authentication mechanisms are supported
```

当前可验收的 Linux 交付方式是本项目构建的镜像。若需将扩展直接安装到客户主机，必须额外固定 PHP ABI、CPU 架构、GaussDB 客户端库路径和 RPATH，并执行完整契约测试。

## 4. Windows 客户使用

Windows 使用 `PDO_ODBC + GaussDB 507 ODBC`。PHP、PDO_ODBC、ODBC 驱动和 DSN 的位数必须一致。

### 4.1 安装 PHP 和 ODBC

以管理员 PowerShell 执行：

```powershell
./packaging/windows-odbc/setup-php.ps1

./packaging/windows-odbc/install-side-by-side.ps1 `
  -X86InstallerPath C:\path\to\x86\gsqlodbc.exe `
  -X64InstallerPath C:\path\to\x64\gsqlodbc.exe

Get-OdbcDriver | Where-Object Name -In @(
  'GaussDB Unicode', 'GaussDB ANSI', 'gsqlodbc'
)
```

只需一种位数时，可使用对应安装器；同一主机同时使用 x64/x86 时应使用 `install-side-by-side.ps1`，避免官方安装器的共用目录和注册表项相互覆盖。

### 4.2 无 DSN 连接（当前推荐）

```powershell
$env:GAUSS_ODBC_CONNECTION_STRING = 'Driver={GaussDB Unicode};Servername=gaussdb.example.com;Port=5432;Database=gdbdrv_m_test;SSLmode=prefer'
$env:GAUSS_USER = 'gauss_php_test'
$env:GAUSS_PASSWORD = '<password>'

C:\GaussDBTest\php-8.3.8-x64\php.exe .\tests\php_pdo_odbc_smoke.php
```

PHP 代码示例：

```php
<?php

$pdo = new PDO(
    'odbc:Driver={GaussDB Unicode};Servername=gaussdb.example.com;Port=5432;Database=gdbdrv_m_test;SSLmode=prefer',
    getenv('GAUSS_USER'),
    getenv('GAUSS_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
```

`SSLmode=prefer` 不等于已经建立 TLS。对安全环境的验收应使用服务端实际支持的 require/证书参数并单独验证。当前本地 507 测试实例为 `ssl=off`。

### 4.3 系统 DSN 连接

```powershell
./packaging/windows-odbc/configure-dsn.ps1 `
  -Name GaussDB507 `
  -DriverName 'GaussDB Unicode' `
  -Server 'gaussdb.example.com' `
  -Port 5432 `
  -Database gdbdrv_m_test `
  -Platform '64-bit'
```

```php
<?php

$pdo = new PDO(
    'odbc:GaussDB507',
    getenv('GAUSS_USER'),
    getenv('GAUSS_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
```

当前实测中，无 DSN 连接串比系统 DSN 在自动化进程中更稳定。

## 5. 生产使用建议

- 密码通过密钥管理系统、受限环境变量或权限受控文件传入，不写入镜像、代码或命令历史。
- 为应用创建最小权限数据库用户，不使用 GaussDB 管理员账号运行业务。
- 发布前在客户的真实 GaussDB 版本、网络和权限环境执行契约测试。
- 不依赖 `getColumnMeta()['native_type']` 识别 M 私有 BOOLEAN/VARBINARY 类型。
- 不依赖 `lastInsertId()`；当前 Linux 和 Windows 都没有稳定的 M `AUTO_INCREMENT` PDO 语义。
- DECIMAL 按字符串处理；TIMESTAMP 当前只承诺秒级精度。
- Linux VARBINARY 二进制值优先使用 `PDO::PARAM_LOB`；Windows 不要假定 ODBC 返回值已是原始字节。
- M TEXT 在当前实例的实测上限为 65,535 字节，65,536 字节开始返回 SQLSTATE `22001`。
- Windows ODBC 当前基线存在中文/emoji 编码问题，未完成适配前不承诺无损 Unicode。

详细说明和临时规避见 [`KNOWN_LIMITATIONS.md`](KNOWN_LIMITATIONS.md)。

## 6. 发布验收

交付给客户前至少完成：

1. 确认 PHP、CPU 和驱动架构一致。
2. 确认 Linux `pdo_pgsql.so` 加载的是 GaussDB 配套 `libpq`，或 Windows PDO_ODBC 加载的是对应位数 GaussDB ODBC。
3. 验证正确密码可连接、错误密码被拒绝，且日志不泄露密码。
4. 执行 CRUD、参数绑定、事务回滚和关键业务类型测试。
5. 评估所有与客户业务相关的已知限制。
6. 若项目要求 TLS，必须在启用 SSL 的 GaussDB 实例上完成 `sslmode=require` 及证书验收。

公共契约测试入口、判定标准和已跟踪的四平台结果见 [`tests/README.md`](tests/README.md)。

## 7. 常见故障

### `could not find driver`

PDO 驱动未加载。Linux 检查 `pdo_pgsql`，Windows 检查 `PDO_ODBC`：

```bash
php -m
php -r 'print_r(PDO::getAvailableDrivers());'
```

### Linux 认证机制不支持

检查 `pdo_pgsql.so` 是否误加载系统 PostgreSQL `libpq`。使用本项目镜像，不要在镜像中再安装系统 `php-pgsql` 覆盖已构建扩展。

### Windows 错误 193

通常是 PHP、PDO_ODBC 和 GaussDB ODBC 位数不一致。使用 `install-side-by-side.ps1` 和 `setup-php.ps1 -ExpectedArchitecture` 重新检查。

### 连接超时或拒绝

检查主机名、端口、容器网络、防火墙和 GaussDB `listen_addresses`/host 认证配置。先从 PHP 实际运行的主机或容器验证 TCP 连通性。

### `server does not support SSL, but SSL was required`

说明客户端已要求 SSL，但当前 GaussDB 实例未提供 SSL。不要为了消除错误而在需要 TLS 的生产环境降级为 `prefer`；应由数据库管理员启用并配置证书后重新验收。
