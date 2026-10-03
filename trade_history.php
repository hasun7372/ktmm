<?php
error_reporting(E_ALL);
@ini_set('display_errors', '1');
session_start();

if (empty($_SESSION['member_logged']) && empty($_SESSION['is_admin_view']) && empty($_SESSION['member_id'])) {
    header("Location: member_login.php");
    exit;
}

$m_id = (int)$_SESSION['member_id'];
session_write_close();

require_once 'db.php';

// 디버깅: 현재 로그인한 세션 정보 및 테이블 전체 데이터 검사
echo "<div style='background:#333; color:#00ffcc; padding:10px; font-size:12px; font-family:monospace; margin-bottom:10px;'>";
echo "📌 [디버깅 정보] 현재 로그인한 내 member_id: <b>{$m_id}</b><br>";

$chk_table = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `automeme_orders`");
$chk_row = mysqli_fetch_assoc($chk_table);
echo "📊 `automeme_orders` 테이블 총 데이터 개수: <b>" . $chk_row['cnt'] . "개</b><br>";

$my_chk = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `automeme_orders` WHERE member_id = {$m_id}");
$my_row = mysqli_fetch_assoc($my_chk);
echo "👤 내 member_id({$m_id})로 저장된 데이터 개수: <b>" . $my_row['cnt'] . "개</b>";
echo "</div>";

// 조회 기간 필터
$show_all = isset($_GET['show_all']) ? true : false;
$s_date = isset($_GET['s_date']) ? $_GET['s_date'] : date('Y-m-d');$e_date = isset($_GET['e_date']) ?$_GET['e_date'] : date('Y-m-d');

$s_date_esc = mysqli_real_escape_string($conn, $s_date);$e_date_esc = mysqli_real_escape_string($conn,$e_date);

if ($show_all) {$query = "SELECT * FROM `automeme_orders` WHERE member_id = {$m_id} ORDER BY id DESC";
} else {
    $query = "SELECT * FROM `automeme_orders` 
              WHERE member_id = {$m_id} 
              AND DATE(reg_date) BETWEEN '{$s_date_esc}' AND '{$e_date_esc}' 
              ORDER BY id DESC";
}

$result = mysqli_query($conn,$query);

$total_pnl = 0;
$total_qty = 0;
$orders = array();
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $orders[] =$row;
        $total_pnl += (float)$row['pnl'];
        $total_qty += (int)$row['qty'];
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>매매거래내역 및 손익</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background-color: #121212; color: #ffffff; font-family: 'Malgun Gothic', sans-serif; padding: 10px; }
        .ledger-container { max-width: 1200px; margin: 0 auto; background: #1e1e1e; border: 1px solid #333; border-radius: 6px; padding: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        
        .top-nav { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; border-bottom: 1px solid #333; padding-bottom: 8px; }
        .top-nav-row { display: flex; justify-content: space-between; align-items: center; width: 100%; }
        .back-btn { background: #2b6cb0; color: #fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: bold; transition: 0.2s; text-align: center; }
        .back-btn:hover { background: #3182ce; }
        
        .search-bar { display: flex; align-items: center; gap: 8px; background: #252525; padding: 10px; border-radius: 4px; border: 1px solid #383838; margin-bottom: 15px; flex-wrap: wrap; }
        .search-bar label { font-size: 12px; color: #cbd5e0; font-weight: bold; }
        .search-bar input[type="date"] { background: #1a1a1a; border: 1px solid #444; color: #fff; padding: 6px 8px; border-radius: 4px; font-size: 13px; }
        .search-bar button { background: #2962ff; color: #fff; border: none; padding: 7px 14px; border-radius: 4px; font-size: 13px; font-weight: bold; cursor: pointer; transition: 0.2s; }
        .search-bar button:hover { background: #1e4bd8; }
        .all-btn { background: #4a5568 !important; }
        .all-btn:hover { background: #718096 !important; }
        
        .table-responsive { width: 100%; overflow-x: auto; max-height: 500px; overflow-y: auto; border: 1px solid #333; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; text-align: center; font-size: 12px; white-space: nowrap; }
        th { background: #2a2e39; color: #d1d4dc; padding: 9px 8px; font-weight: bold; border-bottom: 2px solid #363c4e; position: sticky; top: 0; z-index: 10; }
        td { padding: 8px 8px; border-bottom: 1px solid #2a2a2a; color: #e2e8f0; }
        tr:hover { background: rgba(255, 255, 255, 0.03); }
        
        .text-buy { color: #ff5252; font-weight: bold; }
        .text-sell { color: #448aff; font-weight: bold; }
        .pnl-plus { color: #ff5252; font-weight: bold; }
        .pnl-minus { color: #448aff; font-weight: bold; }
        
        .summary-row { background: #1a1a1a !important; font-weight: bold; border-top: 2px solid #444; }
        .summary-row td { padding: 10px 8px; color: #fff; }
    </style>
</head>
<body>

<div class="ledger-container">
    <div class="top-nav">
        <div class="top-nav-row">
            <h3 style="margin: 0; font-size: 15px; color: #4CAF50;">📋 실시간 매매거래내역 및 손익</h3>
            <a href="member_index.php" class="nav-link back-btn">⬅ 거래화면으로</a>
        </div>
        <div class="top-nav-row">
            <span style="font-size: 11px; color: #a0aec0;">기준종목: 나스닥선물(NQ=F)</span>
        </div>
    </div>

    <!-- HTS 스타일 조회 필터 바 -->
    <form method="GET" class="search-bar">
        <label>조회기간:</label>
        <input type="date" name="s_date" value="<?php echo htmlspecialchars($s_date); ?>">
        <span>~</span>
        <input type="date" name="e_date" value="<?php echo htmlspecialchars($e_date); ?>">
        <button type="submit">조회</button>
        <button type="submit" name="show_all" value="1" class="all-btn">전체보기(날짜무시)</button>
    </form>

    <!-- HTS 표준 거래내역 테이블 -->
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>날짜</th>
                    <th>시간</th>
                    <th>종목코드</th>
                    <th>종목명</th>
                    <th>구분</th>
                    <th>주문량</th>
                    <th>체결량</th>
                    <th>진입가(주문가)</th>
                    <th>청산가(체결가)</th>
                    <th>실현손익 (원)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($orders) > 0): ?>
                    <?php foreach ($orders as$row): 
                        $dt = explode(' ',$row['reg_date']);
                        $date_part = isset($dt[0]) ? $dt[0] : '';$time_part = isset($dt[1]) ?$dt[1] : '';
                        $side_str = ($row['side'] === 'BUY') ? '<span class="text-buy">매수(LONG)</span>' : '<span class="text-sell">매도(SHORT)</span>';
                        $pnl_val = (float)$row['pnl'];
                        $pnl_class = ($pnl_val > 0) ? 'pnl-plus' : (($pnl_val < 0) ? 'pnl-minus' : '');$pnl_str = ($pnl_val > 0 ? '+' : '') . number_format($pnl_val) . '원';
                    ?>
                    <tr>
                        <td><?php echo $date_part; ?></td>
                        <td><?php echo $time_part; ?></td>
                        <td style="color: #a0aec0;">NQ=F</td>
                        <td>나스닥100선물</td>
                        <td><?php echo $side_str; ?></td>
                        <td><?php echo number_format($row['qty']); ?></td>
                        <td><?php echo number_format($row['qty']); ?></td>
                        <td><?php echo number_format($row['entry_price'], 2); ?></td>
                        <td><?php echo number_format($row['close_price'], 2); ?></td>
                        <td class="<?php echo $pnl_class; ?>"><?php echo $pnl_str; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" style="padding: 30px; color: #718096;">조회된 매매거래내역이 없습니다.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <?php if (count($orders) > 0): ?>
            <tfoot>
                <tr class="summary-row">
                    <td colspan="5" style="text-align: left; padding-left: 15px;">합계 (총 계약: <?php echo number_format($total_qty); ?>계약)</td>
                    <td><?php echo number_format($total_qty); ?></td>
                    <td><?php echo number_format($total_qty); ?></td>
                    <td colspan="2" style="text-align: right;">총 실현손익:</td>
                    <td class="<?php echo ($total_pnl > 0 ? 'pnl-plus' : ($total_pnl < 0 ? 'pnl-minus' : '')); ?>">
                        <?php echo ($total_pnl > 0 ? '+' : '') . number_format($total_pnl); ?>원
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

</body>
</html>