# GaussDB 507 M Mode PHP Driver

为 **GaussDB Kernel 507.0.0 M 模式**提供 PHP PDO 接入能力。本项目不修改 GaussDB 内核，也不依赖 MySQL wire protocol。

当前阶段已经验证：PHP 官方 `pdo_pgsql` 在加载 GaussDB 507 配套 `libpq.so.5.5` 后，可以通过 5432 端口连接本地 M 模式数据库并执行 CRUD、原生预编译语句和事务。

## 交付目标

本仓库管理一套 PDO 行为规范、公共测试和三个平台交付物，不拆成三个长期分支：

| 平台 | 接入实现 | 最终计划交付物 | 当前状态 |
|---|---|---|---|
| Linux ARM64 | PHP PDO 扩展 + GaussDB 507 ARM64 libpq | `pdo_gaussdb.so`、客户端库包、安装脚本/镜像 | Phase 1：`pdo_pgsql.so + GaussDB libpq` 原型；独立扩展未实现 |
| Linux x86_64 | 同一扩展源码 + GaussDB 507 x86_64 libpq | `pdo_gaussdb.so`、客户端库包、安装脚本/镜像 | Phase 1：同一 PDO_PGSQL 原型；独立扩展未实现 |
| Windows x64/x86 | GaussDB 507 ODBC + PHP PDO_ODBC | ODBC 安装与 DSN 脚本、PHP 配置、公共契约测试 | Phase 1 已完成 ODBC 双位数基线；不提供 `pdo_gaussdb.dll` |

Windows 第一阶段不重复实现 `pdo_gaussdb.dll`。先复用随 507 驱动包提供的官方 Windows ODBC；只有 PDO_ODBC 出现无法绕过的能力或性能问题时，才评估原生 DLL。

## 为什么使用一个仓库

ARM64 和 x86_64 的扩展逻辑相同，区别只是编译架构和配套客户端库。Windows 虽然使用 ODBC，但必须遵守相同的 PDO 行为契约，例如参数绑定、事务、类型回显和错误处理。

如果把平台拆成长期分支，修复 BOOL、DECIMAL 或事务语义时需要在多条分支反复同步，最终很容易产生行为漂移。本项目采用：

- `main`：始终保存跨平台公共源码、文档和测试。
- `feature/<feature>`：短期功能分支，完成后合并回 `main`。
- `release/<version>`：发布稳定期才创建，用于维护已经发布的版本。
- 平台差异：通过目录、构建参数和 CI matrix 管理，不通过长期分支管理。

## 仓库结构

```text
.
├── src/                         # PDO 扩展公共源码（下一阶段实现）
├── packaging/
│   ├── linux-arm64/             # ARM64 客户端库、构建与安装定义
│   ├── linux-x86_64/            # x86_64 客户端库、构建与安装定义
│   └── windows-odbc/            # Windows ODBC/PDO_ODBC 安装与配置
├── examples/                    # PHP 使用示例
├── tests/                       # 跨平台 PDO 契约及本地冒烟测试
├── docker/                      # 本地 GaussDB 507 测试环境
├── CUSTOMER_USAGE.md            # 客户部署、接入与故障排查手册
├── GAUSSDB_LOCAL_DRIVER_INVENTORY.md
└── GAUSSDB_M_PHP_DRIVER_RESEARCH.md
```

GaussDB 驱动二进制不直接提交到 Git。构建时从已授权的本地驱动包或制品库获取，并校验版本、架构和 SHA-256。

## 已验证环境

- 服务端：`GaussDB Kernel 507.0.0 build d791c80a`
- 数据库：`gdbdrv_m_test`，`datcompatibility = M`
- 服务端端口：5432
- Linux PHP：8.3.33 ARM64 / 8.3 x86_64
- Windows PHP：8.3.8 NTS x64/x86，启用官方 `PDO_ODBC`
- PHP 扩展：Linux 使用官方 `pdo_pgsql`；Windows 使用官方 `PDO_ODBC`
- 客户端：GaussDB `libpq.so.5.5`

当前容器中的 libpq 与下载目录 507 Distributed/Euler2.10/ARM64 驱动包完全一致：

```text
SHA-256 7960663fe291eb290204a4a2c0caa956b71948e4d30ea3f4442ea46b0eb1cfb7
```

x86_64 客户端也已完成提取、校验、amd64 镜像构建，并通过 Docker 模拟连接本地 GaussDB：

```text
SHA-256 6d7876294a11f5b66676a51556ab3f94d8be58eaa57519b21c2d1ad193eee743
```

Windows 507 ODBC 安装器已经完成自动提取与校验：

```text
X64 5dd95b7c1cc3f28a9494d8e4acaa678496f5ec82d3730a2d5df6cd970c6af87e
X86 0fc17a01570fbdcc34bd1d788e1cf36e16bd386723f1d9dfb637d93992e1a007
```

Windows x64 和 x86 均已在 UTM Windows 11（AMD64）中完成真实验证。PHP 8.3.8 NTS AMD64/i586 通过 `GaussDB Unicode` 无 DSN 连接串连接本地 M 模式实例。X86 的扩展契约原始结果已脱敏并跟踪在 [`tests/baselines/windows-x86.json`](tests/baselines/windows-x86.json)；side-by-side 安装脚本会隔离官方 X86/X64 安装器共用的目录和注册表项。

## 当前验证结果

已经通过：

- SHA256 用户认证（使用 GaussDB libpq）。
- PDO 连接、CRUD、native prepared statement。
- Linux PDO_PGSQL 的中文和 emoji 原样回显（Windows ODBC 尚有编码问题）。
- BIGINT、VARCHAR、DECIMAL、BOOLEAN、VARBINARY、TIMESTAMP 基础读写。
- 事务回滚和基础列元数据。
- Windows x64/x86 PDO_ODBC 连接、建表、参数化写入、查询和事务回滚。

需要在适配层解决：

- `PDO::PARAM_BOOL` 的 `t/f` 与 M 模式 `1/0` 差异。
- 包含零字节的 VARBINARY 参数被截断。
- TIMESTAMP 微秒精度。
- M 私有类型 OID 映射。
- `DATABASE()` 与 MySQL 语义不同。
- 本地部署不支持 `SELECT @@sql_mode`。
- Windows ODBC 中文和 emoji 回显存在编码错乱，需继续确认驱动客户端编码配置并纳入适配层测试。

集中限制、影响和临时规避见 [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md)。

## 本地冒烟测试

`docker/compose.yml` 的镜像、平台、端口、容器名、资源限制和外部卷均可用 `GAUSSDB_*` 环境变量覆盖；默认继续复用 `gaussdb507-data`。首次启动前先检查镜像和数据卷：

```bash
make docker-preflight
```

当前已验证的 GaussDB 507 镜像包含 `/usr/sbin/chroot`；entrypoint 用它只做 UID/GID 1000 降权（根目录仍为 `/`）。预检会提前检查该依赖，缺失时给出明确错误，不会进入数据库启动阶段。

已有数据卷只做检查，不会覆盖。对于确认为空的新卷，可设置 `GAUSSDB_ALLOW_EMPTY_DATA=1` 通过预检，并把管理员密码文件只读挂载到容器、设置 `GAUSSDB_INIT_PASSWORD_FILE`；entrypoint 会以 root 修正空卷属主，随后降权到 UID/GID 1000 执行 `gs_initdb`。初始化 SQL 可只读挂载到 `/docker-entrypoint-initdb.d/*.sql`，用于创建 `gdbdrv_m_test`、`gauss_php_test` 和 schema 权限。密码和授权安装介质不进入仓库。

仓库提供 [`docker/init-test-user.sql.example`](docker/init-test-user.sql.example)。它不会被自动执行；复制到安全目录、替换密码占位符后，再以 `.sql` 文件挂载。

示例覆盖文件：

```yaml
services:
  database:
    environment:
      GAUSSDB_INIT_PASSWORD_FILE: /run/secrets/gaussdb-admin-password
    volumes:
      - /secure/path/admin-password:/run/secrets/gaussdb-admin-password:ro
      - /secure/path/init.sql:/docker-entrypoint-initdb.d/10-test-user.sql:ro
```

已有测试文件：[tests/php_pdo_pgsql_smoke.php](tests/php_pdo_pgsql_smoke.php)。测试密码只通过环境变量传入：

```bash
GAUSS_HOST=127.0.0.1 \
GAUSS_PORT=5432 \
GAUSS_DATABASE=gdbdrv_m_test \
GAUSS_USER=gauss_php_test \
GAUSS_PASSWORD='your-password' \
php tests/php_pdo_pgsql_smoke.php
```

完整的三平台契约测试、有效性门槛和实测基线见 [tests/README.md](tests/README.md)。公共套件覆盖 CRUD、重复预处理、SQL 注入值、BIGINT/VARCHAR 边界、批量和大文本、事务/savepoint/脏读、异常恢复、连接生命周期、元数据和兼容类型；另提供错误认证和只读权限专项测试。

同一 PHP 驱动在 ORA、MYSQL、PG、M（以及内核支持时的 C）模式下的矩阵入口为 `make test-modes`。模式测试使用临时数据库，不要求修改 GaussDB 内核。

注意：系统自带 PostgreSQL libpq 可能报以下认证错误：

```text
none of the server's SASL authentication mechanisms are supported
```

这表示 PHP 实际加载的不是 GaussDB 配套 libpq。可以使用 `ldd pdo_pgsql.so` 检查动态链接结果。

## PDO 使用方式与最终目标

> **当前可用的 Linux DSN 只有 `pgsql:`。下面的 `gaussdb:` 是 Phase 2 设计目标，当前代码不能使用。**

Linux 最终目标 DSN：

```php
<?php

$pdo = new PDO(
    'gaussdb:host=127.0.0.1;port=5432;dbname=gdbdrv_m_test',
    getenv('GAUSS_USER'),
    getenv('GAUSS_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$statement = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$statement->execute([1]);
$row = $statement->fetch(PDO::FETCH_ASSOC);
```

在独立 `gaussdb:` DSN 完成前，原型必须使用 `pgsql:` DSN 加载 GaussDB libpq。

Windows 第一阶段目标：

```php
<?php

$pdo = new PDO(
    'odbc:GaussDB507',
    getenv('GAUSS_USER'),
    getenv('GAUSS_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
```

也支持不创建系统 DSN 的部署方式：

```php
$pdo = new PDO(
    'odbc:Driver={GaussDB Unicode};Servername=127.0.0.1;Port=5432;Database=gdbdrv_m_test;SSLmode=prefer',
    getenv('GAUSS_USER'),
    getenv('GAUSS_PASSWORD')
);
```

## 开发阶段

当前处于可运行的跨平台原型验证阶段：连接方案、构建链、三种 CPU/系统组合和公共契约已落地；下一阶段才是独立 `gaussdb:` DSN 的薄适配层。

1. 固化 Linux ARM64 可复现构建环境，使用下载目录中的 507 Distributed libpq 包。
2. 建立 PDO 跨平台契约测试，覆盖参数、类型、事务、错误和连接生命周期。
3. 实现 Linux 薄适配层，优先修复 BOOL、VARBINARY、TIMESTAMP 和私有 OID。
4. 使用同一源码构建 Linux x86_64 产物。
5. 在 Windows VM 安装 507 X64 ODBC，运行同一契约测试并产出安装脚本。
6. 建立版本矩阵、制品校验、发布包和已知限制文档。

Linux ARM64、Linux x86_64 和 Windows ODBC 制品提取/构建入口已经加入 `Makefile`。具体命令见各平台的 `packaging` 文档。

## 项目边界

- 不修改 GaussDB 内核。
- 不嵌入 Python 或 psycopg。
- 不实现 MySQL wire protocol。
- 第一阶段不提供 mysqli ABI，也不承诺 WordPress 等 mysqli-only 应用零修改运行。
- 不在仓库中重新分发未经授权的 GaussDB 二进制包。

## 相关文档

- [客户使用手册](CUSTOMER_USAGE.md)
- [本地驱动盘点](GAUSSDB_LOCAL_DRIVER_INVENTORY.md)
- [PHP 驱动调研与本地验证](GAUSSDB_M_PHP_DRIVER_RESEARCH.md)
- [已知限制](KNOWN_LIMITATIONS.md)
- [可追溯测试基线](tests/baselines/README.md)
