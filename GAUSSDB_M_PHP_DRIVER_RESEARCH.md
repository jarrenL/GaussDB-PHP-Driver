# GaussDB M 模式 PHP 驱动兼容性调研

日期：2026-08-05

## 结论（按当前项目约束修订）

项目固定使用本地 `GaussDB Kernel 507.0.0`，不能修改 GaussDB 内核，也不以升级内核为前提。因此此前提到的 V2.0-8.100+ M 协议端口路线不属于本项目实施范围，只保留为背景信息。

`PDO_GAUSSDB` 不是已有产品或官方驱动；它只是此前对“可能自研的 PDO 扩展”的临时命名。经本地验证，现在更准确的方案定义是：

1. 使用 PHP 官方 `pdo_pgsql` 代码作为 PDO 接口实现。
2. 必须链接/加载 GaussDB 507 随库提供的 `libpq`，不能直接使用操作系统 PostgreSQL libpq。
3. 在此基础上增加一层很薄的 GaussDB M 适配，处理布尔值、二进制、时间精度、M 类型 OID、元数据和少数 PDO 语义差异。
4. 最终可以注册为独立的 `gaussdb:` DSN，也可以先以补丁版 `pdo_pgsql` 形式验证；是否命名为 `pdo_gaussdb` 是发布和维护决策，不是技术前提。

建议不要基于 psycopg2/3 再套一层 PHP 驱动。psycopg 是 Python DB-API 实现，PHP 扩展若嵌入 Python，会同时引入 Python 运行时、GIL、对象转换和两套连接生命周期。此次实测也证明无需经过 psycopg：PHP PDO 可以直接通过 GaussDB libpq 连接。

若目标是兼容使用 PDO 的 PHP 应用，这条路线可行。若目标是让现有 `mysqli`/WordPress 应用一行不改，507 又没有 MySQL wire 端口，则 PDO 扩展无法提供 mysqli ABI；需要另行实现 MySQL 协议代理，属于明显更大的项目。

## 本地实例核验

工作区 Docker 实例：

- 内核：`GaussDB Kernel 507.0.0`
- M 数据库：`gdbdrv_m_test`，`datcompatibility = M`
- 监听/映射：只有 PostgreSQL/GaussDB 端口 `5432`
- compose 未配置独立 M 兼容端口

本地 507 不能因为安装 `php-mysql` 就自然获得 MySQL 协议兼容。当前可用的客户端基础是 GaussDB 自带的 PostgreSQL-compatible wire protocol 和 `libpq.so.5.5`。

## 2026-08-05 本地 PHP 实测结果

测试环境：PHP `8.3.33`、官方 `pdo_pgsql/pgsql` 扩展、本地 `gdbdrv_m_test`、GaussDB 端口 5432。

### 认证结论

- 使用 Debian/PostgreSQL `libpq 15.18`：连接失败，错误为 `none of the server's SASL authentication mechanisms are supported`。
- 保持同一个 PHP `pdo_pgsql.so`，仅让动态加载器使用 GaussDB 随库提供的 `libpq.so.5.5` 及其依赖：连接成功。
- 因此第一项产品要求是正确打包 GaussDB client library；不是重新实现连接协议。

### 已通过

- PDO 建连和错误异常。
- M 模式建表与 CRUD。
- PDO native prepared statement 和位置参数。
- BIGINT、VARCHAR、DECIMAL、BOOLEAN、VARBINARY、TIMESTAMP 基础读写。
- 中文与 emoji。
- 事务 begin/rollback。
- `getColumnMeta()` 基础元数据。

### 已发现的适配项

- `PDO::PARAM_BOOL`：pdo_pgsql 按 PG 语义发送 `t/f`，M 模式报整数输入非法；改发 `1/0` 后成功。
- VARBINARY：输入 `binary\\x00data` 后回读只得到 `binary`，零字节及之后内容丢失，需要改用正确的二进制参数格式或 GaussDB libpq binary format。
- TIMESTAMP：输入微秒后回读只保留到秒，需要核对 M DDL 精度及参数编码。
- M 私有 OID：BOOLEAN OID `5545`、VARBINARY OID `9881` 未被 pdo_pgsql 映射成 native type 名称。
- `DATABASE()`：本地返回 `public`，更接近 schema 语义，不能直接当成 MySQL 当前数据库名。
- `SELECT @@sql_mode`：本地返回 `0A000 Feature not supported`。
- DECIMAL 正确以字符串返回，应该保留，避免 PHP float 丢失精度。

可重复执行的测试程序见 `tests/php_pdo_pgsql_smoke.php`。

本机下载目录中已经找到与当前内核精确匹配的 507 ARM64/x86_64 libpq、Linux ODBC 和 Windows x64/x86 ODBC 安装器。完整盘点见 `GAUSSDB_LOCAL_DRIVER_INVENTORY.md`。其中 507 Distributed ARM64 libpq 与当前运行容器中的 `libpq.so.5.5` 哈希完全一致，可以直接作为 Linux PHP 驱动构建和运行时依赖；Windows 则可先走官方 507 ODBC + PHP PDO_ODBC。

## 可选方案对比

| 方案 | 面向 API | 复用组件 | 适用范围 | 工作量/风险 | 建议 |
|---|---|---|---|---|---|
| 8.100+ 的 M 端口 + mysqlnd | mysqli、PDO_MySQL | PHP mysqlnd | MySQL 生态、传统 PHP 应用 | 主要是服务端协议缺口和兼容测试 | **首选** |
| 新建 PDO_GAUSSDB | PDO | GaussDB libpq | Laravel/Symfony/自研 PDO 应用 | 中等；类型、错误码、lastInsertId 需适配 | **507 首选** |
| 直接 PDO_PGSQL | PDO | 系统/GaussDB libpq | PoC、可接受 `pgsql:` DSN 的应用 | 最低；品牌/API 语义和 M 类型可能有差异 | **先做基线验证** |
| PDO_ODBC | PDO | GaussDB ODBC + unixODBC | 企业通用接入、验证 | 部署链更长；元数据、LOB、预编译差异更多 | 备选/兜底 |
| PHP 扩展嵌入 psycopg2/3 | 自定义 | Python + psycopg | 几乎没有合理场景 | 高；GIL、运行时、内存与异常跨语言 | 不建议 |
| MySQL 协议代理 | mysqli、PDO_MySQL | 自研网关 + libpq/ODBC | 507 且应用零改造 | 很高；握手到 prepared statement 均需实现 | 仅战略需求 |

## 为什么 OceanBase 能直接支持 PHP

OceanBase MySQL 模式的关键不是“做了 PHP 专用驱动”，而是 **OBServer/ODP 在协议层实现 MySQL 协议**。因此官方 PHP 示例直接使用 MySQL EXT、MySQLi 和 PDO；其他 MySQL 协议驱动也可复用。OceanBase 自有 Connector/J 则是在 MySQL/MariaDB JDBC 基础上增加 OceanBase 的两种兼容模式识别和特性。

对 GaussDB 的启示：

- 最高杠杆点是服务端 M 端口对通用 MySQL wire protocol 的完整度；一旦协议足够兼容，PHP、Go、Node.js、Ruby 等生态同时受益。
- 语言专用驱动应只用于 GaussDB 特有能力，而不应重复实现通用 MySQL CRUD。
- 若协议尚不完整，建立“驱动兼容矩阵 + 抓包差异 + 服务端修复清单”比 fork 每个语言驱动更划算。

## 路线 A：8.100+ 直接适配 mysqlnd

### 第一轮必须验证

1. 握手和认证
   - 非 SSL、单向 SSL、双向 SSL。
   - 服务端返回的 authentication plugin 名称和 auth switch。
   - RSA 密码加密流程；证书校验及主机名校验。
   - `utf8mb4` 默认字符集和 collation 编号是否为 mysqlnd 所识别。
2. 两套命令路径
   - Text protocol：`COM_QUERY`。
   - Binary protocol：`COM_STMT_PREPARE/EXECUTE/CLOSE`。
   - `COM_PING`、`COM_INIT_DB`、连接 reset、multi statements。
3. 类型映射
   - signed/unsigned 整数及 BIGINT 溢出策略。
   - DECIMAL 保持字符串精度。
   - DATE/DATETIME/TIMESTAMP、零日期、时区。
   - JSON、BIT、BINARY/VARBINARY/BLOB、TEXT、ENUM/SET（若支持）。
   - NULL、空字符串、布尔值。
4. PDO/mysqli 行为
   - `rowCount/affected_rows`、`insert_id/lastInsertId`。
   - buffered/unbuffered query。
   - native/emulated prepared statements。
   - transaction、savepoint、autocommit、persistent connection reset。
   - SQLSTATE、vendor code 和错误文本。
5. 框架冒烟
   - Laravel PDO MySQL、Symfony Doctrine DBAL。
   - WordPress（mysqli）作为协议兼容压力测试。

### 建议产物

- 一个无需 fork PHP 的 Composer 测试项目，同时跑 `mysqli`、`PDO_MySQL`。
- Docker 测试矩阵：PHP 7.4、8.1、8.2、8.3、8.4；至少覆盖两代 mysqlnd。
- 对每项失败保存服务端日志和 Wireshark MySQL dissector 抓包，问题归到服务端协议兼容，而不是立刻 fork mysqlnd。

只有在确认是 mysqlnd 的 GaussDB 特有认证插件、且无法通过服务端兼容修复时，才考虑 fork/扩展 mysqlnd。否则维护 PHP ABI 和多个 PHP 分支的成本不合算。

## 路线 B：507 的 PDO_GAUSSDB 设计

### 建议架构

```text
PHP application / ORM
        |
      PDO API
        |
   pdo_gaussdb.so
   - DSN 与连接属性
   - PDO 参数绑定
   - 类型/OID 映射
   - SQLSTATE/错误映射
   - lastInsertId 等语义适配
        |
  GaussDB 配套 libpq
        |
 PostgreSQL-compatible wire, port 5432
        |
 GaussDB 507 M database
```

建议从对应 PHP 版本的 `ext/pdo_pgsql` 开始，最小化修改：

- 注册独立 DSN：`gaussdb:host=...;port=5432;dbname=...`。
- 优先动态链接数据库发行包配套的 `libpq`，避免客户端库与 507 内核特性错配。
- 保留 PDO 通用接口；第一版暂不增加大量 GaussDB 私有 API。
- 默认使用 server-side prepare；若 M 模式 PBE 存在类型推断缺口，提供明确的 `ATTR_EMULATE_PREPARES` 兼容开关并记录安全边界。
- 单独维护 M 类型 OID/名称映射，尤其 unsigned、JSON、时间、LOB。
- 用 SQLSTATE 驱动 PDO 异常，同时保留 GaussDB 原始错误码供诊断。

### MVP 边界

MVP 支持：连接/SSL、CRUD、事务、位置和命名参数、结果集、metadata、LOB 基础能力、错误码、持久连接安全 reset。

MVP 不承诺：mysqli API、MySQL wire protocol、WordPress 零改造、全密态（官方当前列出的驱动仅 gsql/JDBC/Go）、复制/CDC、异步 API。

### ODBC 的定位

先用 `PDO_ODBC + GaussDB ODBC` 做 1～2 天的可行性基线很有价值，尤其可快速检查类型和 prepared statement。但产品化仍推荐 libpq-backed PDO：ODBC 方案要同时分发 GaussDB ODBC、unixODBC、DSN 配置和 PHP PDO_ODBC，诊断链与环境差异更大；PHP Unified ODBC 对存储过程输出参数也有限制。

## 分阶段计划

### Phase 0：需求定界（0.5～1 天）

收集目标 PHP 版本、操作系统/CPU、框架，以及“兼容 PHP”究竟指 PDO、mysqli，还是 WordPress/Discuz 等零改造应用。获取 8.100+ 可测试实例和 M 端口配置。

### Phase 1：协议优先 PoC（3～5 天）

- 8.100+ 上跑 mysqli/PDO_MySQL 测试矩阵。
- 507 上跑 PDO_PGSQL 与 PDO_ODBC 基线。
- 输出失败分类：握手、认证、命令、类型、元数据、SQL 行为。

决策门：若 mysqlnd 主链路通过率高，停止开发新驱动，转为服务端协议补齐；若项目必须留在 507，进入 PDO_GAUSSDB。

### Phase 2：PDO_GAUSSDB MVP（约 3～6 周）

- 从 pdo_pgsql 建立可独立编译的 PECL 风格扩展。
- 完成 DSN、连接、statement、绑定、fetch、事务、错误、类型映射。
- 在 PHP 8.1～8.4 和 x86_64/aarch64 上 CI。
- 与 psycopg/ODBC 执行同一份 SQL 契约测试，比较结果与错误。

### Phase 3：框架与发布（约 2～4 周）

- Laravel/Doctrine 集成适配（必要时做 DBAL platform，而非污染底层驱动）。
- RPM/DEB、容器镜像、版本兼容表、SSL 配置和诊断工具。
- 性能测试：短连接、持久连接、prepare 重用、大结果集和并发。

## Go / No-Go 标准

优先判定升级 + mysqlnd 为 Go，若满足：

- mysqli 与 PDO_MySQL 的核心协议测试通过率至少 95%；
- 失败项可通过服务端协议兼容修复，不需长期 fork PHP；
- 认证、TLS、prepared statement、事务、关键类型无阻断问题。

判定 PDO_GAUSSDB 为 Go，若：

- 必须支持 507；
- 目标应用主要使用 PDO/ORM并允许改 DSN/数据库 platform；
- 不要求 mysqli 或 MySQL 客户端生态零改造。

判定“自研 MySQL 协议代理”为 No-Go，除非明确有大量 507 存量应用、完全不能升级且应用完全不能改造；这种需求应作为独立产品立项。

## 资料

- [GaussDB M-compatible development specifications：M port 与 PyMySQL](https://support.huaweicloud.com/intl/en-us/centralized-m-comp-devg-v8-gaussdb/gaussdb-81-0007.html)
- [GaussDB：Changing the M Compatibility Port（含 V2.0-8.100+ 限制）](https://support.huaweicloud.com/intl/en-us/usermanual-gaussdb/gaussdb_01_519.html)
- [GaussDB：基于 MySQL Connector/J 开发](https://support.huaweicloud.com/intl/zh-cn/centralized-m-comp-devg-v8-gaussdb/gaussdb-81-0367.html)
- [GaussDB M 模式系统概述](https://support.huaweicloud.com/intl/en-us/centralized-m-comp-devg-v8-gaussdb/gaussdb-81-0001.html)
- [OceanBase Cloud：PHP 使用 MySQL EXT、MySQLi、PDO](https://en.oceanbase.com/docs/common-oceanbase-cloud-10000000001945386)
- [OceanBase Connector/J：MySQL 协议及兼容策略](https://en.oceanbase.com/docs/common-oceanbase-database-10000000003453491)
- [PHP PDO_PGSQL](https://www.php.net/ref.pdo-pgsql.php)
- [PHP PostgreSQL 支持依赖 libpq](https://www.php.net/manual/en/pgsql.requirements.php)
- [PHP PDO_ODBC](https://www.php.net/manual/en/ref.pdo-odbc.php)
- [PHP PDO prepared statements](https://www.php.net/pdo.prepare.php)
