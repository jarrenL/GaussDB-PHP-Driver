# Tracked test baselines

`compat-m-o-matrix.json` 是当前正式兼容层基线：与测试实例匹配的 GaussDB 官方 Unicode ODBC + PDO_ODBC，在 PHP 7.2.34/8.3、Linux ARM64/x86_64、Windows x64/x86 的 M 与 A/ORA 模式上合计 160/160 通过。

其余 `linux-*.json` 和 `windows-*.json` 是早期 PDO_PGSQL/PDO_ODBC 原始行为表征，保留用于说明兼容层修复前的差异，不代表当前正式结果。

这里保存可随仓库审查的真实测试结果，不包含密码、连接串或授权驱动二进制。

- `linux-arm64.json` / `linux-x86_64.json`：PHP 8.3、对应架构且与服务端匹配的 GaussDB libpq 和 PDO_PGSQL 历史 PoC 基线。
- `windows-x64.json` / `windows-x86.json`：在 UTM Windows 11 AMD64 中使用 PHP 8.3 NTS、对应位数且与服务端匹配的 GaussDB ODBC 和本地 M 数据库运行所得。

四份结果的必选项均为 0 失败；兼容性失败保留原始异常，作为后续适配层基线。结果里的耗时、临时表 OID 等环境数据不作为跨运行断言。

`build/test-results/` 仍用于每次本地运行的临时结果并被 Git 忽略；只有经过脱敏、确认来源的基线才进入本目录。

新的完整矩阵使用 `tests/generate-compat-baseline.php` 从各目标原始 JSON 生成。生成时必须使用只包含本次发布结果的干净输入目录，以便重复目标和缺失平台信息能直接使生成失败。详细命令见 `tests/README.md`。
