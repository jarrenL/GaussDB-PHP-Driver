# Legacy: Windows 原始 PDO_ODBC 基线

> 当前 Windows M/O 兼容层安装说明见 `packaging/windows-odbc/README.md`。

第一阶段使用 GaussDB 507 官方 ODBC：

- Windows x64：配合 64 位 PHP 和 `php_pdo_odbc.dll`。
- Windows x86：配合 32 位 PHP 和 `php_pdo_odbc.dll`。

已在 UTM Windows 11 AMD64 虚拟机确认 X64 和 X86 安装、驱动注册及 PDO_ODBC 连库。PHP 8.3.8 NTS 的 AMD64 与 i586 扩展契约测试均为 25 个通过、0 个必选失败、9 个已知兼容性失败。脱敏原始结果见 [`tests/baselines/`](../../tests/baselines/)。

## 提取安装器

```bash
make extract-windows-odbc \
  GAUSSDB_DRIVER_ARCHIVE='/path/to/DBS-GaussDB-driver_x86_64_....tar.gz'
```

生成但不提交：

```text
build/gaussdb-client/windows-odbc/x64/gsqlodbc.exe
build/gaussdb-client/windows-odbc/x86/gsqlodbc.exe
```

## Windows 安装和测试

以管理员 PowerShell 执行：

```powershell
./setup-php.ps1 # 默认安装并校验 x64 PHP

./install-side-by-side.ps1 `
  -X86InstallerPath C:\path\to\x86\gsqlodbc.exe `
  -X64InstallerPath C:\path\to\x64\gsqlodbc.exe

Get-OdbcDriver | Where-Object Name -In @('GaussDB Unicode', 'GaussDB ANSI', 'gsqlodbc')

./configure-dsn.ps1 `
  -DriverName '<上一步显示的准确名称>' `
  -Server '<GaussDB地址>' `
  -Port 5432 `
  -Database gdbdrv_m_test

$env:GAUSS_ODBC_DSN = 'GaussDB507'
$env:GAUSS_USER = 'gauss_php_test'
$env:GAUSS_PASSWORD = '<password>'
php tests/php_pdo_odbc_smoke.php
```

准备 32 位 PHP 时必须显式声明期望架构，脚本会拒绝 ZIP 与期望位数不一致：

```powershell
./setup-php.ps1 `
  -Archive C:\Windows\Temp\php-8.3.8-nts-Win32-vs16-x86.zip `
  -PhpHome C:\GaussDBTest\php-8.3.8-x86 `
  -StatusFile C:\GaussDBTest\php-setup-x86.txt `
  -ExpectedArchitecture x86
```

不希望创建系统 DSN 时，可直接设置连接串：

```powershell
$env:GAUSS_ODBC_CONNECTION_STRING = 'Driver={GaussDB Unicode};Servername=<GaussDB地址>;Port=5432;Database=gdbdrv_m_test;SSLmode=prefer'
$env:GAUSS_USER = 'gauss_php_test'
$env:GAUSS_PASSWORD = '<password>'
php tests/php_pdo_odbc_smoke.php
```

官方两个安装器共用一个安装目录，并会把 32/64 位注册表视图指向同一 DLL。`install-side-by-side.ps1` 会保存 X86 目录、恢复 X64 目录、修正 WOW6432Node，并校验 PE machine code（X86 `0x014c`、AMD64 `0x8664`），避免错误 193。

## UTM Windows 实测结果

- Windows：NT 10.0.26100，AMD64。
- PHP：8.3.8 NTS x64/x86，`PDO_ODBC` 和 `odbc` 均已加载；X86 需要安装 Microsoft Visual C++ X86 Runtime。
- 驱动：GaussDB 507 X64/X86 ODBC，注册名 `GaussDB Unicode`。
- 服务端：本地 GaussDB Kernel 507.0.0，M 模式数据库 `gdbdrv_m_test`。
- 通过：连接、建表、预处理写入、查询、DECIMAL/BOOLEAN/TIMESTAMP、事务回滚。
- 待处理：中文和 emoji 查询结果发生编码错乱；系统 DSN 在自动化进程中未被 PHP 识别，使用无 DSN 连接串可以稳定连接。

`vm-diagnostics.ps1`、`setup-php.ps1` 和 `run-smoke-test.ps1` 分别用于环境检查、PHP 初始化和可拉取结果的冒烟测试。`configure-utm-test-dsn.ps1`、`run-utm-smoke-test.ps1` 中的地址是本项目 UTM NAT 测试环境专用示例，其他环境应使用通用脚本参数。

密码优先通过当前 PowerShell 进程的 `GAUSS_PASSWORD` 环境变量传入。必须使用文件时，先用 `write-secure-secret-file.ps1` 创建只允许当前用户和 SYSTEM 读取的文件；测试执行器会拒绝继承权限或向 Users/Everyone/Authenticated Users 开放的文件，并在 `finally` 中自动删除约定文件及清除环境变量。

PHP、ODBC 驱动和 DSN 位数必须一致。X64 PHP 使用 X64 安装器；X86 PHP 使用 X86 安装器。
