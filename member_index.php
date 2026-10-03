<?php
error_reporting(E_ERROR | E_PARSE);
@ini_set('display_errors', '0');
session_start();

if (empty($_SESSION['member_logged']) && empty($_SESSION['is_admin_view']) && empty($_SESSION['member_id'])) {
    header("Location: member_login.php");
    exit;
}

$m_id = (int)$_SESSION['member_id'];
$sess_uname = isset($_SESSION['member_username']) ? $_SESSION['member_username'] : (isset($_SESSION['username']) ? $_SESSION['username'] : '');
$sess_token = isset($_SESSION['member_token']) ? $_SESSION['member_token'] : '';
session_write_close();

require_once 'db.php';

// 중복 로그인 검사
if (!empty($sess_token)) {
    $tok_q = mysqli_query($conn, "SELECT session_token FROM `automeme_members` WHERE id = " . $m_id . " LIMIT 1");
    if ($tok_q && $tok_r = mysqli_fetch_assoc($tok_q)) {
        if (!empty($tok_r['session_token']) && $tok_r['session_token'] !== $sess_token) {
            session_start();
            $_SESSION = array();
            session_destroy();
            echo "<script>alert('다른 장소(또는 다른 창)에서 중복 로그인되어 현재 창의 로그인이 해제됩니다.'); location.href='member_login.php';</script>";
            exit;
        }
    }
}

// automeme_orders 테이블 자동 생성
mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS `automeme_orders` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `member_id` INT NOT NULL,
      `username` VARCHAR(50) NOT NULL,
      `symbol` VARCHAR(30) NOT NULL,
      `side` VARCHAR(20) NOT NULL,
      `entry_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
      `close_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
      `qty` INT NOT NULL DEFAULT 1,
      `pnl` DECIMAL(18,0) DEFAULT 0,
      `reg_date` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
);

$ord_col_check1 = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_orders` LIKE 'entry_price'");
if ($ord_col_check1 && mysqli_num_rows($ord_col_check1) == 0) {
    mysqli_query($conn, "ALTER TABLE `automeme_orders` ADD COLUMN `entry_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `side`");
}

$ord_col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_orders` LIKE 'close_price'");
if ($ord_col_check2 && mysqli_num_rows($ord_col_check2) == 0) {
    mysqli_query($conn, "ALTER TABLE `automeme_orders` ADD COLUMN `close_price` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `entry_price`");
}

$col_check = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_members` LIKE 'position_side'");
if ($col_check && mysqli_num_rows($col_check) == 0) {
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD COLUMN `position_side` VARCHAR(20) DEFAULT ''");
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD COLUMN `position_qty` INT DEFAULT 0");
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD COLUMN `position_entry` DECIMAL(18,2) DEFAULT 0.00");
}

$tp_check = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_members` LIKE 'tp_ticks'");
if ($tp_check && mysqli_num_rows($tp_check) == 0) {
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD COLUMN `tp_ticks` INT DEFAULT 0");
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD COLUMN `sl_ticks` INT DEFAULT 0");
}

$qty_col_check = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_members` LIKE 'order_qty'");
if ($qty_col_check && mysqli_num_rows($qty_col_check) == 0) {
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD COLUMN `order_qty` INT DEFAULT 1");
}

// 자동매매 설정 및 블록별 분봉, 신호 조합 컬럼 자동 추가 검사
$auto_cols = array(
    "auto_active TINYINT(1) DEFAULT 0", "auto_tf INT DEFAULT 600", "delay_buy INT DEFAULT 5", "delay_close INT DEFAULT 5", "delay_sell INT DEFAULT 5",
    "sig1_active TINYINT(1) DEFAULT 0", "sig1_tf INT DEFAULT 600", "sig1_use_1 TINYINT(1) DEFAULT 0", "sig1_use_2 TINYINT(1) DEFAULT 0", "sig1_use_3 TINYINT(1) DEFAULT 0", "sig1_dbuy INT DEFAULT 5", "sig1_dclose INT DEFAULT 5", "sig1_dsell INT DEFAULT 5",
    "sig2_active TINYINT(1) DEFAULT 0", "sig2_tf INT DEFAULT 600", "sig2_use_1 TINYINT(1) DEFAULT 0", "sig2_use_2 TINYINT(1) DEFAULT 0", "sig2_use_3 TINYINT(1) DEFAULT 0", "sig2_dbuy INT DEFAULT 5", "sig2_dclose INT DEFAULT 5", "sig2_dsell INT DEFAULT 5",
    "sig3_active TINYINT(1) DEFAULT 0", "sig3_tf INT DEFAULT 600", "sig3_use_1 TINYINT(1) DEFAULT 0", "sig3_use_2 TINYINT(1) DEFAULT 0", "sig3_use_3 TINYINT(1) DEFAULT 0", "sig3_dbuy INT DEFAULT 5", "sig3_dclose INT DEFAULT 5", "sig3_dsell INT DEFAULT 5"
);
foreach ($auto_cols as $col_def) {
    $col_name = explode(' ', trim($col_def))[0];
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_members` LIKE '{$col_name}'");
    if ($chk && mysqli_num_rows($chk) == 0) {
        mysqli_query($conn, "ALTER TABLE `automeme_members` ADD COLUMN {$col_def}");
    }
}

mysqli_query($conn, "UPDATE `automeme_members` SET 
    sig1_dbuy = IF(sig1_dbuy <= 0, 5, sig1_dbuy), sig1_dclose = IF(sig1_dclose <= 0, 5, sig1_dclose), sig1_dsell = IF(sig1_dsell <= 0, 5, sig1_dsell),
    sig2_dbuy = IF(sig2_dbuy <= 0, 5, sig2_dbuy), sig2_dclose = IF(sig2_dclose <= 0, 5, sig2_dclose), sig2_dsell = IF(sig2_dsell <= 0, 5, sig2_dsell),
    sig3_dbuy = IF(sig3_dbuy <= 0, 5, sig3_dbuy), sig3_dclose = IF(sig3_dclose <= 0, 5, sig3_dclose), sig3_dsell = IF(sig3_dsell <= 0, 5, sig3_dsell)
");

function fetchYahooChartJson($url) {
    $res = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: Mozilla/5.0', 'Accept: application/json,text/plain,*/*', 'Cache-Control: no-cache'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_TIMEOUT, 3); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $res = curl_exec($ch); curl_close($ch);
    }
    if (!$res) {
        $opts = array('http' => array('method' => 'GET', 'header' => "User-Agent: Mozilla/5.0\r\nCache-Control: no-cache\r\n", 'timeout' => 3), 'ssl' => array('verify_peer' => false, 'verify_peer_name' => false));
        $res = @file_get_contents($url, false, stream_context_create($opts));
    }
    return $res ? json_decode($res, true) : null;
}

function getNasdaqFuturesLiveQuote() {
    $ts = time() . mt_rand(100, 999);
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/NQ=F?interval=1m&range=1d&_=" . $ts;
    $json = fetchYahooChartJson($url);
    $p = 0; $lastBar = null;

    if ($json && isset($json['chart']['result'][0])) {
        $r0 = $json['chart']['result'][0];
        if (isset($r0['meta']['regularMarketPrice'])) {
            $val = (float)$r0['meta']['regularMarketPrice'];
            if ($val > 1000) $p = round($val * 4) / 4;
        }
        if (isset($r0['timestamp']) && isset($r0['indicators']['quote'][0])) {
            $tsArr = $r0['timestamp']; $q = $r0['indicators']['quote'][0];
            for ($k = count($tsArr) - 1; $k >= 0; $k--) {
                $co = isset($q['open'][$k]) ? (float)$q['open'][$k] : 0;
                $cc = isset($q['close'][$k]) ? (float)$q['close'][$k] : 0;
                if ($co > 1000 && $cc > 1000) {
                    $lastBar = array('time' => (int)(floor((int)$tsArr[$k] / 60) * 60), 'open' => round($co * 4) / 4, 'high' => round(((float)$q['high'][$k]) * 4) / 4, 'low' => round(((float)$q['low'][$k]) * 4) / 4, 'close' => round($cc * 4) / 4, 'volume' => isset($q['volume'][$k]) ? (int)$q['volume'][$k] : 10);
                    if ($p <= 0) $p = $lastBar['close'];
                    break;
                }
            }
        }
    }
    return array('price' => $p, 'last_bar' => $lastBar);
}

if (isset($_GET['ajax_action'])) {
    if ($_GET['ajax_action'] === 'get_futures_price') {
        if (ob_get_length()) @ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $live = getNasdaqFuturesLiveQuote();
        $p = $live['price']; $lastBar = $live['last_bar'];

        $m_req = mysqli_query($conn, "SELECT balance, position_side, position_qty, position_entry, tp_ticks, sl_ticks, order_qty, auto_active, auto_tf, delay_buy, delay_close, delay_sell, sig1_active, sig1_tf, sig1_use_1, sig1_use_2, sig1_use_3, sig1_dbuy, sig1_dclose, sig1_dsell, sig2_active, sig2_tf, sig2_use_1, sig2_use_2, sig2_use_3, sig2_dbuy, sig2_dclose, sig2_dsell, sig3_active, sig3_tf, sig3_use_1, sig3_use_2, sig3_use_3, sig3_dbuy, sig3_dclose, sig3_dsell FROM `automeme_members` WHERE id = " . $m_id . " LIMIT 1");
        $m_row = $m_req ? mysqli_fetch_assoc($m_req) : null;

        $o_req = mysqli_query($conn, "SELECT id, side, entry_price, close_price, qty, pnl FROM `automeme_orders` WHERE member_id = " . $m_id . " ORDER BY id DESC LIMIT 1");
        $last_order = $o_req ? mysqli_fetch_assoc($o_req) : null;

        echo json_encode(array(
            'status'         => ($p > 0 ? 'ok' : 'fail'),
            'price'          => $p,
            'last_bar'       => $lastBar,
            'time'           => date('H:i:s'),
            'balance'        => isset($m_row['balance']) ? (float)$m_row['balance'] : 0,
            'position_side'  => isset($m_row['position_side']) ? (string)$m_row['position_side'] : '',
            'position_qty'   => isset($m_row['position_qty']) ? (int)$m_row['position_qty'] : 0,
            'position_entry' => isset($m_row['position_entry']) ? (float)$m_row['position_entry'] : 0,
            'tp_ticks'       => isset($m_row['tp_ticks']) ? (int)$m_row['tp_ticks'] : 0,
            'sl_ticks'       => isset($m_row['sl_ticks']) ? (int)$m_row['sl_ticks'] : 0,
            'order_qty'      => isset($m_row['order_qty']) ? (int)$m_row['order_qty'] : 1,
            'auto_active'    => isset($m_row['auto_active']) ? (int)$m_row['auto_active'] : 0,
            'sig1_active'    => isset($m_row['sig1_active']) ? (int)$m_row['sig1_active'] : 0, 'sig1_tf' => isset($m_row['sig1_tf']) ? (int)$m_row['sig1_tf'] : 600, 'sig1_use_1' => isset($m_row['sig1_use_1']) ? (int)$m_row['sig1_use_1'] : 0, 'sig1_use_2' => isset($m_row['sig1_use_2']) ? (int)$m_row['sig1_use_2'] : 0, 'sig1_use_3' => isset($m_row['sig1_use_3']) ? (int)$m_row['sig1_use_3'] : 0, 'sig1_dbuy' => isset($m_row['sig1_dbuy']) && $m_row['sig1_dbuy'] > 0 ? (int)$m_row['sig1_dbuy'] : 5, 'sig1_dclose' => isset($m_row['sig1_dclose']) && $m_row['sig1_dclose'] > 0 ? (int)$m_row['sig1_dclose'] : 5, 'sig1_dsell' => isset($m_row['sig1_dsell']) && $m_row['sig1_dsell'] > 0 ? (int)$m_row['sig1_dsell'] : 5,
            'sig2_active'    => isset($m_row['sig2_active']) ? (int)$m_row['sig2_active'] : 0, 'sig2_tf' => isset($m_row['sig2_tf']) ? (int)$m_row['sig2_tf'] : 600, 'sig2_use_1' => isset($m_row['sig2_use_1']) ? (int)$m_row['sig2_use_1'] : 0, 'sig2_use_2' => isset($m_row['sig2_use_2']) ? (int)$m_row['sig2_use_2'] : 0, 'sig2_use_3' => isset($m_row['sig2_use_3']) ? (int)$m_row['sig2_use_3'] : 0, 'sig2_dbuy' => isset($m_row['sig2_dbuy']) && $m_row['sig2_dbuy'] > 0 ? (int)$m_row['sig2_dbuy'] : 5, 'sig2_dclose' => isset($m_row['sig2_dclose']) && $m_row['sig2_dclose'] > 0 ? (int)$m_row['sig2_dclose'] : 5, 'sig2_dsell' => isset($m_row['sig2_dsell']) && $m_row['sig2_dsell'] > 0 ? (int)$m_row['sig2_dsell'] : 5,
            'sig3_active'    => isset($m_row['sig3_active']) ? (int)$m_row['sig3_active'] : 0, 'sig3_tf' => isset($m_row['sig3_tf']) ? (int)$m_row['sig3_tf'] : 600, 'sig3_use_1' => isset($m_row['sig3_use_1']) ? (int)$m_row['sig3_use_1'] : 0, 'sig3_use_2' => isset($m_row['sig3_use_2']) ? (int)$m_row['sig3_use_2'] : 0, 'sig3_use_3' => isset($m_row['sig3_use_3']) ? (int)$m_row['sig3_use_3'] : 0, 'sig3_dbuy' => isset($m_row['sig3_dbuy']) && $m_row['sig3_dbuy'] > 0 ? (int)$m_row['sig3_dbuy'] : 5, 'sig3_dclose' => isset($m_row['sig3_dclose']) && $m_row['sig3_dclose'] > 0 ? (int)$m_row['sig3_dclose'] : 5, 'sig3_dsell' => isset($m_row['sig3_dsell']) && $m_row['sig3_dsell'] > 0 ? (int)$m_row['sig3_dsell'] : 5,
            'last_order'     => $last_order ? $last_order : null
        ));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['ajax_action'])) {
        if (ob_get_length()) @ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        $action = $_POST['ajax_action'];
        
        $uname = mysqli_real_escape_string($conn, empty($sess_uname) ? 'guest' : $sess_uname);

        $ureq = mysqli_query($conn, "SELECT * FROM `automeme_members` WHERE id = " . $m_id . " LIMIT 1");
        $uinfo = mysqli_fetch_assoc($ureq);
        $cur_bal = (float)$uinfo['balance'];
        $db_pos_side = (string)$uinfo['position_side'];
        $db_pos_qty = (int)$uinfo['position_qty'];
        $db_pos_entry = (float)$uinfo['position_entry'];

        if ($action === 'save_order_qty') {
            $o_qty = (int)$_POST['order_qty'];
            if ($o_qty < 1) $o_qty = 1;
            mysqli_query($conn, "UPDATE `automeme_members` SET order_qty = " . $o_qty . " WHERE id = " . $m_id);
            echo json_encode(array('status' => 'ok', 'order_qty' => $o_qty)); exit;
        }

        if ($action === 'save_autotrade_settings') {
            $active = (int)$_POST['auto_active'];

            $s1_act = (int)$_POST['sig1_active']; $s1_tf = (int)$_POST['sig1_tf']; $s1_u1 = (int)$_POST['sig1_use_1']; $s1_u2 = (int)$_POST['sig1_use_2']; $s1_u3 = (int)$_POST['sig1_use_3']; $s1_dbuy = (int)$_POST['sig1_dbuy']; $s1_dclose = (int)$_POST['sig1_dclose']; $s1_dsell = (int)$_POST['sig1_dsell'];
            $s2_act = (int)$_POST['sig2_active']; $s2_tf = (int)$_POST['sig2_tf']; $s2_u1 = (int)$_POST['sig2_use_1']; $s2_u2 = (int)$_POST['sig2_use_2']; $s2_u3 = (int)$_POST['sig2_use_3']; $s2_dbuy = (int)$_POST['sig2_dbuy']; $s2_dclose = (int)$_POST['sig2_dclose']; $s2_dsell = (int)$_POST['sig2_dsell'];
            $s3_act = (int)$_POST['sig3_active']; $s3_tf = (int)$_POST['sig3_tf']; $s3_u1 = (int)$_POST['sig3_use_1']; $s3_u2 = (int)$_POST['sig3_use_2']; $s3_u3 = (int)$_POST['sig3_use_3']; $s3_dbuy = (int)$_POST['sig3_dbuy']; $s3_dclose = (int)$_POST['sig3_dclose']; $s3_dsell = (int)$_POST['sig3_dsell'];

            mysqli_query($conn, "UPDATE `automeme_members` SET auto_active = {$active}, sig1_active = {$s1_act}, sig1_tf = {$s1_tf}, sig1_use_1 = {$s1_u1}, sig1_use_2 = {$s1_u2}, sig1_use_3 = {$s1_u3}, sig1_dbuy = {$s1_dbuy}, sig1_dclose = {$s1_dclose}, sig1_dsell = {$s1_dsell}, sig2_active = {$s2_act}, sig2_tf = {$s2_tf}, sig2_use_1 = {$s2_u1}, sig2_use_2 = {$s2_u2}, sig2_use_3 = {$s2_u3}, sig2_dbuy = {$s2_dbuy}, sig2_dclose = {$s2_dclose}, sig2_dsell = {$s2_dsell}, sig3_active = {$s3_act}, sig3_tf = {$s3_tf}, sig3_use_1 = {$s3_u1}, sig3_use_2 = {$s3_u2}, sig3_use_3 = {$s3_u3}, sig3_dbuy = {$s3_dbuy}, sig3_dclose = {$s3_dclose}, sig3_dsell = {$s3_dsell} WHERE id = " . $m_id);
            echo json_encode(array('status' => 'ok')); exit;
        }

        if ($action === 'save_tpsl') {
            $tp = (int)$_POST['tp_ticks']; $sl = (int)$_POST['sl_ticks'];
            mysqli_query($conn, "UPDATE `automeme_members` SET tp_ticks = " . $tp . ", sl_ticks = " . $sl . " WHERE id = " . $m_id);
            echo json_encode(array('status' => 'ok', 'tp_ticks' => $tp, 'sl_ticks' => $sl)); exit;
        }

        if ($action === 'open_trade') {
            $side = mysqli_real_escape_string($conn, $_POST['side']);
            $entry_p = (float)$_POST['entry_price'];
            $qty = (int)$_POST['qty'];
            if ($qty < 1) $qty = 1;

            $new_side = $side; $new_qty = $qty; $new_entry = $entry_p; $trade_mode = 'NEW';
            $realized_pnl = 0; $closed_qty = 0;

            if (empty($db_pos_side) || $db_pos_qty <= 0) {
                $new_side = $side; $new_qty = $qty; $new_entry = $entry_p; $trade_mode = 'NEW';
            } elseif ($db_pos_side === $side) {
                $total_cost = ($db_pos_entry * $db_pos_qty) + ($entry_p * $qty);
                $new_qty = $db_pos_qty + $qty;
                $new_entry = round(($total_cost / $new_qty) * 4) / 4;
                $new_side = $side; $trade_mode = 'ADD';
            } else {
                if ($db_pos_qty > $qty) {
                    $closed_qty = $qty;
                    $pt_diff = ($db_pos_side === 'BUY') ? ($entry_p - $db_pos_entry) : ($db_pos_entry - $entry_p);
                    $realized_pnl = round($pt_diff * 4000 * $closed_qty);
                    $cur_bal += $realized_pnl;
                    
                    $insert_q = "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES (" . $m_id . ", '" . $uname . "', 'NQ=F', '" . $db_pos_side . "', " . $db_pos_entry . ", " . $entry_p . ", " . $closed_qty . ", " . $realized_pnl . ")";
                    if(!mysqli_query($conn, $insert_q)) { echo json_encode(array('status'=>'fail', 'msg'=>'DB error: '.mysqli_error($conn))); exit; }
                    
                    $new_side = $db_pos_side; $new_qty = $db_pos_qty - $qty; $new_entry = $db_pos_entry; $trade_mode = 'PARTIAL_CLOSE';
                } elseif ($db_pos_qty === $qty) {
                    $closed_qty = $db_pos_qty;
                    $pt_diff = ($db_pos_side === 'BUY') ? ($entry_p - $db_pos_entry) : ($db_pos_entry - $entry_p);
                    $realized_pnl = round($pt_diff * 4000 * $closed_qty);
                    $cur_bal += $realized_pnl;
                    
                    $insert_q = "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES (" . $m_id . ", '" . $uname . "', 'NQ=F', '" . $db_pos_side . "', " . $db_pos_entry . ", " . $entry_p . ", " . $closed_qty . ", " . $realized_pnl . ")";
                    if(!mysqli_query($conn, $insert_q)) { echo json_encode(array('status'=>'fail', 'msg'=>'DB error: '.mysqli_error($conn))); exit; }
                    
                    $new_side = ''; $new_qty = 0; $new_entry = 0; $trade_mode = 'FULL_CLOSE';
                } else {
                    $closed_qty = $db_pos_qty;
                    $pt_diff = ($db_pos_side === 'BUY') ? ($entry_p - $db_pos_entry) : ($db_pos_entry - $entry_p);
                    $realized_pnl = round($pt_diff * 4000 * $closed_qty);
                    $cur_bal += $realized_pnl;
                    
                    $insert_q = "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES (" . $m_id . ", '" . $uname . "', 'NQ=F', '" . $db_pos_side . "', " . $db_pos_entry . ", " . $entry_p . ", " . $closed_qty . ", " . $realized_pnl . ")";
                    if(!mysqli_query($conn, $insert_q)) { echo json_encode(array('status'=>'fail', 'msg'=>'DB error: '.mysqli_error($conn))); exit; }
                    
                    $new_side = $side; $new_qty = $qty - $db_pos_qty; $new_entry = $entry_p; $trade_mode = 'REVERSE';
                }
            }

            mysqli_query($conn, "UPDATE `automeme_members` SET balance = " . $cur_bal . ", position_side = '" . $new_side . "', position_qty = " . $new_qty . ", position_entry = " . $new_entry . " WHERE id = " . $m_id);

            echo json_encode(array('status' => 'ok', 'trade_mode' => $trade_mode, 'balance' => $cur_bal, 'position_side' => $new_side, 'position_qty' => $new_qty, 'position_entry' => $new_entry, 'closed_qty' => $closed_qty, 'pnl' => $realized_pnl)); exit;
        }

        if ($action === 'close_trade') {
            if (empty($db_pos_side) || $db_pos_qty <= 0) { echo json_encode(array('status' => 'fail', 'msg' => 'No position')); exit; }
            $close_p = (float)$_POST['close_price'];
            $pt_diff = ($db_pos_side === 'BUY') ? ($close_p - $db_pos_entry) : ($db_pos_entry - $close_p);
            $pnl_val = round($pt_diff * 4000 * $db_pos_qty);
            $cur_bal += $pnl_val;
            
            mysqli_query($conn, "UPDATE `automeme_members` SET balance = " . $cur_bal . ", position_side = '', position_qty = 0, position_entry = 0 WHERE id = " . $m_id);
            
            $insert_q = "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES (" . $m_id . ", '" . $uname . "', 'NQ=F', '" . $db_pos_side . "', " . $db_pos_entry . ", " . $close_p . ", " . $db_pos_qty . ", " . $pnl_val . ")";
            if(!mysqli_query($conn, $insert_q)) { 
                echo json_encode(array('status'=>'fail', 'msg'=>'DB error: '.mysqli_error($conn))); exit; 
            }
            
            echo json_encode(array('status' => 'ok', 'balance' => $cur_bal, 'pnl' => $pnl_val, 'closed_qty' => $db_pos_qty)); exit;
        }
    }
}

$res = mysqli_query($conn, "SELECT * FROM `automeme_members` WHERE id = " . $m_id . " LIMIT 1");
$my_info = mysqli_fetch_assoc($res);
$my_grade = isset($my_info['grade']) ? $my_info['grade'] : '일반회원';

$db_balance = isset($my_info['balance']) ? (float)$my_info['balance'] : 0;
if ($db_balance <= 0) { $db_balance = 100000000; mysqli_query($conn, "UPDATE `automeme_members` SET balance = 100000000 WHERE id = " . $m_id); }

$saved_pos_side = isset($my_info['position_side']) ? $my_info['position_side'] : '';
$saved_pos_qty = isset($my_info['position_qty']) ? (int)$my_info['position_qty'] : 0;
$saved_pos_entry = isset($my_info['position_entry']) ? (float)$my_info['position_entry'] : 0.00;
$saved_tp = isset($my_info['tp_ticks']) ? (int)$my_info['tp_ticks'] : 0;
$saved_sl = isset($my_info['sl_ticks']) ? (int)$my_info['sl_ticks'] : 0;
$saved_order_qty = isset($my_info['order_qty']) ? (int)$my_info['order_qty'] : 1;
if ($saved_order_qty < 1) $saved_order_qty = 1;

$saved_auto_active = isset($my_info['auto_active']) ? (int)$my_info['auto_active'] : 0;

// 블록별 체크 상태 및 대기초 로드 (DB 저장값 우선, 없거나 0이면 기본값 5)
$saved_s1_act = isset($my_info['sig1_active']) ? (int)$my_info['sig1_active'] : 0; 
$saved_s1_tf = isset($my_info['sig1_tf']) ? (int)$my_info['sig1_tf'] : 600; 
$saved_s1_u1 = isset($my_info['sig1_use_1']) ? (int)$my_info['sig1_use_1'] : 0; 
$saved_s1_u2 = isset($my_info['sig1_use_2']) ? (int)$my_info['sig1_use_2'] : 0; 
$saved_s1_u3 = isset($my_info['sig1_use_3']) ? (int)$my_info['sig1_use_3'] : 0; 
$saved_s1_dbuy = (isset($my_info['sig1_dbuy']) && (int)$my_info['sig1_dbuy'] > 0) ? (int)$my_info['sig1_dbuy'] : 5; 
$saved_s1_dclose = (isset($my_info['sig1_dclose']) && (int)$my_info['sig1_dclose'] > 0) ? (int)$my_info['sig1_dclose'] : 5; 
$saved_s1_dsell = (isset($my_info['sig1_dsell']) && (int)$my_info['sig1_dsell'] > 0) ? (int)$my_info['sig1_dsell'] : 5;

$saved_s2_act = isset($my_info['sig2_active']) ? (int)$my_info['sig2_active'] : 0; 
$saved_s2_tf = isset($my_info['sig2_tf']) ? (int)$my_info['sig2_tf'] : 600; 
$saved_s2_u1 = isset($my_info['sig2_use_1']) ? (int)$my_info['sig2_use_1'] : 0; 
$saved_s2_u2 = isset($my_info['sig2_use_2']) ? (int)$my_info['sig2_use_2'] : 0; 
$saved_s2_u3 = isset($my_info['sig2_use_3']) ? (int)$my_info['sig2_use_3'] : 0; 
$saved_s2_dbuy = (isset($my_info['sig2_dbuy']) && (int)$my_info['sig2_dbuy'] > 0) ? (int)$my_info['sig2_dbuy'] : 5; 
$saved_s2_dclose = (isset($my_info['sig2_dclose']) && (int)$my_info['sig2_dclose'] > 0) ? (int)$my_info['sig2_dclose'] : 5; 
$saved_s2_dsell = (isset($my_info['sig2_dsell']) && (int)$my_info['sig2_dsell'] > 0) ? (int)$my_info['sig2_dsell'] : 5;

$saved_s3_act = isset($my_info['sig3_active']) ? (int)$my_info['sig3_active'] : 0; 
$saved_s3_tf = isset($my_info['sig3_tf']) ? (int)$my_info['sig3_tf'] : 600; 
$saved_s3_u1 = isset($my_info['sig3_use_1']) ? (int)$my_info['sig3_use_1'] : 0; 
$saved_s3_u2 = isset($my_info['sig3_use_2']) ? (int)$my_info['sig3_use_2'] : 0; 
$saved_s3_u3 = isset($my_info['sig3_use_3']) ? (int)$my_info['sig3_use_3'] : 0; 
$saved_s3_dbuy = (isset($my_info['sig3_dbuy']) && (int)$my_info['sig3_dbuy'] > 0) ? (int)$my_info['sig3_dbuy'] : 5; 
$saved_s3_dclose = (isset($my_info['sig3_dclose']) && (int)$my_info['sig3_dclose'] > 0) ? (int)$my_info['sig3_dclose'] : 5; 
$saved_s3_dsell = (isset($my_info['sig3_dsell']) && (int)$my_info['sig3_dsell'] > 0) ? (int)$my_info['sig3_dsell'] : 5;

$last_ord_q = mysqli_query($conn, "SELECT id FROM `automeme_orders` WHERE member_id = " . $m_id . " ORDER BY id DESC LIMIT 1");
$last_ord_r = $last_ord_q ? mysqli_fetch_assoc($last_ord_q) : null;
$init_last_order_id = $last_ord_r ? (int)$last_ord_r['id'] : 0;

function getNasdaq1MinMasterCandles() {
    $ts = time() . mt_rand(100, 999);
    $json5d = fetchYahooChartJson("https://query1.finance.yahoo.com/v8/finance/chart/NQ=F?interval=1m&range=5d&includePrePost=true&_=" . $ts);
    $json1d = fetchYahooChartJson("https://query1.finance.yahoo.com/v8/finance/chart/NQ=F?interval=1m&range=1d&_=" . $ts . "1");

    $map = array(); $livePrice = 0; $sources = array($json5d, $json1d);
    foreach ($sources as $json) {
        if (!$json || !isset($json['chart']['result'][0])) continue;
        $res0 = $json['chart']['result'][0];
        if (isset($res0['meta']['regularMarketPrice'])) {
            $p = (float)$res0['meta']['regularMarketPrice'];
            if ($p > 1000) $livePrice = round($p * 4) / 4;
        }
        if (!isset($res0['timestamp']) || !isset($res0['indicators']['quote'][0])) continue;

        $timestamps = $res0['timestamp']; $quote = $res0['indicators']['quote'][0]; $cnt = count($timestamps);
        for ($i = 0; $i < $cnt; $i++) {
            $t = (int)(floor((int)$timestamps[$i] / 60) * 60);
            $o = isset($quote['open'][$i]) ? (float)$quote['open'][$i] : 0;
            $h = isset($quote['high'][$i]) ? (float)$quote['high'][$i] : 0;
            $l = isset($quote['low'][$i]) ? (float)$quote['low'][$i] : 0;
            $c = isset($quote['close'][$i]) ? (float)$quote['close'][$i] : 0;
            $v = isset($quote['volume'][$i]) ? (int)$quote['volume'][$i] : 0;

            if ($o <= 1000 || $c <= 1000 || $h <= 1000 || $l <= 1000) continue;
            $map[$t] = array('time' => $t, 'open' => round($o * 4) / 4, 'high' => round($h * 4) / 4, 'low' => round($l * 4) / 4, 'close' => round($c * 4) / 4, 'volume' => ($v > 0 ? $v : 1));
        }
    }
    ksort($map);
    return array('price' => $livePrice, 'candles' => array_values($map));
}

$master_data = getNasdaq1MinMasterCandles();
$init_fut = $master_data['price'];
if ($init_fut <= 0) { $q_init = getNasdaqFuturesLiveQuote(); $init_fut = $q_init['price']; }
if ($init_fut <= 0) $init_fut = 30598.25;
$init_candles_json = json_encode($master_data['candles']);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>나스닥 100 선물 모의 트레이딩 룸</title>
    <script src="trading_signals.js?v=<?php echo time(); ?>"></script>
    <script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background-color: #121212; color: #ffffff; font-family: 'Malgun Gothic', sans-serif; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 8px 16px; background-color: #1e1e1e; border-bottom: 1px solid #333; flex-wrap: wrap; gap: 6px; z-index: 20; }
        header h2 { margin: 0; font-size: 15px; color: #fff; white-space: nowrap; }
        .user-bar { font-size: 12px; color: #cbd5e0; white-space: nowrap; display: flex; align-items: center; gap: 8px; }
        .user-bar a { color: #fc8181; text-decoration: none; margin-left: 4px; font-weight: bold; }
        .ledger-btn { background: #2b6cb0; color: #fff; padding: 4px 10px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: bold; transition: 0.2s; }
        .ledger-btn:hover { background: #3182ce; }

        .main-container { display: flex; flex: 1; overflow: hidden; width: 100%; position: relative; }
        
        .left-area { flex: 1; display: flex; flex-direction: column; height: 100%; background: #131722; min-width: 0; min-height: 0; overflow: hidden; position: relative; z-index: 1; }
        .chart-container { flex: 1; width: 100%; height: 100%; min-height: 0; position: relative; display: flex; flex-direction: column; overflow: hidden; }
        .custom-chart-wrapper { display: flex; flex-direction: column; width: 100%; height: 100%; min-height: 0; flex: 1; background: #131722; user-select: none; overflow: hidden; }
        
        #lwChartContainer { flex: 1; width: 100%; height: 100%; min-height: 0; position: relative; overflow: hidden; }

        .tf-btn { background: #2a2e39; color: #b2b5be; border: 1px solid #363c4e; padding: 5px 11px; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer; transition: all 0.15s; }
        .tf-btn:hover { background: #363c4e; color: #ffffff; }
        .tf-btn.active { background: #2962ff; color: #ffffff; border-color: #2962ff; box-shadow: 0 0 6px rgba(41, 98, 255, 0.6); }

        .order-panel { width: 380px; flex-shrink: 0; background-color: #1e1e1e; border-left: 1px solid #333; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; overflow-y: auto; position: relative; z-index: 10; }

        @media (max-width: 860px) {
            body { height: auto !important; min-height: 100vh; overflow-y: auto !important; overflow-x: hidden; }
            .main-container { flex-direction: column; overflow: visible !important; height: auto !important; min-height: 100vh; }
            .left-area { height: 50vh; min-height: 350px; flex: none; overflow: hidden; border-bottom: 2px solid #2d3748; }
            .order-panel { width: 100%; border-left: none; padding-top: 14px; height: auto; overflow: visible !important; flex: none; padding-bottom: 60px; }
        }

        .top-action-bar { background: #252525; border: 1px solid #383838; border-radius: 6px; padding: 10px; margin-bottom:4px; }
        .market-price-banner { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; padding: 8px 10px; background: #14181f; border-radius: 4px; border: 1px solid #2d3748; font-size: 12px; min-height: 46px; }
        .live-market-input { width: 135px; height: 32px; line-height: 28px; background: transparent; border: 1px dashed rgba(0, 230, 118, 0.5); border-radius: 4px; padding: 2px 6px; text-align: right; font-size: 18px; font-weight: bold; color: #00e676; font-family: Consolas, monospace; }
        .btn-group { display: grid; grid-template-columns: 1fr 0.9fr 1fr; gap: 8px; }
        button.trade-btn { padding: 12px 6px; font-weight: bold; font-size: 14px; border: 2px solid transparent; border-radius: 5px; cursor: pointer; color: #fff; white-space: nowrap; box-shadow: 0 2px 5px rgba(0,0,0,0.3); transition: all 0.2s; }
        .btn-buy   { background-color: #d32f2f; }
        .btn-close { background-color: #f57c00; }
        .btn-sell  { background-color: #1976d2; }
        button.trade-btn:hover { opacity: 0.92; }

        details.ui-panel { background: #1e1e1e; border: 1px solid #333; border-radius: 6px; }
        details.ui-panel > summary { font-size: 13px; font-weight: bold; color: #4CAF50; padding: 10px 12px; cursor: pointer; background: #252525; list-style: none; display: flex; justify-content: space-between; align-items: center; border-radius: 6px; }
        details.ui-panel > summary::-webkit-details-marker { display: none; }
        details.ui-panel > summary::after { content: '▼'; font-size: 10px; color: #cbd5e0; }
        details.ui-panel[open] > summary { border-bottom-left-radius: 0; border-bottom-right-radius: 0; border-bottom: 1px solid #333; }
        details.ui-panel[open] > summary::after { content: '▲'; }
        .panel-content { padding: 12px; }

        .info-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; gap: 8px; padding: 4px 6px; border-radius: 4px; }
        .info-row:last-child { margin-bottom: 0; }
        .info-row strong { text-align: right; font-size: 14px; }

        @keyframes blinkPositionBuy { 0%, 70%, 100% { background-color: rgba(255, 59, 59, 0.12); border-color: rgba(255, 59, 59, 0.45); } 85% { background-color: rgba(255, 59, 59, 0.45); border-color: #ff3b3b; box-shadow: 0 0 12px rgba(255, 59, 59, 0.75); } }
        @keyframes blinkPositionSell { 0%, 70%, 100% { background-color: rgba(41, 121, 255, 0.12); border-color: rgba(41, 121, 255, 0.45); } 85% { background-color: rgba(41, 121, 255, 0.45); border-color: #2979ff; box-shadow: 0 0 12px rgba(41, 121, 255, 0.75); } }
        .pos-blink-buy { animation: blinkPositionBuy 3s infinite ease-in-out; }
        .pos-blink-sell { animation: blinkPositionSell 3s infinite ease-in-out; }

        .tp-sl-grid-3 { display: grid; grid-template-columns: 1.1fr 0.9fr 1.1fr; gap: 6px; }
        .tp-sl-box { background: #252525; border: 1px solid #383838; border-radius: 4px; padding: 6px 6px; text-align: center; }
        .tp-sl-box label { font-size: 10px; color: #a0aec0; display: block; margin-bottom: 2px; }
        .tp-sl-input { width: 100%; background: #1a1a1a; border: 1px solid #444; color: #fff; padding: 5px 4px; border-radius: 3px; font-size: 13px; text-align: center; font-weight: bold; }
        .reset-mid-btn { width: 100%; height: 100%; background: #2d3748; color: #cbd5e0; border: 1px solid #4a5568; padding: 5px 4px; font-size: 12px; font-weight: bold; border-radius: 3px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; }
        .reset-mid-btn:hover { background: #4a5568; color: #fff; }

        input.form-input { width: 100%; padding: 7px 10px; background: #2a2a2a; border: 1px solid #444; color: #fff; border-radius: 4px; font-size: 14px; }
        .qty-quick-btns { display: flex; gap: 6px; margin-top: 6px; }
        .qty-btn { flex: 1; background: #333; color: #ddd; border: 1px solid #444; padding: 6px; border-radius: 4px; font-size: 12px; cursor: pointer; }
        .qty-btn:hover { background: #444; color: #fff; }

        .log-box { background: #111; border: 1px solid #333; height: 180px; min-height: 120px; max-height: 500px; resize: vertical; overflow-y: auto; padding: 8px; font-size: 12px; font-family: monospace; color: #00ffcc; border-radius: 4px; line-height: 1.4; }
    </style>
</head>
<body>

    <header>
        <h2>📈 나스닥 100 선물 모의 트레이딩 룸</h2>
        <div class="user-bar">
            <a href="trade_history.php" target="_blank" class="ledger-btn">📋 거래내역</a>
            <span><b><?php echo htmlspecialchars((string)$my_info['name']); ?></b> (<?php echo htmlspecialchars((string)$my_info['username']); ?>)님 환영합니다!</span>
            <a href="member_logout.php">로그아웃</a>
        </div>
    </header>

    <div class="main-container">
        <div class="left-area">
            <div class="chart-container">
                <div class="custom-chart-wrapper">
                    <details id="chart-toolbar-details" open style="background:#1e222d; border-bottom:1px solid #2a2e39;">
                        <summary style="padding:6px 12px; font-size:12px; font-weight:bold; color:#d1d4dc; border:none; background:transparent; cursor:pointer; list-style:none; display:flex; justify-content:space-between; align-items:center;">
                            <span>📊 뷰어 도구 모음 (접기/펼치기)</span>
                            <div onclick="event.stopPropagation();" style="display:flex; align-items:center; gap:6px; background:#14181f; padding:3px 8px; border-radius:4px; border:1px solid #363c4e;">
                                <span style="font-size:11px; font-weight:bold; color:#fff;">🤖 자동매매</span>
                                <span id="autotrade-status-text" style="font-size:11px; color:#a0aec0;">(OFF)</span>
                                <?php if ($my_grade === '정회원'): ?>
                                    <button type="button" id="autotrade-toggle-btn" onclick="toggleAutoTrading()" style="background:#2d3748; color:#cbd5e0; border:1px solid #4a5568; padding:3px 8px; border-radius:3px; font-size:11px; font-weight:bold; cursor:pointer; transition:0.2s;">시작 (OFF)</button>
                                <?php else: ?>
                                    <button type="button" onclick="alert('자동매매 시스템은 [정회원]만 이용 가능합니다.');" style="background:#444; color:#999; border:1px solid #555; padding:3px 8px; border-radius:3px; font-size:11px; font-weight:bold; cursor:not-allowed;">🔒 정회원전용</button>
                                <?php endif; ?>
                            </div>
                        </summary>
                        <div style="padding:0 12px 8px 12px; display:flex; flex-direction:column; gap:6px;">
                            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                <span id="chartTitleLabel" style="color:#d1d4dc; font-weight:bold; font-size:14px;">US Nas 100 선물 · 10분봉</span>
                                <span id="chartOhlcInfo" style="color:#00e676; font-size:12px; font-family:monospace;"></span>
                            </div>
                            <div id="tfButtonGroup" style="display:flex; gap:4px; flex-wrap:wrap; margin-top:4px;">
                                <button type="button" class="tf-btn" onclick="changeTimeframe(60, '1분봉', this)">1분</button>
                                <button type="button" class="tf-btn" onclick="changeTimeframe(180, '3분봉', this)">3분</button>
                                <button type="button" class="tf-btn" onclick="changeTimeframe(300, '5분봉', this)">5분</button>
                                <button type="button" class="tf-btn active" onclick="changeTimeframe(600, '10분봉', this)">10분</button>
                                <button type="button" class="tf-btn" onclick="changeTimeframe(900, '15분봉', this)">15분</button>
                                <button type="button" class="tf-btn" onclick="changeTimeframe(1800, '30분봉', this)">30분</button>
                                <button type="button" class="tf-btn" onclick="changeTimeframe(3600, '1시간봉', this)">1시간</button>
                            </div>
                        </div>
                    </details>
                    
                    <div id="lwChartContainer"></div>

                </div>
            </div>
        </div>

        <div class="order-panel">
            <div class="top-action-bar">
                <div class="market-price-banner">
                    <div>
                        <span style="display:block; font-weight:bold; color:#e2e8f0;">⚡ 실시간 선물 시장가:</span>
                        <div id="sync-status" style="font-size:10px; color:#68d391; margin-top:2px;">🟢 실시간 수신중</div>
                    </div>
                    <input type="text" id="live-market-price" class="live-market-input" value="<?php echo number_format($init_fut, 2); ?>" onchange="onManualPriceInput(this.value)" onclick="this.select();" onfocus="setTimeout(()=>this.select(), 50);">
                </div>
                <div class="btn-group">
                    <button id="btn-buy-order" class="trade-btn btn-buy" onclick="marketOrder('BUY')">매수 (LONG)</button>
                    <button id="btn-close-order" class="trade-btn btn-close" onclick="closeOrder()">청산 (CLOSE)</button>
                    <button id="btn-sell-order" class="trade-btn btn-sell" onclick="marketOrder('SELL')">매도 (SHORT)</button>
                </div>
            </div>

            <details class="ui-panel" open>
                <summary>📊 계좌 및 실시간 자산 <span style="font-size:10px; font-weight:normal; color:#a0aec0;">0.25pt = ±1,000원</span></summary>
                <div class="panel-content" style="background:#2a2a2a; border-radius:4px;">
                    <div class="info-row">
                        <span style="font-size:12px;">기본 예수금:</span>
                        <strong id="base-balance" style="color:#cbd5e0;"><?php echo number_format($db_balance, 0); ?>원</strong>
                    </div>
                    <div class="info-row" style="border-bottom:1px dashed #444; padding-bottom:6px;">
                        <span style="font-size:12px;">실시간 평가자산:</span>
                        <strong id="total-equity" style="color:#68d391; font-size:15px;"><?php echo number_format($db_balance, 0); ?>원</strong>
                    </div>
                    <div id="position-row" class="info-row" style="margin-top:4px;">
                        <span style="font-size:12px;">보유 포지션:</span>
                        <strong id="position"><?php echo ($saved_pos_qty > 0) ? (($saved_pos_side === 'BUY' ? '매수(LONG)' : '매도(SHORT)') . ' (' . $saved_pos_qty . '계약)') : '없음'; ?></strong>
                    </div>
                    <div class="info-row">
                        <span style="font-size:12px;">진입 가격:   </span>
                        <strong id="entry-price"><?php echo ($saved_pos_qty > 0) ? number_format($saved_pos_entry, 2) : '-'; ?></strong>
                    </div>
                    <div class="info-row">
                        <span style="font-size:12px;">평가 손익:</span>
                        <strong id="pnl" style="color:#aaa;">0원 (0.00pt)</strong>
                    </div>
                </div>
            </details>

            <?php if ($my_grade === '정회원'): ?>
            <details class="ui-panel" open>
                <summary>⚙️ 자동매매 설정값 (블록별 분봉 & 신호 조합)</summary>
                <div class="panel-content" style="display:flex; flex-direction:column; gap:10px;">
                    
                    <!-- 블록 1 -->
                    <div style="background:#252525; border:1px solid #383838; border-radius:4px; padding:8px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label style="display:flex; align-items:center; gap:6px; font-size:12px; font-weight:bold; color:#ff5252; cursor:pointer;">
                                <input type="checkbox" id="sig1-active" <?php echo ($saved_s1_act == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()" style="accent-color:#ff5252; width:15px; height:15px; cursor:pointer;">
                                🔴 설정 블록 1번 사용
                            </label>
                            <select id="auto-tf-1" style="background:#1a1a1a; color:#fff; border:1px solid #444; padding:3px 6px; border-radius:3px; font-size:11px;" onchange="saveAutoTradeSettingsToDB()">
                                <option value="60" <?php echo ($saved_s1_tf == 60) ? 'selected' : ''; ?>>1분봉</option>
                                <option value="180" <?php echo ($saved_s1_tf == 180) ? 'selected' : ''; ?>>3분봉</option>
                                <option value="300" <?php echo ($saved_s1_tf == 300) ? 'selected' : ''; ?>>5분봉</option>
                                <option value="600" <?php echo ($saved_s1_tf == 600) ? 'selected' : ''; ?>>10분봉</option>
                                <option value="900" <?php echo ($saved_s1_tf == 900) ? 'selected' : ''; ?>>15분봉</option>
                                <option value="1800" <?php echo ($saved_s1_tf == 1800) ? 'selected' : ''; ?>>30분봉</option>
                                <option value="3600" <?php echo ($saved_s1_tf == 3600) ? 'selected' : ''; ?>>1시간봉</option>
                            </select>
                        </div>
                        <div style="display:flex; gap:12px; margin-bottom:6px; font-size:11px; color:#cbd5e0; background:#1e1e1e; padding:5px 8px; border-radius:3px;">
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px;"><input type="checkbox" id="s1-u1" <?php echo ($saved_s1_u1 == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()"> 신호1</label>
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px;"><input type="checkbox" id="s1-u2" <?php echo ($saved_s1_u2 == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()"> 신호2</label>
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px;"><input type="checkbox" id="s1-u3" <?php echo ($saved_s1_u3 == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()"> 신호3</label>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:4px;">
                            <div class="tp-sl-box"><label>매수 대기초</label><input type="number" id="delay-buy-1" class="tp-sl-input" value="<?php echo $saved_s1_dbuy; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="saveAutoTradeSettingsToDB()"></div>
                            <div class="tp-sl-box"><label>청산 대기초</label><input type="number" id="delay-close-1" class="tp-sl-input" value="<?php echo $saved_s1_dclose; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="saveAutoTradeSettingsToDB()"></div>
                            <div class="tp-sl-box"><label>매도 대기초</label><input type="number" id="delay-sell-1" class="tp-sl-input" value="<?php echo $saved_s1_dsell; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="saveAutoTradeSettingsToDB()"></div>
                        </div>
                    </div>

                    <!-- 블록 2 -->
                    <div style="background:#252525; border:1px solid #383838; border-radius:4px; padding:8px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label style="display:flex; align-items:center; gap:6px; font-size:12px; font-weight:bold; color:#448aff; cursor:pointer;">
                                <input type="checkbox" id="sig2-active" <?php echo ($saved_s2_act == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()" style="accent-color:#448aff; width:15px; height:15px; cursor:pointer;">
                                🔵 설정 블록 2번 사용
                            </label>
                            <select id="auto-tf-2" style="background:#1a1a1a; color:#fff; border:1px solid #444; padding:3px 6px; border-radius:3px; font-size:11px;" onchange="saveAutoTradeSettingsToDB()">
                                <option value="60" <?php echo ($saved_s2_tf == 60) ? 'selected' : ''; ?>>1분봉</option>
                                <option value="180" <?php echo ($saved_s2_tf == 180) ? 'selected' : ''; ?>>3분봉</option>
                                <option value="300" <?php echo ($saved_s2_tf == 300) ? 'selected' : ''; ?>>5분봉</option>
                                <option value="600" <?php echo ($saved_s2_tf == 600) ? 'selected' : ''; ?>>10분봉</option>
                                <option value="900" <?php echo ($saved_s2_tf == 900) ? 'selected' : ''; ?>>15분봉</option>
                                <option value="1800" <?php echo ($saved_s2_tf == 1800) ? 'selected' : ''; ?>>30분봉</option>
                                <option value="3600" <?php echo ($saved_s2_tf == 3600) ? 'selected' : ''; ?>>1시간봉</option>
                            </select>
                        </div>
                        <div style="display:flex; gap:12px; margin-bottom:6px; font-size:11px; color:#cbd5e0; background:#1e1e1e; padding:5px 8px; border-radius:3px;">
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px;"><input type="checkbox" id="s2-u1" <?php echo ($saved_s2_u1 == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()"> 신호1</label>
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px;"><input type="checkbox" id="s2-u2" <?php echo ($saved_s2_u2 == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()"> 신호2</label>
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px;"><input type="checkbox" id="s2-u3" <?php echo ($saved_s2_u3 == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()"> 신호3</label>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:4px;">
                            <div class="tp-sl-box"><label>매수 대기초</label><input type="number" id="delay-buy-2" class="tp-sl-input" value="<?php echo $saved_s2_dbuy; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="saveAutoTradeSettingsToDB()"></div>
                            <div class="tp-sl-box"><label>청산 대기초</label><input type="number" id="delay-close-2" class="tp-sl-input" value="<?php echo $saved_s2_dclose; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="saveAutoTradeSettingsToDB()"></div>
                            <div class="tp-sl-box"><label>매도 대기초</label><input type="number" id="delay-sell-2" class="tp-sl-input" value="<?php echo $saved_s2_dsell; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="saveAutoTradeSettingsToDB()"></div>
                        </div>
                    </div>

                    <!-- 블록 3 -->
                    <div style="background:#252525; border:1px solid #383838; border-radius:4px; padding:8px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label style="display:flex; align-items:center; gap:6px; font-size:12px; font-weight:bold; color:#4caf50; cursor:pointer;">
                                <input type="checkbox" id="sig3-active" <?php echo ($saved_s3_act == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()" style="accent-color:#4caf50; width:15px; height:15px; cursor:pointer;">
                                🟢 설정 블록 3번 사용
                            </label>
                            <select id="auto-tf-3" style="background:#1a1a1a; color:#fff; border:1px solid #444; padding:3px 6px; border-radius:3px; font-size:11px;" onchange="saveAutoTradeSettingsToDB()">
                                <option value="60" <?php echo ($saved_s3_tf == 60) ? 'selected' : ''; ?>>1분봉</option>
                                <option value="180" <?php echo ($saved_s3_tf == 180) ? 'selected' : ''; ?>>3분봉</option>
                                <option value="300" <?php echo ($saved_s3_tf == 300) ? 'selected' : ''; ?>>5분봉</option>
                                <option value="600" <?php echo ($saved_s3_tf == 600) ? 'selected' : ''; ?>>10분봉</option>
                                <option value="900" <?php echo ($saved_s3_tf == 900) ? 'selected' : ''; ?>>15분봉</option>
                                <option value="1800" <?php echo ($saved_s3_tf == 1800) ? 'selected' : ''; ?>>30분봉</option>
                                <option value="3600" <?php echo ($saved_s3_tf == 3600) ? 'selected' : ''; ?>>1시간봉</option>
                            </select>
                        </div>
                        <div style="display:flex; gap:12px; margin-bottom:6px; font-size:11px; color:#cbd5e0; background:#1e1e1e; padding:5px 8px; border-radius:3px;">
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px;"><input type="checkbox" id="s3-u1" <?php echo ($saved_s3_u1 == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()"> 신호1</label>
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px;"><input type="checkbox" id="s3-u2" <?php echo ($saved_s3_u2 == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()"> 신호2</label>
                            <label style="cursor:pointer; display:flex; align-items:center; gap:4px;"><input type="checkbox" id="s3-u3" <?php echo ($saved_s3_u3 == 1) ? 'checked' : ''; ?> onchange="saveAutoTradeSettingsToDB()"> 신호3</label>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:4px;">
                            <div class="tp-sl-box"><label>매수 대기초</label><input type="number" id="delay-buy-3" class="tp-sl-input" value="<?php echo $saved_s3_dbuy; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="saveAutoTradeSettingsToDB()"></div>
                            <div class="tp-sl-box"><label>청산 대기초</label><input type="number" id="delay-close-3" class="tp-sl-input" value="<?php echo $saved_s3_dclose; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="saveAutoTradeSettingsToDB()"></div>
                            <div class="tp-sl-box"><label>매도 대기초</label><input type="number" id="delay-sell-3" class="tp-sl-input" value="<?php echo $saved_s3_dsell; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="saveAutoTradeSettingsToDB()"></div>
                        </div>
                    </div>

                </div>
            </details>
            <?php endif; ?>

            <details class="ui-panel">
                <summary>🎯 자동 익절 / 손절 설정</summary>
                <div class="panel-content">
                    <div class="tp-sl-grid-3">
                        <div class="tp-sl-box">
                            <label style="color:#68d391;">🟢 익절 틱수 (TP)</label>
                            <input type="number" id="tp-ticks" class="tp-sl-input" value="<?php echo $saved_tp; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="confirmTpSlSetting('익절')">
                        </div>
                        <div class="tp-sl-box" style="display:flex; flex-direction:column; justify-content:flex-end;">
                            <label style="color:#cbd5e0;">리셋</label>
                            <button type="button" class="reset-mid-btn" onclick="resetAllTpSl()">초기화</button>
                        </div>
                        <div class="tp-sl-box">
                            <label style="color:#ff5252;">🔴 손절 틱수 (SL)</label>
                            <input type="number" id="sl-ticks" class="tp-sl-input" value="<?php echo $saved_sl; ?>" min="0" inputmode="numeric" onfocus="this.select();" onchange="confirmTpSlSetting('손절')">
                        </div>
                    </div>
                </div>
            </details>

            <details class="ui-panel">
                <summary>⚡ 계약 수량 설정</summary>
                <div class="panel-content">
                    <input type="number" id="order-qty" class="form-input" value="<?php echo $saved_order_qty; ?>" min="1" max="100" inputmode="numeric" onfocus="this.select();" onchange="saveOrderQtyToDB()">
                    <div class="qty-quick-btns">
                        <button type="button" class="qty-btn" onclick="setQty(1)">1계약</button>
                        <button type="button" class="qty-btn" onclick="setQty(2)">2계약</button>
                        <button type="button" class="qty-btn" onclick="setQty(5)">5계약</button>
                        <button type="button" class="qty-btn" onclick="setQty(10)">10계약</button>
                    </div>
                </div>
            </details>

            <details class="ui-panel" open>
                <summary>📝 실시간 체결 및 정산 로그</summary>
                <div class="panel-content" style="padding:4px;">
                    <div id="log-box" class="log-box"></div>
                </div>
            </details>
        </div>
    </div>

    <script>
        var currentMemberId = <?php echo $m_id; ?>;
        var userGrade = '<?php echo $my_grade; ?>'; 
        var baseBalance = <?php echo $db_balance; ?>;
        var currentMarketPrice = <?php echo $init_fut; ?>;
        var pointValue = 4000;
        var isEditingPrice = false;
        var isEditingTpSl = false;

        var hasPosition = <?php echo ($saved_pos_qty > 0) ? 'true' : 'false'; ?>;
        var currentSide = '<?php echo $saved_pos_side; ?>';
        var currentAction = '<?php echo ($saved_pos_side === 'BUY') ? '매수(LONG)' : (($saved_pos_side === 'SELL') ? '매도(SHORT)' : ''); ?>';
        var currentQty = <?php echo $saved_pos_qty; ?>;
        var currentEntry = <?php echo $saved_pos_entry; ?>;
        var currentPnl = 0;
        var lastSyncedOrderId = <?php echo $init_last_order_id; ?>;

        var isClosing = false;
        var isOrdering = false;
        var activeTpTicks = <?php echo $saved_tp; ?>;
        var activeSlTicks = <?php echo $saved_sl; ?>;

        var tpInputBox = document.getElementById('tp-ticks');
        var slInputBox = document.getElementById('sl-ticks');
        var priceInputBox = document.getElementById('live-market-price');

        var autoTradingActive = <?php echo $saved_auto_active; ?>;
        var autoTimerCount = 0;
        var targetAutoAction = '';
        var signalLossGrace = 0; 

        window.onload = function() {
            var savedLogs = localStorage.getItem('trade_logs_' + currentMemberId);
            if(savedLogs) { document.getElementById('log-box').innerHTML = savedLogs; } 
            else { logMessage('[시스템] 서버 DB 연동 모니터링 뷰어 로드 완료.'); }
            updateAutoTradeUIState();
        };

        function logMessage(msg) {
            var box = document.getElementById('log-box');
            var now = new Date().toTimeString().split(' ')[0];
            box.innerHTML = '[' + now + '] ' + msg + '<br>' + box.innerHTML;
            localStorage.setItem('trade_logs_' + currentMemberId, box.innerHTML);
        }

        function saveOrderQtyToDB() {
            var qtyVal = document.getElementById('order-qty').value;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'member_index.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('ajax_action=save_order_qty&order_qty=' + qtyVal);
        }

        function setQty(n) {
            document.getElementById('order-qty').value = n;
            saveOrderQtyToDB();
        }

        function saveAutoTradeSettingsToDB() {
            if (userGrade !== '정회원') return;
            var activeVal = autoTradingActive ? 1 : 0;
            var s1Act = document.getElementById('sig1-active').checked ? 1 : 0; var s1Tf = document.getElementById('auto-tf-1').value;
            var s1U1 = document.getElementById('s1-u1').checked ? 1 : 0; var s1U2 = document.getElementById('s1-u2').checked ? 1 : 0; var s1U3 = document.getElementById('s1-u3').checked ? 1 : 0;
            var s1Dbuy = document.getElementById('delay-buy-1').value; var s1Dclose = document.getElementById('delay-close-1').value; var s1Dsell = document.getElementById('delay-sell-1').value;

            var s2Act = document.getElementById('sig2-active').checked ? 1 : 0; var s2Tf = document.getElementById('auto-tf-2').value;
            var s2U1 = document.getElementById('s2-u1').checked ? 1 : 0; var s2U2 = document.getElementById('s2-u2').checked ? 1 : 0; var s2U3 = document.getElementById('s2-u3').checked ? 1 : 0;
            var s2Dbuy = document.getElementById('delay-buy-2').value; var s2Dclose = document.getElementById('delay-close-2').value; var s2Dsell = document.getElementById('delay-sell-2').value;

            var s3Act = document.getElementById('sig3-active').checked ? 1 : 0; var s3Tf = document.getElementById('auto-tf-3').value;
            var s3U1 = document.getElementById('s3-u1').checked ? 1 : 0; var s3U2 = document.getElementById('s3-u2').checked ? 1 : 0; var s3U3 = document.getElementById('s3-u3').checked ? 1 : 0;
            var s3Dbuy = document.getElementById('delay-buy-3').value; var s3Dclose = document.getElementById('delay-close-3').value; var s3Dsell = document.getElementById('delay-sell-3').value;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'member_index.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('ajax_action=save_autotrade_settings&auto_active=' + activeVal +
                     '&sig1_active=' + s1Act + '&sig1_tf=' + s1Tf + '&sig1_use_1=' + s1U1 + '&sig1_use_2=' + s1U2 + '&sig1_use_3=' + s1U3 + '&sig1_dbuy=' + s1Dbuy + '&sig1_dclose=' + s1Dclose + '&sig1_dsell=' + s1Dsell +
                     '&sig2_active=' + s2Act + '&sig2_tf=' + s2Tf + '&sig2_use_1=' + s2U1 + '&sig2_use_2=' + s2U2 + '&sig2_use_3=' + s2U3 + '&sig2_dbuy=' + s2Dbuy + '&sig2_dclose=' + s2Dclose + '&sig2_dsell=' + s2Dsell +
                     '&sig3_active=' + s3Act + '&sig3_tf=' + s3Tf + '&sig3_use_1=' + s3U1 + '&sig3_use_2=' + s3U2 + '&sig3_use_3=' + s3U3 + '&sig3_dbuy=' + s3Dbuy + '&sig3_dclose=' + s3Dclose + '&sig3_dsell=' + s3Dsell);
        }

        function updateAutoTradeUIState() {
            var btn = document.getElementById('autotrade-toggle-btn');
            var txt = document.getElementById('autotrade-status-text');
            if (!btn || !txt) return;

            if (autoTradingActive) {
                btn.innerText = '중지 (ON)'; btn.style.background = '#2e7d32'; btn.style.color = '#ffffff'; btn.style.borderColor = '#4caf50';
                txt.innerText = '(실행중)'; txt.style.color = '#68d391';
            } else {
                btn.innerText = '시작 (OFF)'; btn.style.background = '#2d3748'; btn.style.color = '#cbd5e0'; btn.style.borderColor = '#4a5568';
                txt.innerText = '(OFF)'; txt.style.color = '#a0aec0';
            }
        }

        function toggleAutoTrading() {
            if (userGrade !== '정회원') { alert('자동매매 시스템은 [정회원]만 이용 가능합니다.'); return; }
            autoTradingActive = !autoTradingActive;
            updateAutoTradeUIState();
            saveAutoTradeSettingsToDB();

            if (autoTradingActive) logMessage('[자동매매] 서버 연동 모니터링 모드 활성화 (서버 단일 통제)');
            else { logMessage('[자동매매] 연동 중지'); autoTimerCount = 0; targetAutoAction = ''; }
        }

        function applyPositionVisualState() {
            var posRow = document.getElementById('position-row');
            var posEl = document.getElementById('position');
            var entryEl = document.getElementById('entry-price');
            var buyBtn = document.getElementById('btn-buy-order');
            var sellBtn = document.getElementById('btn-sell-order');

            posRow.classList.remove('pos-blink-buy', 'pos-blink-sell'); buyBtn.classList.remove('pos-blink-buy'); sellBtn.classList.remove('pos-blink-sell');

            if (hasPosition && currentQty > 0) {
                currentAction = (currentSide === 'BUY') ? '매수(LONG)' : '매도(SHORT)';
                posEl.innerText = currentAction + ' (' + currentQty + '계약)';
                entryEl.innerText = formatPrice(currentEntry);
                if (currentSide === 'BUY') { posEl.style.color = '#ff5252'; posRow.classList.add('pos-blink-buy'); buyBtn.classList.add('pos-blink-buy'); } 
                else { posEl.style.color = '#448aff'; posRow.classList.add('pos-blink-sell'); sellBtn.classList.add('pos-blink-sell'); }
            } else {
                hasPosition = false; currentSide = ''; currentAction = ''; currentQty = 0; currentEntry = 0; currentPnl = 0;
                posEl.innerText = '없음'; posEl.style.color = '#ffffff'; entryEl.innerText = '-';
                document.getElementById('pnl').innerText = '0원 (0.00pt)'; document.getElementById('pnl').style.color = '#aaa';
            }
        }

        var master1MinMap = {}; var master1MinCandles = [];
        var serverMasterCandles = <?php echo $init_candles_json ? $init_candles_json : '[]'; ?>;
        var KST_OFFSET = 9 * 3600;
        var currentIntervalSec = 600; 
        var lwChart = null;
        var candleSeries, volumeSeries, signal2Series, signal3Series, bbUpperSeries, bbMiddleSeries, bbLowerSeries;
        var high20Series, low20Series, vw02Series, vw05Series, ma2Series;
        var candleHistory = [];
        var currentBar = null;

        function roundTick(val) { return Math.round(val * 4) / 4; }
        function seededRandom(seed) { var x = Math.sin(seed * 12.9898) * 43758.5453; return x - Math.floor(x); }

        function buildMaster1MinCandles(basePrice) {
            master1MinMap = {}; master1MinCandles = [];
            if (serverMasterCandles && serverMasterCandles.length > 10) {
                for (var i = 0; i < serverMasterCandles.length; i++) {
                    var sc = serverMasterCandles[i];
                    var tKst = Math.floor((Number(sc.time) + KST_OFFSET) / 60) * 60;
                    master1MinMap[tKst] = { time: tKst, open: roundTick(Number(sc.open)), high: roundTick(Number(sc.high)), low: roundTick(Number(sc.low)), close: roundTick(Number(sc.close)), volume: Number(sc.volume || 120) };
                }
            }
            var times = Object.keys(master1MinMap).map(Number).sort(function(a, b) { return a - b; });
            if (times.length === 0) {
                var nowKst = Math.floor(Date.now() / 1000) + KST_OFFSET;
                var alignedMin = Math.floor(nowKst / 60) * 60;
                master1MinMap[alignedMin] = { time: alignedMin, open: basePrice, high: basePrice, low: basePrice, close: basePrice, volume: 200 };
                times = [alignedMin];
            }
            for (var j = 0; j < times.length; j++) { master1MinCandles.push(master1MinMap[times[j]]); }
            var targetCount = 4500;
            while (master1MinCandles.length < targetCount) {
                var first = master1MinCandles[0]; var prevTime = first.time - 60;
                var r1 = seededRandom(prevTime), r2 = seededRandom(prevTime + 1), r3 = seededRandom(prevTime + 2);
                var prevClose = first.open; var delta = (r1 - 0.498) * 2.0;
                var prevOpen = roundTick(prevClose - delta);
                var prevHigh = roundTick(Math.max(prevOpen, prevClose) + r2 * 1.25);
                var prevLow = roundTick(Math.min(prevOpen, prevClose) - r3 * 1.25);
                var item = { time: prevTime, open: prevOpen, high: prevHigh, low: prevLow, close: prevClose, volume: Math.floor(r1 * 200) + 50 };
                master1MinCandles.unshift(item); master1MinMap[prevTime] = item;
            }
            var last = master1MinCandles[master1MinCandles.length - 1];
            if (last && basePrice > 1000) { last.close = basePrice; if (basePrice > last.high) last.high = basePrice; if (basePrice < last.low) last.low = basePrice; }
        }

        function aggregateCandles(master1M, intervalSec) {
            if (!master1M || master1M.length === 0) return [];
            var bucketsMap = {}; var bucketTimes = [];
            for (var i = 0; i < master1M.length; i++) {
                var m = master1M[i];
                var bTime = Math.floor(m.time / intervalSec) * intervalSec;
                if (!bucketsMap[bTime]) {
                    bucketsMap[bTime] = { time: bTime, open: m.open, high: m.high, low: m.low, close: m.close, volume: m.volume };
                    bucketTimes.push(bTime);
                } else {
                    var b = bucketsMap[bTime];
                    if (m.high > b.high) b.high = m.high;
                    if (m.low < b.low) b.low = m.low;
                    b.close = m.close; b.volume += m.volume;
                }
            }
            bucketTimes.sort(function(a, b) { return a - b; });
            var res = []; for (var j = 0; j < bucketTimes.length; j++) { res.push(bucketsMap[bucketTimes[j]]); }
            return res;
        }

        function renderAggregatedTimeframe(intervalSec) {
            if (!candleSeries || master1MinCandles.length === 0) return;
            candleHistory = aggregateCandles(master1MinCandles, intervalSec);
            currentBar = candleHistory[candleHistory.length - 1];
            candleSeries.setData(candleHistory);
            updateBollingerBands();
            updateOhlcHeader(currentBar);
            if (lwChart) {
                lwChart.timeScale().applyOptions({ barSpacing: 10, rightOffset: 5 });
                lwChart.timeScale().scrollToRealTime();
            }
        }

        function initCustomChart() {
            var container = document.getElementById('lwChartContainer');
            if (!container || typeof LightweightCharts === 'undefined') return;

            var initH = container.clientHeight;
            if (!initH || initH < 200) { initH = window.innerWidth <= 860 ? 330 : 500; }

            lwChart = LightweightCharts.createChart(container, {
                width: container.clientWidth, height: initH,
                layout: { background: { type: 'solid', color: '#131722' }, textColor: '#d1d4dc' },
                grid: { vertLines: { color: '#1e222d' }, horzLines: { color: '#1e222d' } },
                crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
                rightPriceScale: { borderColor: '#2a2e39', scaleMargins: { top: 0.06, bottom: 0.22 } },
                timeScale: { borderColor: '#2a2e39', timeVisible: true, secondsVisible: false, barSpacing: 10, rightOffset: 5 },
            });

            candleSeries = lwChart.addCandlestickSeries({ upColor: '#ff3b3b', downColor: '#2979ff', borderUpColor: '#ff3b3b', borderDownColor: '#2979ff', wickUpColor: '#ff3b3b', wickDownColor: '#2979ff', lastValueVisible: true, priceLineVisible: true, priceFormat: { type: 'price', precision: 2, minMove: 0.25 }});
            volumeSeries = lwChart.addHistogramSeries({ color: '#ffffff', priceFormat: { type: 'volume' }, priceScaleId: 'sig1_scale', priceLineVisible: false, lastValueVisible: false, autoscaleInfoProvider: function() { return { priceRange: { minValue: 0, maxValue: 100 } }; }});
            lwChart.priceScale('sig1_scale').applyOptions({ scaleMargins: { top: 0.70, bottom: 0.20 }, visible: false });
            signal2Series = lwChart.addHistogramSeries({ color: '#ffffff', priceFormat: { type: 'volume' }, priceScaleId: 'sig2_scale', priceLineVisible: false, lastValueVisible: false, autoscaleInfoProvider: function() { return { priceRange: { minValue: 0, maxValue: 100 } }; }});
            lwChart.priceScale('sig2_scale').applyOptions({ scaleMargins: { top: 0.80, bottom: 0.10 }, visible: false });
            signal3Series = lwChart.addHistogramSeries({ color: '#ffffff', priceFormat: { type: 'volume' }, priceScaleId: 'sig3_scale', priceLineVisible: false, lastValueVisible: false, autoscaleInfoProvider: function() { return { priceRange: { minValue: 0, maxValue: 100 } }; }});
            lwChart.priceScale('sig3_scale').applyOptions({ scaleMargins: { top: 0.90, bottom: 0.00 }, visible: false });

            bbUpperSeries = lwChart.addLineSeries({ color: 'rgba(38, 198, 218, 0.75)', lineWidth: 1, priceLineVisible: false, lastValueVisible: false });
            bbMiddleSeries = lwChart.addLineSeries({ color: 'rgba(255, 152, 0, 0.9)', lineWidth: 1, priceLineVisible: false, lastValueVisible: false });
            bbLowerSeries = lwChart.addLineSeries({ color: 'rgba(38, 198, 218, 0.75)', lineWidth: 1, priceLineVisible: false, lastValueVisible: false });
            high20Series = lwChart.addLineSeries({ color: 'rgba(255, 82, 82, 0.85)', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, priceLineVisible: false, lastValueVisible: false });
            low20Series = lwChart.addLineSeries({ color: 'rgba(68, 138, 255, 0.85)', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, priceLineVisible: false, lastValueVisible: false });
            vw05Series = lwChart.addLineSeries({ color: '#ff00ff', lineWidth: 3, priceLineVisible: false, lastValueVisible: false });
            vw02Series = lwChart.addLineSeries({ color: '#ffeb3b', lineWidth: 2, priceLineVisible: false, lastValueVisible: false });
            ma2Series = lwChart.addLineSeries({ color: '#00e676', lineWidth: 1, priceLineVisible: false, lastValueVisible: false });

            buildMaster1MinCandles(currentMarketPrice);
            renderAggregatedTimeframe(currentIntervalSec);

            if (typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(function(entries) {
                    if (entries.length === 0 || entries[0].target !== container) return;
                    var newRect = entries[0].contentRect;
                    if (newRect.width > 0 && newRect.height > 0) lwChart.applyOptions({ width: newRect.width, height: newRect.height });
                }).observe(container);
            }
        }

        function updateBollingerBands() {
            if (!bbUpperSeries || !volumeSeries || typeof calculateAllSignals !== 'function') return;
            var res = calculateAllSignals(candleHistory);
            bbUpperSeries.setData(res.upper); bbMiddleSeries.setData(res.middle); bbLowerSeries.setData(res.lower);
            high20Series.setData(res.high20Arr); low20Series.setData(res.low20Arr); ma2Series.setData(res.ma2Arr);
            vw02Series.setData(res.vw02Data.linePoints); vw05Series.setData(res.vw05Data.linePoints);
            volumeSeries.setData(res.signal1Bars); signal2Series.setData(res.signal2Bars);
            if (res.signal3Bars) signal3Series.setData(res.signal3Bars);
        }

        function changeTimeframe(sec, labelText, btnEl) {
            currentIntervalSec = Number(sec);
            var btns = document.querySelectorAll('#tfButtonGroup .tf-btn');
            for (var i = 0; i < btns.length; i++) { btns[i].classList.remove('active'); }
            if (btnEl) btnEl.classList.add('active');
            var titleEl = document.getElementById('chartTitleLabel');
            if (titleEl && labelText) titleEl.innerText = 'US Nas 100 선물 · ' + labelText;
            renderAggregatedTimeframe(currentIntervalSec);
        }

        // ==========================================
        // [서버 단일 통제 모드] 화면은 주문 관리를 서버에 위임하고 모니터링만 수행
        // ==========================================
        function checkAndRunAutoTradeLogic() {
            if (!autoTradingActive || userGrade !== '정회원') return;
            // 자동매매 주문 판단 및 실행은 서버(auto_trigger.php)에서 단독으로 수행하여 
            // 핑퐁 현상과 중복 주문을 원천 차단합니다.
        }

        function syncChartWithMarketPrice(newPrice, serverLast1MinBar) {
            if (!newPrice || isNaN(newPrice) || !currentBar) return;
            if (serverLast1MinBar && serverLast1MinBar.time) {
                var tKst = Math.floor((Number(serverLast1MinBar.time) + KST_OFFSET) / 60) * 60;
                if (!master1MinMap[tKst]) {
                    var new1m = { time: tKst, open: roundTick(Number(serverLast1MinBar.open)), high: roundTick(Math.max(Number(serverLast1MinBar.high), newPrice)), low: roundTick(Math.min(Number(serverLast1MinBar.low), newPrice)), close: newPrice, volume: Number(serverLast1MinBar.volume || 50) };
                    master1MinMap[tKst] = new1m; master1MinCandles.push(new1m);
                } else {
                    var exist1m = master1MinMap[tKst]; exist1m.close = newPrice;
                    if (newPrice > exist1m.high) exist1m.high = newPrice;
                    if (newPrice < exist1m.low) exist1m.low = newPrice;
                }
            }

            var targetBucket = Math.floor((master1MinCandles[master1MinCandles.length - 1].time) / currentIntervalSec) * currentIntervalSec;
            if (targetBucket > currentBar.time) {
                currentBar = { time: targetBucket, open: newPrice, high: newPrice, low: newPrice, close: newPrice, volume: 50 };
                candleHistory.push(currentBar);
            }
            
            currentBar.close = newPrice;
            if (newPrice > currentBar.high) currentBar.high = newPrice;
            if (newPrice < currentBar.low) currentBar.low = newPrice;
            candleHistory[candleHistory.length - 1] = currentBar;

            candleSeries.update(currentBar);
            updateBollingerBands();
            updateOhlcHeader(currentBar);
            
            checkAndRunAutoTradeLogic();
        }

        function updateOhlcHeader(bar) {
            var info = document.getElementById('chartOhlcInfo');
            if (info && bar) info.innerText = '시 ' + bar.open.toFixed(2) + ' 고 ' + bar.high.toFixed(2) + ' 저 ' + bar.low.toFixed(2) + ' 종 ' + bar.close.toFixed(2);
        }

        function formatKRW(num) { var sign = num < 0 ? '-' : ''; var absVal = Math.round(Math.abs(num)); return sign + absVal.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",") + '원'; }
        function formatPrice(num) { var aligned = Math.round(Number(num) * 4) / 4; return aligned.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ","); }

        function saveTpSlToDB() {
            var tp = document.getElementById('tp-ticks').value; var sl = document.getElementById('sl-ticks').value;
            var xhr = new XMLHttpRequest(); xhr.open('POST', 'member_index.php', true); xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded'); xhr.send('ajax_action=save_tpsl&tp_ticks=' + tp + '&sl_ticks=' + sl);
        }

        function confirmTpSlSetting(type) {
            var inputId = (type === '익절') ? 'tp-ticks' : 'sl-ticks'; var val = document.getElementById(inputId).value;
            var isOk = confirm('설정하신 ' + type + ' 틱수 [' + val + '틱]으로 지정하시겠습니까?');
            if (isOk) {
                if (type === '익절') activeTpTicks = Math.abs(parseInt(val, 10) || 0);
                if (type === '손절') activeSlTicks = Math.abs(parseInt(val, 10) || 0);
                saveTpSlToDB(); logMessage('[' + type + '지정완료] ' + val + '틱으로 설정되었습니다.');
            } else {
                document.getElementById(inputId).value = (type === '익절') ? activeTpTicks : activeSlTicks; logMessage('[' + type + '취소] 설정이 초기화되었습니다.');
            }
        }

        function resetAllTpSl() {
            var isOk = confirm('익절 및 손절 설정을 모두 초기화(0틱) 하시겠습니까?');
            if (isOk) {
                document.getElementById('tp-ticks').value = 0; document.getElementById('sl-ticks').value = 0;
                activeTpTicks = 0; activeSlTicks = 0; saveTpSlToDB(); logMessage('[초기화완료] 익절/손절 값이 모두 0틱으로 리셋되었습니다.');
            }
        }

        priceInputBox.addEventListener('focus', function() { isEditingPrice = true; this.select(); });
        priceInputBox.addEventListener('blur', function() { isEditingPrice = false; });
        function onManualPriceInput(rawVal) {
            var val = parseFloat(String(rawVal).replace(/,/g, '').trim());
            if (!isNaN(val) && val > 1000) { currentMarketPrice = Math.round(val * 4) / 4; isEditingPrice = false; priceInputBox.blur(); updateMarketUI(null); }
        }

        function updateMarketUI(serverLast1MinBar) {
            if (!isEditingPrice) { priceInputBox.value = formatPrice(currentMarketPrice); }
            syncChartWithMarketPrice(currentMarketPrice, serverLast1MinBar);

            if (!hasPosition || currentQty <= 0) {
                document.getElementById('base-balance').innerText = formatKRW(baseBalance);
                document.getElementById('total-equity').innerText = formatKRW(baseBalance); document.getElementById('total-equity').style.color = '#68d391'; return;
            }

            if (isClosing) return;

            var pointDiff = (currentSide === 'BUY') ? (currentMarketPrice - currentEntry) : (currentEntry - currentMarketPrice);
            currentPnl = Math.round(pointDiff * pointValue * currentQty);
            var totalEquity = baseBalance + currentPnl; var ticks = Math.round(pointDiff / 0.25);
            var pnlEl = document.getElementById('pnl'); var equityEl = document.getElementById('total-equity');

            if (currentPnl > 0) { pnlEl.innerText = '+' + formatKRW(currentPnl) + ' (+' + pointDiff.toFixed(2) + 'pt / +' + ticks + '틱)'; pnlEl.style.color = '#ff5252'; equityEl.style.color = '#ff5252'; } 
            else if (currentPnl < 0) { pnlEl.innerText = formatKRW(currentPnl) + ' (' + pointDiff.toFixed(2) + 'pt / ' + ticks + '틱)'; pnlEl.style.color = '#448aff'; equityEl.style.color = '#448aff'; } 
            else { pnlEl.innerText = '0원 (0.00pt / 0틱)'; pnlEl.style.color = '#ffeb3b'; equityEl.style.color = '#68d391'; }
            
            document.getElementById('base-balance').innerText = formatKRW(baseBalance); equityEl.innerText = formatKRW(totalEquity);

            if (activeTpTicks > 0 && ticks >= activeTpTicks) { logMessage('🎯 [자동익절 도달] +' + ticks + '틱 달성으로 자동 청산 실행!'); closeOrder(); return; }
            if (activeSlTicks > 0 && ticks <= -activeSlTicks) { logMessage('💥 [자동손절 도달] ' + ticks + '틱 이탈로 자동 청산 실행!'); closeOrder(); return; }
        }

        function pollFuturesPrice() {
            var xhr = new XMLHttpRequest(); xhr.open('GET', 'member_index.php?ajax_action=get_futures_price&_t=' + new Date().getTime(), true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.status === 'ok' && res.price > 1000) {
                            currentMarketPrice = Math.round(Number(res.price) * 4) / 4;
                            var syncEl = document.getElementById('sync-status'); if (syncEl && res.time) syncEl.innerText = '🟢 실시간 수신중 (' + res.time + ')';
                        }
                        if (typeof res.auto_active !== 'undefined') {
                            var dbActive = parseInt(res.auto_active, 10);
                            if (dbActive !== (autoTradingActive ? 1 : 0)) { autoTradingActive = (dbActive === 1); updateAutoTradeUIState(); }
                        }
                        if (!isOrdering && !isClosing && typeof res.balance !== 'undefined' && res.balance > 0) {
                            var dbSide = res.position_side || ''; var dbQty = parseInt(res.position_qty, 10) || 0; var dbEntry = parseFloat(res.position_entry) || 0; var dbBal = parseFloat(res.balance) || baseBalance;
                            if (res.last_order && parseInt(res.last_order.id, 10) > lastSyncedOrderId) {
                                lastSyncedOrderId = parseInt(res.last_order.id, 10);
                                logMessage('[실시간 정산동기화] 청산가 @ ' + formatPrice(res.last_order.close_price) + ' (' + res.last_order.qty + '계약) / 확정손익: ' + formatKRW(res.last_order.pnl));
                            }
                            if (dbSide !== currentSide || dbQty !== currentQty || Math.abs(dbEntry - currentEntry) > 0.01 || Math.abs(dbBal - baseBalance) > 1) {
                                var prevSide = currentSide; var prevQty = currentQty;
                                baseBalance = dbBal; currentSide = dbSide; currentQty = dbQty; currentEntry = dbEntry;
                                hasPosition = (dbQty > 0 && dbSide !== ''); applyPositionVisualState();
                                if (hasPosition && (prevSide !== currentSide || prevQty !== currentQty)) {
                                    logMessage('[기기동기화] 보유 포지션: ' + currentAction + ' (' + currentQty + '계약 @ ' + formatPrice(currentEntry) + ')');
                                }
                            }
                        }
                        updateMarketUI(res.last_bar || null);
                    } catch (e) {}
                }
            }; xhr.send();
        }

        function marketOrder(type) { var qty = parseInt(document.getElementById('order-qty').value, 10) || 1; marketOrderInternal(type, qty); }
        
        function marketOrderInternal(type, qty) {
            if (isOrdering || isClosing) return; isOrdering = true;
            var xhr = new XMLHttpRequest(); xhr.open('POST', 'member_index.php', true); xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    isOrdering = false;
                    if (xhr.status === 200) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.status === 'ok') {
                                baseBalance = parseFloat(res.balance); currentSide = res.position_side || ''; currentQty = parseInt(res.position_qty, 10) || 0; currentEntry = parseFloat(res.position_entry) || 0;
                                hasPosition = (currentQty > 0 && currentSide !== ''); isClosing = false;
                                applyPositionVisualState(); updateMarketUI(null);

                                if (res.trade_mode === 'PARTIAL_CLOSE') logMessage('[부분청산 -' + res.closed_qty + '계약] 확정손익: ' + formatKRW(res.pnl));
                                else if (res.trade_mode === 'FULL_CLOSE') logMessage('[상쇄청산 완료] ' + res.closed_qty + '계약 청산 / 확정손익: ' + formatKRW(res.pnl));
                                else if (res.trade_mode === 'REVERSE') logMessage('🔄 [포지션 스위칭] 자동 스위칭 진입 완료');
                                else logMessage('[시장가체결] ' + currentAction + ' / 진입가: ' + formatPrice(currentEntry) + ' / 수량: ' + currentQty + '계약');
                            } else if (res.status === 'fail') {
                                logMessage('❌ [DB 에러] 데이터 저장 실패: ' + (res.msg || '알 수 없는 오류'));
                                console.error('Insert DB Error:', res.msg);
                            }
                        } catch (e) {
                            console.error('JSON Parse Error:', e);
                        }
                    }
                }
            }; xhr.send('ajax_action=open_trade&side=' + type + '&entry_price=' + currentMarketPrice + '&qty=' + qty);
        }

        function closeOrder() {
            if (!hasPosition || isClosing) return; isClosing = true; 
            var closePrice = currentMarketPrice;
            var xhr = new XMLHttpRequest(); xhr.open('POST', 'member_index.php', true); xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.status === 'ok') {
                                baseBalance = parseFloat(res.balance); logMessage('[전량 시장가청산] 청산가 @ ' + formatPrice(closePrice) + ' / 확정손익: ' + formatKRW(res.pnl));
                                hasPosition = false; currentSide = ''; currentAction = ''; currentQty = 0; currentEntry = 0; currentPnl = 0;
                                applyPositionVisualState(); isClosing = false; updateMarketUI(null); return;
                            } else if (res.status === 'fail') {
                                logMessage('❌ [DB 에러] 데이터 저장 실패: ' + (res.msg || '알 수 없는 오류'));
                                console.error('Insert DB Error:', res.msg);
                            }
                        } catch (e) {
                            console.error('JSON Parse Error:', e);
                        }
                    }
                    isClosing = false;
                }
            }; xhr.send('ajax_action=close_trade&close_price=' + closePrice);
        }

        initCustomChart();
        applyPositionVisualState();
        pollFuturesPrice();
        // 렉(부하) 방지를 위해 폴링 주기를 2초(2000ms)로 최적화했습니다.
        setInterval(pollFuturesPrice, 2000);
    </script>
</body>
</html>