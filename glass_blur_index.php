<?php
// ============================================================
//  弘盛机电 · 非洲库存查看器  —  合并版 index.php
//  前端 HTML + 后端 API 二合一；有 action 参数时返回 JSON，否则输出页面
// ============================================================

// ---------- 数据库配置（原 config.php 内容，请按实际填写） ----------
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

define('DB_HOST',    'pgm-gw8ffg06e16gfgcwho.pgsql.germany.rds.aliyuncs.com');
define('DB_PORT',    '5432');
define('DB_NAME',    'postgres');
define('DB_USER',    'Honsen_Admin');
define('DB_PASSWORD','!66778899HONSEN');
define('DB_TIMEOUT', 8);


$TABLE_NAME_MAP = [
    'inventory'    => ['display' => '库存'],
    'transactions' => ['display' => '存取记录'],
];

function getDBConnection() {
    if (!extension_loaded('pdo_pgsql')) {
        return null;
    }
    $dsn = 'pgsql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';connect_timeout='.DB_TIMEOUT;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET timezone TO 'UTC'");
        return $pdo;
    } catch (PDOException $e) {
        error_log('[DB连接失败] ' . $e->getMessage());
        return null;
    }
}

function fetchTableNames($conn) {
    $stmt = $conn->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
// ---------- 数据库配置结束 ----------


// ============================================================
//  API 模式：有 action 参数时输出 JSON 并退出
// ============================================================
$action = $_GET['action'] ?? '';

if ($action !== '') {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    header('Content-Type: application/json; charset=utf-8');

    if (!extension_loaded('pdo_pgsql')) {
        echo json_encode(['success' => false, 'error' => 'PDO PostgreSQL 扩展未安装'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    function apiSuccess($data) {
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    }
    function apiError($msg) {
        echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $conn = getDBConnection();
    if (!$conn) apiError('无法连接到数据库');

    try {
        switch ($action) {

            // ── ping ──────────────────────────────────────────────
            case 'ping':
                $conn->query('SELECT 1');
                apiSuccess('pong');

            // ── tables ────────────────────────────────────────────
            case 'tables':
                $name_map = [
                    'Inventory'    => '库存',
                    'Transactions' => '存取记录',
                ];
                $display_order = ['库存', '存取记录'];
            
                $sql = "SELECT table_name FROM information_schema.tables 
                        WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name";
                $rows = $conn->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            
                $by_display = [];
                foreach ($rows as $actual) {
                    if (isset($name_map[$actual])) {
                        $by_display[$name_map[$actual]] = $actual; // 同名后者覆盖前者
                    }
                }
            
                $result = [];
                foreach ($display_order as $dname) {
                    if (isset($by_display[$dname])) {
                        $result[] = ['actual' => $by_display[$dname], 'display' => $dname];
                    }
                }
                apiSuccess($result);

            // ── filter_opts ───────────────────────────────────────
            case 'filter_opts':
                $invTable   = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['inventory_table']   ?? 'Inventory');
                $transTable = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['transactions_table'] ?? 'Transactions');

                $opts = [
                    'inventory'    => ['category' => [], 'domain' => [], 'location' => [], 'cabinet' => []],
                    'transactions' => ['project' => []],
                ];

                foreach (['category','domain','location','cabinet'] as $col) {
                    $stmt = $conn->query("SELECT DISTINCT $col FROM \"$invTable\" WHERE $col IS NOT NULL ORDER BY $col");
                    $opts['inventory'][$col] = $stmt->fetchAll(PDO::FETCH_COLUMN);
                }
                $stmt = $conn->query("SELECT DISTINCT project_ref FROM \"$transTable\" WHERE project_ref IS NOT NULL ORDER BY project_ref");
                $opts['transactions']['project'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
                apiSuccess($opts);

            // ── inventory ─────────────────────────────────────────
            case 'inventory':
                $t        = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table'] ?? 'Inventory');
                $keyword  = $_GET['keyword']  ?? '';
                $category = $_GET['category'] ?? '全部';
                $domain   = $_GET['domain']   ?? '全部';
                $location = $_GET['location'] ?? '全部';
                $cabinet  = $_GET['cabinet']  ?? '全部';
                $status   = $_GET['status']   ?? '全部';

                $where = []; $params = [];
                if ($keyword  !== '')     { $where[] = 'name ILIKE :kw';        $params[':kw']  = "%$keyword%"; }
                if ($category !== '全部') { $where[] = 'category = :cat';       $params[':cat'] = $category; }
                if ($domain   !== '全部') { $where[] = 'domain = :dom';         $params[':dom'] = $domain; }
                if ($location !== '全部') { $where[] = 'location = :loc';       $params[':loc'] = $location; }
                if ($cabinet  !== '全部') { $where[] = 'cabinet = :cab';        $params[':cab'] = $cabinet; }
                if ($status === 'out')    { $where[] = 'current_stock = 0'; }
                elseif ($status === 'low'){ $where[] = 'current_stock > 0 AND current_stock < min_stock'; }
                elseif ($status === 'normal'){ $where[] = 'current_stock >= min_stock'; }

                $sql = "SELECT * FROM \"$t\"" . ($where ? ' WHERE '.implode(' AND ',$where) : '') . ' ORDER BY id';
                $start = microtime(true);
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                $dur  = round(microtime(true) - $start, 3);

                $total    = count($rows);
                $warning  = 0; $out = 0; $cats = []; $doms = []; $locs = []; $cabs = [];
                foreach ($rows as $r) {
                    $cur = (int)($r['current_stock'] ?? -1);
                    $min = (int)($r['min_stock'] ?? 0);
                    if ($cur === 0) $out++;
                    elseif ($cur > 0 && $cur < $min) $warning++;
                    $cats[$r['category'] ?? ''] = 1;
                    $doms[$r['domain']   ?? ''] = 1;
                    $locs[$r['location'] ?? ''] = 1;
                    $cabs[$r['cabinet']  ?? ''] = 1;
                }
                apiSuccess([
                    'rows'     => $rows,
                    'total'    => $total,
                    'duration' => $dur,
                    'stats'    => [
                        'total'     => $total,
                        'warning'   => $warning,
                        'out'       => $out,
                        'categories'=> count($cats),
                        'domains'   => count($doms),
                        'locations' => count($locs),
                        'cabinets'  => count($cabs),
                    ],
                ]);

            // ── transactions ──────────────────────────────────────
            case 'transactions':
                $t      = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table']           ?? 'Transactions');
                $invT   = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['inventory_table'] ?? 'Inventory');
                $keyword    = $_GET['keyword']    ?? '';
                $type       = $_GET['type']       ?? '全部';
                $project    = $_GET['project']    ?? '全部';
                $startDate  = $_GET['start_date'] ?? '';
                $endDate    = $_GET['end_date']   ?? '';

                $where = []; $params = [];
                if ($keyword !== '')     { $where[] = 'I.name ILIKE :kw';        $params[':kw']  = "%$keyword%"; }
                if ($type    !== '全部') { $where[] = 'T.type = :type';           $params[':type']= $type; }
                if ($project !== '全部') { $where[] = 'T.project_ref = :proj';    $params[':proj']= $project; }
                if ($startDate !== '')   { $where[] = 'T.date >= :sd';            $params[':sd']  = $startDate; }
                if ($endDate   !== '')   {
                    $ed = date('Y-m-d', strtotime($endDate . ' +1 day'));
                    $where[] = 'T.date < :ed';
                    $params[':ed'] = $ed;
                }

                $sql = "SELECT T.id, I.name AS item_name, I.category AS item_category,
                               I.domain AS item_domain, T.item_id, T.type, T.quantity,
                               T.date, T.recipient_source, T.project_ref
                        FROM \"$t\" T
                        INNER JOIN \"$invT\" I ON T.item_id = I.id"
                      . ($where ? ' WHERE '.implode(' AND ',$where) : '')
                      . ' ORDER BY T.date DESC';

                $start = microtime(true);
                $stmt  = $conn->prepare($sql);
                $stmt->execute($params);
                $rows  = $stmt->fetchAll();
                $dur   = round(microtime(true) - $start, 3);

                $total = count($rows); $inCount = 0; $outCount = 0; $cats = []; $projs = [];
                foreach ($rows as $r) {
                    if ($r['type'] === 'IN') $inCount++; else $outCount++;
                    $cats[$r['item_category'] ?? ''] = 1;
                    $projs[$r['project_ref']  ?? ''] = 1;
                }
                apiSuccess([
                    'rows'     => $rows,
                    'total'    => $total,
                    'duration' => $dur,
                    'stats'    => [
                        'total'      => $total,
                        'in'         => $inCount,
                        'out'        => $outCount,
                        'categories' => count($cats),
                        'projects'   => count($projs),
                    ],
                ]);

            // ── export ────────────────────────────────────────────
            case 'export':
                $tableType = $_GET['table_type'] ?? '';
                // 复用现有参数，重新走一遍查询后输出 CSV
                $_GET['action'] = $tableType === 'inventory' ? 'inventory' : 'transactions';
                // 先获取数据
                ob_start();
                // 递归调用自身逻辑（简化：直接重定向到对应 case）
                // 实际输出 CSV
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="export_' . date('Ymd_His') . '.csv"');
                echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

                if ($tableType === 'inventory') {
                    $t        = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table'] ?? 'Inventory');
                    $keyword  = $_GET['keyword']  ?? '';
                    $category = $_GET['category'] ?? '全部';
                    $domain   = $_GET['domain']   ?? '全部';
                    $location = $_GET['location'] ?? '全部';
                    $cabinet  = $_GET['cabinet']  ?? '全部';
                    $status   = $_GET['status']   ?? '全部';
                    $where = []; $params = [];
                    if ($keyword  !== '')     { $where[] = 'name ILIKE :kw';  $params[':kw']  = "%$keyword%"; }
                    if ($category !== '全部') { $where[] = 'category = :cat'; $params[':cat'] = $category; }
                    if ($domain   !== '全部') { $where[] = 'domain = :dom';   $params[':dom'] = $domain; }
                    if ($location !== '全部') { $where[] = 'location = :loc'; $params[':loc'] = $location; }
                    if ($cabinet  !== '全部') { $where[] = 'cabinet = :cab';  $params[':cab'] = $cabinet; }
                    if ($status === 'out')     $where[] = 'current_stock = 0';
                    elseif ($status === 'low') $where[] = 'current_stock > 0 AND current_stock < min_stock';
                    elseif ($status === 'normal') $where[] = 'current_stock >= min_stock';
                    $sql  = "SELECT * FROM \"$t\"" . ($where ? ' WHERE '.implode(' AND ',$where) : '') . ' ORDER BY id';
                    $stmt = $conn->prepare($sql); $stmt->execute($params);
                    $rows = $stmt->fetchAll();
                    if ($rows) {
                        echo implode(',', array_keys($rows[0])) . "\n";
                        foreach ($rows as $r) echo implode(',', array_map(fn($v)=>'"'.str_replace('"','""',$v??'').'"', $r)) . "\n";
                    }
                } else {
                    $t      = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table']           ?? 'Transactions');
                    $invT   = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['inventory_table'] ?? 'Inventory');
                    $keyword   = $_GET['keyword']    ?? '';
                    $type      = $_GET['type']       ?? '全部';
                    $project   = $_GET['project']    ?? '全部';
                    $startDate = $_GET['start_date'] ?? '';
                    $endDate   = $_GET['end_date']   ?? '';
                    $where = []; $params = [];
                    if ($keyword !== '')     { $where[] = 'I.name ILIKE :kw';     $params[':kw']  = "%$keyword%"; }
                    if ($type    !== '全部') { $where[] = 'T.type = :type';        $params[':type']= $type; }
                    if ($project !== '全部') { $where[] = 'T.project_ref = :proj'; $params[':proj']= $project; }
                    if ($startDate !== '')   { $where[] = 'T.date >= :sd';         $params[':sd']  = $startDate; }
                    if ($endDate   !== '')   { $ed = date('Y-m-d', strtotime($endDate.' +1 day')); $where[] = 'T.date < :ed'; $params[':ed'] = $ed; }
                    $sql  = "SELECT T.id,I.name AS item_name,T.type,T.quantity,T.date,T.recipient_source,T.project_ref
                             FROM \"$t\" T INNER JOIN \"$invT\" I ON T.item_id=I.id"
                           . ($where ? ' WHERE '.implode(' AND ',$where) : '') . ' ORDER BY T.date DESC';
                    $stmt = $conn->prepare($sql); $stmt->execute($params);
                    $rows = $stmt->fetchAll();
                    if ($rows) {
                        echo implode(',', array_keys($rows[0])) . "\n";
                        foreach ($rows as $r) echo implode(',', array_map(fn($v)=>'"'.str_replace('"','""',$v??'').'"', $r)) . "\n";
                    }
                }
                exit;

            default:
                apiError('未知的 action: ' . htmlspecialchars($action));
        }
    } catch (Exception $e) {
        apiError('服务器错误: ' . $e->getMessage());
    } finally {
        $conn = null;
    }
    exit;
}

// ============================================================
//  页面模式：输出 HTML（JS 里 API 地址改为 ?action=... 自身）
// ============================================================
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<link rel="preload" href="fonts/AlibabaPuHuiTi-3-55-RegularL3.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/AlibabaPuHuiTi-3-75-SemiBold.woff2" as="font" type="font/woff2" crossorigin>
<title>弘盛机电 · 非洲库存查看器</title>
<link rel="icon" type="image/png" href="logo.ico">
<style>
@font-face { font-family:'Alibaba PuHuiTi'; src:url('fonts/AlibabaPuHuiTi-3-45-Light.woff2') format('woff2'); font-weight:300; font-display:swap; }
@font-face { font-family:'Alibaba PuHuiTi'; src:url('fonts/AlibabaPuHuiTi-3-55-RegularL3.woff2') format('woff2'); font-weight:400; font-display:swap; }
@font-face { font-family:'Alibaba PuHuiTi'; src:url('fonts/AlibabaPuHuiTi-3-75-SemiBold.woff2') format('woff2'); font-weight:600; font-display:swap; }
@font-face { font-family:'Alibaba PuHuiTi'; src:url('fonts/AlibabaPuHuiTi-3-95-ExtraBold.woff2') format('woff2'); font-weight:700; font-display:swap; }
@font-face { font-family:'Alibaba PuHuiTi'; src:url('fonts/AlibabaPuHuiTi-3-105-Heavy.woff2') format('woff2'); font-weight:900; font-display:swap; }

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}

:root {
  --theme-primary:#1e3a8a;
  --theme-secondary:#3b82f6;
  --theme-pr:30,58,138;
  --theme-sc:59,130,246;
}

body {
  font-family:'Alibaba PuHuiTi',-apple-system,BlinkMacSystemFont,'PingFang SC','Microsoft YaHei',sans-serif;
  color:#333;
  background:url('https://bing.ee123.net/img/cn/fhd/') center/cover no-repeat fixed;
  min-height:100vh;
  font-weight:400;
  overflow:hidden;
}
body::after {
  content:'';position:fixed;inset:0;
  background:rgba(0,0,0,0.3);pointer-events:none;z-index:0;
}

.glass {
  background:rgba(255,255,255,0.25);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
  border:1px solid rgba(255,255,255,0.35);
  box-shadow:0 8px 32px rgba(0,0,0,0.12);
}

/* ── LAYOUT ── */
#app {
  position:relative;z-index:1;
  display:flex;flex-direction:column;height:100vh;
}

/* HEADER */
#header {
  display:flex;align-items:center;gap:10px;
  padding:8px 20px;
  border-radius:0;flex-shrink:0;
}
.logo-icon{width:28px;height:28px;border-radius:6px;overflow:hidden;flex-shrink:0;}
.logo-icon img{width:100%;height:100%;object-fit:contain;}
.logo-text{font-size:15px;font-weight:700;color:#1a202c;line-height:1.2;}
.logo-sub{font-size:10px;color:#4a5568;letter-spacing:0.06em;}
#conn-status{margin-left:auto;display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#4a5568;}
.status-dot{width:8px;height:8px;border-radius:50%;background:#a0aec0;flex-shrink:0;transition:background 0.2s;}
.btn-header{
  padding:6px 14px;border:none;border-radius:8px;cursor:pointer;
  font-family:inherit;font-size:12px;font-weight:600;color:#fff;
  background:linear-gradient(135deg,var(--theme-primary),var(--theme-secondary));
  box-shadow:0 3px 10px rgba(var(--theme-pr),0.4);
  transition:all 0.25s;
}
.btn-header:hover{transform:translateY(-1px);box-shadow:0 5px 14px rgba(var(--theme-pr),0.55);}

/* MAIN */
#main-body{display:flex;flex:1;overflow:hidden;gap:16px;padding:16px 24px 24px;}

/* SIDEBAR */
#sidebar{
  width:230px;min-width:230px;padding:20px 16px;
  border-radius:16px;display:flex;flex-direction:column;gap:8px;
  overflow-y:auto;
}
#sidebar h3{font-size:15px;font-weight:700;color:#2d3748;margin-bottom:8px;}
.table-item{
  display:flex;align-items:center;gap:10px;
  padding:12px 14px;border-radius:10px;cursor:pointer;
  font-size:13px;font-weight:600;color:#4a5568;
  background:rgba(255,255,255,0.5);border:1px solid rgba(255,255,255,0.5);
  transition:all 0.25s;
}
.table-item:hover{background:rgba(255,255,255,0.85);transform:translateX(4px);box-shadow:0 4px 12px rgba(0,0,0,0.1);}
.table-item.active{
  background:linear-gradient(135deg,var(--theme-primary),var(--theme-secondary));
  color:#fff;border-color:transparent;
  box-shadow:0 4px 14px rgba(var(--theme-pr),0.4);
}
.table-icon{font-size:16px;}

/* CONTENT */
#content{flex:1;display:flex;flex-direction:column;gap:12px;overflow:hidden;}

/* content header bar */
#content-header{
  border-radius:14px;padding:16px 20px;
  display:flex;align-items:center;gap:12px;flex-shrink:0;
}
#content-title{font-size:16px;font-weight:700;color:#2d3748;}
#content-meta{font-size:12px;color:#718096;margin-left:auto;}
.btn-export{
  padding:8px 18px;border:none;border-radius:10px;cursor:pointer;
  font-family:inherit;font-size:13px;font-weight:600;color:#fff;
  background:linear-gradient(135deg,var(--theme-primary),var(--theme-secondary));
  box-shadow:0 4px 12px rgba(var(--theme-pr),0.35);
  transition:all 0.25s;display:none;
}
.btn-export:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(var(--theme-pr),0.5);}

/* FILTER PANEL */
#filter-panel{
  border-radius:14px;padding:16px 20px;flex-shrink:0;display:none;
}
.filter-grid{
  display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:12px;
}
.filter-field label{
  display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:5px;
}
.filter-field input,
.filter-field select{
  width:100%;padding:8px 10px;border:1px solid rgba(203,213,224,0.8);
  border-radius:8px;font-size:13px;font-family:inherit;
  background:rgba(255,255,255,0.85);color:#2d3748;
  outline:none;transition:border-color 0.2s,box-shadow 0.2s;
}
.filter-field input:focus,
.filter-field select:focus{
  border-color:var(--theme-secondary);
  box-shadow:0 0 0 3px rgba(var(--theme-sc),0.15);
}
.filter-actions{display:flex;gap:10px;align-items:center;}
.btn-primary{
  padding:8px 20px;border:none;border-radius:10px;cursor:pointer;
  font-family:inherit;font-size:13px;font-weight:600;color:#fff;
  background:linear-gradient(135deg,var(--theme-primary),var(--theme-secondary));
  box-shadow:0 4px 12px rgba(var(--theme-pr),0.35);
  transition:all 0.25s;
}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(var(--theme-pr),0.5);}
.btn-ghost{
  padding:8px 20px;border:1px solid rgba(203,213,224,0.8);border-radius:10px;
  cursor:pointer;font-family:inherit;font-size:13px;font-weight:600;
  background:rgba(255,255,255,0.7);color:#4a5568;transition:all 0.25s;
}
.btn-ghost:hover{background:rgba(255,255,255,0.95);}

/* STATS BAR */
#stats-bar{
  border-radius:12px;padding:10px 20px;
  display:none;gap:20px;align-items:center;flex-wrap:wrap;flex-shrink:0;
  background:rgba(224,247,250,0.7);border:1px solid rgba(0,188,212,0.25);
  backdrop-filter:blur(8px);
}
.stat-chip{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#0f4c75;}
.stat-chip .val{font-size:14px;font-weight:700;}

/* PAGINATION */
#pagination-bar{
  border-radius:12px;padding:8px 16px;
  display:none;align-items:center;gap:8px;flex-shrink:0;flex-wrap:wrap;
}
#page-info{font-size:12px;color:#718096;white-space:nowrap;min-width:160px;}
#page-btns{display:flex;align-items:center;gap:3px;flex:1;flex-wrap:wrap;}
.page-btn{
  min-width:30px;height:28px;padding:0 7px;
  font-family:inherit;font-size:12px;font-weight:600;
  background:rgba(255,255,255,0.7);border:1px solid rgba(203,213,224,0.7);
  border-radius:7px;cursor:pointer;transition:all 0.2s;
  display:inline-flex;align-items:center;justify-content:center;color:#4a5568;
}
.page-btn:hover:not(.disabled):not(.active){
  background:rgba(var(--theme-sc),0.1);border-color:var(--theme-secondary);color:var(--theme-primary);
}
.page-btn.active{
  background:linear-gradient(135deg,var(--theme-primary),var(--theme-secondary));
  color:#fff;border-color:transparent;font-weight:700;cursor:default;
}
.page-btn.disabled{opacity:0.35;cursor:default;}
.page-ellipsis{font-size:12px;color:#a0aec0;padding:0 2px;}
.page-size-wrap{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#718096;margin-left:auto;}
.page-size-wrap select{
  padding:4px 8px;border:1px solid rgba(203,213,224,0.7);border-radius:7px;
  font-family:inherit;font-size:12px;background:rgba(255,255,255,0.8);
  color:#4a5568;outline:none;cursor:pointer;
}

/* TABLE WRAP */
#table-wrap{
  flex:1;overflow:auto;border-radius:14px;position:relative;
  background:rgba(255,255,255,0.55);
  backdrop-filter:blur(10px);
  border:1px solid rgba(255,255,255,0.4);
}
table{width:100%;border-collapse:collapse;font-size:13.5px;}
thead th{
  position:sticky;top:0;z-index:2;
  background:linear-gradient(135deg,rgba(var(--theme-pr),0.93),rgba(var(--theme-sc),0.93));
  color:#fff;padding:12px 11px;text-align:left;
  font-weight:700;font-size:13px;white-space:nowrap;
  user-select:none;cursor:pointer;
  border-bottom:none;
}
thead th .sort-icon{margin-left:5px;opacity:0.5;font-style:normal;}
thead th.sorted-asc .sort-icon,thead th.sorted-desc .sort-icon{opacity:1;}
thead th:hover{filter:brightness(1.08);}
tbody tr{border-bottom:1px solid rgba(226,232,240,0.6);transition:background 0.15s;}
tbody tr:nth-child(even){background:rgba(247,250,252,0.5);}
tbody tr.row-warning{background:rgba(254,240,138,0.55)!important;}
tbody tr.row-danger {background:rgba(254,202,202,0.55)!important;}
tbody tr:hover       {background:rgba(237,242,247,0.75)!important;}
tbody td{padding:10px 11px;color:#2d3748;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px;}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:3px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;}
.badge-green {background:rgba(198,246,213,0.85);color:#276749;}
.badge-yellow{background:rgba(254,240,138,0.85);color:#744210;}
.badge-red   {background:rgba(254,202,202,0.85);color:#742a2a;}
.badge-blue  {background:rgba(190,227,248,0.85);color:#2c5282;}

/* OVERLAY */
#overlay{
  position:absolute;inset:0;border-radius:14px;
  background:rgba(255,255,255,0.55);backdrop-filter:blur(4px);
  display:flex;align-items:center;justify-content:center;
  flex-direction:column;gap:14px;z-index:20;
  pointer-events:none;opacity:0;transition:opacity 0.2s;
}
#overlay.visible{opacity:1;pointer-events:all;}
.spinner{
  width:38px;height:38px;border-radius:50%;
  border:3px solid rgba(var(--theme-pr),0.2);
  border-top-color:var(--theme-primary);
  animation:spin 0.7s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg);}}
.overlay-text{color:#4a5568;font-size:13px;font-weight:600;}

/* EMPTY */
#empty-state{
  display:none;align-items:center;justify-content:center;
  flex-direction:column;gap:12px;height:100%;color:#a0aec0;
}
.empty-icon{font-size:52px;opacity:0.35;}
.empty-text{font-size:14px;font-weight:600;letter-spacing:0.04em;}

/* TOAST */
#toast-container{position:fixed;bottom:24px;right:24px;display:flex;flex-direction:column;gap:8px;z-index:9999;}
.toast{
  padding:10px 18px;border-radius:12px;font-size:13px;font-weight:600;
  color:#fff;animation:toastIn 0.25s ease forwards;max-width:360px;
  backdrop-filter:blur(8px);
}
.toast.ok   {background:rgba(72,187,120,0.92);}
.toast.error{background:rgba(245,101,101,0.92);}
.toast.info {background:rgba(66,153,225,0.92);}
@keyframes toastIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(var(--theme-sc),0.4);border-radius:3px;}
::-webkit-scrollbar-thumb:hover{background:rgba(var(--theme-sc),0.65);}
</style>
</head>
<body>
<div id="app">

<header id="header" class="glass">
  <div class="logo-icon"><img src="logo.png" alt="logo"></div>
  <div>
    <div class="logo-text">弘盛机电</div>
    <div class="logo-sub">AFRICA INVENTORY SYSTEM</div>
  </div>
  <div id="conn-status">
    <div class="status-dot loading" id="status-dot"></div>
    <span id="status-text">正在连接...</span>
  </div>
  <button class="btn-header" onclick="init()">🔄 刷新</button>
</header>
  <div id="main-body">

    <!-- Sidebar -->
    <nav id="sidebar" class="glass">
      <h3>📋 数据表列表</h3>
      <div id="table-list">
        <div class="table-item"><span>正在加载...</span></div>
      </div>
    </nav>

    <!-- Content -->
    <div id="content">

      <div id="content-header" class="glass">
        <div id="content-title" style="color:#718096">← 请选择一张数据表</div>
        <div id="content-meta"></div>
        <button class="btn-export" id="btn-export" onclick="exportCSV()">📥 导出 CSV</button>
      </div>

      <div id="filter-panel" class="glass">
        <div id="inv-filters" style="display:none">
          <div class="filter-grid">
            <div class="filter-field"><label>物品名称</label><input id="inv-keyword" type="text" placeholder="关键字搜索..."></div>
            <div class="filter-field"><label>材料类型</label><select id="inv-category"><option>全部</option></select></div>
            <div class="filter-field"><label>专业</label><select id="inv-domain"><option>全部</option></select></div>
            <div class="filter-field"><label>储存位置</label><select id="inv-location"><option>全部</option></select></div>
            <div class="filter-field"><label>柜号</label><select id="inv-cabinet"><option>全部</option></select></div>
            <div class="filter-field">
              <label>状态</label>
              <select id="inv-status">
                <option value="全部">全部</option>
                <option value="normal">🟢 正常 (Normal)</option>
                <option value="low">🟡 预警 (Low Stock)</option>
                <option value="out">🔴 缺货 (Stock Out)</option>
              </select>
            </div>
          </div>
        </div>
        <div id="trans-filters" style="display:none">
          <div class="filter-grid">
            <div class="filter-field"><label>物品名称</label><input id="trans-keyword" type="text" placeholder="关键字搜索..."></div>
            <div class="filter-field">
              <label>类型</label>
              <select id="trans-type">
                <option>全部</option>
                <option value="IN">IN（入库）</option>
                <option value="OUT">OUT（出库）</option>
              </select>
            </div>
            <div class="filter-field"><label>项目</label><select id="trans-project"><option>全部</option></select></div>
            <div class="filter-field"><label>起始日期</label><input id="trans-start" type="date"></div>
            <div class="filter-field"><label>截止日期</label><input id="trans-end" type="date"></div>
          </div>
        </div>
        <div class="filter-actions">
          <button class="btn-primary" onclick="applyFilters()">🔍 应用筛选</button>
          <button class="btn-ghost" onclick="resetFilters()">✕ 重置</button>
        </div>
      </div>

      <div id="stats-bar"></div>

      <div id="pagination-bar" class="glass">
        <span id="page-info"></span>
        <div id="page-btns"></div>
        <div class="page-size-wrap">
          <label>每页</label>
          <select id="page-size" onchange="onPageSizeChange()">
            <option value="25">25 条</option>
            <option value="50" selected>50 条</option>
            <option value="100">100 条</option>
            <option value="0">全部</option>
          </select>
        </div>
      </div>

      <div id="table-wrap">
        <div id="overlay">
          <div class="spinner"></div>
          <div class="overlay-text" id="overlay-text">加载中...</div>
        </div>
        <div id="empty-state">
          <div class="empty-icon">📭</div>
          <div class="empty-text">无数据 / 请选择数据表</div>
        </div>
        <table id="data-table" style="display:none">
          <thead id="table-head"></thead>
          <tbody id="table-body"></tbody>
        </table>
      </div>

    </div>
  </div>
</div>

<div id="toast-container"></div>

<script>
/* ── Dynamic theme from Bing wallpaper ── */
(function(){
  const bingUrl = 'https://bing.ee123.net/img/';
  document.body.style.backgroundImage = `url('${bingUrl}')`;

  const img = new Image();
  img.crossOrigin = 'Anonymous';

  img.onload = function() {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    canvas.width = img.width;
    canvas.height = img.height;
    ctx.drawImage(img, 0, 0);

    const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    const colorMap = {};
    for (let i = 0; i < pixels.length; i += 40) {
      const r = Math.floor(pixels[i]   / 20) * 20;
      const g = Math.floor(pixels[i+1] / 20) * 20;
      const b = Math.floor(pixels[i+2] / 20) * 20;
      const br = (r + g + b) / 3;
      if (br < 30 || br > 230) continue;
      const k = `${r},${g},${b}`;
      colorMap[k] = (colorMap[k] || 0) + 1;
    }

    const top = Object.entries(colorMap)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 2)
      .map(([k]) => k.split(',').map(Number));

    if (!top.length) return;

    function darken(r, g, b, max) {
      const br = (r + g + b) / 3;
      if (br > max) { const f = max / br; return [Math.round(r*f), Math.round(g*f), Math.round(b*f)]; }
      return [r, g, b];
    }

    const [r1, g1, b1] = darken(...top[0], 100);
    const [r2, g2, b2] = darken(...(top[1] || [Math.min(255,r1+50), Math.min(255,g1+50), Math.min(255,b1+50)]), 120);

    const p = `${r1},${g1},${b1}`, s = `${r2},${g2},${b2}`;

    // 更新 CSS 变量
    document.documentElement.style.setProperty('--theme-primary',   `rgb(${p})`);
    document.documentElement.style.setProperty('--theme-secondary',  `rgb(${s})`);
    document.documentElement.style.setProperty('--theme-pr', p);
    document.documentElement.style.setProperty('--theme-sc', s);

    // 动态注入样式（同步更新所有用到主题色的元素）
    let el = document.getElementById('dynamic-theme');
    if (!el) { el = document.createElement('style'); el.id = 'dynamic-theme'; document.head.appendChild(el); }
    el.textContent = `
      .btn-header, .btn-primary, .btn-export {
        background: linear-gradient(135deg, rgb(${p}), rgb(${s})) !important;
        box-shadow: 0 4px 14px rgba(${p}, 0.4) !important;
      }
      .table-item.active {
        background: linear-gradient(135deg, rgb(${p}), rgb(${s})) !important;
        box-shadow: 0 4px 14px rgba(${p}, 0.4) !important;
      }
      thead th {
        background: linear-gradient(135deg, rgba(${p},0.93), rgba(${s},0.93)) !important;
      }
      ::-webkit-scrollbar-thumb { background: rgba(${s},0.4) !important; }
    `;
  };

  img.onerror = function() { console.warn('壁纸加载失败，使用默认主题'); };
  img.src = bingUrl + '?_t=' + Date.now();
})();
/* ================================================================
   CONFIG & STATE
   ================================================================ */

// ★ API 现在就是本文件自身，通过 ?action=... 调用
const API = location.pathname;

const state={
  currentTable:null,
  actualNames:{},
  sortCol:null,sortDir:'asc',
  rawRows:[],columns:[],
  currentPage:1,pageSize:50,
};

const COL_DEFS={
  inventory:[
    {key:'name',         label:'物品名称', width:240},
    {key:'reference',    label:'物品型号', width:160},
    {key:'category',     label:'材料类型', width:110},
    {key:'domain',       label:'专业',     width:100},
    {key:'unit',         label:'单位',     width:60},
    {key:'current_stock',label:'当前库存', width:80},
    {key:'min_stock',    label:'最低库存', width:80},
    {key:'location',     label:'储存位置', width:100},
    {key:'cabinet',      label:'柜号',     width:80},
    {key:'_status',      label:'状态',     width:140,virtual:true},
  ],
  transactions:[
    {key:'id',               label:'序号',       width:70},
    {key:'date',             label:'日期时间',   width:160},
    {key:'item_name',        label:'物品名称',   width:280},
    {key:'type',             label:'类型',       width:80},
    {key:'quantity',         label:'数量',       width:70},
    {key:'recipient_source', label:'来源/接收人',width:140},
    {key:'project_ref',      label:'项目',       width:120},
  ],
};

/* ── API ── */
async function apiFetch(params){
  const res=await fetch(API+'?'+new URLSearchParams(params));
  if(!res.ok)throw new Error(`HTTP ${res.status}`);
  const j=await res.json();
  if(!j.success)throw new Error(j.error||'未知错误');
  return j.data;
}

/* ── INIT ── */
async function init(){
  setStatus('loading','正在连接...');
  showOverlay('连接数据库...');
  try{
    await apiFetch({action:'ping'});
    setStatus('ok','已连接');
  }catch(e){
    setStatus('error','连接失败');
    toast('数据库连接失败: '+e.message,'error');
    hideOverlay();return;
  }
  await loadTables();
  await loadFilterOptions();
  hideOverlay();
}

/* ── TABLE LIST ── */
const TABLE_ICONS={'库存':'📦','存取记录':'📋'};
async function loadTables(){
  try{
    const tables=await apiFetch({action:'tables'});
    state.actualNames={};
    tables.forEach(t=>{state.actualNames[t.actual.toLowerCase()]=t.actual;});
    const listEl=document.getElementById('table-list');
    listEl.innerHTML='';
    tables.forEach((t,i)=>{
      const el=document.createElement('div');
      el.className='table-item';
      el.dataset.actual=t.actual;
      el.dataset.clean=t.actual.toLowerCase();
      el.innerHTML=`<span class="table-icon">${TABLE_ICONS[t.display]||'🗃'}</span><span>${t.display}</span>`;
      el.onclick=()=>selectTable(el);
      listEl.appendChild(el);
      if(i===0)selectTable(el);
    });
  }catch(e){toast('获取表列表失败: '+e.message,'error');}
}

function selectTable(el){
  document.querySelectorAll('.table-item').forEach(i=>i.classList.remove('active'));
  el.classList.add('active');
  const clean=el.dataset.clean;
  state.currentTable=clean;
  state.sortCol=null;state.sortDir='asc';
  showFilterPanel(clean);
  resetFilters(false);
  loadData();
}

/* ── FILTER OPTIONS ── */
async function loadFilterOptions(){
  try{
    const inv=state.actualNames['inventory']||'inventory';
    const trans=state.actualNames['transactions']||'transactions';
    const opts=await apiFetch({action:'filter_opts',inventory_table:inv,transactions_table:trans});
    populateSelect('inv-category',opts.inventory?.category);
    populateSelect('inv-domain',  opts.inventory?.domain);
    populateSelect('inv-location',opts.inventory?.location);
    populateSelect('inv-cabinet', opts.inventory?.cabinet);
    populateSelect('trans-project',opts.transactions?.project);
  }catch(e){toast('筛选选项加载失败: '+e.message,'error');}
}

function populateSelect(id,items){
  if(!items)return;
  const sel=document.getElementById(id);
  const cur=sel.value;
  sel.innerHTML='<option value="全部">全部</option>';
  items.forEach(v=>{
    const opt=document.createElement('option');
    opt.value=opt.textContent=v;
    sel.appendChild(opt);
  });
  if([...sel.options].some(o=>o.value===cur))sel.value=cur;
}

/* ── FILTER PANEL ── */
function showFilterPanel(clean){
  const panel=document.getElementById('filter-panel');
  const invF =document.getElementById('inv-filters');
  const traF =document.getElementById('trans-filters');
  const sbar =document.getElementById('stats-bar');
  if(clean==='inventory'||clean==='transactions'){
    panel.style.display='block';
    invF.style.display =clean==='inventory'?'block':'none';
    traF.style.display =clean==='transactions'?'block':'none';
    sbar.style.display ='flex';
  }else{
    panel.style.display='none';
    sbar.style.display='none';
  }
}

function resetFilters(reload=true){
  document.getElementById('inv-keyword').value ='';
  document.getElementById('inv-category').value='全部';
  document.getElementById('inv-domain').value  ='全部';
  document.getElementById('inv-location').value='全部';
  document.getElementById('inv-cabinet').value ='全部';
  document.getElementById('inv-status').value  ='全部';
  document.getElementById('trans-keyword').value='';
  document.getElementById('trans-type').value   ='全部';
  document.getElementById('trans-project').value='全部';
  const today=new Date();
  const first=new Date(today.getFullYear(),today.getMonth(),1);
  document.getElementById('trans-start').value=fmt(first);
  document.getElementById('trans-end').value  =fmt(today);
  if(reload&&state.currentTable)loadData();
}
function applyFilters(){loadData();}
function fmt(d){return d.toISOString().slice(0,10);}

/* ── LOAD DATA ── */
async function loadData(){
  const clean=state.currentTable;if(!clean)return;
  document.getElementById('content-title').textContent='⏳ 正在加载...';
  showOverlay('查询数据库...');
  try{
    let result;
    if(clean==='inventory'){
      result=await apiFetch({
        action:'inventory',
        table:state.actualNames['inventory']||'inventory',
        keyword: document.getElementById('inv-keyword').value,
        category:document.getElementById('inv-category').value,
        domain:  document.getElementById('inv-domain').value,
        location:document.getElementById('inv-location').value,
        cabinet: document.getElementById('inv-cabinet').value,
        status:  document.getElementById('inv-status').value,
      });
    }else if(clean==='transactions'){
      result=await apiFetch({
        action:'transactions',
        table:          state.actualNames['transactions']||'transactions',
        inventory_table:state.actualNames['inventory']||'inventory',
        keyword: document.getElementById('trans-keyword').value,
        type:    document.getElementById('trans-type').value,
        project: document.getElementById('trans-project').value,
        start_date:document.getElementById('trans-start').value,
        end_date:  document.getElementById('trans-end').value,
      });
    }else{hideOverlay();return;}

    state.rawRows   =result.rows;
    state.columns   =COL_DEFS[clean]||[];
    state.currentPage=1;

    renderPage();
    renderStats(result.stats,clean);
    document.getElementById('content-title').textContent=`✅ ${displayName(clean)} — 共 ${result.total} 条`;
    document.getElementById('content-meta').textContent=`查询耗时 ${result.duration}s`;
    document.getElementById('btn-export').style.display='inline-block';
  }catch(e){
    toast('数据加载失败: '+e.message,'error');
    document.getElementById('content-title').textContent='❌ 加载失败';
  }finally{hideOverlay();}
}

/* ── PAGINATION ── */
function renderPage(){
  const ps=state.pageSize,total=state.rawRows.length;
  const slice=ps===0?state.rawRows:state.rawRows.slice((state.currentPage-1)*ps,state.currentPage*ps);
  renderTable(slice);
  renderPaginationBar(total);
}

function renderPaginationBar(total){
  const bar=document.getElementById('pagination-bar');
  const info=document.getElementById('page-info');
  const btns=document.getElementById('page-btns');
  const ps=state.pageSize;
  if(total===0){bar.style.display='none';return;}
  bar.style.display='flex';
  if(ps===0){info.textContent=`共 ${total} 条（全部显示）`;btns.innerHTML='';return;}
  const totalPages=Math.ceil(total/ps);
  const cur=state.currentPage;
  info.textContent=`第 ${(cur-1)*ps+1}–${Math.min(cur*ps,total)} 条，共 ${total} 条`;
  btns.innerHTML='';
  function mkBtn(label,page,disabled,active){
    const b=document.createElement('button');
    b.className='page-btn'+(active?' active':'')+(disabled?' disabled':'');
    b.textContent=label;
    if(!disabled&&!active)b.onclick=()=>goToPage(page);
    btns.appendChild(b);
  }
  mkBtn('«',1,cur===1,false);mkBtn('‹',cur-1,cur===1,false);
  let lo=Math.max(1,cur-2),hi=Math.min(totalPages,cur+2);
  if(hi-lo<4){if(lo===1)hi=Math.min(totalPages,lo+4);else lo=Math.max(1,hi-4);}
  if(lo>1){mkBtn('1',1,false,false);if(lo>2)btns.appendChild(Object.assign(document.createElement('span'),{className:'page-ellipsis',textContent:'…'}));}
  for(let p=lo;p<=hi;p++)mkBtn(String(p),p,false,p===cur);
  if(hi<totalPages){if(hi<totalPages-1)btns.appendChild(Object.assign(document.createElement('span'),{className:'page-ellipsis',textContent:'…'}));mkBtn(String(totalPages),totalPages,false,false);}
  mkBtn('›',cur+1,cur===totalPages,false);mkBtn('»',totalPages,cur===totalPages,false);
}

function goToPage(page){
  const ps=state.pageSize,total=state.rawRows.length;
  const totalPages=ps===0?1:Math.ceil(total/ps);
  state.currentPage=Math.max(1,Math.min(page,totalPages));
  renderPage();
  document.getElementById('table-wrap').scrollTop=0;
}
function onPageSizeChange(){
  state.pageSize=parseInt(document.getElementById('page-size').value);
  state.currentPage=1;renderPage();
}

/* ── RENDER TABLE ── */
function renderTable(rows){
  const clean=state.currentTable,cols=state.columns;
  const thead=document.getElementById('table-head');
  thead.innerHTML='';
  const tr=document.createElement('tr');
  cols.forEach(col=>{
    const th=document.createElement('th');
    th.dataset.key=col.key;
    th.style.minWidth=col.width+'px';
    let icon='⇅';
    if(state.sortCol===col.key)icon=state.sortDir==='asc'?'↑':'↓';
    th.innerHTML=`${col.label}<em class="sort-icon">${icon}</em>`;
    if(state.sortCol===col.key)th.className='sorted-'+state.sortDir;
    if(!col.virtual)th.onclick=()=>sortBy(col.key);
    tr.appendChild(th);
  });
  thead.appendChild(tr);

  const tbody=document.getElementById('table-body');
  tbody.innerHTML='';
  const tbl=document.getElementById('data-table');

  if(!rows||rows.length===0){
    document.getElementById('empty-state').style.display='flex';
    tbl.style.display='none';return;
  }
  document.getElementById('empty-state').style.display='none';
  tbl.style.display='';

  rows.forEach(row=>{
    const tr=document.createElement('tr');
    if(clean==='inventory'){
      const cur=parseInt(row.current_stock??-1),min=parseInt(row.min_stock??0);
      if(cur===0)tr.className='row-danger';
      else if(cur>0&&cur<min)tr.className='row-warning';
    }
    cols.forEach(col=>{
      const td=document.createElement('td');
      td.title=String(row[col.key]??'');
      if(col.key==='_status'){
        const cur=parseInt(row.current_stock??-1),min=parseInt(row.min_stock??0);
        if(cur===0)          td.innerHTML='<span class="badge badge-red">🔴 缺货</span>';
        else if(cur>0&&cur<min)td.innerHTML='<span class="badge badge-yellow">🟡 预警</span>';
        else                 td.innerHTML='<span class="badge badge-green">🟢 正常</span>';
      }else if(col.key==='type'){
        const v=row[col.key]??'';
        td.innerHTML=v==='IN'?'<span class="badge badge-green">↑ IN</span>':'<span class="badge badge-blue">↓ OUT</span>';
      }else{
        const v=row[col.key];
        td.textContent=(v===null||v===undefined)?'—':String(v);
      }
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  });
}

/* ── SORT ── */
function sortBy(key){
  if(state.sortCol===key){state.sortDir=state.sortDir==='asc'?'desc':'asc';}
  else{state.sortCol=key;state.sortDir='asc';}
  state.rawRows.sort((a,b)=>{
    let av=a[key]??'',bv=b[key]??'';
    const an=parseFloat(av),bn=parseFloat(bv);
    if(!isNaN(an)&&!isNaN(bn)){av=an;bv=bn;}
    if(av<bv)return state.sortDir==='asc'?-1:1;
    if(av>bv)return state.sortDir==='asc'?1:-1;
    return 0;
  });
  state.currentPage=1;renderPage();
}

/* ── STATS ── */
function renderStats(stats,clean){
  const bar=document.getElementById('stats-bar');
  bar.innerHTML='';if(!stats)return;
  let html='';
  if(clean==='inventory'){
    html=`
      <div class="stat-chip">📦 总计 <span class="val">${stats.total}</span></div>
      <div class="stat-chip">类型 <span class="val">${stats.categories}</span></div>
      <div class="stat-chip">专业 <span class="val">${stats.domains}</span></div>
      <div class="stat-chip">位置 <span class="val">${stats.locations}</span></div>
      <div class="stat-chip">柜号 <span class="val">${stats.cabinets}</span></div>
      <div class="stat-chip">🟡 预警 <span class="val">${stats.warning}</span></div>
      <div class="stat-chip">🔴 缺货 <span class="val">${stats.out}</span></div>`;
  }else if(clean==='transactions'){
    html=`
      <div class="stat-chip">📋 总计 <span class="val">${stats.total}</span></div>
      <div class="stat-chip">↑ 入库 <span class="val">${stats.in}</span></div>
      <div class="stat-chip">↓ 出库 <span class="val">${stats.out}</span></div>
      <div class="stat-chip">类型 <span class="val">${stats.categories}</span></div>
      <div class="stat-chip">项目 <span class="val">${stats.projects}</span></div>`;
  }
  bar.innerHTML=html;
}

/* ── EXPORT ── */
function exportCSV(){
  const clean=state.currentTable;if(!clean)return;
  const params=new URLSearchParams({action:'export',table_type:clean});
  if(clean==='inventory'){
    params.set('table',   state.actualNames['inventory']||'inventory');
    params.set('keyword', document.getElementById('inv-keyword').value);
    params.set('category',document.getElementById('inv-category').value);
    params.set('domain',  document.getElementById('inv-domain').value);
    params.set('location',document.getElementById('inv-location').value);
    params.set('cabinet', document.getElementById('inv-cabinet').value);
    params.set('status',  document.getElementById('inv-status').value);
  }else{
    params.set('table',          state.actualNames['transactions']||'transactions');
    params.set('inventory_table',state.actualNames['inventory']||'inventory');
    params.set('keyword', document.getElementById('trans-keyword').value);
    params.set('type',    document.getElementById('trans-type').value);
    params.set('project', document.getElementById('trans-project').value);
    params.set('start_date',document.getElementById('trans-start').value);
    params.set('end_date',  document.getElementById('trans-end').value);
  }
  const a=document.createElement('a');
  a.href=API+'?'+params.toString();a.download='';a.click();
  toast('CSV 导出已开始下载','ok');
}

/* ── UI HELPERS ── */
function showOverlay(text='加载中...'){
  document.getElementById('overlay-text').textContent=text;
  document.getElementById('overlay').classList.add('visible');
}
function hideOverlay(){document.getElementById('overlay').classList.remove('visible');}
function setStatus(type,text){
  document.getElementById('status-dot').className='status-dot '+type;
  document.getElementById('status-text').textContent=text;
}
function toast(msg,type='info'){
  const el=document.createElement('div');
  el.className=`toast ${type}`;el.textContent=msg;
  document.getElementById('toast-container').appendChild(el);
  setTimeout(()=>el.remove(),3500);
}
function displayName(clean){return{inventory:'库存',transactions:'存取记录'}[clean]||clean;}

document.addEventListener('keydown',e=>{
  if(e.key==='Enter'&&(document.activeElement.tagName==='INPUT'||document.activeElement.tagName==='SELECT'))applyFilters();
});

init();
</script>
</body>
</html>