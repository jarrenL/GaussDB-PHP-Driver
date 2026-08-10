# Windows ODBC packaging

第一阶段使用 GaussDB 507 官方 ODBC：

- Windows x64：配合 64 位 PHP 和 `php_pdo_odbc.dll`。
- Windows x86：配合 32 位 PHP 和 `php_pdo_odbc.dll`。

已在 UTM Windows 11 AMD64 虚拟机确认 X64 安装、驱动注册和 PDO_ODBC 连库。X86 安装器已提取，但仍需 32 位 PHP 环境完成对应验证。

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
./install-gaussdb-odbc.ps1 -InstallerPath C:\path\to\x64\gsqlodbc.exe

Get-OdbcDriver | Where-Object Name -Match 'Gauss|gsql|PostgreSQL'

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

不希望创建系统 DSN 时，可直接设置连接串：

```powershell
$env:GAUSS_ODBC_CONNECTION_STRING = 'Driver={GaussDB Unicode};Servername=<GaussDB地址>;Port=5432;Database=gdbdrv_m_test;SSLmode=prefer'
$env:GAUSS_USER = 'gauss_php_test'
$env:GAUSS_PASSWORD = '<password>'
php tests/php_pdo_odbc_smoke.php
```

## UTM Windows x64 实测结果

- Windows：NT 10.0.26100，AMD64。
- PHP：8.3.8 NTS x64，`PDO_ODBC` 和 `odbc` 均已加载。
- 驱动：GaussDB 507 X64 ODBC，注册名 `GaussDB Unicode`。
- 服务端：本地 GaussDB Kernel 507.0.0，M 模式数据库 `gdbdrv_m_test`。
- 通过：连接、建表、预处理写入、查询、DECIMAL/BOOLEAN/TIMESTAMP、事务回滚。
- 待处理：中文和 emoji 查询结果发生编码错乱；系统 DSN 在自动化进程中未被 PHP 识别，使用无 DSN 连接串可以稳定连接。

`vm-diagnostics.ps1`、`setup-php.ps1` 和 `run-smoke-test.ps1` 分别用于环境检查、PHP 初始化和可拉取结果的冒烟测试。`configure-utm-test-dsn.ps1`、`run-utm-smoke-test.ps1` 中的地址是本项目 UTM NAT 测试环境专用示例，其他环境应使用通用脚本参数。

PHP、ODBC 驱动和 DSN 位数必须一致。X64 PHP 使用 X64 安装器；X86 PHP 使用 X86 安装器。
