# Agent Audit Log

## 2026-07-12 — Start: 科特迪瓦 / 喀麦隆 页面切换

- **Intent**: 页面上切换科特迪瓦（阿里云）与喀麦隆（Neon）两套只读库存库
- **Planned tasks**: T1 文档 → T2 api.php 多 region → T3 index.html 切换控件 → T4 按 Test Rules 验证
- **Notes**: 本地无 PHP；喀麦隆连接串已提供；本轮不同步 `glass_blur_index.php`

## 2026-07-12 — Done: 双区域切换落地

- **Intent**: 完成页头国家切换与后端多库路由
- **Changed**:
  - `api.php`：`region=ci|cm` 选择阿里云 / Neon；`ping` 回传区域标签；Neon 使用 `sslmode=require`
  - `index.html`：科特迪瓦 / 喀麦隆切换；请求统一带 `region`；切换重置并重连；`localStorage` 记住上次区域
  - `style.css`：区域切换按钮样式
  - `docs/*`：Task / Test Rules / Audit Log
  - `scripts/verify_regions.py`：双库连通与表映射冒烟
- **Tests**:
  - `python scripts/verify_regions.py`：ci / cm 均可连；两侧均能映射库存+存取记录
  - 科特迪瓦：`Inventory` 887 行，`Transactions` 225 行
  - 喀麦隆：`Inventory` / `transactions` 均为 **0 行**（表结构可用，数据尚未同步）
  - TR-01~05：逻辑已实现；TR-04 非法 region 由 API 返回 400；TR-06 依赖部署环境实测
- **Acceptance**: T1–T4 勾选完成；页面部署后可点切换验证 UI
- **Next**: 若喀麦隆需按「项目」再分库/分表，另开任务；可选同步 `glass_blur_index.php`

## 2026-07-12 — 表显示名对齐

- **Intent**: 确认真实表名：库存=`Inventory`，存取记录=`transactions`；界面仍显示「存取记录」
- **Changed**: 保持显示名「存取记录」；映射用 `strtolower` 兼容大小写表名
- **Tests**: 无
- **Acceptance**: 侧栏为「库存」「存取记录」
- **Next**: 部署后目视确认

## 2026-07-12 — Fix HTTP 500 数据加载

- **Intent**: 修复「数据加载失败: HTTP 500」并露出真实后端错误
- **Changed**:
  - `api.php`：表名大小写解析 `resolve_table_name`；LIMIT/OFFSET 改整型字面量；路由 `try/catch`；PDO emulate prepares
  - `index.html`：`apiFetch` 解析 500 响应体中的 `error` 字段
- **Tests**: 待部署后复测；若仍失败 Toast 应显示具体 SQL/连接错误
- **Acceptance**: 不再只显示笼统 HTTP 500；`Inventory`/`transactions` 大小写可查
- **Next**: 用户部署后反馈具体 Toast 文案（若仍失败）

## 2026-07-12 — 喀麦隆 Neon SSL 不可用

- **Intent**: 定位「sslmode value require invalid when SSL support is not compiled in」
- **Changed**: `api.php` 对该错误给出可操作中文说明
- **Tests**: 根因属服务器 PHP/libpq 未编 SSL，应用层无法绕过 Neon 强制 SSL
- **Acceptance**: Toast 说明需启用带 OpenSSL 的 pgsql，或改喀麦隆托管方式
- **Next**: 等用户选方案——①服务器启用 SSL pgsql ②喀麦隆数据迁到阿里云同主机 ③另外部署带 SSL 的代理 API

## 2026-07-13 — 喀麦隆 Neon 已连通

- **Intent**: 宝塔 PHP 8.2 的 pdo_pgsql 改链系统 libpq（带 SSL）
- **Changed**: 服务器侧重编 `pdo_pgsql` → `/usr/lib/.../libpq.so.5` + `libssl`；Neon `ping` 返回 OK
- **Tests**: CLI Neon PDO 连接 OK；用户确认页面侧已 OK
- **Acceptance**: 科特迪瓦 / 喀麦隆切换可用
- **Next**: 喀麦隆库若仍为空表，等业务数据同步；勿在宝塔面板重装 pgsql 扩展



