# GaussDB PHP M/O 兼容驱动使用手册

## 1. 客户需要安装什么

这不是一个单独的 `.so`/`.dll` 安装包。完整运行链由三部分组成：

1. PHP 官方 `PDO_ODBC`。
2. GaussDB 官方 Unicode ODBC 驱动。
3. 本仓库 `src/` PHP 兼容层。

客户不需要修改 GaussDB 服务端，也不需要下载或编译 PDO_PGSQL。业务服务器必须使用与操作系统、CPU 架构和 GaussDB 版本匹配的 ODBC 包。

| 平台 | ODBC Driver Manager | GaussDB 文件 |
|---|---|---|
| Linux ARM64 | unixODBC | ARM64 `gsqlodbcw.so` 及配套库 |
| Linux x86_64 | unixODBC | x86_64 `gsqlodbcw.so` 及配套库 |
| Windows x64 | Windows ODBC | x64 官方安装器/DLL |
| Windows x86 | Windows ODBC | x86 官方安装器/DLL |

## 2. 安装本项目代码

将仓库放到应用服务器，例如：

```text
/opt/gaussdb-php-compat/
├── composer.json
└── src/
```

本项目目前没有发布到 Packagist。通过公开 Git 仓库安装：

```bash
composer config repositories.gaussdb-php-compat vcs https://github.com/jarrenL/GaussDB-PHP-Driver.git
composer require jarrenl/gaussdb-php-compat:dev-main
```

内网或已下载源码的环境建议从本地 path 仓库安装：

```bash
composer config repositories.gaussdb-php-compat path /opt/gaussdb-php-compat
composer require jarrenl/gaussdb-php-compat:@dev
```

Composer 用户在业务代码中加载 `vendor/autoload.php`。不使用 Composer 时直接加载：

```php
require '/opt/gaussdb-php-compat/src/autoload.php';
```

本仓库代码负责模式校验、UTF-8、布尔和二进制适配。只安装 PDO_ODBC 而不加载 `src/`，仍会暴露厂商驱动的原始差异。

`CompatibilityMode` 是 PHP 7.2 可用的字符串常量类，不是 enum。固定模式使用 `CompatibilityMode::M` 或 `CompatibilityMode::ORACLE`；动态配置使用 `CompatibilityMode::fromName()`。别名映射如下：

| 输入 | 归一化值 | 用途 |
|---|---|---|
| `M`、`MYSQL` | `M` | M 模式 |
| `A`、`O`、`ORA`、`ORACLE` | `ORA` | A/ORA（O）模式 |

`CompatibilityMode::ORACLE` 的常量名表达用途，它的字符串值是规范值 `ORA`。`ConnectionConfig` 接收 `string $mode` 后会统一调用 `fromName()` 校验和归一化。

## 3. M 模式连接

```php
<?php

use GaussDb\Compat\CompatibilityMode;
use GaussDb\Compat\ConnectionConfig;
use GaussDb\Compat\Driver;

require '/opt/gaussdb-php-compat/src/autoload.php';

$password = getenv('GAUSS_PASSWORD');
if ($password === false || $password === '') {
    throw new RuntimeException('GAUSS_PASSWORD is required');
}

$db = Driver::connect(new ConnectionConfig(
    getenv('GAUSS_HOST') ?: 'gaussdb.example.com',
    (int) (getenv('GAUSS_PORT') ?: 5432),
    getenv('GAUSS_DATABASE') ?: 'app_m',
    getenv('GAUSS_USER') ?: 'app_user',
    $password,
    CompatibilityMode::M
));

$statement = $db->execute('SELECT id, name FROM users WHERE id = ?', [1]);
$row = $statement->fetch();
```

连接成功后代码会查询 `pg_database.datcompatibility`；M 模式接受数据库返回 `M` 或 `MYSQL`，其他值会立即失败。

## 4. Oracle 兼容模式连接

GaussDB 官方名称是 A/ORA。本项目也接受字符串别名 `O`：

将 `ConnectionConfig` 第六个参数改为 `CompatibilityMode::ORACLE`。

或从配置读取：

也可以把第六个参数写为 `CompatibilityMode::fromName(getenv('GAUSS_MODE') ?: 'O')`。只有来自环境变量或配置文件的动态值才需要在调用处显式使用 `fromName()`。

其他连接代码与 M 相同。连接到非 ORA 数据库时会拒绝继续运行。

## 5. 参数和类型

普通参数直接使用数组。PHP 布尔值会自动绑定为整数 `0/1`：

```php
$db->execute(
    'INSERT INTO feature_flags (id, enabled) VALUES (?, ?)',
    [1, true]
);
```

`Statement::bindValue()` 未显式传第三个 PDO 类型时，与 `execute()` 使用同一套推断：整数为 `PDO::PARAM_INT`、`null` 为 `PDO::PARAM_NULL`、`BinaryValue` 为模式对应的二进制绑定。显式传第三个参数时以调用方指定的类型为准。

DECIMAL/NUMBER 建议用字符串，避免 PHP `float` 精度损失。

二进制入参使用 `BinaryValue`，不要自行判断当前模式：

```php
use GaussDb\Compat\BinaryValue;
use GaussDb\Compat\ResultType;

$bytes = "A\x00B\xFFZ";
$db->execute(
    'INSERT INTO files (id, payload) VALUES (?, ?)',
    [1, new BinaryValue($bytes)]
);

$row = $db->execute(
    'SELECT payload FROM files WHERE id = ?',
    [1],
    ['payload' => ResultType::BINARY_HEX]
)->fetch();
```

M 模式表字段使用 `BLOB`；ORA 模式使用 `RAW/BLOB`。`BINARY_HEX` 只应用于明确的二进制列，避免把普通十六进制文本误解码。

布尔结果需要 PHP `bool` 时显式标记：

```php
$row = $db->execute(
    'SELECT enabled FROM feature_flags WHERE id = ?',
    [1],
    ['enabled' => ResultType::BOOLEAN]
)->fetch();
```

布尔结果接受 PHP `true/false`、数字 `1/0`，以及不区分大小写的字符串 `1/0`、`t/f`、`true/false`（会去除首尾空白）。其他值会抛出 `UnexpectedValueException`。

`fetchAll()` 会透传 PDO 的附加参数，例如读取第 3 列：

```php
$values = $statement->fetchAll(PDO::FETCH_COLUMN, 2);
```

需要对该列做结果归一化时，在创建语句时传入对应列索引的 `ResultType`。
数组行和 `PDO::FETCH_OBJ` 返回的 `stdClass` 行都支持按列名或列索引归一化；自定义 `PDO::FETCH_CLASS` 对象不自动改写，可通过 `nativeStatement()` 自行处理。

## 6. 事务和原生 PDO

```php
$db->beginTransaction();
try {
    $db->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', ['10.00', 1]);
    $db->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', ['10.00', 2]);
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $error;
}
```

兼容层未封装的标准能力可通过 `$db->nativePdo()` 和 `$statement->nativeStatement()` 访问；此时调用方自行承担类型差异。

## 7. Windows 安装概要

1. 安装 PHP 7.2.34+，并启用与该 PHP 版本匹配的 `PDO_ODBC`；Windows 推荐使用 NTS 包。
2. 安装匹配位数的 GaussDB ODBC；PHP x64 对应 ODBC x64，PHP x86 对应 ODBC x86。
3. 优先使用 `GaussDB Unicode` 驱动。
4. 将本仓库放到服务器并加载 `src/autoload.php`。

辅助脚本见 [`packaging/windows-odbc/`](packaging/windows-odbc/)。同机安装 x86/x64 时使用 `install-side-by-side.ps1`，避免厂商安装器共用目录互相覆盖。

## 8. 连接参数

兼容层默认生成无 DSN 连接串，并加入：

```text
Driver={GaussDB Unicode}
ConnSettings=set client_encoding=UTF8
BoolsAsChar=0
ByteaAsLongVarBinary=1
```

驱动名使用 ODBC 花括号值语法并转义内部 `}`。GaussDB ODBC 会把 host/database 外层花括号当成实际值，因此这些标量保持无括号格式，并直接拒绝分号、花括号和 NUL 字节，防止注入新连接属性或产生驱动解析歧义。

已配置系统 DSN 时可传入：

```php
$config = new ConnectionConfig(
    'unused',
    5432,
    'app_m',
    $user,
    $password,
    CompatibilityMode::M,
    'GaussDB Unicode',
    'prefer',
    'GaussDB'
);
```

系统 DSN 自身仍须设置 UTF-8 和二进制相关选项。

## 9. 验收

```bash
export GAUSS_HOST='gaussdb.example.com'
export GAUSS_PORT='5432'
export GAUSS_DATABASE='app_m'
export GAUSS_MODE='M'
export GAUSS_USER='app_user'
read -r -s -p 'GaussDB password: ' GAUSS_PASSWORD
export GAUSS_PASSWORD
php tests/php_compat_integration.php
```

期望 `summary.fail` 为 `0`。ORA 库将 `GAUSS_MODE` 改为 `O` 并设置对应数据库名。

## 10. 生产边界

- 不依赖 `PDO::lastInsertId()`；M 模式的 `AUTO_INCREMENT` 与 PDO/ODBC 语义应以目标实例实测为准。
- 当前只承诺时间戳秒级精度，不伪造已丢失的微秒。
- M `TEXT` 本地实测 65,535 字节成功，65,536 字节返回 `22001`。
- ODBC 用户自定义类型、部分存储过程 OUT 参数等限制仍然存在。
- 密码从密钥管理系统或进程环境注入，不写入代码、仓库或普通日志。
- 客户升级 GaussDB、PHP、操作系统或 CPU 架构后必须重新执行契约测试。

更多信息见 [`KNOWN_LIMITATIONS.md`](KNOWN_LIMITATIONS.md)。
