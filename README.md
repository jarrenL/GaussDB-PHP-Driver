# GaussDB PHP M/O 兼容驱动

本项目为 PHP 提供 GaussDB **M 模式**和 **Oracle 兼容模式**接入能力。GaussDB 官方将 Oracle 兼容模式记为 **A/ORA**，本项目同时接受客户常用的 `O` 名称。

实现不修改 GaussDB 内核。底层统一使用 GaussDB 官方 Unicode ODBC 驱动，上层使用 PHP 官方 `PDO_ODBC`，本仓库提供真正处理模式差异的 PHP 兼容层：

```text
客户 PHP 代码
    ↓
GaussDb\Compat（本仓库）
    ↓
PHP PDO_ODBC
    ↓
GaussDB 官方 ODBC
    ↓
GaussDB M 或 A/ORA 数据库
```

GaussDB 当前官方驱动清单没有独立 PHP 驱动；官方开发指南明确列出 M 模式支持 ODBC、不支持 libpq/Psycopg。因此，早期 `PDO_PGSQL + libpq` 只保留为历史 PoC，不再作为 M 模式交付方案。

## 已实现能力

- Windows 与 Linux 使用同一套 PHP API。
- M 与 A/ORA（O）连接后自动校验数据库模式，防止连错库。
- 连接串强制加入 `ConnSettings=set client_encoding=UTF8`。
- PHP `bool` 自动适配为 M/ODBC 可接受的 `0/1`。
- `BinaryValue` 根据模式自动选择绑定方式：M `VARBINARY/BLOB` 使用原始 LOB，ORA `RAW` 使用十六进制。
- `ResultType::BINARY_HEX` 将 GaussDB ODBC 返回的十六进制二进制值恢复成 PHP 原始字节。
- 保留预处理、命名/位置参数、事务、保存点、`rowCount()`、SQLSTATE 和原生 PDO 访问能力。
- 支持 Composer PSR-4，也可直接加载 `src/autoload.php`。

## 平台与实测结果

使用与测试实例匹配的 GaussDB 官方 Unicode ODBC 驱动，并以同一套 10 项兼容契约完成以下验证：

| 客户端 | PHP/架构 | M | A/ORA（O） |
|---|---|---:|---:|
| Linux | PHP 8.3 ARM64 | 10/10 | 10/10 |
| Linux | PHP 8.3 x86_64 | 10/10 | 10/10 |
| Windows 11 UTM | PHP 8.3.8 AMD64 | 10/10 | 10/10 |
| Windows 11 UTM | PHP 8.3.8 i586 | 10/10 | 10/10 |

合计 **80/80 通过**。覆盖预处理 CRUD、DECIMAL、NULL、布尔、中文/emoji、含 NUL 与 `0xFF` 的二进制、SQL 注入防护、命名参数、结果映射、语句复用、增删改行数、事务、保存点、重复键 SQLSTATE 和异常后连接恢复。基线见 [`tests/baselines/compat-m-o-matrix.json`](tests/baselines/compat-m-o-matrix.json)。

## 客户怎么使用

客户需要安装：

1. PHP 8.1+ 与 `PDO_ODBC`。
2. 与 GaussDB 服务端版本、操作系统和 CPU 架构匹配的官方 ODBC 驱动。
3. 本仓库 `src/` 代码，或通过 Composer 引入本项目。

最小示例：

```php
<?php

use GaussDb\Compat\CompatibilityMode;
use GaussDb\Compat\ConnectionConfig;
use GaussDb\Compat\Driver;

require '/opt/gaussdb-php-compat/src/autoload.php';

$db = Driver::connect(new ConnectionConfig(
    host: 'gaussdb.example.com',
    port: 5432,
    database: 'app_m',
    user: getenv('GAUSS_USER'),
    password: getenv('GAUSS_PASSWORD'),
    mode: CompatibilityMode::M,
));

$row = $db->execute('SELECT id, name FROM users WHERE id = ?', [1])->fetch();
```

Oracle 兼容库只需改为：

```php
mode: CompatibilityMode::ORACLE
```

完整安装、二进制类型和卸载说明见：

- [客户使用手册](CUSTOMER_USAGE.md)
- [Linux 普通服务器安装](LINUX_BUILD_INSTALL.md)
- [Windows ODBC 安装](packaging/windows-odbc/README.md)

## 构建入口

驱动二进制不提交到 Git。客户使用与服务端版本、操作系统和 CPU 架构匹配的已授权驱动包提取并校验：

```bash
make extract-odbc-arm64 GAUSSDB_DRIVER_ARCHIVE='/path/to/aarch64-driver.tar.gz'
make build-odbc-arm64

make extract-odbc-x86_64 GAUSSDB_DRIVER_ARCHIVE='/path/to/x86_64-driver.tar.gz'
make build-odbc-x86_64

make extract-windows-odbc GAUSSDB_DRIVER_ARCHIVE='/path/to/x86_64-driver.tar.gz'
```

`packaging/linux-odbc/Dockerfile` 只用于项目复现和 CI。客户普通服务器不需要 Docker，按 `LINUX_BUILD_INSTALL.md` 安装即可。

## 项目边界

- 不提供 `pdo_gaussdb.so`、`pdo_gaussdb.dll` 或 `gaussdb:` DSN；PHP 驱动名仍是 `odbc`。
- 当前实测 PHP 版本为 8.3；代码最低语法版本为 8.1。
- ODBC 不支持的用户自定义类型、部分存储过程 OUT 参数等能力，本兼容层不能凭空补齐。
- `TIMESTAMP` 微秒、M `AUTO_INCREMENT/lastInsertId()`、M `TEXT` 65,535 字节限制属于服务端或 ODBC 语义边界，不能伪造为已兼容。
- 仓库不重新分发 GaussDB 厂商二进制，客户必须从有授权的驱动包或官方渠道获取。

详细边界见 [已知限制](KNOWN_LIMITATIONS.md)。

## 官方依据

- [GaussDB 应用程序开发指南：驱动与兼容模式矩阵](https://support.huaweicloud.com/intl/en-us/centralized-devg-v10-gaussdb/gaussdb-42-0076.html)
- [GaussDB ODBC 高性能绑定：UTF-8 ConnSettings](https://support.huaweicloud.com/intl/en-us/distributed-devg-v10-gaussdb/gaussdb-12-0145.html)
- [PHP PDO_ODBC](https://www.php.net/manual/en/ref.pdo-odbc.php)
