# Linux ARM64 packaging

> Legacy PDO_PGSQL PoC。M/O 正式实现请使用 `packaging/linux-odbc/`。

目标客户端包：GaussDB 507.0.0 B071 Distributed / Euler2.10 / ARM64 libpq。

当前已验证其 `libpq.so.5.5` 与本地运行实例配套客户端完全一致。后续在这里加入构建容器、依赖清单、RPATH/loader 配置和安装脚本；GaussDB 二进制本身不提交到 Git。

## 原型构建

从本地授权驱动总包提取并校验客户端：

```bash
make extract-client \
  GAUSSDB_DRIVER_ARCHIVE='/path/to/DBS-GaussDB-driver_aarch64_....tar.gz'
```

构建 PHP 8.3 ARM64 原型镜像：

```bash
make build-php
./packaging/linux-arm64/verify-image.sh
```

提取脚本默认接受 507.0.0 B071 Distributed/Euler2.10/ARM64 客户端并严格校验 `libpq.so.5.5` SHA-256。升级驱动时可显式设置 `GAUSSDB_PACKAGE_SERIES`、`GAUSSDB_RELEASE_VERSION`、`GAUSSDB_BUILD_VERSION` 和 `GAUSSDB_EXPECTED_LIBPQ_SHA256`；不会静默接受未知二进制。生成的 `build/` 目录不会提交到 Git。

镜像不会让客户端包内的旧 `libstdc++.so.6` 和 `libcurl.so.4` 覆盖 PHP/Debian 系统版本；实测这两组库会造成 PHP 的 ICU/cURL 依赖冲突。libpq 使用系统较新 libstdc++，其余认证和加密相关依赖继续从 GaussDB 客户端目录加载。

PDO 扩展直接使用 GaussDB 507 的头文件和 `libpq.so.5.5` 编译、链接。构建阶段会比较 `pdo_pgsql.so`/`pgsql.so` 所需的 `PQ*` 符号与 GaussDB libpq 的导出符号，缺失时立即失败。运行时通过扩展和客户端库自身的 RPATH 定位私有依赖，不设置全局 `LD_LIBRARY_PATH`，因此客户端随包的 SSL/Kerberos 库不会影响无关 PHP 扩展。
