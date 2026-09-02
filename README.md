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
- M 与 A/ORA（O）连接后自动校验数据库模式，M 接受 `datcompatibility` 返回 `M` 或 `MYSQL`，防止连错库。
- 连接串强制加入 `ConnSettings=set client_encoding=UTF8`。
- PHP `bool` 自动适配为 M/ODBC 可接受的 `0/1`。
- `BinaryValue` 根据模式自动选择绑定方式：M `BLOB` 使用原始 LOB，ORA `RAW` 使用十六进制。
- `ResultType::BINARY_HEX` 将 GaussDB ODBC 返回的十六进制二进制值恢复成 PHP 原始字节。
- 保留预处理、命名/位置参数、事务、保存点、`rowCount()`、SQLSTATE 和原生 PDO 访问能力。
- 支持 Composer PSR-4，也可直接加载 `src/autoload.php`。

## 平台与实测结果

使用与测试实例匹配的 GaussDB 官方 Unicode ODBC 驱动，并以同一套 10 项兼容契约完成以下验证：

| 客户端 | PHP/架构 | M | A/ORA（O） |
|---|---|---:|---:|
| Linux | PHP 7.2.34 ARM64 | 10/10 | 10/10 |
| Linux | PHP 7.2.34 x86_64 | 10/10 | 10/10 |
| Windows 11 UTM | PHP 7.2.34 AMD64 | 10/10 | 10/10 |
| Windows 11 UTM | PHP 7.2.34 i586 | 10/10 | 10/10 |
| Linux | PHP 8.3 ARM64 | 10/10 | 10/10 |
| Linux | PHP 8.3 x86_64 | 10/10 | 10/10 |
| Windows 11 UTM | PHP 8.3.8 AMD64 | 10/10 | 10/10 |
| Windows 11 UTM | PHP 8.3.8 i586 | 10/10 | 10/10 |

合计 **160/160 通过**。覆盖预处理 CRUD、DECIMAL、NULL、布尔、中文/emoji、含 NUL 与 `0xFF` 的二进制、SQL 注入防护、命名参数、结果映射、语句复用、增删改行数、事务、保存点、重复键 SQLSTATE 和异常后连接恢复。基线见 [`tests/baselines/compat-m-o-matrix.json`](tests/baselines/compat-m-o-matrix.json)。

## CI

每次 push/PR 都会执行 PHP 7.2/8.3 语法、单元测试、Composer、Shell、PowerShell 和 JSON 检查。GaussDB 官方 ODBC 二进制不提交到仓库，因此真实集成矩阵由 `workflow_dispatch` 在具有授权驱动包的 self-hosted runner 上触发。

集成 job 会提取 ODBC 驱动，构建 PHP 7.2/8.3 的 ARM64/x86_64 镜像，运行 M/O 兼容契约，生成可追溯汇总并上传原始 JSON 工件。所需仓库变量为 `GAUSSDB_ARM64_DRIVER_ARCHIVE`、`GAUSSDB_X86_64_DRIVER_ARCHIVE`、`GAUSS_HOST`、`GAUSS_USER`、`GAUSS_M_DATABASE`、`GAUSS_O_DATABASE`和可选 `GAUSS_DOCKER_NETWORK`；密码通过 `GAUSS_PASSWORD` secret 注入。

## 客户怎么使用

客户需要先准备：

1. PHP 7.2.34+ 与对应版本的 `PDO_ODBC`。
2. 与 GaussDB 服务端、操作系统和 CPU 架构匹配的官方 ODBC 驱动。
3. 本项目的 PHP 兼容层。

### 安装本项目代码

本项目目前没有发布到 Packagist，不能在未配置仓库时直接执行 `composer require`。通过公开 Git 仓库安装：

以下命令在客户应用已有 `composer.json` 的项目根目录执行；全新空目录先执行 `composer init --no-interaction --name=customer/application`。

```bash
composer config repositories.gaussdb-php-compat vcs https://github.com/jarrenL/GaussDB-PHP-Driver.git
composer require jarrenl/gaussdb-php-compat:dev-main
```

内网或已下载源码的环境建议使用本地 path 仓库：

```bash
composer config repositories.gaussdb-php-compat path /opt/gaussdb-php-compat
composer require jarrenl/gaussdb-php-compat:@dev
```

业务代码随后加载 `vendor/autoload.php`。不使用 Composer 时，将仓库放到应用服务器并直接加载 `src/autoload.php`。

### 模式参数

`CompatibilityMode` 是为兼容 PHP 7.2 而提供的字符串常量类，不是 PHP 8.1 enum。固定模式优先传常量：

- M：`CompatibilityMode::M`，常量值为 `M`。
- Oracle 兼容模式：`CompatibilityMode::ORACLE`，常量值为 GaussDB 的规范值 `ORA`。

从环境变量等外部配置读取时，显式归一化：

```php
$mode = CompatibilityMode::fromName(getenv('GAUSS_MODE') ?: 'M');
```

`fromName()` 和 `ConnectionConfig` 接受的别名为：`M/MYSQL` 归一化为 `M`，`A/O/ORA/ORACLE` 归一化为 `ORA`。`ConnectionConfig` 的 `$mode` 是 `string`，是因为这些常量在 PHP 7.2 中本质上就是字符串；构造函数会再次校验和归一化。

### 最小连接示例

```php
<?php

use GaussDb\Compat\CompatibilityMode;
use GaussDb\Compat\ConnectionConfig;
use GaussDb\Compat\Driver;

require __DIR__ . '/vendor/autoload.php';

$user = getenv('GAUSS_USER');
$password = getenv('GAUSS_PASSWORD');
if (!is_string($user) || $user === '' || !is_string($password) || $password === '') {
    throw new RuntimeException('GAUSS_USER and GAUSS_PASSWORD are required');
}

$db = Driver::connect(new ConnectionConfig(
    'gaussdb.example.com',
    5432,
    'app_m',
    $user,
    $password,
    CompatibilityMode::M
));

$row = $db->execute('SELECT id, name FROM users WHERE id = ?', [1])->fetch();
```

Oracle 兼容库将第六个参数改为 `CompatibilityMode::ORACLE`。不使用 Composer 时，将 `require` 改为本仓库 `src/autoload.php` 的实际路径。

### Windows x64 快速安装

在管理员 PowerShell 中执行，将以下路径替换为客户的实际路径：

```powershell
Set-ExecutionPolicy -Scope Process Bypass

$Repo = 'C:\GaussDBTest\GaussDB-PHP-Driver'
$PhpArchive = 'C:\packages\php-7.2.34-nts-Win32-VC15-x64.zip'
$PhpHome = 'C:\GaussDBTest\php-7.2.34-x64'
$OdbcInstaller = 'C:\packages\gaussdb-odbc-x64\gsqlodbc.exe'

& "$Repo\packaging\windows-odbc\setup-php.ps1" `
  -Archive $PhpArchive `
  -PhpHome $PhpHome `
  -StatusFile 'C:\GaussDBTest\php-setup-x64.txt' `
  -ExpectedArchitecture x64

& "$Repo\packaging\windows-odbc\install-gaussdb-odbc.ps1" `
  -InstallerPath $OdbcInstaller

& "$PhpHome\php.exe" -r "var_export(PDO::getAvailableDrivers());"
Get-OdbcDriver | Where-Object Name -In @('GaussDB Unicode', 'GaussDB ANSI', 'gsqlodbc')
```

`setup-php.ps1` 会重建 `$PhpHome` 目录、生成 `php.ini` 并启用 `PDO_ODBC`。PHP x86 需要 x86 PHP 压缩包、`-ExpectedArchitecture x86` 和 x86 ODBC 安装器；同机同时安装 x64/x86 时使用 `install-side-by-side.ps1`。连接验收命令见 [Windows ODBC 安装](packaging/windows-odbc/README.md)。

完整安装、二进制类型和卸载说明见：

- [客户使用手册](CUSTOMER_USAGE.md)
- [测试指南](TESTING_GUIDE.md)（面向测试人员：项目讲解、怎么跑、怎么判读、已知边界）
- [测试驱动制品](TEST_ASSETS.md)（公开下载、SHA-256 和四平台对应关系）
- [Linux 普通服务器安装](LINUX_BUILD_INSTALL.md)
- [Windows ODBC 安装](packaging/windows-odbc/README.md)
- [M/O 兼容层验收测试](tests/README.md)

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
- 已实测 PHP 7.2.34 和 PHP 8.3；代码最低语法版本为 PHP 7.2.34。
- PHP 7.2 已停止官方安全维护；兼容能力不等于建议新生产系统继续使用旧 PHP，存量系统应配合隔离、补丁和升级计划。
- ODBC 不支持的用户自定义类型、部分存储过程 OUT 参数等能力，本兼容层不能凭空补齐。
- `TIMESTAMP` 微秒、M `AUTO_INCREMENT/lastInsertId()`、M `TEXT` 65,535 字节限制属于服务端或 ODBC 语义边界，不能伪造为已兼容。
- 仓库不重新分发 GaussDB 厂商二进制，客户必须从有授权的驱动包或官方渠道获取。

详细边界见 [已知限制](KNOWN_LIMITATIONS.md)。

## 官方依据

- [GaussDB 应用程序开发指南：驱动与兼容模式矩阵](https://support.huaweicloud.com/intl/en-us/centralized-devg-v10-gaussdb/gaussdb-42-0076.html)
- [GaussDB ODBC 高性能绑定：UTF-8 ConnSettings](https://support.huaweicloud.com/intl/en-us/distributed-devg-v10-gaussdb/gaussdb-12-0145.html)
- [PHP PDO_ODBC](https://www.php.net/manual/en/ref.pdo-odbc.php)
