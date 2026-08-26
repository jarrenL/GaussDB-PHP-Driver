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

项目使用 Composer 时，可把本仓库配置为 VCS/path 依赖并执行 `composer require jarrenl/gaussdb-php-compat`。不使用 Composer 时直接加载：

```php
require '/opt/gaussdb-php-compat/src/autoload.php';
```

本仓库代码负责模式校验、UTF-8、布尔和二进制适配。只安装 PDO_ODBC 而不加载 `src/`，仍会暴露厂商驱动的原始差异。

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
    host: getenv('GAUSS_HOST') ?: 'gaussdb.example.com',
    port: (int) (getenv('GAUSS_PORT') ?: 5432),
    database: getenv('GAUSS_DATABASE') ?: 'app_m',
    user: getenv('GAUSS_USER') ?: 'app_user',
    password: $password,
    mode: CompatibilityMode::M,
));

$statement = $db->execute('SELECT id, name FROM users WHERE id = ?', [1]);
$row = $statement->fetch();
```

连接成功后代码会查询 `pg_database.datcompatibility`；目标不是 M 时立即失败。

## 4. Oracle 兼容模式连接

GaussDB 官方名称是 A/ORA。本项目也接受字符串别名 `O`：

```php
mode: CompatibilityMode::ORACLE
```

或从配置读取：

```php
mode: CompatibilityMode::fromName(getenv('GAUSS_MODE') ?: 'O')
```

其他连接代码与 M 相同。连接到非 ORA 数据库时会拒绝继续运行。

## 5. 参数和类型

普通参数直接使用数组。PHP 布尔值会自动绑定为整数 `0/1`：

```php
$db->execute(
    'INSERT INTO feature_flags (id, enabled) VALUES (?, ?)',
    [1, true],
);
```

DECIMAL/NUMBER 建议用字符串，避免 PHP `float` 精度损失。

二进制入参使用 `BinaryValue`，不要自行判断当前模式：

```php
use GaussDb\Compat\BinaryValue;
use GaussDb\Compat\ResultType;

$bytes = "A\x00B\xFFZ";
$db->execute(
    'INSERT INTO files (id, payload) VALUES (?, ?)',
    [1, new BinaryValue($bytes)],
);

$row = $db->execute(
    'SELECT payload FROM files WHERE id = ?',
    [1],
    ['payload' => ResultType::BINARY_HEX],
)->fetch();
```

M 模式表字段使用 `VARBINARY/BLOB`；ORA 模式使用 `RAW/BLOB`。`BINARY_HEX` 只应用于明确的二进制列，避免把普通十六进制文本误解码。

布尔结果需要 PHP `bool` 时显式标记：

```php
$row = $db->execute(
    'SELECT enabled FROM feature_flags WHERE id = ?',
    [1],
    ['enabled' => ResultType::BOOLEAN],
)->fetch();
```

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

1. 安装 PHP 8.3 NTS，并启用 `extension=pdo_odbc`。
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

已配置系统 DSN 时可传入：

```php
new ConnectionConfig(
    host: 'unused',
    port: 5432,
    database: 'app_m',
    user: $user,
    password: $password,
    mode: CompatibilityMode::M,
    dsn: 'GaussDB',
)
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
