# 测试制品

测试代码在 Git 仓库中，已实测的四平台 GaussDB ODBC 二进制放在同一仓库的公开 Release 中，避免每次克隆代码都下载约 20MB 二进制。

## 一键获取

在仓库根目录执行：

```bash
make download-test-drivers
```

脚本从以下地址下载并强制校验 SHA-256：

- 文件：[gaussdb-php-test-drivers-v1.zip](https://github.com/jarrenL/GaussDB-PHP-Driver/releases/download/test-assets-v1/gaussdb-php-test-drivers-v1.zip)
- 大小：20,270,579 bytes
- SHA-256：`bdb5d1c4a1d1e9b18422814d353ceaa31677c5d2a92423ca126525f43b46e2a3`

成功后文件落在 `build/gaussdb-client/`。下载、哈希或包结构不符合预期时脚本直接失败。

## 平台对应关系

| 测试平台 | 使用的目录或安装程序 | 后续动作 |
|---|---|---|
| Linux ARM64 | `build/gaussdb-client/linux-arm64-odbc/` | `make build-odbc-arm64`；PHP 7.2 使用 `make build-php72-odbc-arm64` |
| Linux x86_64 | `build/gaussdb-client/linux-x86_64-odbc/` | `make build-odbc-x86_64`；PHP 7.2 使用 `make build-php72-odbc-x86_64` |
| Windows x64 | `build/gaussdb-client/windows-odbc/x64/gsqlodbc.exe` | 配合 x64 PHP、x64 PDO_ODBC 安装测试 |
| Windows x86 | `build/gaussdb-client/windows-odbc/x86/gsqlodbc.exe` | 配合 x86 PHP、x86 PDO_ODBC 安装测试 |

Windows 可在 macOS/Linux 下载仓库及制品后整体复制到测试机，也可直接从 Release 下载 ZIP 并解压到仓库的 `build\gaussdb-client`。

## 使用边界

该 Release 用于复现本项目已记录的测试基线，不代表适配任意 GaussDB 服务端。客户生产环境应优先使用与服务端版本、部署形态、操作系统和 CPU 架构匹配的官方驱动；更换驱动或服务端后必须重新执行完整契约测试。

PHP 运行时、操作系统依赖和 GaussDB 服务端不包含在 Release 中：Linux Docker 测试由现有 Dockerfile 获取 PHP 7.2/8.3 基础镜像；普通服务器和 Windows 测试按安装文档准备对应位数的 PHP/PDO_ODBC。
