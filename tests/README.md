# M/O 兼容层测试

正式入口是 `php_compat_integration.php`。测试只在目标数据库创建随机名称临时表，并在 `finally` 中清理，不修改内核配置。

覆盖 10 项：

1. 预处理 CRUD、DECIMAL、NULL、时间戳和布尔归一化。
2. UTF-8 中文与 emoji 无损往返。
3. 含 NUL/`0xFF` 的二进制往返。
4. 绑定参数阻止 SQL 注入。
5. 预处理语句复用和 `rowCount()`。
6. 命名参数和结果类型映射。
7. UPDATE/DELETE 与受影响行数。
8. 事务回滚与提交。
9. 保存点回滚。
10. 重复键五位 SQLSTATE 和异常后连接恢复。

## Linux

```bash
export GAUSS_HOST='gaussdb.example.com'
export GAUSS_PORT='5432'
export GAUSS_DATABASE='app_m'
export GAUSS_MODE='M'
export GAUSS_USER='app_user'
export GAUSS_PASSWORD='由受控凭据来源注入'
php tests/php_compat_integration.php
```

ORA 数据库使用 `GAUSS_MODE=O`。成功条件是进程退出 `0` 且 JSON `summary.fail=0`。

## Windows

```powershell
$env:GAUSS_PASSWORD = '<password>'
./tests/run-windows-compat-matrix.ps1 `
  -Server 'gaussdb.example.com' `
  -MDatabase 'app_m' `
  -ODatabase 'app_ora' `
  -User 'app_user'
```

脚本默认使用项目测试机上的 PHP 8.3 x64 路径。PHP 7.2.34 或 x86 测试通过 `-PhpPath` 传入对应路径；PHP、PDO_ODBC 和 GaussDB ODBC 位数必须一致。

## 本次基线

正式结果见 `baselines/compat-m-o-matrix.json`：PHP 7.2.34/8.3、Linux ARM64/x86_64、Windows AMD64/i586 的 M 与 A/ORA 共 16 个目标，合计 160/160 通过。

旧 `php_pdo_contract.php` 及四份早期原始行为结果仍保留，用于回归对比和解释兼容层修复前的差异，不作为当前正式通过标准。
