# Windows GaussDB PDO_ODBC

Windows x64/x86 均使用 PHP PDO_ODBC + GaussDB 官方 Unicode ODBC + 本仓库 `src/` 兼容层。M 与 A/ORA（O）使用同一套代码。

## 快速安装（单一位数）

先把本仓库、PHP Windows NTS 压缩包和匹配位数的 GaussDB ODBC `gsqlodbc.exe` 复制到 Windows。以 x64 PHP 7.2.34 为例，在管理员 PowerShell 中执行：

```powershell
Set-ExecutionPolicy -Scope Process Bypass

$Repo = 'C:\GaussDBTest\GaussDB-PHP-Driver'
$PhpArchive = 'C:\packages\php-7.2.34-nts-Win32-VC15-x64.zip'
$PhpHome = 'C:\GaussDBTest\php-7.2.34-x64'
$OdbcInstaller = 'C:\packages\gaussdb-odbc-x64\gsqlodbc.exe'

& "$Repo\packaging\windows-odbc\setup-php.ps1" `
  -Archive $PhpArchive `
  -PhpHome $PhpHome `
  -StatusFile 'C:\GaussDBTest\php-setup-7.2.34-x64.txt' `
  -ExpectedArchitecture x64

& "$Repo\packaging\windows-odbc\install-gaussdb-odbc.ps1" `
  -InstallerPath $OdbcInstaller

& "$PhpHome\php.exe" -v
& "$PhpHome\php.exe" -m | Select-String -Pattern 'PDO|ODBC'
& "$PhpHome\php.exe" -r "var_export(PDO::getAvailableDrivers());"
Get-OdbcDriver | Where-Object Name -In @('GaussDB Unicode', 'GaussDB ANSI', 'gsqlodbc')
```

`setup-php.ps1` 完成解压 PHP、从 `php.ini-production` 生成 `php.ini`、启用 `pdo_odbc`/`odbc` 并校验 PHP 架构。目标 `$PhpHome` 已存在时会被重建，不要指向已有业务环境目录。

只用一种位数时安装对应驱动即可。PHP x64 必须配 ODBC x64；PHP x86 必须配 ODBC x86。x86 时同时替换 PHP 压缩包、`$PhpHome`、ODBC 安装器，并传入 `-ExpectedArchitecture x86`。

## 同机安装 x64 和 x86

厂商 x64/x86 安装器可能共用安装目录。两种位数都需要时使用：

```powershell
& "$Repo\packaging\windows-odbc\install-side-by-side.ps1" `
  -X86InstallerPath 'C:\packages\gaussdb-odbc-x86\gsqlodbc.exe' `
  -X64InstallerPath 'C:\packages\gaussdb-odbc-x64\gsqlodbc.exe'
```

该脚本会保留 x86 文件、重新安装 x64，然后校验注册的 DLL 位数。

## DSN

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
& "$Repo\tests\run-windows-compat-matrix.ps1" `
  -PhpPath 'C:\GaussDBTest\php-7.2.34-x64\php.exe' `
  -RepositoryPath $Repo `
  -Server 'gaussdb.example.com' `
  -MDatabase 'app_m' `
  -ODatabase 'app_ora' `
  -User 'app_user'
```

已在 UTM Windows 11 中完成 PHP 7.2.34 和 PHP 8.3 AMD64/i586 的 M/O 验证，各目标均为 10/10。UTF-8 中文/emoji 与含 NUL 二进制均通过。

密码只通过进程环境或权限受控文件传入，不写入脚本和仓库。
