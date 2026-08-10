# 本地 GaussDB 驱动盘点与复用结论

日期：2026-08-05

## 最重要结论

`~/Downloads` 中有与当前内核精确匹配的 `GaussDB 507.0.0 B071` 驱动包，同时覆盖：

- Linux ARM64：libpq、静态 libpq、ODBC、Python、JDBC、Go 等。
- Linux x86_64：libpq、静态 libpq、ODBC、Python、JDBC、Go 等。
- Windows x64 和 x86：GaussDB ODBC 安装器。

当前 Docker 实例 `/opt/gaussdb/app/lib/libpq.so.5.5` 的 SHA-256 与 507 ARM 驱动包中 **Distributed / Euler2.10 ARM64 libpq** 完全相同：

```text
7960663fe291eb290204a4a2c0caa956b71948e4d30ea3f4442ea46b0eb1cfb7
```

这证明后续 Linux PHP 驱动应直接复用下载目录中的 `507 / Distributed / Euler2.10 / ARM64 libpq`，不需要从数据库容器中人工收集动态库。

## 507 同版本总包

ARM64：

```text
/Users/lj/Downloads/baiduyunpan/GaussDB 507 docker安装本地部署/ARM/
DBS-GaussDB-driver_aarch64_V2.0-10.0.0_26.861.0.0.1109004653171008.tar.gz
```

x86_64：

```text
/Users/lj/Downloads/baiduyunpan/GaussDB 507 docker安装本地部署/X86/
DBS-GaussDB-driver_x86_64_V2.0-10.0.0_26.861.0.0.1109004653171008.tar.gz
```

内部版本分别为：

- `507.0_20260531162048.mini.aarch64`
- `507.0_20260531162050.mini.x86_64`
- libpq/ODBC 子包标记：`GaussDB-Kernel_507.0.0.B071`

## Linux libpq：最高优先级复用

动态和静态包都包含：

- `include/libpq-fe.h` 及内部/公共头文件。
- `lib/libpq.so.5.5`、`libpq.so`、`libpq.a`。
- GaussDB SHA256 认证所需实现。
- OpenSSL、Kerberos、LDAP、com_err、stdc++ 等配套依赖库。
- ARM64 与 x86_64 两套构建。

用途：

1. 编译 PHP 官方 `pdo_pgsql` 或派生的 `pdo_gaussdb`。
2. 作为运行时客户端库解决标准 PostgreSQL libpq 无法完成 GaussDB SHA256 认证的问题。
3. 为 Linux ARM64/x86_64 分别制作自包含驱动包或容器镜像。

推荐第一阶段使用动态 libpq 包；静态包可用于研究减少运行时依赖，但仍需要核对许可证、OpenSSL/Kerberos 等间接依赖和 PHP 扩展链接参数，不能仅凭文件名假设完全静态。

## Linux ODBC：可作为 PDO_ODBC 对照路线

507 ARM64/x86_64 ODBC 包包含：

- unixODBC：`libodbc.so`、`libodbcinst.so`、`libodbccr.so`。
- GaussDB ODBC：`gsqlodbca.so`、`gsqlodbcw.so`。
- PostgreSQL 兼容名称：`psqlodbca.so`、`psqlodbcw.so`。
- 同版本 `libpq.so.5.5` 及认证/加密依赖。
- VERSION：`GaussDB-Kernel_507.0.0 / 6.0.6.1`。

用途：

- PHP `PDO_ODBC` 的对照测试和企业环境兜底。
- 不建议作为 Linux PDO 主路线，因为比直接 libpq 多一层 unixODBC 和 DSN 配置。

## Windows：现在可以明确支持 PDO_ODBC 路线

507 包中同时有：

- `GaussDB-Kernel_507.0.0_Windows_X64_Odbc.tar.gz`
- `GaussDB-Kernel_507.0.0_Windows_X86_Odbc.tar.gz`

每个压缩包内为 `gsqlodbc.exe`，是 Nullsoft 自解压安装器。外层安装器本身显示为 PE32 并不能说明内部驱动位数；应按包名安装对应 X64/X86 版本。

Windows PHP 推荐组合：

```text
64-bit PHP + 507 Windows X64 GaussDB ODBC + php_pdo_odbc.dll
32-bit PHP + 507 Windows X86 GaussDB ODBC + php_pdo_odbc.dll
```

这条路线不需要 Windows 版 libpq，也不需要自己编译 `pdo_gaussdb.dll`。下一步需在 Windows VM 中实际安装 X64 ODBC、确认驱动注册名称，并运行 PDO_ODBC CRUD/类型/事务测试。

下载目录根部的这些包版本较旧，不应优先用于当前 507：

- `GaussDB-Kernel_505.2.0_Windows_X64_Odbc.tar.gz`
- `GaussDB-Kernel_505.2.1_Windows_X64_Odbc.tar.gz`
- `GaussDB-Kernel_505.2.1_Windows_X86_Odbc.tar.gz`
- `GaussDB_driver.zip` 中的 `V500R002C10` 驱动。

除非 507 ODBC 验证失败并需要做版本回归，否则不要混用 505/V500 客户端。

## Python/JDBC 等包的定位

- 507 Python 驱动可作为查询结果、事务、错误码的行为参照，不应嵌入 PHP。
- 507 JDBC 可作为成熟驱动契约参考，尤其 prepared statement 和类型映射。
- `flink-connector-jdbc-gaussdb*.jar` 面向 Flink，不可直接用于 PHP。
- `gaussdb_sqlalchemy_driver` 和 Windows `pyodbc` wheel 面向 Python/SQLAlchemy，不可直接作为 PHP 驱动，但已有 Windows 测试包说明 ODBC 路线此前已经被用于客户端验证。

## 调整后的交付平台

- Linux ARM64：基于 507 Distributed ARM64 libpq，主交付。
- Linux x86_64：基于 507 Distributed x86_64 libpq，可同步构建。
- Windows x64/x86：先以官方 507 ODBC + PHP PDO_ODBC 交付；是否再开发原生 PDO DLL 取决于 ODBC 测试结果，目前没有必要提前投入。

## 已固化的制品校验值

```text
Linux ARM64 Distributed libpq.so.5.5
7960663fe291eb290204a4a2c0caa956b71948e4d30ea3f4442ea46b0eb1cfb7

Linux x86_64 Distributed libpq.so.5.5
6d7876294a11f5b66676a51556ab3f94d8be58eaa57519b21c2d1ad193eee743

Windows X64 gsqlodbc.exe
5dd95b7c1cc3f28a9494d8e4acaa678496f5ec82d3730a2d5df6cd970c6af87e

Windows X86 gsqlodbc.exe
0fc17a01570fbdcc34bd1d788e1cf36e16bd386723f1d9dfb637d93992e1a007
```
