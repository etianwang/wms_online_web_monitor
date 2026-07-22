# Test Rules — 多区域库切换

## TR-01 默认区域
- **Conditions**: 首次打开页面（无 localStorage）
- **Inputs**: 无
- **Expected**: 默认选中 `科特迪瓦`（`region=ci`）

## TR-02 切换到喀麦隆
- **Conditions**: 页面已加载
- **Inputs**: 点击「喀麦隆」
- **Expected**: `region=cm`；表/筛选/数据来自喀麦隆 Neon

## TR-03 切换到科特迪瓦精装
- **Conditions**: 页面已加载
- **Inputs**: 点击「科特迪瓦精装」
- **Expected**: `region=ci_jz`；重连精装 Neon；侧栏映射库存/存取记录

## TR-04 切回科特迪瓦
- **Conditions**: 当前在精装或喀麦隆
- **Inputs**: 点击「科特迪瓦」
- **Expected**: 恢复阿里云库数据；分页回到第 1 页

## TR-05 API region 参数
- **Inputs**:
  - `api.php?action=ping&region=ci`
  - `api.php?action=ping&region=ci_jz`
  - `api.php?action=ping&region=cm`
  - `api.php?action=ping&region=xx`
- **Expected**: 前三个在配置正确且库可达时 `success:true`；非法 region 返回明确可用列表

## TR-06 业务查询携带 region
- **Conditions**: 已选科特迪瓦精装并打开库存
- **Inputs**: 筛选 / 翻页 / 导出
- **Expected**: 请求均带 `region=ci_jz`

## TR-07 连接失败提示
- **Conditions**: 目标库不可达
- **Expected**: 状态点错误；Toast 含区域标签与原因

## TR-08 库存行存取记录弹窗
- **Conditions**: 已打开库存表
- **Inputs**: 点击某行「📋 查看」
- **Expected**: 弹窗显示该 `item_id` 全部存取记录（日期/类型/数量/来源/项目）；无记录显示「暂无存取记录」；Esc 或点遮罩关闭
