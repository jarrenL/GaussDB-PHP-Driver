# Legacy: Linux PDO_PGSQL 自助构建与安装

> 本文档仅保留早期验证记录。M 模式当前使用 PDO_ODBC，最新流程见仓库根目录 `LINUX_BUILD_INSTALL.md`。

本文面向在普通 Linux 应用服务器上使用 PHP 8.3，通过 `PDO_PGSQL + GaussDB libpq` 连接 GaussDB 507 M 模式数据库的客户。整个流程不使用容器。

当前方案不会修改 GaussDB 服务端，也不会向 Git 仓库提交 GaussDB 官方二进制。客户需要自行准备有授权、与服务端版本和 CPU 架构匹配的 GaussDB 驱动总包。

## 1. 安装模型

```text
PHP 应用
  -> pdo_pgsql.so
  -> /opt/gaussdb-client/lib/libpq.so.5.5
  -> 网络中的 GaussDB 服务器
```

`pdo_pgsql.so` 是 PHP 二进制扩展，不是可以通过 `require` 加载的 PHP 源文件。它必须与服务器上的 PHP ABI、CPU 架构和线程安全模式一致。

## 2. 当前支持范围

| 项目 | 当前验证范围 |
|---|---|
| GaussDB | Kernel 507.0.0，M 模式 |
| PHP | 8.3，NTS |
| Linux CPU | ARM64、x86_64 |
| PHP DSN | `pgsql:` |
| 客户端 | GaussDB 507 配套 `libpq.so.5.5` |

PHP 8.1、8.2、8.4，ZTS PHP，以及 GaussDB 505 等其他版本必须重新构建并执行契约测试，不能直接复用本项目的 507 验证结论。

## 3. 构建前检查

确认操作系统和 CPU 架构：

```bash
uname -a
uname -m
getconf LONG_BIT
```

确认 PHP 环境：

```bash
php -v
php -i | grep -E 'Thread Safety|PHP API|PHP Extension|Zend Extension'
php-config --version
php-config --extension-dir
php --ini
```

要求：

- `uname -m` 为 `aarch64` 时使用 ARM64 GaussDB 客户端。
- `uname -m` 为 `x86_64` 时使用 x86_64 GaussDB 客户端。
- PHP、`phpize`、`php-config` 和 PHP 开发头文件必须来自同一套 PHP 安装。
- 已安装 C 编译器、`make`、`autoconf`、`binutils`、`file`、`patchelf` 和 PHP 开发包。
- 应用服务器可以访问 GaussDB 主机和监听端口。

常见发行版的依赖包名称不同，可由系统管理员安装对应的编译工具和 PHP 开发包。不要为了方便而在同一台服务器混用多个来源的 PHP、`phpize` 和 `php-config`。

## 4. 获取并校验 GaussDB 客户端

克隆本仓库并切换到已交付的版本：

```bash
git clone https://github.com/jarrenL/GaussDB-PHP-Driver.git
cd GaussDB-PHP-Driver
git checkout feature/cross-platform-prototype
```

客户从有授权的介质准备 GaussDB 507 驱动总包，然后按服务器架构提取。

ARM64：

```bash
make extract-client-arm64 \
  GAUSSDB_DRIVER_ARCHIVE='/secure/path/DBS-GaussDB-driver_aarch64_507.tar.gz'

client_source="$PWD/build/gaussdb-client/linux-arm64"
```

x86_64：

```bash
make extract-client-x86_64 \
  GAUSSDB_DRIVER_ARCHIVE='/secure/path/DBS-GaussDB-driver_x86_64_507.tar.gz'

client_source="$PWD/build/gaussdb-client/linux-x86_64"
```

提取脚本会严格检查包结构和当前已验证的 `libpq.so.5.5` SHA-256，不会静默接受未知二进制。若客户驱动包的版本、构建号或校验值不同，应先建立新版本配置并完成测试，不要直接关闭校验。

确认文件架构：

```bash
file "$client_source/lib/libpq.so.5.5"
sha256sum "$client_source/lib/libpq.so.5.5"
```

## 5. 安装专用 GaussDB 客户端目录

把提取结果复制到专用目录，不要修改客户保存的原始驱动介质：

```bash
sudo install -d -m 0755 /opt/gaussdb-client
sudo cp -a "$client_source/." /opt/gaussdb-client/
sudo chown -R root:root /opt/gaussdb-client
sudo find /opt/gaussdb-client -type d -exec chmod 0755 {} \;
```

不要将 `/opt/gaussdb-client/lib` 写入 `/etc/ld.so.conf` 或系统全局 `LD_LIBRARY_PATH`。GaussDB 驱动包携带的加密、认证、C++ 和 curl 依赖可能影响其他 PHP 扩展。

为客户端目录中的 ELF 动态库设置私有 RPATH：

```bash
find /opt/gaussdb-client/lib -type f -name '*.so*' -print0 |
while IFS= read -r -d '' library; do
    if file "$library" | grep -q 'ELF'; then
        sudo patchelf --set-rpath /opt/gaussdb-client/lib "$library"
    fi
done

patchelf --print-rpath /opt/gaussdb-client/lib/libpq.so.5.5
ldd /opt/gaussdb-client/lib/libpq.so.5.5
```

RPATH 应为 `/opt/gaussdb-client/lib`，`ldd` 不应出现 `not found`。如果客户端包内的 `libstdc++.so` 或 `libcurl.so` 与服务器 PHP 依赖冲突，应停止安装并由交付方针对该操作系统制作依赖白名单，不能直接设置全局库路径规避。

## 6. 编译 PDO_PGSQL

下载与 `php -v` 完全一致的 PHP 官方源码包。以下以 PHP 8.3.x 为例：

```bash
mkdir -p "$HOME/gaussdb-php-build"
cd "$HOME/gaussdb-php-build"
tar -xf /secure/path/php-8.3.x.tar.xz
cd php-8.3.x/ext/pdo_pgsql

phpize
env LD_LIBRARY_PATH=/opt/gaussdb-client/lib \
  ./configure \
    --with-php-config="$(command -v php-config)" \
    --with-pdo-pgsql=/opt/gaussdb-client

env LD_LIBRARY_PATH=/opt/gaussdb-client/lib \
  make -j"$(getconf _NPROCESSORS_ONLN)"
```

构建结束后检查扩展：

```bash
file modules/pdo_pgsql.so
ldd modules/pdo_pgsql.so
```

为扩展设置RPATH，并确认它使用GaussDB libpq：

```bash
patchelf --set-rpath /opt/gaussdb-client/lib modules/pdo_pgsql.so
patchelf --print-rpath modules/pdo_pgsql.so
ldd modules/pdo_pgsql.so | grep -E 'libpq|not found'
```

结果中的 `libpq` 必须指向：

```text
/opt/gaussdb-client/lib/libpq.so.5.5
```

检查PDO_PGSQL需要的 `PQ*` 符号是否都由GaussDB libpq提供：

```bash
nm -D --defined-only /opt/gaussdb-client/lib/libpq.so.5.5 |
  awk '{print $3}' | sort -u > /tmp/gaussdb-libpq-symbols

nm -D --undefined-only modules/pdo_pgsql.so |
  awk '{print $2}' | grep '^PQ' | sort -u > /tmp/pdo-pgsql-required-symbols

comm -23 /tmp/pdo-pgsql-required-symbols /tmp/gaussdb-libpq-symbols
```

最后一条命令必须没有输出；有输出表示客户端缺少扩展需要的符号，不能继续安装。

## 7. 安装扩展

获取PHP扩展目录并备份可能存在的旧文件：

```bash
extension_dir="$(php-config --extension-dir)"
test -d "$extension_dir"

if test -f "$extension_dir/pdo_pgsql.so"; then
    sudo cp -a "$extension_dir/pdo_pgsql.so" \
      "$extension_dir/pdo_pgsql.so.before-gaussdb"
fi

sudo install -m 0755 modules/pdo_pgsql.so "$extension_dir/pdo_pgsql.so"
```

通过 `php --ini` 找到当前 PHP 扫描的附加配置目录，在该目录创建 `30-pdo-pgsql-gaussdb.ini`：

```ini
extension=pdo_pgsql
```

如果已有其他配置加载 `pdo_pgsql`，应先由管理员禁用旧配置，避免重复加载。不要同时启用系统包提供的另一个 `pdo_pgsql.so`。

CLI验证通过后，按照服务器实际部署方式重新加载服务：

```bash
# 以下只执行服务器实际使用的一种服务，名称由管理员确认：
sudo systemctl restart php-fpm
# 或 sudo systemctl restart php8.3-fpm
# 或重新加载使用 mod_php 的 Web 服务器
```

## 8. 安装后校验

确认扩展和PDO驱动：

```bash
php --ri pdo_pgsql
php -r 'var_dump(extension_loaded("pdo_pgsql"));'
php -r 'print_r(PDO::getAvailableDrivers());'
```

输出应包含 `pgsql`。再次检查实际动态链接：

```bash
extension_dir="$(php-config --extension-dir)"
patchelf --print-rpath "$extension_dir/pdo_pgsql.so"
ldd "$extension_dir/pdo_pgsql.so" | grep -E 'libpq|not found'
```

如果Web应用由PHP-FPM运行，还要在与PHP-FPM相同的用户、配置和环境下验证，不能只以CLI结果代替Web运行环境验收。

## 9. 连接正常GaussDB服务器

确认网络连通后设置测试参数：

```bash
export GAUSS_HOST='gaussdb.example.com'
export GAUSS_PORT='5432'
export GAUSS_DATABASE='gdbdrv_m_test'
export GAUSS_USER='gauss_php_test'
read -r -s -p 'GaussDB password: ' GAUSS_PASSWORD
export GAUSS_PASSWORD
printf '\n'
```

执行快速连接测试：

```bash
php examples/pdo_pgsql_prototype.php
php tests/php_pdo_pgsql_smoke.php
```

PHP业务代码使用：

```php
<?php

$pdo = new PDO(
    'pgsql:host=gaussdb.example.com;port=5432;dbname=gdbdrv_m_test',
    getenv('GAUSS_USER'),
    getenv('GAUSS_PASSWORD'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
```

当前DSN是 `pgsql:`，不是 `gaussdb:`。业务代码不需要 `require` 本仓库中的PHP文件。

## 10. 升级

升级PHP、GaussDB客户端、Linux大版本或CPU架构时，应视为一次新构建：

1. 备份当前扩展、INI配置和 `/opt/gaussdb-client` 清单。
2. 使用目标PHP的源码和 `php-config` 重新编译。
3. 在独立测试环境完成动态链接检查和契约测试。
4. 停止或排空业务流量后替换扩展和客户端库。
5. 重新加载PHP-FPM并执行连接、CRUD和事务验证。

不要只替换 `libpq.so.5.5` 而保留未经验证的旧依赖库组合。

## 11. 卸载与回滚

先停止使用该扩展的PHP服务，再删除专用INI配置。配置路径必须以 `php --ini` 的实际结果为准：

```bash
# 示例路径，执行前由管理员确认：
sudo rm /etc/php.d/30-pdo-pgsql-gaussdb.ini
# Debian/Ubuntu也可能位于 /etc/php/8.3/mods-available/ 或对应 conf.d 目录。
```

如果安装前存在备份扩展，则恢复：

```bash
extension_dir="$(php-config --extension-dir)"

if test -f "$extension_dir/pdo_pgsql.so.before-gaussdb"; then
    sudo mv "$extension_dir/pdo_pgsql.so.before-gaussdb" \
      "$extension_dir/pdo_pgsql.so"
else
    sudo rm "$extension_dir/pdo_pgsql.so"
fi
```

只有确认没有其他应用使用专用客户端目录后，才删除 `/opt/gaussdb-client`。随后重新加载PHP-FPM并运行：

```bash
php -m | grep -i pgsql || true
php -r 'print_r(PDO::getAvailableDrivers());'
```

卸载不会修改GaussDB服务器，也不会删除数据库中的业务数据。

## 12. 常见问题

### `could not find driver`

PHP没有加载 `pdo_pgsql.so`。检查 `php --ini`、INI目录、扩展目录和PHP-FPM使用的配置是否与CLI一致。

### `none of the server's SASL authentication mechanisms are supported`

通常表示PDO_PGSQL加载了系统PostgreSQL libpq，而不是GaussDB libpq。检查：

```bash
ldd "$(php-config --extension-dir)/pdo_pgsql.so" | grep libpq
```

### `libssl.so`、`libcrypto.so` 或其他库 `not found`

检查 `pdo_pgsql.so`、`libpq.so.5.5` 和GaussDB配套ELF库的RPATH。不要通过设置系统全局 `LD_LIBRARY_PATH` 临时掩盖问题。

### CLI成功但Web失败

CLI与PHP-FPM可能使用不同的PHP二进制、INI目录、扩展目录或服务用户。分别检查CLI和PHP-FPM配置，并重新加载实际提供Web请求的服务。

### PHP启动时崩溃或其他扩展异常

停止发布并回滚旧扩展。重点检查GaussDB客户端包携带的OpenSSL、`libstdc++`、curl、Kerberos库是否与服务器其他PHP扩展发生冲突。

## 13. 验收要求

客户完成自助构建后，至少保存以下证据：

- `uname -m`、`php -v`、PHP API和线程安全模式。
- GaussDB驱动包名称及SHA-256。
- `pdo_pgsql.so`及 `libpq.so.5.5` 的SHA-256。
- `patchelf --print-rpath` 和 `ldd` 输出。
- PDO驱动列表。
- 连接、CRUD、参数绑定和事务回滚测试结果。
- 与业务有关的已知限制评估。

当前已知限制见 [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md)，完整公共测试范围见 [tests/README.md](tests/README.md)。
