<?php
/**
 * 弘盛机电 非洲库存系统 - PHP 后端 API
 * 兼容 PHP 7.4+  |  需要 pdo_pgsql 扩展
 */

// ============================================================
// 错误处理
// ============================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================================
// 数据库配置
// ============================================================
define('DB_HOST',    'pgm-gw8ffg06e16gfgcwho.pgsql.germany.rds.aliyuncs.com');
define('DB_PORT',    '5432');
define('DB_NAME',    'postgres');
define('DB_USER',    'Honsen_Admin');
define('DB_PASSWORD','!66778899HONSEN');
define('DB_TIMEOUT', 8);

// ============================================================
// 输出头
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================================
// 工具函数
// ============================================================
function get_param($key, $default = '') {
    $val = isset($_GET[$key]) ? $_GET[$key] : (isset($_POST[$key]) ? $_POST[$key] : $default);
    return trim((string)$val);
}

function json_ok($data) {
    echo json_encode(array('success' => true, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function json_err($msg, $code = 500) {
    http_response_code($code);
    echo json_encode(array('success' => false, 'error' => $msg), JSON_UNESCAPED_UNICODE);
    exit();
}

// ============================================================
// 数据库连接
// ============================================================
function get_connection() {
    if (!extension_loaded('pdo_pgsql')) {
        json_err('服务器缺少 pdo_pgsql 扩展，请在 php.ini 中启用 extension=pdo_pgsql 并重启PHP');
    }
    $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';connect_timeout=' . DB_TIMEOUT;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
        $pdo->exec("SET timezone TO 'UTC'");
        return $pdo;
    } catch (PDOException $e) {
        json_err('数据库连接失败: ' . $e->getMessage());
    }
}

// ============================================================
// 路由
// ============================================================
$action = get_param('action', 'ping');
switch ($action) {
    case 'ping':         action_ping();               break;
    case 'tables':       action_get_tables();         break;
    case 'filter_opts':  action_get_filter_options(); break;
    case 'inventory':    action_get_inventory();      break;
    case 'transactions': action_get_transactions();   break;
    case 'export':       action_export_csv();         break;
    default:             json_err('未知操作: ' . $action, 400);
}

// ============================================================
// ping
// ============================================================
function action_ping() {
    $pdo = get_connection();
    json_ok(array('status' => 'connected', 'php' => PHP_VERSION, 'time' => gmdate('Y-m-d H:i:s') . ' UTC'));
}

// ============================================================
// tables
// ============================================================
function action_get_tables() {
    $pdo = get_connection();
    $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

    $name_map      = array('inventory' => '库存', 'transactions' => '存取记录');
    $display_order = array('库存', '存取记录');

    $by_display = array();
    foreach ($rows as $actual) {
        $key = strtolower($actual);
        if (isset($name_map[$key])) $by_display[$name_map[$key]] = $actual;
    }

    $ordered = array();
    foreach ($display_order as $dname) {
        if (isset($by_display[$dname])) $ordered[] = array('display' => $dname, 'actual' => $by_display[$dname]);
    }
    json_ok($ordered);
}

// ============================================================
// filter_opts
// ============================================================
function action_get_filter_options() {
    $pdo   = get_connection();
    $inv   = get_param('inventory_table',    'inventory');
    $trans = get_param('transactions_table', 'transactions');

    $result = array('inventory' => array(), 'transactions' => array());
    foreach (array('category', 'domain', 'location', 'cabinet') as $col) {
        $stmt = $pdo->prepare('SELECT DISTINCT ' . $col . ' FROM public."' . $inv . '" WHERE ' . $col . ' IS NOT NULL ORDER BY ' . $col);
        $stmt->execute();
        $result['inventory'][$col] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $stmt = $pdo->prepare('SELECT DISTINCT project_ref FROM public."' . $trans . '" WHERE project_ref IS NOT NULL ORDER BY project_ref');
    $stmt->execute();
    $result['transactions']['project'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    json_ok($result);
}

// ============================================================
// inventory
// ============================================================
function action_get_inventory() {
    $t0       = microtime(true);
    $pdo      = get_connection();
    $table    = get_param('table',    'inventory');
    $keyword  = get_param('keyword');
    $category = get_param('category');
    $domain   = get_param('domain');
    $location = get_param('location');
    $cabinet  = get_param('cabinet');
    $status   = get_param('status');

    $where = array(); $params = array();
    if ($keyword  !== '')             { $where[] = 'I.name ILIKE :keyword';   $params[':keyword']  = '%'.$keyword.'%'; }
    if ($category !== '' && $category !== '全部') { $where[] = 'I.category = :category'; $params[':category'] = $category; }
    if ($domain   !== '' && $domain   !== '全部') { $where[] = 'I.domain = :domain';     $params[':domain']   = $domain; }
    if ($location !== '' && $location !== '全部') { $where[] = 'I.location = :location'; $params[':location'] = $location; }
    if ($cabinet  !== '' && $cabinet  !== '全部') { $where[] = 'I.cabinet = :cabinet';   $params[':cabinet']  = $cabinet; }

    $ws   = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql  = 'SELECT * FROM public."' . $table . '" I ' . $ws . ' ORDER BY I.id LIMIT 1000';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if ($status !== '' && $status !== '全部') {
        $rows = array_values(array_filter($rows, function ($r) use ($status) {
            $cur = (isset($r['current_stock']) && is_numeric($r['current_stock'])) ? (int)$r['current_stock'] : -1;
            $min = (isset($r['min_stock'])     && is_numeric($r['min_stock']))     ? (int)$r['min_stock']     :  0;
            if ($status === 'out'    && $cur === 0)               return true;
            if ($status === 'low'    && $cur > 0 && $cur < $min)  return true;
            if ($status === 'normal' && $cur > 0 && $cur >= $min) return true;
            return false;
        }));
    }

    json_ok(array('rows' => $rows, 'stats' => compute_inventory_stats($rows), 'duration' => round(microtime(true)-$t0,3), 'total' => count($rows)));
}

function compute_inventory_stats($rows) {
    $cats = array(); $doms = array(); $locs = array(); $cabs = array();
    $warn = 0; $out = 0;
    foreach ($rows as $r) {
        if (!empty($r['category'])) $cats[$r['category']] = 1;
        if (!empty($r['domain']))   $doms[$r['domain']]   = 1;
        if (!empty($r['location'])) $locs[$r['location']] = 1;
        if (!empty($r['cabinet']))  $cabs[$r['cabinet']]  = 1;
        $cur = (isset($r['current_stock']) && is_numeric($r['current_stock'])) ? (int)$r['current_stock'] : -1;
        $min = (isset($r['min_stock'])     && is_numeric($r['min_stock']))     ? (int)$r['min_stock']     :  0;
        if ($cur === 0)             $out++;
        elseif ($cur>0 && $cur<$min) $warn++;
    }
    return array('categories'=>count($cats),'domains'=>count($doms),'locations'=>count($locs),'cabinets'=>count($cabs),'warning'=>$warn,'out'=>$out,'total'=>count($rows));
}

// ============================================================
// transactions
// ============================================================
function action_get_transactions() {
    $t0          = microtime(true);
    $pdo         = get_connection();
    $trans_table = get_param('table',           'transactions');
    $inv_table   = get_param('inventory_table', 'inventory');
    $keyword     = get_param('keyword');
    $type        = get_param('type');
    $project     = get_param('project');
    $start_date  = get_param('start_date');
    $end_date    = get_param('end_date');

    $where = array(); $params = array();
    if ($keyword    !== '')                  { $where[] = 'I.name ILIKE :keyword';    $params[':keyword']    = '%'.$keyword.'%'; }
    if ($type       !== '' && $type    !== '全部') { $where[] = 'T.type = :type';          $params[':type']       = $type; }
    if ($project    !== '' && $project !== '全部') { $where[] = 'T.project_ref = :project'; $params[':project']    = $project; }
    if ($start_date !== '') { $where[] = 'T.date >= :start_date'; $params[':start_date'] = $start_date; }
    if ($end_date   !== '') { $where[] = 'T.date < :end_date';    $params[':end_date']   = date('Y-m-d', strtotime($end_date.' +1 day')); }

    $ws  = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = 'SELECT T.id, I.name AS item_name, I.category AS item_category, T.item_id, T.type, T.quantity, T.date, T.recipient_source, T.project_ref
            FROM public."'.$trans_table.'" T
            INNER JOIN public."'.$inv_table.'" I ON T.item_id = I.id
            '.$ws.' ORDER BY T.date DESC LIMIT 1000';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        if (!empty($row['date'])) $row['date'] = date('Y-m-d H:i:s', strtotime($row['date']));
    }
    unset($row);

    json_ok(array('rows'=>$rows,'stats'=>compute_transactions_stats($rows),'duration'=>round(microtime(true)-$t0,3),'total'=>count($rows)));
}

function compute_transactions_stats($rows) {
    $in=0; $out=0; $cats=array(); $projs=array();
    foreach ($rows as $r) {
        $t = strtoupper(isset($r['type']) ? $r['type'] : '');
        if ($t==='IN') $in++; if ($t==='OUT') $out++;
        if (!empty($r['item_category'])) $cats[$r['item_category']]  = 1;
        if (!empty($r['project_ref']))   $projs[$r['project_ref']]   = 1;
    }
    return array('in'=>$in,'out'=>$out,'categories'=>count($cats),'projects'=>count($projs),'total'=>count($rows));
}

// ============================================================
// export CSV
// ============================================================
function action_export_csv() {
    $table_type = get_param('table_type', 'inventory');
    $pdo        = get_connection();

    if ($table_type === 'inventory') {
        $table    = get_param('table',    'inventory');
        $keyword  = get_param('keyword');
        $category = get_param('category');
        $domain   = get_param('domain');
        $location = get_param('location');
        $cabinet  = get_param('cabinet');
        $status   = get_param('status');

        $where = array(); $params = array();
        if ($keyword  !== '')             { $where[] = 'I.name ILIKE :keyword';   $params[':keyword']  = '%'.$keyword.'%'; }
        if ($category !== '' && $category !== '全部') { $where[] = 'I.category = :category'; $params[':category'] = $category; }
        if ($domain   !== '' && $domain   !== '全部') { $where[] = 'I.domain = :domain';     $params[':domain']   = $domain; }
        if ($location !== '' && $location !== '全部') { $where[] = 'I.location = :location'; $params[':location'] = $location; }
        if ($cabinet  !== '' && $cabinet  !== '全部') { $where[] = 'I.cabinet = :cabinet';   $params[':cabinet']  = $cabinet; }

        $ws   = $where ? ('WHERE '.implode(' AND ',$where)) : '';
        $stmt = $pdo->prepare('SELECT * FROM public."'.$table.'" I '.$ws.' ORDER BY I.id LIMIT 5000');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if ($status !== '' && $status !== '全部') {
            $rows = array_values(array_filter($rows, function ($r) use ($status) {
                $cur = (isset($r['current_stock'])&&is_numeric($r['current_stock'])) ? (int)$r['current_stock'] : -1;
                $min = (isset($r['min_stock'])    &&is_numeric($r['min_stock']))     ? (int)$r['min_stock']     :  0;
                if ($status==='out'    && $cur===0)              return true;
                if ($status==='low'    && $cur>0 && $cur<$min)   return true;
                if ($status==='normal' && $cur>0 && $cur>=$min)  return true;
                return false;
            }));
        }

        $col_keys   = array('name','reference','category','domain','unit','current_stock','min_stock','location','cabinet');
        $col_labels = array('物品名称','物品型号','材料类型','专业','单位','当前库存','最低库存','储存位置','柜号');
        $fname      = '库存_'.date('Ymd_His').'.csv';
    } else {
        $trans_table = get_param('table',           'transactions');
        $inv_table   = get_param('inventory_table', 'inventory');
        $keyword     = get_param('keyword');
        $type        = get_param('type');
        $project     = get_param('project');
        $start_date  = get_param('start_date');
        $end_date    = get_param('end_date');

        $where = array(); $params = array();
        if ($keyword  !== '')                  { $where[] = 'I.name ILIKE :keyword';    $params[':keyword']    = '%'.$keyword.'%'; }
        if ($type     !== '' && $type    !== '全部') { $where[] = 'T.type = :type';         $params[':type']       = $type; }
        if ($project  !== '' && $project !== '全部') { $where[] = 'T.project_ref = :proj';  $params[':proj']       = $project; }
        if ($start_date !== '') { $where[] = 'T.date >= :sd'; $params[':sd'] = $start_date; }
        if ($end_date   !== '') { $where[] = 'T.date < :ed';  $params[':ed'] = date('Y-m-d',strtotime($end_date.' +1 day')); }

        $ws   = $where ? ('WHERE '.implode(' AND ',$where)) : '';
        $stmt = $pdo->prepare('SELECT T.id,I.name AS item_name,T.type,T.quantity,T.date,T.recipient_source,T.project_ref FROM public."'.$trans_table.'" T INNER JOIN public."'.$inv_table.'" I ON T.item_id=I.id '.$ws.' ORDER BY T.date DESC LIMIT 5000');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) { if (!empty($row['date'])) $row['date']=date('Y-m-d H:i:s',strtotime($row['date'])); } unset($row);

        $col_keys   = array('id','date','item_name','type','quantity','recipient_source','project_ref');
        $col_labels = array('序号','日期时间','物品名称','类型','数量','来源/接收人','项目');
        $fname      = '存取记录_'.date('Ymd_His').'.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output','w');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,$col_labels);
    foreach ($rows as $row) {
        $line = array();
        foreach ($col_keys as $k) $line[] = isset($row[$k]) ? $row[$k] : '';
        fputcsv($out,$line);
    }
    fclose($out);
    exit();
}