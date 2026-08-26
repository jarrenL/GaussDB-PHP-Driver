# Linux x86_64 packaging

> Legacy PDO_PGSQL PoC。M/O 正式实现请使用 `packaging/linux-odbc/`。

目标客户端包：GaussDB 507.0.0 B071 Distributed / Euler2.10 / x86_64 libpq。

本平台与 ARM64 使用同一扩展源码，只在构建镜像、工具链、客户端库和产物架构上不同。

## 原型构建

```bash
make extract-client-x86_64 \
  GAUSSDB_DRIVER_ARCHIVE='/path/to/DBS-GaussDB-driver_x86_64_....tar.gz'
make build-php-x86_64
./packaging/linux-x86_64/verify-image.sh
```

在 ARM64 主机上，Docker Desktop 通过 `linux/amd64` 模拟构建和运行；正式发布仍需在原生 x86_64 CI runner 上复验性能和 ABI。

与 ARM64 构建一致，PDO 扩展直接使用 GaussDB 507 头文件和 libpq 编译、链接，并在构建时检查 `PQ*` 符号闭包。运行时通过 RPATH 精确加载 GaussDB `libpq.so.5.5` 及其私有依赖，不设置全局 `LD_LIBRARY_PATH`。
