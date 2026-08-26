# Linux 普通服务器安装、校验与卸载

本文适用于 Linux ARM64/x86_64，不使用 Docker，不修改 GaussDB 服务端。

## 1. 准备文件

- 与服务端版本、部署形态、Linux 发行版和 CPU 架构匹配的 GaussDB 官方 ODBC 包。
- 本项目仓库。
- PHP 8.1+；当前实测为 PHP 8.3。

507 总包可以使用仓库脚本提取：

```bash
./scripts/extract-gaussdb-507-linux-odbc.sh \
  '/secure/path/DBS-GaussDB-driver_aarch64_....tar.gz' \
  build/gaussdb-client/linux-arm64-odbc arm64
```

x86_64 将最后两个参数改为 `linux-x86_64-odbc x86_64`。脚本会校验 `gsqlodbcw.so` 与配套 `libpq.so.5.5` 的 SHA-256，未知制品不会静默通过。

## 2. 安装 PHP 和 unixODBC

发行版包名可能不同。常见示例：

```bash
# Debian/Ubuntu
sudo apt-get install php8.3-cli php8.3-odbc unixodbc patchelf

# RHEL/openEuler/HCE 系列
sudo dnf install php-cli php-pdo php-odbc unixODBC patchelf
```

确认：

```bash
php -r 'var_dump(extension_loaded("pdo_odbc"), PDO::getAvailableDrivers());'
odbcinst -j
```

输出必须包含 `pdo_odbc=true` 和 PDO 驱动 `odbc`。若发行版 PHP 没有 PDO_ODBC，再使用与当前 PHP 完全匹配的 PHP 源码编译官方 `ext/pdo_odbc`；不需要 PDO_PGSQL。

## 3. 安装 GaussDB ODBC

把提取结果复制到独立目录：

```bash
sudo install -d -m 0755 /opt/gaussdb-odbc
sudo cp -a build/gaussdb-client/linux-arm64-odbc/. /opt/gaussdb-odbc/
```

本方案使用系统 unixODBC，移除复制目录内厂商自带的 Driver Manager 和旧 `libstdc++`，保留 GaussDB 驱动及其私有依赖：

```bash
sudo rm -f /opt/gaussdb-odbc/lib/libodbc.so* \
  /opt/gaussdb-odbc/lib/libodbcinst.so* \
  /opt/gaussdb-odbc/lib/libodbccr.so* \
  /opt/gaussdb-odbc/lib/libstdc++.so*

for library in /opt/gaussdb-odbc/lib/*.so*; do
  test -e "$library" || continue
  sudo patchelf --set-rpath /opt/gaussdb-odbc/lib "$library"
done
sudo patchelf --set-rpath /opt/gaussdb-odbc/lib \
  /opt/gaussdb-odbc/odbc/lib/gsqlodbcw.so
```

在 `/etc/odbcinst.ini` 注册：

```ini
[GaussDB Unicode]
Description=GaussDB Unicode ODBC Driver
Driver=/opt/gaussdb-odbc/odbc/lib/gsqlodbcw.so
Setup=/opt/gaussdb-odbc/odbc/lib/gsqlodbcw.so
FileUsage=1
```

校验动态库：

```bash
odbcinst -q -d -n 'GaussDB Unicode'
ldd /opt/gaussdb-odbc/odbc/lib/gsqlodbcw.so
```

`ldd` 不允许出现 `not found`。不要设置全局 `LD_LIBRARY_PATH`，以免 GaussDB 自带 OpenSSL/Kerberos 库影响其他 PHP 模块。

## 4. 安装兼容层

```bash
sudo install -d -m 0755 /opt/gaussdb-php-compat
sudo cp -a composer.json src /opt/gaussdb-php-compat/
```

业务入口加载：

```php
require '/opt/gaussdb-php-compat/src/autoload.php';
```

使用方式见 [`CUSTOMER_USAGE.md`](CUSTOMER_USAGE.md)。这一步就是本仓库代码发挥作用的位置：它处理 M/ORA 模式校验、UTF-8、布尔和二进制差异。

## 5. 连接验证

```bash
export GAUSS_HOST='gaussdb.example.com'
export GAUSS_PORT='5432'
export GAUSS_DATABASE='app_m'
export GAUSS_MODE='M'
export GAUSS_USER='app_user'
read -r -s -p 'GaussDB password: ' GAUSS_PASSWORD
export GAUSS_PASSWORD
php tests/php_compat_integration.php
```

期望 10 项全部通过。ORA 数据库使用 `GAUSS_MODE=O`。

PHP-FPM 场景还要确认 FPM 与 CLI 使用相同 PHP 版本、INI 和扩展目录，并在发布前从实际 Web 进程完成一次连接验证。

## 6. 升级

GaussDB 客户端、PHP、操作系统大版本或 CPU 架构任一变化，都应视为新组合：

1. 将新 ODBC 安装到独立临时目录。
2. 检查架构、SHA-256、RPATH 和 `ldd`。
3. 在测试环境运行 M/O 契约。
4. 维护窗口内原子切换 `/opt/gaussdb-odbc`。
5. 重新加载 PHP-FPM 并执行应用冒烟测试。

## 7. 卸载

先停止引用兼容层的 PHP 服务，然后：

```bash
sudo rm -rf /opt/gaussdb-php-compat
sudo rm -rf /opt/gaussdb-odbc
```

再由管理员从 `/etc/odbcinst.ini` 删除本项目的 `[GaussDB Unicode]` 段；若该驱动还被其他应用使用，不得删除。PDO_ODBC 和 unixODBC 是系统组件，只有确认没有其他程序依赖时才通过发行版包管理器卸载。

卸载客户端不会修改 GaussDB 数据库，也不会删除业务表。
