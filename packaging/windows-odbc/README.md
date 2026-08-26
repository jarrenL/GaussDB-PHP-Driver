# Windows GaussDB PDO_ODBC

Windows x64/x86 均使用 PHP PDO_ODBC + GaussDB 官方 Unicode ODBC + 本仓库 `src/` 兼容层。M 与 A/ORA（O）使用同一套代码。

## 安装

管理员 PowerShell：

```powershell
./setup-php.ps1

./install-side-by-side.ps1 `
  -X86InstallerPath C:\path\to\x86\gsqlodbc.exe `
  -X64InstallerPath C:\path\to\x64\gsqlodbc.exe

Get-OdbcDriver | Where-Object Name -In @('GaussDB Unicode', 'GaussDB ANSI', 'gsqlodbc')
```

只用一种位数时安装对应驱动即可。PHP x64 必须配 ODBC x64；PHP x86 必须配 ODBC x86。

创建系统 DSN 时，`configure-dsn.ps1` 会加入：

```text
ConnSettings=set client_encoding=UTF8
BoolsAsChar=0
ByteaAsLongVarBinary=1
```

兼容层默认使用无 DSN 连接，因此通常不需要手工创建 DSN。

## 安装项目代码

将仓库放到例如 `C:\GaussDBTest\GaussDB-PHP-Driver`，业务代码加载：

```php
require 'C:\\GaussDBTest\\GaussDB-PHP-Driver\\src\\autoload.php';
```

连接和二进制使用方式见仓库根目录 `CUSTOMER_USAGE.md`。

## 验证 M/O

```powershell
$env:GAUSS_PASSWORD = '<password>'
./tests/run-windows-compat-matrix.ps1 `
  -RepositoryPath 'C:\GaussDBTest\GaussDB-PHP-Driver' `
  -Server 'gaussdb.example.com' `
  -MDatabase 'app_m' `
  -ODatabase 'app_ora' `
  -User 'app_user'
```

2026-08-26 已在 UTM Windows 11 中完成 PHP 8.3.8 AMD64/i586 的 M/O 验证，四个目标各 10/10 通过。UTF-8 中文/emoji 与含 NUL 二进制均通过。

密码只通过进程环境或权限受控文件传入，不写入脚本和仓库。
