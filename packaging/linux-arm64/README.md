# Linux ARM64 packaging

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

提取脚本只接受包含 507.0.0 B071 Distributed/Euler2.10/ARM64 客户端的总包，并校验 `libpq.so.5.5` SHA-256。生成的 `build/` 目录不会提交到 Git。

镜像不会让客户端包内的旧 `libstdc++.so.6` 和 `libcurl.so.4` 覆盖 PHP/Debian 系统版本；实测这两组库会造成 PHP 的 ICU/cURL 依赖冲突。libpq 使用系统较新 libstdc++，其余认证和加密相关依赖继续从 GaussDB 客户端目录加载。
