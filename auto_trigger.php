<?php
// auto_trigger.php : 신호 조건 완벽 동기화 버전

error_reporting(E_ERROR | E_PARSE);
@ini_set('display_errors', '0');
@set_time_limit(60);

require_once 'db.php';

function fetchYahooChartJson($url) {$res = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: Mozilla/5.0', 'Accept: application/json,text/plain,*/*', 'Cache-Control: no-cache'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $res = curl_exec($ch); 
        curl_close($ch);
    }
    if (!$res) {$opts = array('http' => array('method' => 'GET', 'header' => "User-Agent: Mozilla/5.0\r\nCache-Control: no-cache\r\n", 'timeout' => 3), 'ssl' => array('verify_peer' => false, 'verify_peer_name' => false));
        $res = @file_get_contents($url, false, stream_context_create($opts));
    }
    return $res ? json_decode($res, true) : null;
}

function getNasdaqMarketData() {
    $ts = time() . mt_rand(100, 999);
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/NQ=F?interval=1m&range=2d&includePrePost=true&_=" . $ts;
    $json = fetchYahooChartJson($url);$livePrice = 0;
    $candles = array();

    if ($json && isset($json['chart']['result'][0])) {
        $r0 =$json['chart']['result'][0];
        if (isset($r0['meta']['regularMarketPrice'])) {
            $val = (float)$r0['meta']['regularMarketPrice'];
            if ($val > 1000) $livePrice = round($val * 4) / 4;
        }
        if (isset($r0['timestamp']) && isset($r0['indicators']['quote'][0])) {
            $tsArr =$r0['timestamp']; 
            $q =$r0['indicators']['quote'][0];
            $cnt = count($tsArr);
            for ($i = 0; $i < $cnt; $i++) {
                $t = (int)(floor((int)$tsArr[$i] / 60) * 60);$o = isset($q['open'][$i]) ? (float)$q['open'][$i] : 0;
                $h = isset($q['high'][$i]) ? (float)$q['high'][$i] : 0;
                $l = isset($q['low'][$i]) ? (float)$q['low'][$i] : 0;
                $c = isset($q['close'][$i]) ? (float)$q['close'][$i] : 0;
                $v = isset($q['volume'][$i]) ? (int)$q['volume'][$i] : 0;

                if ($o > 1000 &&$c > 1000 && $h > 1000 &&$l > 1000) {
                    $candles[$t] = array(
                        'time'   => $t,
                        'open'   => round($o * 4) / 4,                         'high'   => round($h * 4) / 4,
                        'low'    => round($l * 4) / 4,                         'close'  => round($c * 4) / 4,
                        'volume' => ($v > 0 ?$v : 50)
                    );
                }
            }
        }
    }
    ksort($candles);
    return array('price' => $livePrice, 'candles' => array_values($candles));
}

function aggregateCandles($master1M,$intervalSec) {
    if (!is_array($master1M) \vert{}\vert{} count($master1M) === 0) return array();
    $bucketsMap = array();$bucketTimes = array();
    
    foreach ($master1M as$m) {
        $bTime = (int)(floor($m['time'] / $intervalSec) *$intervalSec);
        if (!isset($bucketsMap[$bTime])) {
            $bucketsMap[$bTime] = array('time' => $bTime, 'open' =>$m['open'], 'high' => $m['high'], 'low' =>$m['low'], 'close' => $m['close'], 'volume' =>$m['volume']);
            $bucketTimes[] =$bTime;
        } else {
            $b = &$bucketsMap[$bTime];
            if ($m['high'] >$b['high']) $b['high'] =$m['high'];
            if ($m['low'] <$b['low']) $b['low'] =$m['low'];
            $b['close'] =$m['close'];
            $b['volume'] +=$m['volume'];
        }
    }
    sort($bucketTimes);$res = array();
    foreach ($bucketTimes as $t) {$res[] = $bucketsMap[$t];
    }
    return $res;
}

function calculateValueWhenZigZagStepPHP($candles,$pct) {
    if (!is_array($candles) \vert{}\vert{} count($candles) < 2) return array();
    $thresh =$pct / 100.0;
    $barValues = array_fill(0, count($candles), 0);

    $trend = 0; 
    $extremeVal = $candles[0]['high'];$extremeIdx = 0;
    $activeValue =$candles[0]['close'];

    $barValues[0] =$activeValue;
    $pivots = array(); 
    
    for ($k = 1; $k < count($candles);$k++) {
        $h =$candles[$k]['high'];$l = $candles[$k]['low'];

        if ($trend === 0) {
            if ($h >$extremeVal) { $extremeVal =$h; $extremeIdx =$k; }
            if ($l <= $extremeVal * (1 -$thresh)) {
                $pivots[] = array('index' =>$extremeIdx, 'value' => $extremeVal, 'type' => 'HIGH');$trend = -1; 
                $extremeVal =$l; 
                $extremeIdx =$k;
            } elseif ($l <$extremeVal) {
                $extremeVal =$l; 
                $extremeIdx =$k;
            }
            if ($h >= $extremeVal * (1 +$thresh)) {
                $pivots[] = array('index' =>$extremeIdx, 'value' => $extremeVal, 'type' => 'LOW');$trend = 1; 
                $extremeVal =$h; 
                $extremeIdx =$k;
            }
        } elseif ($trend === 1) {
            if ($h >$extremeVal) { 
                $extremeVal =$h; 
                $extremeIdx =$k; 
            } elseif (($extremeVal -$l) / $extremeVal >=$thresh) {
                $pivots[] = array('index' =>$extremeIdx, 'value' => $extremeVal, 'type' => 'HIGH');$trend = -1; 
                $extremeVal =$l; 
                $extremeIdx =$k;
            }
        } elseif ($trend === -1) {
            if ($l <$extremeVal) { 
                $extremeVal =$l; 
                $extremeIdx =$k; 
            } elseif (($h -$extremeVal) / $extremeVal >=$thresh) {
                $pivots[] = array('index' =>$extremeIdx, 'value' => $extremeVal, 'type' => 'LOW');$trend = 1; 
                $extremeVal =$h; 
                $extremeIdx =$k;
            }
        }
    }

    $pivotPtr = 0;
    $currentVal =$candles[0]['close'];
    if (count($pivots) > 0) $currentVal =$pivots[0]['value'];

    for ($j = 0; $j < count($candles);$j++) {
        while ($pivotPtr < count($pivots) &&$pivots[$pivotPtr]['index'] <=$j) {
            $currentVal =$pivots[$pivotPtr]['value'];$pivotPtr++;
        }
        $barValues[$j] =$currentVal;
    }
    return $barValues;
}

function getServerSignalState($candles) {
    $len = count($candles);
    if ($len < 20) return array('s1' => 'NONE', 's2' => 'NONE', 's3' => 'NONE');

    $vw02Vals = calculateValueWhenZigZagStepPHP($candles, 0.2);
    $vw05Vals = calculateValueWhenZigZagStepPHP($candles, 0.5);

    $lastIdx =$len - 1;
    $c = $candles[$lastIdx];

    // 신호 1 조건
    $s1 = 'NONE';$v02 = $vw02Vals[$lastIdx];
    if ($c['close'] > $v02) {$s1 = 'BUY';
    } elseif ($c['close'] < $v02) {$s1 = 'SELL';
    }

    // 신호 2 조건 (화면 자바스크립트와 동일한 2MA 꺾임 및 지그재그 연동)
    $s2 = 'NONE';
    $ma2Curr = ($c['close'] + $candles[$lastIdx - 1]['close']) / 2.0;
    $v05 =$vw05Vals[$lastIdx];$ma2Prev = ($len >= 3) ? (($candles[$lastIdx - 1]['close'] +$candles[$lastIdx - 2]['close']) / 2.0) :$ma2Curr;

    if ($c['close'] > $v02 &&$c['close'] > $v05 &&$ma2Curr > $ma2Prev) {$s2 = 'BUY';
    } elseif ($c['close'] < $v02 &&$c['close'] < $v05 &&$ma2Curr < $ma2Prev) {$s2 = 'SELL';
    }

    // 신호 3 조건
    $s3 = 'NONE';$aSum = 0;
    for ($ap = 0; $ap < 5; $ap++) {
        if ($lastIdx -$ap >= 0) $aSum +=$candles[$lastIdx -$ap]['close'];
    }
    $A_val =$aSum / 5.0;

    $diffs = array();
    for ($dp = max(0,$lastIdx - 19); $dp <=$lastIdx; $dp++) {$subSum = 0;
        for ($sp = 0; $sp < 5; $sp++) {
            if ($dp -$sp >= 0) $subSum +=$candles[$dp -$sp]['close'];
        }
        $subA =$subSum / 5.0;
        $diffs[] =$candles[$dp]['close'] -$subA;
    }

    $negArr = array();$posArr = array();
    foreach ($diffs as$v) {
        if ($v < 0) {
            $negArr[] =$v;
        } elseif ($v > 0) {
            $posArr[] =$v;
        }
    }

    $avgNeg = count($negArr) > 0 ? array_sum($negArr) / count($negArr) : 0;
    $stdNeg = 0;
    if (count($negArr) > 0) {$nVar = 0; 
        foreach ($negArr as$val) {
            $nVar += pow($val - $avgNeg, 2);                  }$stdNeg = sqrt($nVar / count($negArr));
    }

    $avgPos = count($posArr) > 0 ? array_sum($posArr) / count($posArr) : 0;
    $stdPos = 0;
    if (count($posArr) > 0) {$pVar = 0; 
        foreach ($posArr as$val) {
            $pVar += pow($val - $avgPos, 2);                  }$stdPos = sqrt($pVar / count($posArr));
    }

    $xx_val = 0.1;
    $aa_buy =$A_val + $avgNeg - ($xx_val * $stdNeg);$aa_sell = $A_val +$avgPos + ($xx_val * $stdPos);

    $sumL = 0; 
    $sumH = 0;
    for ($bp = 0; $bp < 5; $bp++) {
        if ($lastIdx -$bp >= 0) {
            $sumL +=$candles[$lastIdx -$bp]['low'];
            $sumH +=$candles[$lastIdx -$bp]['high'];
        }
    }
    $bb_l =$sumL / 5.0;
    $bb_h =$sumH / 5.0;

    if ($c['close'] > $v05 &&$bb_l > $v05 &&$aa_buy > $v05) {$s3 = 'BUY';
    } elseif ($c['close'] < $v05 &&$bb_h < $v05 &&$aa_sell < $v05) {$s3 = 'SELL';
    }

    return array('s1' => $s1, 's2' => $s2, 's3' =>$s3);
}

$startTime = time();$runDuration = 55;
$totalProcessed = 0;
$lastPrice = 0;
$timers = array();

while (time() - $startTime < $runDuration) {$market = getNasdaqMarketData();
    $currentPrice =$market['price'];
    $masterCandles =$market['candles'];

    if ($currentPrice > 0) {
        $lastPrice =$currentPrice;
        $members_query = mysqli_query($conn, "SELECT * FROM `automeme_members` WHERE auto_active = 1");
        
        if ($members_query) {
            while ($member = mysqli_fetch_assoc($members_query)) {$m_id = (int)$member['id'];$uname = $member['username'];$cur_bal = (float)$member['balance'];$pos_side = (string)$member['position_side'];$pos_qty = (int)$member['position_qty'];$pos_entry = (float)$member['position_entry'];$order_qty = (int)($member['order_qty'] > 0 ?$member['order_qty'] : 1);
                $tp_ticks = (int)$member['tp_ticks'];
                $sl_ticks = (int)$member['sl_ticks'];

                if ($pos_qty > 0 &&$pos_side !== '') {
                    $pt_diff = ($pos_side === 'BUY') ? ($currentPrice -$pos_entry) : ($pos_entry -$currentPrice);
                    $ticks = round($pt_diff / 0.25);
                    $pnl_val = round($pt_diff * 4000 * $pos_qty);$should_close = false;
                    if ($tp_ticks > 0 &&$ticks >= $tp_ticks)$should_close = true;
                    if ($sl_ticks > 0 &&$ticks <= -$sl_ticks)$should_close = true;

                    if ($should_close) {
                        $cur_bal +=$pnl_val;
                        mysqli_query($conn, "UPDATE `automeme_members` SET balance = {$cur_bal}, position_side = '', position_qty = 0, position_entry = 0 WHERE id = {$m_id}");
                        mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', '{$pos_side}', {$pos_entry}, {$currentPrice}, {$pos_qty}, {$pnl_val})");
                        
                        $pos_qty = 0; 
                        $pos_side = '';$pos_entry = 0;
                        if (isset($timers[$m_id])) unset($timers[$m_id]);$totalProcessed++;
                    }
                }

                $detectedAction = '';$activeBlockCount = 0;
                $buyMatches = 0;
                $sellMatches = 0;
                $buyDelays = array();$sellDelays = array();

                for ($b = 1; $b <= 3; $b++) {$bActive = (int)$member['sig' .$b . '_active'];
                    if ($bActive !== 1) continue;

                    $tf = (int)$member['sig' . $b . '_tf'];$u1 = (int)$member['sig' .$b . '_use_1'];
                    $u2 = (int)$member['sig' . $b . '_use_2'];$u3 = (int)$member['sig' .$b . '_use_3'];

                    if ($u1 == 0 && $u2 == 0 &&$u3 == 0) continue;

                    $tfCandles = aggregateCandles($masterCandles,$tf);
                    $sigState = getServerSignalState($tfCandles);
                    
                    $activeBlockCount++;$blockBuyOk = true; 
                    $blockSellOk = true;

                    if ($u1 == 1) {
                        if ($sigState['s1'] !== 'BUY')$blockBuyOk = false;
                        if ($sigState['s1'] !== 'SELL')$blockSellOk = false;
                    }
                    if ($u2 == 1) {
                        if ($sigState['s2'] !== 'BUY')$blockBuyOk = false;
                        if ($sigState['s2'] !== 'SELL')$blockSellOk = false;
                    }
                    if ($u3 == 1) {
                        if ($sigState['s3'] !== 'BUY')$blockBuyOk = false;
                        if ($sigState['s3'] !== 'SELL')$blockSellOk = false;
                    }

                    if ($blockBuyOk) {$buyMatches++;
                        $buyDelays[] = (int)$member['sig' . $b . '_dbuy'] > 0 ? (int)$member['sig' . $b . '_dbuy'] : 5;                                          } elseif ($blockSellOk) {
                        $sellMatches++;$sellDelays[] = (int)$member['sig' .$b . '_dsell'] > 0 ? (int)$member['sig' .$b . '_dsell'] : 5;
                    }
                }

                $maxDelay = 0;
                if ($activeBlockCount > 0) {
                    if ($buyMatches === $activeBlockCount) {$detectedAction = 'BUY';
                        $maxDelay = max($buyDelays);
                    } elseif ($sellMatches === $activeBlockCount) {$detectedAction = 'SELL';
                        $maxDelay = max($sellDelays);
                    }
                }

                if ($detectedAction !== '' && ($pos_qty <= 0 || $pos_side !==$detectedAction)) {
                    if (!isset($timers[$m_id]) \vert{}\vert{}$timers[$m_id]['action'] !==$detectedAction) {
                        $timers[$m_id] = array(
                            'action' => $detectedAction,
                            'execute_at' => time() + $maxDelay
                        );
                    } else {
                        if (time() >= $timers[$m_id]['execute_at']) {
                            $entry_p =$currentPrice;
                            $qty =$order_qty;
                            $side =$detectedAction;
                            
                            $new_side =$side; 
                            $new_qty =$qty; 
                            $new_entry =$entry_p;
                            $realized_pnl = 0; 
                            $closed_qty = 0;

                            if (empty($pos_side) \vert{}\vert{}$pos_qty <= 0) {
                                $new_side =$side; 
                                $new_qty =$qty; 
                                $new_entry =$entry_p;
                                mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', '{$new_side}', {$new_entry}, 0, {$new_qty}, 0)");
                            } else {
                                if ($pos_qty >$qty) {
                                    $closed_qty =$qty;
                                    $pt_diff = ($pos_side === 'BUY') ? ($entry_p - $pos_entry) : ($pos_entry - $entry_p);$realized_pnl = round($pt_diff * 4000 * $closed_qty);
                                    $cur_bal +=$realized_pnl;
                                    mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', '{$pos_side}', {$pos_entry}, {$entry_p}, {$closed_qty}, {$realized_pnl})");
                                    $new_side =$pos_side; 
                                    $new_qty = $pos_qty -$qty; 
                                    $new_entry =$pos_entry;
                                } elseif ($pos_qty ===$qty) {
                                    $closed_qty =$pos_qty;
                                    $pt_diff = ($pos_side === 'BUY') ? ($entry_p - $pos_entry) : ($pos_entry - $entry_p);$realized_pnl = round($pt_diff * 4000 * $closed_qty);
                                    $cur_bal +=$realized_pnl;
                                    mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', '{$pos_side}', {$pos_entry}, {$entry_p}, {$closed_qty}, {$realized_pnl})");
                                    $new_side = '';$new_qty = 0; 
                                    $new_entry = 0;
                                } else {
                                    $closed_qty =$pos_qty;
                                    $pt_diff = ($pos_side === 'BUY') ? ($entry_p - $pos_entry) : ($pos_entry - $entry_p);$realized_pnl = round($pt_diff * 4000 * $closed_qty);
                                    $cur_bal +=$realized_pnl;
                                    mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', '{$pos_side}', {$pos_entry}, {$entry_p}, {$closed_qty}, {$realized_pnl})");
                                    $new_side =$side; 
                                    $new_qty = $qty -$pos_qty; 
                                    $new_entry =$entry_p;
                                    mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, close_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', '{$new_side}', {$new_entry}, 0, {$new_qty}, 0)");
                                }
                            }

                            mysqli_query($conn, "UPDATE `automeme_members` SET balance = {$cur_bal}, position_side = '{$new_side}', position_qty = {$new_qty}, position_entry = {$new_entry} WHERE id = {$m_id}");
                            $totalProcessed++;
                            
                            unset($timers[$m_id]);
                        }
                    }
                } else {
                    if (isset($timers[$m_id])) unset($timers[$m_id]);
                }
            }
        }
    }
    
    sleep(3);
}

echo json_encode(array('status' => 'ok', 'last_price' => $lastPrice, 'total_processed_in_1_min' =>$totalProcessed, 'time' => date('Y-m-d H:i:s')));
?>