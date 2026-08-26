# Legacy: PDO_PGSQL PoC known limitations

> 仅供早期基线追溯。当前 PDO_ODBC 兼容层限制见仓库根目录 `KNOWN_LIMITATIONS.md`。

本文件描述当前 **Phase 1 跨平台 PoC** 的实测边界。当前 Linux 驱动是 PHP 官方 `PDO_PGSQL + GaussDB libpq`，Windows 是 `PDO_ODBC + GaussDB ODBC`；独立 `pdo_gaussdb` 扩展尚未实现。

| 范围 | 实测表现 | 影响/临时规避 | 计划 |
|---|---|---|---|
| Linux BOOLEAN | `PDO::PARAM_BOOL` 发送 `t/f`，M 模式期望 `1/0` | 当前绑定整数 `0/1` | Phase 2 类型绑定适配 |
| Linux VARBINARY | 普通字符串参数中的 NUL 会被截断；小值使用 `PDO::PARAM_LOB` 可完整回显 | 二进制参数优先 `PDO::PARAM_LOB`，上线前按最大长度复验 | Phase 2 二进制格式适配 |
| Windows VARBINARY/BLOB | ODBC 可能返回十六进制文本，BLOB 也不能按原字节直接回显 | 应用层不得假定返回值已经是原始字节 | Phase 2 统一二进制解码 |
| TIMESTAMP | 两条路径均会丢失微秒 | 当前只承诺秒级精度 | Phase 2 时间类型适配 |
| M TEXT | 本地 507 M 实测 65,535 字节成功、65,536 字节开始报 SQLSTATE `22001` | 大文本拆分或改用经验证的其他存储方案；不是 libpq 缓冲区推断 | 服务端类型调研 + Phase 2 LOB 策略 |
| M 私有 OID | BOOLEAN 列返回 OID `5545`（实际目录类型 `int1`），VARBINARY 返回 `9881`；PDO_PGSQL 均没有 `native_type` | 业务暂不依赖 `getColumnMeta()['native_type']` | Phase 2 OID 映射表 |
| `lastInsertId()` | Linux 无可用 `lastval` 时为 SQLSTATE `55000`；Windows ODBC 为 `IM001` 不支持；M `AUTO_INCREMENT` 与 PDO 语义未对齐 | 插入后使用业务键或显式查询，不依赖 `lastInsertId()` | Phase 2 identity 语义适配 |
| BYTEA/CLOB | 类型目录存在，但当前 M 模式建表语法拒绝 `BYTEA` 和 `CLOB` | 不将 PostgreSQL BYTEA/CLOB 语法视为 M 可用能力 | 后续确认 M 对应类型语法 |
| Windows 字符集 | 中文/emoji 在当前 ODBC 基线存在乱码 | 暂不承诺无损 Unicode；需明确客户端编码 | Phase 2 Windows 编码适配 |
| SSL | 本地实例 `ssl=off`；`sslmode=require` 正确失败并报告服务端不支持 SSL | 当前没有 TLS 成功基线，不能用于要求 SSL 的生产验收 | 在独立启用证书的实例执行单向/双向 SSL 验收 |
| PHP 版本 | 当前只实测 PHP 8.3 | 8.1、8.2、8.4 不在当前兼容承诺内 | Phase 2 CI matrix |
| psycopg 对照 | 尚未用 psycopg 执行同一份扩展契约 | 当前只有 PDO_PGSQL 与 PDO_ODBC 对照 | Phase 2 增加 Python oracle/baseline |

网络故障注入、自动重连、死锁、长时间压力、Laravel/Doctrine、RPM/DEB 和性能测试属于后续阶段，不是当前 PoC 的已交付能力。
