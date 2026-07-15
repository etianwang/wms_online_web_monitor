<?php
/**
 * 弘盛机电 非洲库存系统 - PHP 后端 API
 * 兼容 PHP 7.4+  |  需要 pdo_pgsql 扩展
 * v3 — 多区域：ci=科特迪瓦(阿里云) / cm=喀麦隆(Neon)
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

/** @var array 区域数据库配置；来自同目录 db_config.php（见 db_config.example.php） */
$db_config_file = __DIR__ . '/db_config.php';
if (!is_file($db_config_file)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error'   => '缺少 db_config.php。请复制 db_config.example.php 为 db_config.php 并填写数据库信息。',
    ), JSON_UNESCAPED_UNICODE);
    exit();
}
$DB_REGIONS = require $db_config_file;
if (!is_array($DB_REGIONS) || empty($DB_REGIONS)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => 'db_config.php 格式无效'), JSON_UNESCAPED_UNICODE);
    exit();
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

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

function get_region_config() {
    global $DB_REGIONS;
    $region = strtolower(get_param('region', 'ci'));
    if ($region === '') $region = 'ci';
    if (!isset($DB_REGIONS[$region])) {
        json_err('未知区域: ' . $region . '（可用: ci=科特迪瓦, cm=喀麦隆）', 400);
    }
    return array($region, $DB_REGIONS[$region]);
}

function get_connection() {
    if (!extension_loaded('pdo_pgsql')) {
        json_err('服务器缺少 pdo_pgsql 扩展，请在 php.ini 中启用 extension=pdo_pgsql 并重启PHP');
    }
    list($region, $cfg) = get_region_config();
    $dsn = 'pgsql:host=' . $cfg['host']
         . ';port=' . $cfg['port']
         . ';dbname=' . $cfg['dbname']
         . ';connect_timeout=' . $cfg['timeout'];
    if (!empty($cfg['sslmode'])) {
        $dsn .= ';sslmode=' . $cfg['sslmode'];
    }
    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['password'], array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
        ));
        $pdo->exec("SET timezone TO 'UTC'");
        return $pdo;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'sslmode') !== false && stripos($msg, 'SSL support is not compiled') !== false) {
            json_err(
                '[' . $cfg['label'] . '] 当前 PHP 的 pgsql 未启用 SSL，无法连接 Neon。'
                . '请在服务器安装带 OpenSSL 的 php-pgsql/pdo_pgsql，或将喀麦隆库迁到与科特迪瓦相同的可连主机；'
                . '原始错误: ' . $msg
            );
        }
        if (stripos($msg, 'SSL') !== false || stripos($msg, 'sslmode') !== false) {
            json_err(
                '[' . $cfg['label'] . '] SSL 连接失败（Neon 必须 SSL）。请确认服务器 pgsql 支持 SSL。原始错误: ' . $msg
            );
        }
        json_err('[' . $cfg['label'] . '] 数据库连接失败: ' . $msg);
    }
}

/** 将 Inventory / inventory 等解析为库中真实表名（大小写敏感） */
function resolve_table_name($pdo, $wanted) {
    $wanted = trim((string)$wanted);
    if ($wanted === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $wanted)) {
        json_err('非法表名: ' . $wanted, 400);
    }
    $stmt = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
           AND LOWER(table_name) = LOWER(:t)
         LIMIT 1"
    );
    $stmt->execute(array(':t' => $wanted));
    $actual = $stmt->fetchColumn();
    if (!$actual) {
        json_err('表不存在: ' . $wanted, 404);
    }
    return $actual;
}

/* ── 路由 ── */
$action = get_param('action', 'ping');
try {
    switch ($action) {
        case 'ping':         action_ping();               break;
        case 'tables':       action_get_tables();         break;
        case 'filter_opts':  action_get_filter_options(); break;
        case 'inventory':    action_get_inventory();      break;
        case 'transactions': action_get_transactions();   break;
        case 'export':       action_export_csv();         break;
        default:             json_err('未知操作: ' . $action, 400);
    }
} catch (Throwable $e) {
    json_err($e->getMessage());
}

/* ── ping ── */
function action_ping() {
    list($region, $cfg) = get_region_config();
    $pdo = get_connection();
    json_ok(array(
        'status' => 'connected',
        'region' => $region,
        'label'  => $cfg['label'],
        'php'    => PHP_VERSION,
        'time'   => gmdate('Y-m-d H:i:s') . ' UTC',
    ));
}

/* ── tables ── */
function action_get_tables() {
    $pdo = get_connection();
    $sql = "SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE' ORDER BY table_name";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $name_map = array('inventory' => '库存', 'transactions' => '存取记录');
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

/* ── filter_opts ── */
function action_get_filter_options() {
    $pdo   = get_connection();
    $inv   = resolve_table_name($pdo, get_param('inventory_table',    'inventory'));
    $trans = resolve_table_name($pdo, get_param('transactions_table', 'transactions'));
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

/* ── 分页参数（per_page 最大 500） ── */
function parse_pagination() {
    $page     = max(1, (int)get_param('page', 1));
    $per_page = min(500, max(10, (int)get_param('per_page', 50)));
    $offset   = ($page - 1) * $per_page;
    return array($page, $per_page, $offset);
}

/* ── inventory ── */
function action_get_inventory() {
    $t0       = microtime(true);
    $pdo      = get_connection();
    $table    = resolve_table_name($pdo, get_param('table', 'inventory'));
    $keyword  = get_param('keyword');
    $category = get_param('category');
    $domain   = get_param('domain');
    $location = get_param('location');
    $cabinet  = get_param('cabinet');
    $status   = get_param('status');

    list($page, $per_page, $offset) = parse_pagination();

    $where = array(); $params = array();
    if ($keyword  !== '')                          { $where[] = 'I.name ILIKE :keyword';   $params[':keyword']  = '%'.$keyword.'%'; }
    if ($category !== '' && $category !== '全部') { $where[] = 'I.category = :category';  $params[':category'] = $category; }
    if ($domain   !== '' && $domain   !== '全部') { $where[] = 'I.domain = :domain';      $params[':domain']   = $domain; }
    if ($location !== '' && $location !== '全部') { $where[] = 'I.location = :location';  $params[':location'] = $location; }
    if ($cabinet  !== '' && $cabinet  !== '全部') { $where[] = 'I.cabinet = :cabinet';    $params[':cabinet']  = $cabinet; }
    $ws = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    /* 状态筛选需要内存计算（涉及两列），先拉全部再内存分页 */
    if ($status !== '' && $status !== '全部') {
        $stmt = $pdo->prepare('SELECT * FROM public."' . $table . '" I ' . $ws . ' ORDER BY I.id');
        $stmt->execute($params);
        $all = array_values(array_filter($stmt->fetchAll(), function ($r) use ($status) {
            $cur = (isset($r['current_stock']) && is_numeric($r['current_stock'])) ? (int)$r['current_stock'] : -1;
            $min = (isset($r['min_stock'])     && is_numeric($r['min_stock']))     ? (int)$r['min_stock']     :  0;
            if ($status === 'out'    && $cur === 0)               return true;
            if ($status === 'low'    && $cur > 0 && $cur < $min)  return true;
            if ($status === 'normal' && $cur > 0 && $cur >= $min) return true;
            return false;
        }));
        $total_count = count($all);
        $rows        = array_slice($all, $offset, $per_page);
        $stats       = compute_inventory_stats($all);
    } else {
        /* 数据库分页（LIMIT/OFFSET 用整型字面量，避免部分 PDO/pgsql 绑定失败） */
        $count_stmt = $pdo->prepare('SELECT COUNT(*) FROM public."' . $table . '" I ' . $ws);
        $count_stmt->execute($params);
        $total_count = (int)$count_stmt->fetchColumn();

        $sql = 'SELECT * FROM public."' . $table . '" I ' . $ws
             . ' ORDER BY I.id LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $stats = compute_inventory_stats_from_db($pdo, $table, $ws, $params);
    }

    $total_pages = $per_page > 0 ? (int)ceil($total_count / $per_page) : 1;
    json_ok(array(
        'rows'        => $rows,
        'stats'       => $stats,
        'duration'    => round(microtime(true) - $t0, 3),
        'total'       => count($rows),
        'total_count' => $total_count,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total_pages,
    ));
}

function compute_inventory_stats_from_db($pdo, $table, $ws, $params) {
    $sql = 'SELECT
              COUNT(DISTINCT category) AS categories,
              COUNT(DISTINCT domain)   AS domains,
              COUNT(DISTINCT location) AS locations,
              COUNT(DISTINCT cabinet)  AS cabinets,
              COUNT(*) AS total,
              SUM(CASE WHEN current_stock = 0 THEN 1 ELSE 0 END) AS out_count,
              SUM(CASE WHEN current_stock > 0 AND current_stock < min_stock THEN 1 ELSE 0 END) AS warn_count
            FROM public."' . $table . '" I ' . $ws;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $r = $stmt->fetch();
    if (!$r) {
        return array(
            'categories' => 0, 'domains' => 0, 'locations' => 0, 'cabinets' => 0,
            'total' => 0, 'warning' => 0, 'out' => 0,
        );
    }
    return array(
        'categories' => (int)$r['categories'],
        'domains'    => (int)$r['domains'],
        'locations'  => (int)$r['locations'],
        'cabinets'   => (int)$r['cabinets'],
        'total'      => (int)$r['total'],
        'warning'    => (int)$r['warn_count'],
        'out'        => (int)$r['out_count'],
    );
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
        if ($cur === 0)              $out++;
        elseif ($cur > 0 && $cur < $min) $warn++;
    }
    return array(
        'categories' => count($cats), 'domains' => count($doms),
        'locations'  => count($locs), 'cabinets' => count($cabs),
        'warning'    => $warn, 'out' => $out, 'total' => count($rows),
    );
}

/* ── transactions ── */
function action_get_transactions() {
    $t0          = microtime(true);
    $pdo         = get_connection();
    $trans_table = resolve_table_name($pdo, get_param('table',           'transactions'));
    $inv_table   = resolve_table_name($pdo, get_param('inventory_table', 'inventory'));
    $keyword     = get_param('keyword');
    $type        = get_param('type');
    $project     = get_param('project');
    $start_date  = get_param('start_date');
    $end_date    = get_param('end_date');

    list($page, $per_page, $offset) = parse_pagination();

    $where = array(); $params = array();
    if ($keyword    !== '')                          { $where[] = 'I.name ILIKE :keyword';    $params[':keyword']    = '%'.$keyword.'%'; }
    if ($type       !== '' && $type    !== '全部') { $where[] = 'T.type = :type';            $params[':type']       = $type; }
    if ($project    !== '' && $project !== '全部') { $where[] = 'T.project_ref = :project';  $params[':project']    = $project; }
    if ($start_date !== '') { $where[] = 'T.date >= :start_date'; $params[':start_date'] = $start_date; }
    if ($end_date   !== '') { $where[] = 'T.date < :end_date';    $params[':end_date']   = date('Y-m-d', strtotime($end_date . ' +1 day')); }
    $ws   = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $join = 'FROM public."' . $trans_table . '" T INNER JOIN public."' . $inv_table . '" I ON T.item_id = I.id';

    /* 总数 */
    $count_stmt = $pdo->prepare('SELECT COUNT(*) ' . $join . ' ' . $ws);
    $count_stmt->execute($params);
    $total_count = (int)$count_stmt->fetchColumn();

    /* 当页数据 */
    $stmt = $pdo->prepare(
        'SELECT T.id, I.name AS item_name, I.category AS item_category, T.item_id, T.type, T.quantity, T.date, T.recipient_source, T.project_ref
         ' . $join . ' ' . $ws . '
         ORDER BY T.date DESC LIMIT ' . (int)$per_page . ' OFFSET ' . (int)$offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if (!empty($row['date'])) $row['date'] = date('Y-m-d H:i:s', strtotime($row['date']));
    }
    unset($row);

    /* 统计（全量） */
    $stats_stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN UPPER(T.type)=\'IN\'  THEN 1 ELSE 0 END) AS in_count,
                SUM(CASE WHEN UPPER(T.type)=\'OUT\' THEN 1 ELSE 0 END) AS out_count,
                COUNT(DISTINCT I.category)    AS categories,
                COUNT(DISTINCT T.project_ref) AS projects
         ' . $join . ' ' . $ws
    );
    $stats_stmt->execute($params);
    $sr    = $stats_stmt->fetch();
    $stats = array(
        'total'      => (int)$sr['total'],
        'in'         => (int)$sr['in_count'],
        'out'        => (int)$sr['out_count'],
        'categories' => (int)$sr['categories'],
        'projects'   => (int)$sr['projects'],
    );

    $total_pages = $per_page > 0 ? (int)ceil($total_count / $per_page) : 1;
    json_ok(array(
        'rows'        => $rows,
        'stats'       => $stats,
        'duration'    => round(microtime(true) - $t0, 3),
        'total'       => count($rows),
        'total_count' => $total_count,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total_pages,
    ));
}

/* ── export CSV（不分页，上限 5000） ── */
function action_export_csv() {
    $table_type = get_param('table_type', 'inventory');
    $pdo        = get_connection();

    if ($table_type === 'inventory') {
        $table    = resolve_table_name($pdo, get_param('table', 'inventory'));
        $keyword  = get_param('keyword');
        $category = get_param('category');
        $domain   = get_param('domain');
        $location = get_param('location');
        $cabinet  = get_param('cabinet');
        $status   = get_param('status');

        $where = array(); $params = array();
        if ($keyword  !== '')                          { $where[] = 'I.name ILIKE :keyword';   $params[':keyword']  = '%'.$keyword.'%'; }
        if ($category !== '' && $category !== '全部') { $where[] = 'I.category = :category';  $params[':category'] = $category; }
        if ($domain   !== '' && $domain   !== '全部') { $where[] = 'I.domain = :domain';      $params[':domain']   = $domain; }
        if ($location !== '' && $location !== '全部') { $where[] = 'I.location = :location';  $params[':location'] = $location; }
        if ($cabinet  !== '' && $cabinet  !== '全部') { $where[] = 'I.cabinet = :cabinet';    $params[':cabinet']  = $cabinet; }
        $ws   = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $stmt = $pdo->prepare('SELECT * FROM public."' . $table . '" I ' . $ws . ' ORDER BY I.id LIMIT 5000');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if ($status !== '' && $status !== '全部') {
            $rows = array_values(array_filter($rows, function ($r) use ($status) {
                $cur = (isset($r['current_stock']) && is_numeric($r['current_stock'])) ? (int)$r['current_stock'] : -1;
                $min = (isset($r['min_stock'])     && is_numeric($r['min_stock']))     ? (int)$r['min_stock']     :  0;
                if ($status === 'out'    && $cur === 0)              return true;
                if ($status === 'low'    && $cur > 0 && $cur < $min) return true;
                if ($status === 'normal' && $cur > 0 && $cur >= $min) return true;
                return false;
            }));
        }
        $col_keys   = array('name','reference','category','domain','unit','current_stock','min_stock','location','cabinet');
        $col_labels = array('物品名称','物品型号','材料类型','专业','单位','当前库存','最低库存','储存位置','柜号');
        $fname      = '库存_' . date('Ymd_His') . '.csv';
    } else {
        $trans_table = resolve_table_name($pdo, get_param('table',           'transactions'));
        $inv_table   = resolve_table_name($pdo, get_param('inventory_table', 'inventory'));
        $keyword     = get_param('keyword');
        $type        = get_param('type');
        $project     = get_param('project');
        $start_date  = get_param('start_date');
        $end_date    = get_param('end_date');

        $where = array(); $params = array();
        if ($keyword  !== '')                          { $where[] = 'I.name ILIKE :keyword';    $params[':keyword']  = '%'.$keyword.'%'; }
        if ($type     !== '' && $type    !== '全部') { $where[] = 'T.type = :type';            $params[':type']     = $type; }
        if ($project  !== '' && $project !== '全部') { $where[] = 'T.project_ref = :proj';     $params[':proj']     = $project; }
        if ($start_date !== '') { $where[] = 'T.date >= :sd'; $params[':sd'] = $start_date; }
        if ($end_date   !== '') { $where[] = 'T.date < :ed';  $params[':ed'] = date('Y-m-d', strtotime($end_date . ' +1 day')); }
        $ws   = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $stmt = $pdo->prepare(
            'SELECT T.id, I.name AS item_name, T.type, T.quantity, T.date, T.recipient_source, T.project_ref
             FROM public."' . $trans_table . '" T
             INNER JOIN public."' . $inv_table . '" I ON T.item_id = I.id
             ' . $ws . ' ORDER BY T.date DESC LIMIT 5000'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (!empty($row['date'])) $row['date'] = date('Y-m-d H:i:s', strtotime($row['date']));
        }
        unset($row);
        $col_keys   = array('id','date','item_name','type','quantity','recipient_source','project_ref');
        $col_labels = array('序号','日期时间','物品名称','类型','数量','来源/接收人','项目');
        $fname      = '存取记录_' . date('Ymd_His') . '.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $col_labels);
    foreach ($rows as $row) {
        $line = array();
        foreach ($col_keys as $k) $line[] = isset($row[$k]) ? $row[$k] : '';
        fputcsv($out, $line);
    }
    fclose($out);
    exit();
}