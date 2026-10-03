<?php
error_reporting(E_ALL);
@ini_set('display_errors', '1');
@set_time_limit(50);

require_once 'db.php';

// 1. 야후 파이낸스 API 무차단 cURL 수신 함수
function fetchYahooChartJsonCron($url) {$res = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: application/json,text/plain,*/*',
            'Cache-Control: no-cache'
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $res = curl_exec($ch);
        curl_close($ch);
    }
    if (!$res) {$opts = array(
            'http' => array('method' => 'GET', 'header' => "User-Agent: Mozilla/5.0\r\nCache-Control: no-cache\r\n", 'timeout' => 3),
            'ssl'  => array('verify_peer' => false, 'verify_peer_name' => false)
        );
        $res = @file_get_contents($url, false, stream_context_create($opts));
    }
    return $res ? json_decode($res, true) : null;
}

// 2. 나스닥 선물(NQ=F) 최근 5일 1분봉 마스터 데이터 수신
function getCronMasterCandles() {
    $ts = time() . mt_rand(100, 999);
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/NQ=F?interval=1m&range=5d&includePrePost=true&_=" . $ts;
    $json = fetchYahooChartJsonCron($url);

    $map = array();$livePrice = 0;

    if ($json && isset($json['chart']['result'][0])) {
        $res0 =$json['chart']['result'][0];
        if (isset($res0['meta']['regularMarketPrice'])) {
            $p = (float)$res0['meta']['regularMarketPrice'];
            if ($p > 1000) $livePrice = round($p * 4) / 4;
        }
        if (isset($res0['timestamp']) && isset($res0['indicators']['quote'][0])) {
            $timestamps =$res0['timestamp'];
            $quote =$res0['indicators']['quote'][0];
            $cnt = count($timestamps);

            for ($i = 0; $i < $cnt; $i++) {
                $t = (int)(floor((int)$timestamps[$i] / 60) * 60);$o = isset($quote['open'][$i]) ? (float)$quote['open'][$i] : 0;
                $h = isset($quote['high'][$i]) ? (float)$quote['high'][$i] : 0;
                $l = isset($quote['low'][$i]) ? (float)$quote['low'][$i] : 0;
                $c = isset($quote['close'][$i]) ? (float)$quote['close'][$i] : 0;
                $v = isset($quote['volume'][$i]) ? (int)$quote['volume'][$i] : 0;

                if ($o <= 1000 \vert{}\vert{}$c <= 1000 || $h <= 1000 \vert{}\vert{}$l <= 1000) continue;

                $map[$t] = array(
                    'time'   => $t,
                    'open'   => round($o * 4) / 4,                     'high'   => round($h * 4) / 4,
                    'low'    => round($l * 4) / 4,                     'close'  => round($c * 4) / 4,
                    'volume' => ($v > 0 ?$v : 1)
                );
            }
        }
    }
    ksort($map);
    return array('price' => $livePrice, 'candles' => array_values($map));
}

// 3. 지그재그(0.2% / 0.5%) 계단식 수평선 계산 함수
function serverCalculateZigZagStep($candles,$pct) {
    if (!$candles \vert{}\vert{} count($candles) < 2) return array('barValues' => array());
    $thresh =$pct / 100.0;
    $barValues = array();$trend = 0;
    $extremeVal =$candles[0]['high'];
    $activeValue =$candles[0]['close'];

    foreach ($candles as $i =>$c) {
        $h =$c['high'];
        $l =$c['low'];
        if ($trend === 0) {
            if ($h >$extremeVal) {
                $extremeVal =$h;
            }
            if ($l <=$extremeVal * (1 - $thresh)) {$trend = -1;
                $extremeVal =$l;
            } else if ($l <$extremeVal) {
                $extremeVal =$l;
            }
            if ($h >=$extremeVal * (1 + $thresh)) {$trend = 1;
                $extremeVal =$h;
            }
        } else if ($trend === 1) {
            if ($h >$extremeVal) {
                $extremeVal =$h;
            } else if (($extremeVal - $l) /$extremeVal >= $thresh) {$trend = -1;
                $extremeVal =$l;
            }
        } else if ($trend === -1) {
            if ($l <$extremeVal) {
                $extremeVal =$l;
            } else if (($h - $extremeVal) /$extremeVal >= $thresh) {$trend = 1;
                $extremeVal =$h;
            }
        }
        $barValues[$i] =$extremeVal;
    }
    return array('barValues' => $barValues);
}

// 4. 분봉 집계 함수
function serverAggregateCandles($master1M,$intervalSec) {
    if (empty($master1M)) return array();
    $bucketsMap = array();$bucketTimes = array();

    foreach ($master1M as$m) {
        $bTime = (int)(floor($m['time'] / $intervalSec) *$intervalSec);
        if (!isset($bucketsMap[$bTime])) {
            $bucketsMap[$bTime] = array('time' => $bTime, 'open' =>$m['open'], 'high' => $m['high'], 'low' =>$m['low'], 'close' => $m['close'], 'volume' =>$m['volume']);
            $bucketTimes[] =$bTime;
        } else {
            if ($m['high'] >$bucketsMap[$bTime]['high'])$bucketsMap[$bTime]['high'] =$m['high'];
            if ($m['low'] <$bucketsMap[$bTime]['low'])$bucketsMap[$bTime]['low'] =$m['low'];
            $bucketsMap[$bTime]['close'] = $m['close'];$bucketsMap[$bTime]['volume'] +=$m['volume'];
        }
    }
    sort($bucketTimes);$res = array();
    foreach ($bucketTimes as $bt) {$res[] = $bucketsMap[$bt];
    }
    return $res;
}

// 5. 개별 블록별 신호(1, 2, 3) 상태 판별 함수
function serverGetBlockSignalState($candles,$use1, $use2,$use3) {
    $cnt = count($candles);
    if ($cnt < 2) return 'NONE';

    $vw02 = serverCalculateZigZagStep($candles, 0.2)['barValues'];
    $vw05 = serverCalculateZigZagStep($candles, 0.5)['barValues'];

    $i =$cnt - 1;
    $c =$candles[$i];$v02 = isset($vw02[$i]) ? $vw02[$i] : null;
    $v05 = isset($vw05[$i]) ? $vw05[$i] : null;

    $ma2Curr = ($i >= 1) ? (($candles[$i]['close'] + $candles[$i - 1]['close']) / 2.0) : $c['close'];$ma2Prev = ($i >= 2) ? (($candles[$i - 1]['close'] +$candles[$i - 2]['close']) / 2.0) :$ma2Curr;

    $buyConditionsMet = true;
    $sellConditionsMet = true;
    $hasConditionChecked = false;

    // 신호 1 검사 (0.2% 지그재그 노란선 기준 대소 비교)
    if ($use1 == 1) {$hasConditionChecked = true;
        if ($v02 !== null) {
            if (!($c['close'] > $v02))$buyConditionsMet = false;
            if (!($c['close'] < $v02))$sellConditionsMet = false;
        } else {
            $buyConditionsMet = false; $sellConditionsMet = false;
        }
    }

    // 신호 2 검사 (0.2%, 0.5% 및 2MA 모멘텀 복합)
    if ($use2 == 1) {$hasConditionChecked = true;
        if ($v02 !== null &&$v05 !== null) {
            if (!($c['close'] > $v02 &&$c['close'] > $v05 &&$ma2Curr > $ma2Prev))$buyConditionsMet = false;
            if (!($c['close'] < $v02 &&$c['close'] < $v05 &&$ma2Curr < $ma2Prev))$sellConditionsMet = false;
        } else {
            $buyConditionsMet = false; $sellConditionsMet = false;
        }
    }

    // 신호 3 검사 (0.5% 지그재그 및 볼린저/이평 연동)
    if ($use3 == 1) {$hasConditionChecked = true;
        if ($v05 !== null &&$cnt >= 20) {
            $as1 =$v05;
            $sumL = 0; $sumH = 0;
            for ($bp = 0; bp < 5; bp++) {
                $sumL +=$candles[$cnt - 1 -$bp]['low'];
                $sumH +=$candles[$cnt - 1 -$bp]['high'];
            }
            $bb_l =$sumL / 5.0;
            $bb_h =$sumH / 5.0;

            if (!($c['close'] > $as1 &&$bb_l > $as1))$buyConditionsMet = false;
            if (!($c['close'] < $as1 &&$bb_h < $as1))$sellConditionsMet = false;
        } else {
            $buyConditionsMet = false; $sellConditionsMet = false;
        }
    }

    if (!$hasConditionChecked) return 'NONE';

    if ($buyConditionsMet) return 'BUY';
    if ($sellConditionsMet) return 'SELL';
    return 'NONE';
}

// --- ⚡ [메인 실행부] ---
$masterData = getCronMasterCandles();
$livePrice =$masterData['price'];
$masterCandles =$masterData['candles'];

if ($livePrice <= 0 \vert{}\vert{} empty($masterCandles)) {
    exit("CRON FAIL: Live Price Error");
}

$membersQuery = mysqli_query($conn, "SELECT * FROM `automeme_members` WHERE auto_active = 1");
if (!$membersQuery) {
    exit("CRON FAIL: DB Query Error");
}

while ($member = mysqli_fetch_assoc($membersQuery)) {
    $m_id = (int)$member['id'];
    $uname =$member['username'];
    $dbSide = (string)$member['position_side'];
    $dbQty = (int)$member['position_qty'];
    $dbEntry = (float)$member['position_entry'];
    $curBal = (float)$member['balance'];
    $tpTicks = (int)$member['tp_ticks'];
    $slTicks = (int)$member['sl_ticks'];

    // 1. 익절 / 손절 체크 및 자동 청산
    if ($dbQty > 0 && !empty($dbSide)) {$ptDiff = ($dbSide === 'BUY') ? ($livePrice - $dbEntry) : ($dbEntry - $livePrice);$ticks = (int)round($ptDiff / 0.25);$shouldClose = false;

        if ($tpTicks > 0 &&$ticks >= $tpTicks)$shouldClose = true;
        if ($slTicks > 0 &&$ticks <= -$slTicks)$shouldClose = true;

        if ($shouldClose) {$pnlVal = (int)round($ptDiff * 4000 * $dbQty);
            $curBal +=$pnlVal;
            mysqli_query($conn, "UPDATE `automeme_members` SET balance = {$curBal}, position_side = '', position_qty = 0, position_entry = 0 WHERE id = {$m_id}");
            mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', '{$dbSide}', {$dbEntry}, {$livePrice}, {$dbQty}, {$pnlVal})");
            continue;
        }
    }

    // 2. 다중 블록(1번, 2번, 3번) 설정 및 분봉 조건 검사
    $buyVotes = 0;
    $sellVotes = 0;
    $activeBlocks = 0;

    $blocks = array(
        array('active' => $member['sig1_active'], 'tf' => $member['sig1_tf'], 'u1' =>$member['sig1_use_1'], 'u2' => $member['sig1_use_2'], 'u3' =>$member['sig1_use_3']),
        array('active' => $member['sig2_active'], 'tf' => $member['sig2_tf'], 'u1' =>$member['sig2_use_1'], 'u2' => $member['sig2_use_2'], 'u3' =>$member['sig2_use_3']),
        array('active' => $member['sig3_active'], 'tf' => $member['sig3_tf'], 'u1' =>$member['sig3_use_1'], 'u2' => $member['sig3_use_2'], 'u3' =>$member['sig3_use_3'])
    );

    foreach ($blocks as$blk) {
        if ((int)$blk['active'] === 1) {
            $activeBlocks++;$tfSec = (int)($blk['tf'] > 0 ?$blk['tf'] : 600);
            $aggCandles = serverAggregateCandles($masterCandles, $tfSec);$sig = serverGetBlockSignalState($aggCandles,$blk['u1'], $blk['u2'],$blk['u3']);
            if ($sig === 'BUY')$buyVotes++;
            if ($sig === 'SELL')$sellVotes++;
        }
    }

    $finalAction = '';
    if ($activeBlocks > 0) {
        if ($buyVotes === $activeBlocks)$finalAction = 'BUY';
        if ($sellVotes === $activeBlocks)$finalAction = 'SELL';
    }

    // 3. 조건 충족 시 회원 포지션 진입 또는 스위칭 처리
    if ($finalAction === 'BUY' && $dbSide !== 'BUY') {$qty = 1;
        if ($dbQty > 0 &&$dbSide === 'SELL') {
            $ptDiff = ($dbEntry - $livePrice);$pnlVal = (int)round($ptDiff * 4000 * $dbQty);
            $curBal +=$pnlVal;
            mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', 'SELL', {$dbEntry}, {$livePrice}, {$dbQty}, {$pnlVal})");
        }
        mysqli_query($conn, "UPDATE `automeme_members` SET balance = {$curBal}, position_side = 'BUY', position_qty = {$qty}, position_entry = {$livePrice} WHERE id = {$m_id}");
    } else if ($finalAction === 'SELL' && $dbSide !== 'SELL') {$qty = 1;
        if ($dbQty > 0 &&$dbSide === 'BUY') {
            $ptDiff = ($livePrice - $dbEntry);$pnlVal = (int)round($ptDiff * 4000 * $dbQty);
            $curBal +=$pnlVal;
            mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', 'BUY', {$dbEntry}, {$livePrice}, {$dbQty}, {$pnlVal})");
        }
        mysqli_query($conn, "UPDATE `automeme_members` SET balance = {$curBal}, position_side = 'SELL', position_qty = {$qty}, position_entry = {$livePrice} WHERE id = {$m_id}");
    }
}
echo "CRON SUCCESS: " . date('Y-m-d H:i:s');
?>