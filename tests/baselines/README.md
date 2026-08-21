# Tracked test baselines

这里保存可随仓库审查的真实测试结果，不包含密码、连接串或授权驱动二进制。

- `linux-arm64.json` / `linux-x86_64.json`：PHP 8.3、对应架构的 GaussDB 507 libpq 和 PDO_PGSQL。
- `windows-x64.json` / `windows-x86.json`：2026-08-10 在 UTM Windows 11 AMD64 中使用 PHP 8.3.8 NTS、对应位数 GaussDB 507 ODBC 和本地 M 数据库运行所得。

四份结果的必选项均为 0 失败；兼容性失败保留原始异常，作为后续适配层基线。结果里的耗时、临时表 OID 等环境数据不作为跨运行断言。

`build/test-results/` 仍用于每次本地运行的临时结果并被 Git 忽略；只有经过脱敏、确认来源的基线才进入本目录。
