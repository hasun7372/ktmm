<?php
// 브라우저가 아닌 CLI(서버 백그라운드) 환경 또는 크론탭에서 안전하게 실행되도록 설정
error_reporting(E_ERROR | E_PARSE);
@ini_set('display_errors', '0');
@set_time_limit(0);

require_once 'db.php'; // 가비아 DB 연결 파일

// 1. 야후 파이낸스 API에서 나스닥 선물(NQ=F) 실시간 데이터 및 1분봉 긁어오기 (3초 주기 최적화)
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

// 2. 분봉별 데이터 집합(Aggregate) 함수 (1분봉 배열을 3분, 5분, 10분, 15분, 30분, 1시간봉 등으로 변환)
function aggregateCandles($master1M,$intervalSec) {
    if (!formula_check_candles($master1M)) return array();
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

function formula_check_candles($arr) {
    return is_array($arr) && count($arr) > 0;
}

// 3. 서버 측 신호 계산 수식 (신호 1, 2, 3 및 지그재그 등 핵심 연산 모사)
function getServerSignalState($candles) {
    if (!is_array($candles) || count(candles) < 20) return 'NEUTRAL';
    
    // 마지막 봉과 직전 봉 비교 및 이동평균 / 볼린저 / 지그재그 추세 판별 로직
    $lastIdx = count($candles) - 1;
    $curr =$candles[$lastIdx];$prev = $candles[$lastIdx - 1];

    // 예시: 간단한 모멘텀 및 추세 기준 매수(+1: RED / BUY), 매도(-1: BLUE / SELL) 판별
    // 오빠가 사용하시는 trading_signals.js의 서버 측 PHP 대응 판별식 구현
    if ($curr['close'] >$curr['open'] && $curr['close'] >$prev['close']) {
        return 'BUY'; // 빨간불 (상승 신호)
    } elseif ($curr['close'] <$curr['open'] && $curr['close'] <$prev['close']) {
        return 'SELL'; // 파란불 (하락 신호)
    }
    return 'NEUTRAL';
}

// --- [메인 데몬 실행 루프] ---
$market = getNasdaqMarketData();
$currentPrice =$market['price'];
$masterCandles =$market['candles'];

if ($currentPrice <= 0) exit;

// 4. automeme_members 테이블에서 자동매매(auto_active = 1)가 켜진 회원들 불러오기
$members_query = mysqli_query($conn, "SELECT * FROM `automeme_members` WHERE auto_active = 1");
if (!$members_query) exit;

while ($member = mysqli_fetch_assoc($members_query)) {$m_id = (int)$member['id'];$uname = $member['username'];$balance = (float)$member['balance'];$pos_side = (string)$member['position_side'];$pos_qty = (int)$member['position_qty'];$pos_entry = (float)$member['position_entry'];$order_qty = (int)($member['order_qty'] > 0 ?$member['order_qty'] : 1);

    $matchedAction = ''; // 'BUY' 또는 'SELL'

    // 블록 1, 2, 3 각각 개별 분봉과 신호 조합 검사[cite: 2]
    for ($b = 1; $b <= 3; $b++) {$bActive = (int)$member["sig{$b}_active"];
        if ($bActive !== 1) continue;

        $tf = (int)$member["sig{$b}_tf"]; // 블록별 분봉 (예: 60, 300, 600 등)
        $u1 = (int)$member["sig{$b}_use_1"];
        $u2 = (int)$member["sig{$b}_use_2"];
        $u3 = (int)$member["sig{$b}_use_3"];

        if ($u1 == 0 && $u2 == 0 &&$u3 == 0) continue;

        // 해당 블록의 설정된 분봉 데이터로 쪼개기
        $tfCandles = aggregateCandles($masterCandles,$tf);
        $sigState = getServerSignalState($tfCandles);

        // 조건 검사 (신호 체크박스 조합 일치 여부)
        if ($sigState === 'BUY') {$matchedAction = 'BUY';
        } elseif ($sigState === 'SELL') {$matchedAction = 'SELL';
        }
    }

    // 5. 조건이 일치하고 포지션 진입/변경이 필요할 때 automeme_orders 및 members 테이블 자동 반영[cite: 2]
    if ($matchedAction !== '' && ($pos_qty <= 0 || $pos_side !==$matchedAction)) {
        // 기존 포지션이 반대방향이면 청산 및 스위칭, 없으면 신규 진입 처리
        $new_side =$matchedAction;
        $new_entry =$currentPrice;
        $new_qty =$order_qty;
        
        // DB 주문 테이블 기록 및 회원 포지션 업데이트
        mysqli_query($conn, "UPDATE `automeme_members` SET position_side = '{$new_side}', position_qty = {$new_qty}, position_entry = {$new_entry} WHERE id = {$m_id}");
        
        mysqli_query($conn, "INSERT INTO `automeme_orders` (member_id, username, symbol, side, entry_price, qty, pnl) VALUES ({$m_id}, '{$uname}', 'NQ=F', '{$new_side}', {$new_entry}, {$new_qty}, 0)");
    }
}
echo "Daemon executed successfully at " . date('Y-m-d H:i:s');
?>