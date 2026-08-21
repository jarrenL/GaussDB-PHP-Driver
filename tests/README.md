# 三平台驱动测试

`php_pdo_contract.php` 是 Linux ARM64、Linux x86_64、Windows x64 和 Windows x86 共用的 PDO 契约测试。它不修改服务端配置，只创建一个带随机后缀的临时表，并在 `finally` 中清理；计时类型同时兼容 32 位 PHP。

## 有效性判定

进程退出码为 `0` 才表示驱动的必需能力有效。JSON 中的状态含义：

- `pass`：通过。
- `fail`：必需能力失败，测试进程返回 `1`。
- `compatibility-fail`：驱动可连接和执行核心业务，但存在需要适配的类型或编码差异；单独出现时进程仍返回 `0`。

覆盖场景：

| 分类 | 场景 | 门槛 |
|---|---|---|
| 环境 | PDO 扩展加载、实际 PDO driver、PHP/OS/CPU 架构、客户端和服务端版本 | 必需 |
| 连接 | 正常认证、第二连接读取已提交数据 | 必需 |
| DDL/CRUD | 建表、插入、查询、更新、删除、受影响行数、NULL/空串、DECIMAL、BIGINT 边界、VARCHAR 最大长度 | 必需 |
| 预处理/安全 | `?` 参数绑定、语句重复执行、含 SQL 注入内容的字符串 | 必需 |
| 事务 | commit、rollback、savepoint、第二连接不可脏读 | 必需 |
| 异常 | 主键冲突、NOT NULL、五位 SQLSTATE、异常后连接恢复 | 必需 |
| 元数据 | `columnCount()`、`getColumnMeta()`、BOOLEAN OID 5545、VARBINARY OID 9881 | 必需 |
| 字符集 | 中文和 emoji 原样回显 | 兼容性 |
| 类型 | `PDO::PARAM_BOOL`、含 NUL/0xFF 二进制、TIMESTAMP 微秒 | 兼容性 |
| 数据量 | 500 行重复绑定、分页排序、TEXT 65,535/65,536 字节边界 | 批量必需，大文本兼容性 |
| 连接生命周期 | Fetch 模式、`closeCursor()` 后复用、持久连接及事务清理 | 基础必需，持久连接兼容性 |
| identity/LOB | 无生成列及 AUTO_INCREMENT 的 `lastInsertId()`、BYTEA/BLOB/CLOB、VARBINARY `PARAM_LOB` 对照 | 兼容性/基线 |
| 扩展语义 | 命名参数、DDL 事务回滚 | 兼容性 |

冒烟文件仍保留，用于快速诊断；正式判断以契约测试为准。

## Linux ARM64

```bash
GAUSS_PASSWORD='your-password' \
  ./tests/run-linux-driver-contract.sh linux-arm64
```

报告写入 `build/test-results/linux-arm64.json`。

## Linux x86_64

```bash
GAUSS_PASSWORD='your-password' \
  ./tests/run-linux-driver-contract.sh linux-x86_64
```

报告写入 `build/test-results/linux-x86_64.json`。脚本显式指定 `linux/amd64`，可在 ARM Mac 上通过 Docker 模拟执行。

可通过环境变量覆盖 `GAUSS_HOST`、`GAUSS_PORT`、`GAUSS_DATABASE`、`GAUSS_USER`、`GAUSS_DOCKER_NETWORK`，也可把第二个参数作为镜像名。

## Windows x64 / x86

先把 `php_pdo_contract.php` 和 `run-windows-driver-contract.ps1` 放到 Windows，然后执行：

```powershell
$env:GAUSS_ODBC_CONNECTION_STRING = 'Driver={GaussDB Unicode};Servername=192.168.64.1;Port=15432;Database=gdbdrv_m_test;SSLmode=prefer'
$env:GAUSS_PASSWORD = 'your-password'
./run-windows-driver-contract.ps1
```

默认报告为 `C:\GaussDBTest\windows-x64.json`。X86 可直接运行 `run-windows-x86-contract.ps1`，其默认报告为 `C:\GaussDBTest\windows-x86.json`；PHP、PDO_ODBC、ODBC 注册表视图和 DLL 位数必须一致。side-by-side 安装方法见 `packaging/windows-odbc/README.md`。

Windows 执行器优先读取环境变量。文件方式会检查 ACL，拒绝继承权限或宽泛主体访问；无论测试成功还是失败，脚本都会在 `finally` 中删除默认密码/连接串文件。

## 2026-08-10 本地实测基线

| 驱动交付物 | 总通过 | 必选失败 | 兼容性失败 |
|---|---:|---:|---:|
| Linux ARM64 + GaussDB libpq + PDO_PGSQL | 27 | 0 | 7 |
| Linux x86_64 + GaussDB libpq + PDO_PGSQL | 27 | 0 | 7 |
| Windows x64 + GaussDB ODBC + PDO_ODBC | 25 | 0 | 9 |
| Windows x86 + GaussDB ODBC + PDO_ODBC | 25 | 0 | 9 |

完整原始结果保存在 `tests/baselines/`。Linux 两种架构的扩展兼容性失败还包括 `lastInsertId()`、BYTEA 和 CLOB；Windows 还包括 ODBC 不支持 `lastInsertId()`、BLOB/VARBINARY 表示差异。集中解释见 `KNOWN_LIMITATIONS.md`。

大 TEXT 边界由独立测试确认：65,535 字节成功，65,536 字节开始返回 SQLSTATE `22001`。复验入口：

```bash
GAUSS_PASSWORD='your-password' make test-text-threshold
```

## 认证失败测试

为避免在启用了登录失败锁定策略的环境里自动反复试错，公共套件不会主动使用错误密码。`php_pdo_auth_negative.php` 每次只尝试一次，并验证异常信息没有泄露密码：

```bash
GAUSS_TEST_DRIVER=pgsql \
GAUSS_BAD_PASSWORD='one-controlled-wrong-password' \
make test-auth
```

发布验收时确认：

1. 连接被拒绝；
2. PHP 抛出 `PDOException`；
3. 日志不输出密码；
4. 随后使用正确密码仍能连接。

## 只读权限测试

准备一个只有连接和查询权限、没有建表权限的专用账号后运行：

```bash
GAUSS_TEST_DRIVER=pgsql \
GAUSS_READONLY_USER='readonly_user' \
GAUSS_READONLY_PASSWORD='password' \
make test-readonly
```

测试要求 `SELECT 1` 成功且 `CREATE TABLE` 被拒绝。它不会自动创建或授权用户，避免测试脚本要求管理员权限。

## SSL 能力探测

`GAUSS_PASSWORD='your-password' make test-ssl` 强制使用 `sslmode=require`。成功 TLS 返回 0；服务端不支持 SSL 时探测脚本返回 3，该非零状态会继续传递，因此 Make 目标和启用 `require_ssl` 的 CI 都会失败。这是 require-SSL 验收门禁的预期语义，不代表测试通过，也不会把 `sslmode=prefer` 误判成 SSL 成功。当前本地实例 `ssl=off`，实测错误为 `server does not support SSL, but SSL was required`。

## 明确不纳入当前自动化的场景

按当前项目范围，不做断网、服务端重启、链路抖动和自动重连故障注入。完整 SSL 证书链（单向/双向）、死锁/锁等待、长时间压力与内存泄漏适合后续在独立集成或性能环境执行，避免日常驱动测试卡死或改变服务端状态。

## 数据库兼容模式矩阵

`modes/php_mode_contract.php` 使用同一套 GaussDB libpq + PDO_PGSQL 检查不同数据库兼容模式。公共部分覆盖模式识别、DDL、CRUD、参数绑定、注入值、事务、SQLSTATE 和异常后连接恢复；另外检查：

- ORA：`NVL`、Oracle 空字符串语义。
- MYSQL：`IFNULL`、`LIMIT`。
- PG：`::` 类型转换、`generate_series`。
- M：`IFNULL`、`DATABASE()` 可调用性。
- C：先探测当前内核是否支持创建，再执行公共契约。

本地一键运行：

```bash
GAUSS_PASSWORD='your-password' make test-modes
```

脚本创建独立临时数据库，结束或异常退出时都会尝试清理。报告写入 `build/test-results/modes/`，汇总文件为 `summary.json`。当前本地 507 使用 `ORA`、`MYSQL`、`PG`、`M` 作为实际 `DBCOMPATIBILITY` 值；不要直接根据新版文档把 A/B 名称传给旧内核。

本地 507 只允许存在一个 M 数据库，因此脚本默认复用已有的 `gdbdrv_m_test`，仅创建并清理自己的测试表；可以通过 `GAUSS_M_DATABASE` 改名。2026-08-07 实测结果：

| 模式 | 公共/专用用例 | 失败 | 结论 |
|---|---:|---:|---|
| ORA（A） | 7 通过 | 0 | PDO_PGSQL 可用 |
| MYSQL（B） | 7 通过 | 0 | PDO_PGSQL 可用 |
| PG | 7 通过 | 0 | PDO_PGSQL 可用 |
| M | 7 通过 | 0 | PDO_PGSQL 可用 |
| C | 未执行 | - | 本地内核返回 `Compatibility args C is invalid` |

这里的“可用”表示同一 GaussDB libpq 驱动能够完成连接、模式识别、DDL、CRUD、参数化查询、事务和错误恢复，并通过对应模式的基础语义检查；不表示 Oracle OCI、MySQL wire protocol 或 Teradata 原生驱动兼容。
