# Test Rules — 科特迪瓦 / 喀麦隆 库切换

## TR-01 默认区域
- **Conditions**: 首次打开页面
- **Inputs**: 无
- **Expected**: 默认选中 `科特迪瓦`（`region=ci`）；连接状态可成功或显示该库真实错误；表列表来自阿里云库

## TR-02 切换到喀麦隆
- **Conditions**: 页面已加载
- **Inputs**: 点击「喀麦隆」
- **Expected**: 当前 `region=cm`；重新 ping Neon；侧边栏表列表与数据来自喀麦隆库；筛选选项刷新

## TR-03 切回科特迪瓦
- **Conditions**: 当前在喀麦隆
- **Inputs**: 点击「科特迪瓦」
- **Expected**: 数据与筛选恢复为阿里云库内容；分页重置为第 1 页

## TR-04 API region 参数
- **Conditions**: 直接请求 API
- **Inputs**:
  - `api.php?action=ping&region=ci`
  - `api.php?action=ping&region=cm`
  - `api.php?action=ping&region=xx`
- **Expected**: `ci`/`cm` 返回 `success:true`（库可达时）；非法 region 返回 `success:false` 且错误信息明确

## TR-05 业务查询携带 region
- **Conditions**: 已选喀麦隆并打开库存表
- **Inputs**: 筛选 / 翻页 / 导出
- **Expected**: 请求均带 `region=cm`；结果与喀麦隆库一致；导出文件内容匹配当前区域筛选结果

## TR-06 连接失败提示
- **Conditions**: 目标库不可达或凭据错误（模拟或真实失败）
- **Inputs**: 切换到失败区域并刷新
- **Expected**: 状态点为错误；Toast/文案提示连接失败，不静默空白
