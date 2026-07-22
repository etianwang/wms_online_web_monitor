# Task Breakdown — 库存行存取记录弹窗

## Goal
库存表每一行增加「存取记录」按钮，点击弹窗显示该材料全部存取记录，无需去存取记录页筛选。

## Tasks

- [x] **T10** 更新 Task / Test Rules / Audit Log
- [x] **T11** `api.php`：`action=item_transactions`，按 `item_id` 返回全部记录
- [x] **T12** `index.html`：库存表操作列 + 弹窗 + 拉取渲染
- [x] **T13** `style.css`：弹窗样式

## Out of scope
- 弹窗内分页（记录上限 5000）
- `glass_blur_index.php` 同步
