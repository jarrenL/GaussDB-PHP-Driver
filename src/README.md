# Extension source

本目录将保存 Linux `pdo_gaussdb` 扩展的公共源码。

实现策略是从与目标 PHP 版本匹配的官方 `ext/pdo_pgsql` 提取最小基线，链接 GaussDB 507 配套 libpq，再加入 M 模式参数与类型适配。正式导入代码前先固定上游 PHP 版本、许可证文件和补丁维护方式。

ARM64 与 x86_64 共用这里的源码，不维护架构分支。

