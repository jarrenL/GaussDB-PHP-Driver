# Extension source (Phase 2 reserved)

本目录是 Linux `pdo_gaussdb` 扩展的 Phase 2 预留位置。当前没有导入扩展源码，也没有交付 `pdo_gaussdb.so` 或 `gaussdb:` DSN；Phase 1 的可运行 Linux 原型仍是 PHP 官方 `pdo_pgsql.so` 加 GaussDB 507 `libpq`。

候选实现策略是从与目标 PHP 版本匹配的官方 `ext/pdo_pgsql` 提取最小基线，链接 GaussDB 507 配套 libpq，再加入 M 模式参数与类型适配。正式导入代码前先固定上游 PHP 版本、许可证文件和补丁维护方式。

ARM64 与 x86_64 共用这里的源码，不维护架构分支。
