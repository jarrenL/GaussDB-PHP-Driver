# Linux PDO_ODBC packaging

Linux ARM64 和 x86_64 共用本构建定义。PHP 使用系统 `unixODBC`，实际数据库驱动使用与服务端匹配的官方 `gsqlodbcw.so`；厂商二进制只从客户已有的驱动总包中提取，不提交到仓库。

```bash
make extract-odbc-arm64 GAUSSDB_DRIVER_ARCHIVE='/path/to/aarch64-driver.tar.gz'
make build-odbc-arm64

make extract-odbc-x86_64 GAUSSDB_DRIVER_ARCHIVE='/path/to/x86_64-driver.tar.gz'
make build-odbc-x86_64
```

PHP 7.2.34 验证镜像使用相同驱动目录：

```bash
make build-php72-odbc-arm64
make build-php72-odbc-x86_64
```

运行时通过 `Driver={GaussDB Unicode}` 使用 Unicode 驱动，连接串固定加入 `ConnSettings=set client_encoding=UTF8`。M 和 A/ORA（客户口径 O）模式使用相同的 PHP 代码，但连接后会校验目标数据库模式，防止连错库。
