# PHP compatibility layer

这里是实际交付的 PHP 运行时代码，不再是预留目录。实现基于 PHP `PDO_ODBC` 和 GaussDB 官方 Unicode ODBC 驱动，不修改 GaussDB 内核，也不复制厂商二进制。

- `CompatibilityMode::M`：M 兼容模式。
- `CompatibilityMode::ORACLE`：GaussDB 官方名称是 A/ORA；项目同时接受客户口径中的 `O`。
- `Driver`：创建 ODBC 连接，强制校验数据库兼容模式和 UTF-8 客户端编码。
- `Statement`：统一布尔参数为 `0/1`，支持二进制 LOB 参数，并按显式列映射解码 ODBC 返回的十六进制二进制值。

代码遵循 PSR-4，可通过 Composer 加载；没有 Composer 时也可以直接引用 `src/autoload.php`。
最低支持 PHP 7.2.34，并持续兼容 PHP 8.x。
