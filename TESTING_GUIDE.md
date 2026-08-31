# 测试指南

面向测试人员：先讲清楚这个项目是什么，再说明怎么测、怎么判读结果、哪些现象是已知边界。不涉及代码细节，代码级说明见 [tests/README.md](tests/README.md)。

## 1. 这个项目是什么

一句话：**它不是一个应用，而是一个"转接头"**。

背景：

1. GaussDB 有多种兼容模式——M 模式（行为学 MySQL）、A/ORA 模式（行为学 Oracle）。同一条 SQL 在两种模式下表现不一样。
2. PHP 官方没有 GaussDB 专用驱动，官方推荐走 ODBC 通道。
3. 但直接用 PHP 的 PDO_ODBC 连 GaussDB 会遇到一堆坑：布尔值表现形式不对、二进制数据变成十六进制文本、M 和 ORA 的二进制写入方式完全不同。

本项目就是在 PDO_ODBC 之上**包了一层 PHP 兼容层（`src/`，约 500 行）**，把这些差异统一抹平。业务代码不管底下连的是 M 库还是 ORA 库，写法都一样。

```
客户 PHP 代码
    ↓
GaussDb\Compat 兼容层（本仓库 src/）
    ↓
PHP 官方 PDO_ODBC
    ↓
GaussDB 官方 ODBC 驱动
    ↓
GaussDB M 或 A/ORA 数据库
```

测试要验证的就是：**这个"转接头"在各种环境下都接得上、数据不变形、错误不失控**。

## 2. 测试矩阵：到底在测什么

正式基线按 PHP 版本、平台/架构和数据库模式组合：

| 维度 | 取值 | 原因 |
|---|---|---|
| PHP 版本 | 7.2.34、8.3 | 客户存量系统有老 PHP |
| 平台/架构 | Linux ARM64、Linux x86_64、Windows x64、Windows x86 | 同时覆盖 Linux 国产 ARM 服务器和 Windows 32/64 位环境 |
| 数据库模式 | M、ORA | 两种兼容模式都要验 |

2 × 4 × 2 = 16 个组合，每个组合跑同一套 10 项契约 = 160 项。当前基线见 [`tests/baselines/compat-m-o-matrix.json`](tests/baselines/compat-m-o-matrix.json)（160/160 通过）。

10 项契约逐条翻译：

| # | 契约 | 验证什么 |
|---|---|---|
| 1 | 预处理 CRUD 与标量类型 | 存取金额 `1234567890123456.7890` 一个字符不能差（DECIMAL 精度）；NULL 还是 NULL；布尔归一化成 PHP true/false |
| 2 | UTF-8 中文与 emoji | 存 `GaussDB 中文与 emoji 🚀` 原样取回，不乱码 |
| 3 | 二进制往返 | 存含 `\x00` 和 `\xFF` 的字节串，一个字节不丢 |
| 4 | SQL 注入防护 | 存 `x'); DROP TABLE xxx; --` 只会被当普通文本，表不会被删 |
| 5 | 语句复用与 rowCount | 同一条 INSERT 模板用 3 次，每次报告影响 1 行 |
| 6 | 命名参数与结果映射 | `WHERE id = :id` 写法可用，结果列能按名字/位置归一化 |
| 7 | UPDATE/DELETE 行数 | 改 1 条报告 1，删 1 条报告 1 |
| 8 | 事务回滚与提交 | 回滚的数据真的没了，提交的数据真的在 |
| 9 | 保存点 | 事务里打"存档点"，回滚到存档点：存档前的保留、之后的撤销 |
| 10 | 重复键 SQLSTATE 与连接恢复 | 报标准 5 位 SQLSTATE；报错后连接还能继续用（不会一错就瘫） |

## 3. 怎么跑

### 方式 A：已装好环境的机器直接跑（推荐先跑这个）

前提：机器上有 PHP（7.2.34 或 8.3）+ PDO_ODBC 扩展 + GaussDB 官方 ODBC 驱动。

```bash
export GAUSS_HOST='数据库IP'
export GAUSS_PORT='5432'
export GAUSS_DATABASE='M模式的库名'
export GAUSS_MODE='M'          # 测 ORA 库时换成 O
export GAUSS_USER='测试账号'
read -r -s -p 'GaussDB password: ' GAUSS_PASSWORD
export GAUSS_PASSWORD
php tests/php_compat_integration.php
```

**通过标准（两个条件同时满足）**：

1. 进程退出码为 0；
2. 输出 JSON 中 `"summary": {"pass": 10, "fail": 0}`。

有失败时，JSON 的 `tests` 数组里每条失败都带异常类、SQLSTATE 和报错原文，直接定位。测试全程只用随机命名的临时表，`finally` 里自动清理，不碰业务数据。

### 方式 B：Windows 测试机

```powershell
$env:GAUSS_PASSWORD = '***'
./tests/run-windows-compat-matrix.ps1 `
  -Server '数据库IP' `
  -MDatabase 'M库名' -ODatabase 'ORA库名' `
  -User '测试账号' `
  -PhpPath 'C:\GaussDBTest\php-8.3.8-x64\php.exe'
```

脚本自动把 M 和 ORA 各跑一遍，结果 JSON 写到 `C:\GaussDBTest\compat-results\`，失败会 Warning 提示。PHP 7.2.34 或 x86 环境通过 `-PhpPath` 传入对应 php.exe。

### 方式 C：Linux + Docker（跑完整矩阵）

```bash
make extract-odbc-arm64 GAUSSDB_DRIVER_ARCHIVE='/受控目录/ARM64官方驱动包.tar.gz'
make build-odbc-arm64

export GAUSS_HOST='数据库IP'
export GAUSS_USER='测试账号'
export GAUSS_M_DATABASE='M模式的库名'
export GAUSS_O_DATABASE='ORA模式的库名'
export GAUSS_DOCKER_NETWORK='GaussDB所在Docker网络'  # 数据库不在 Docker 时不设置
read -r -s -p 'GaussDB password: ' GAUSS_PASSWORD
export GAUSS_PASSWORD
make test-compat-arm64
```

x86_64 将上述目标换成 `extract-odbc-x86_64`、`build-odbc-x86_64` 和 `test-compat-x86_64`；PHP 7.2 矩阵使用 `test-compat-php72-arm64` 或 `test-compat-php72-x86_64`。驱动包必须来自受控目录，不提交到仓库。

## 4. 测试前准备清单

1. **两个库**：请 DBA 建一个 M 模式库、一个 ORA 模式库，分别使用 `DBCOMPATIBILITY 'M'` 和 `DBCOMPATIBILITY 'ORA'`。[`docker/init-test-user.sql.example`](docker/init-test-user.sql.example) 是 M 库初始化示例；ORA 库需由 DBA 按客户权限规范另建。部分部署只允许一个 M 兼容库，此时复用已有 M 库。
2. **测试账号**：需要能在自己的 schema 里建表、删表。
3. **环境自检**（跑之前确认）：

```bash
php -r 'var_dump(extension_loaded("pdo_odbc"), PDO::getAvailableDrivers());'
# 必须输出 true，且驱动列表里有 "odbc"
```

## 5. 特别注意的坑

**坑 1：位数必须三合一。** PHP 位数 = ODBC 驱动位数 = 机器架构。x86 的 PHP 配 x64 的 ODBC 驱动会连不上或报诡异错误。Windows 同机装 x64/x86 两套用 `packaging/windows-odbc/install-side-by-side.ps1`。

**坑 2：驱动版本要与服务端匹配。** 必须用 GaussDB 官方 ODBC 驱动，且版本对应服务端 GaussDB 版本。不要拿 PostgreSQL 的 ODBC 凑数。

**坑 3：模式连错会主动报错——这是防呆设计，不是 bug。** 兼容层连上后第一件事就是校验数据库模式。用 `GAUSS_MODE=M` 去连 ORA 库会抛 `Connected database compatibility mode is ORA; expected M`。测试时 M 配 M 库、O 配 ORA 库。

**坑 4：以下现象是已知边界，提问题前先按边界判定**（详见 [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md)）：

| 现象 | 原因 | 正确姿势 |
|---|---|---|
| `lastInsertId()` 不可用 | ODBC 返回 IM001，M 模式本身语义不稳定 | 用业务主键，或 SQL 里显式返回生成值 |
| TIMESTAMP 没有微秒 | ODBC 通道只返回到秒 | 需要微秒就拆成整数微秒字段 |
| M 模式 TEXT 长度受实例和字段定义影响 | 服务端与字段定义差异 | 不固化统一阈值，大文本按客户实例专项验证 |
| 读二进制是十六进制乱码 | ODBC 就是这样返回的 | 查询时显式传 `ResultType::BINARY_HEX`，兼容层会还原 |
| 用户自定义类型 / 存储过程 OUT 参数 | 官方 ODBC 存在支持边界 | 优先换基础类型/JSON，并对目标过程专项验收 |

**坑 5：密码安全。** 密码只通过环境变量 `GAUSS_PASSWORD` 传递。不要写进命令行参数（会出现在进程列表）、不要写进文件提交到 git。

**坑 6：判断"现象正常吗"的顺序。** 先查 [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md)，再对照 [`tests/baselines/compat-m-o-matrix.json`](tests/baselines/compat-m-o-matrix.json) 的基线，两处都没有再提问题。

## 6. 一分钟冒烟测试

不想跑全量时，验证"转接头通电"最快的方式：

```bash
export GAUSS_HOST='数据库IP'
export GAUSS_DATABASE='M模式的库名'
export GAUSS_MODE='M'
export GAUSS_USER='测试账号'
read -r -s -p 'GaussDB password: ' GAUSS_PASSWORD
export GAUSS_PASSWORD
php examples/compat_odbc.php
```

输出 `{"enabled": true, "payload_hex": "410042ff"}` 即说明连接、模式校验、布尔归一化、二进制往返四件事全部打通。

## 7. 专项测试（可选）

以下专项由 [`tests/README.md`](tests/README.md) 提供，一般由开发/交付侧执行，测试同学按需参与：

- `make test-auth`：错误密码认证失败行为（一次受控尝试）。
- `make test-readonly`：只读账号越权写入被拒。
- `make test-text-threshold`：M 模式 TEXT 长度阈值探测。
- `make test-ssl`：SSL 强制连接探测（无 TLS 环境会以非零退出，属预期）。

## 8. 一句话总结

准备好位数匹配的环境 + M/O 两个库，设好 6 个环境变量，跑 `php tests/php_compat_integration.php`，看退出码和 `summary.fail`，对照 KNOWN_LIMITATIONS.md 判断异常是不是已知边界。
