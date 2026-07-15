# Task Breakdown — 科特迪瓦 / 喀麦隆 库切换

## Goal
在页面上切换 **科特迪瓦（阿里云）** 与 **喀麦隆（Neon）** 两套远程 PostgreSQL，各自读取 `inventory` / `transactions`。

## Tasks

- [x] **T1** 建立 Task / Test Rules / Audit Log
- [x] **T2** `api.php`：按 `region` 参数选择数据库配置（`ci` = 科特迪瓦，`cm` = 喀麦隆）；连接失败返回明确错误
- [x] **T3** `index.html`：页头增加国家切换控件；切换后重置状态并重新 `ping` / 拉表 / 拉筛选 / 拉数据；所有 API 请求携带当前 `region`
- [x] **T4** 按 `docs/04-Test-Rules.md` 验证双库可读与切换行为

## Out of scope
- 写入 / 编辑库存
- 项目级子库拆分（若同库多项目，后续再做）
- `glass_blur_index.php` 合并版同步（本轮以 `index.html` + `api.php` 为准）
