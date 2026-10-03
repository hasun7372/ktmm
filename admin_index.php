<?php
session_start();
require_once 'db.php';

$tableName = 'automeme_members'; 

// 야후 API 시세 수신 함수
function fetchAdminYahooJson($url) {
    $res = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: Mozilla/5.0', 'Accept: application/json,text/plain,*/*', 'Cache-Control: no-cache'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_TIMEOUT, 2); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);$res = curl_exec($ch); curl_close($ch);
    }
    if (!$res) {$opts = array('http' => array('method' => 'GET', 'header' => "User-Agent: Mozilla/5.0\r\nCache-Control: no-cache\r\n", 'timeout' => 2), 'ssl' => array('verify_peer' => false, 'verify_peer_name' => false));
        $res = @file_get_contents($url, false, stream_context_create($opts));
    }
    return $res ? json_decode($res, true) : null;
}

// 현재 나스닥 선물 실시간 가격 조회
$live_futures_price = 30598.25;
$ts = time() . mt_rand(100, 999);
$json_live = fetchAdminYahooJson("https://query1.finance.yahoo.com/v8/finance/chart/NQ=F?interval=1m&range=1d&_=" . $ts);
if ($json_live && isset($json_live['chart']['result'][0]['meta']['regularMarketPrice'])) {
    $p_val = (float)$json_live['chart']['result'][0]['meta']['regularMarketPrice'];
    if ($p_val > 1000) $live_futures_price = round($p_val * 4) / 4;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {$action = isset($_POST['action']) ?$_POST['action'] : '';
    $target_id = (int)$_POST['target_id'];

    if ($action === 'update_member') {
        $name = mysqli_real_escape_string($conn, $_POST['name']);$phone = mysqli_real_escape_string($conn,$_POST['phone']);
        $balance = (float)$_POST['balance'];
        $holding = mysqli_real_escape_string($conn, $_POST['holding']);$grade = mysqli_real_escape_string($conn,$_POST['grade']);
        $pw = trim($_POST['password']);

        if (!empty($pw)) {
            $hashed_pw = password_hash($pw, PASSWORD_DEFAULT);
            mysqli_query($conn, "UPDATE `$tableName` SET name = '$name', phone = '$phone', balance =$balance, holding = '$holding', grade = '$grade', password = '$hashed_pw' WHERE id =$target_id");
        } else {
            mysqli_query($conn, "UPDATE `$tableName` SET name = '$name', phone = '$phone', balance = $balance, holding = '$holding', grade = '$grade' WHERE id =$target_id");
        }
        header("Location: admin_index.php?msg=updated");
        exit;
    }

    if ($action === 'delete_member') {
        mysqli_query($conn, "DELETE FROM `$tableName` WHERE id = $target_id");
        header("Location: admin_index.php?msg=deleted");
        exit;
    }

    if ($action === 'adjust_point') {
        $pt_type =$_POST['pt_type'];
        $amount = (float)$_POST['amount'];
        if ($amount > 0) {
            $sign = ($pt_type === 'give') ? '+' : '-';
            mysqli_query($conn, "UPDATE `$tableName` SET balance = balance $sign $amount WHERE id =$target_id");
        }
        header("Location: admin_index.php?msg=points_adjusted");
        exit;
    }
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if (!in_array($limit, [20, 50, 100, 200])) {$limit = 20;
}

$members = [];$query = "SELECT * FROM `$tableName` ORDER BY id DESC LIMIT $limit";
$result = mysqli_query($conn,$query);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $members[] =$row;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>오토매매 관리자 대시보드</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .member-link { color: #63b3ed; text-decoration: none; font-weight: bold; }
        .member-link:hover { text-decoration: underline; }
        
        .btn-point { background: #d69e2e; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: bold; transition: 0.2s; }
        .btn-point:hover { background: #b7791f; }

        .modal { display: none; position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); justify-content: center; align-items: center; }
        .modal-content { background: #1e1e1e; padding: 20px; border-radius: 8px; width: 400px; max-width: 90%; border: 1px solid #444; display: flex; flex-direction: column; gap: 12px; color: #fff; }
        .modal-content h3 { margin: 0 0 10px 0; color: #4CAF50; font-size: 16px; border-bottom: 1px solid #333; padding-bottom: 6px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
        .form-group input, .form-group select { background: #2a2a2a; border: 1px solid #444; color: #fff; padding: 8px; border-radius: 4px; font-size: 13px; }
        .modal-btns { display: flex; gap: 8px; margin-top: 10px; }
        .btn-save { flex: 1; background: #38a169; color: #fff; border: none; padding: 10px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-delete { flex: 1; background: #e53e3e; color: #fff; border: none; padding: 10px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-cancel { flex: 1; background: #4a5568; color: #fff; border: none; padding: 10px; border-radius: 4px; font-weight: bold; cursor: pointer; }

        .point-modal { display: none; position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); justify-content: center; align-items: center; }

        .table-responsive-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            background: #1e1e1e;
            border: 1px solid #333;
            border-radius: 8px;
        }

        .header { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background-color: #1e1e1e; border-bottom: 1px solid #333; }
        .header h1 { margin: 0; font-size: 20px; color: #fff; }
        .top-right { display: flex; align-items: center; gap: 12px; }
        .top-right-btn { background: #d69e2e; color: #fff; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; font-size: 13px; }
        .btn-qna { background: #3182ce; color: #fff; border: none; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; font-size: 13px; }
        .admin-greeting { color: #cbd5e0; font-size: 14px; }
        
        .menu-container { padding: 15px; display: flex; gap: 10px; flex-wrap: wrap; }
        .menu-btn { background: #2d3748; color: #e2e8f0; border: 1px solid #4a5568; padding: 10px 16px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .menu-btn:hover { background: #4a5568; }

        .pnl-plus { color: #ff5252; font-weight: bold; }
        .pnl-minus { color: #448aff; font-weight: bold; }

        /* 프로그램 상태 배지 스타일 */
        .status-badge-on { background: rgba(72, 187, 120, 0.2); color: #68d391; border: 1px solid #48bb78; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; }
        .status-badge-off { background: rgba(160, 174, 192, 0.15); color: #a0aec0; border: 1px solid #4a5568; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; display: inline-block; }

        /* 보유 포지션(매수/매도) 표시 스타일 */
        .pos-buy { color: #ff5252; font-weight: bold; background: rgba(255, 82, 82, 0.1); padding: 3px 8px; border-radius: 4px; border: 1px solid rgba(255, 82, 82, 0.3); display: inline-block; }
        .pos-sell { color: #448aff; font-weight: bold; background: rgba(68, 138, 255, 0.1); padding: 3px 8px; border-radius: 4px; border: 1px solid rgba(68, 138, 255, 0.3); display: inline-block; }

        @media (max-width: 860px) {
            .header { flex-direction: column; align-items: stretch; gap: 12px; padding: 15px; text-align: center; }
            .header h1 { font-size: 18px; margin-bottom: 5px; }
            
            .top-right { flex-direction: column; width: 100%; gap: 8px; align-items: stretch; }
            .top-right-btn, .btn-qna { width: 100%; text-align: center; padding: 12px; font-size: 14px; margin: 0; }
            .admin-greeting { width: 100%; text-align: center; margin-top: 5px; padding: 8px; background: #252525; border-radius: 4px; border: 1px solid #333; }

            .menu-container { display: flex; flex-direction: column; padding: 10px 15px; gap: 8px; }
            .menu-btn { width: 100%; padding: 12px; font-size: 14px; margin: 0; }

            .table-header-bar { flex-direction: column; align-items: flex-start; gap: 10px; }
            .table-header-bar form { width: 100%; text-align: right; }
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>🤖 오토매매 관리자 대시보드 (실시간 나스닥 기준가: <span style="color: #00e676;"><?php echo number_format($live_futures_price, 2); ?></span>)</h1>
        <div class="top-right">
            <button class="top-right-btn" onclick="openPointModal()">💰 포인트 지급/회수</button>
            <a href="qna.php" class="btn-qna">💬 1:1 문의 관리</a>
            <div class="admin-greeting"><b>관리자</b>님 환영합니다! | <a href="admin_logout.php" style="color: #fc8181; text-decoration: none; font-weight:bold;">로그아웃</a></div>
        </div>
    </div>

    <div class="menu-container">
        <button class="menu-btn" onclick="toggleMenu('menuConfig')">📌 기본환경 설정</button>
        <button class="menu-btn" onclick="toggleMenu('menuMember')">👥 신규 회원 등록</button>
        <button class="menu-btn" onclick="toggleMenu('menuSignal')">📊 시그널 조건 제어</button>
        <button class="menu-btn" onclick="toggleMenu('menuLogs')">⚙️ 시스템 로그 제어</button>
    </div>

    <div id="menuConfig" class="collapsible-content">
        <h3>[기본환경 설정 패널]</h3>
        <p>나스닥 및 비트코인 API 연동 키, 수수료율 및 시스템 전역 변수를 제어하는 공간입니다.</p>
    </div>
    <div id="menuMember" class="collapsible-content">
        <h3>[신규 회원 등록 패널]</h3>
        <p>새로운 트레이딩 참여 사용자를 수동 등록하고 초기 가상 예수금을 부여할 수 있습니다.</p>
        <a href="member_register.php" style="background:#38a169; color:#fff; padding:6px 12px; text-decoration:none; border-radius:4px; display:inline-block; margin-top:8px;" target="_blank">회원가입 페이지 열기</a>
    </div>
    <div id="menuSignal" class="collapsible-content">
        <h3>[시그널 조건 제어 패널]</h3>
        <p>3가지 조건(지그재그, 볼린저밴드 등)의 민감도 및 보조지표 박스 색상 출력 기준을 세팅합니다.</p>
    </div>
    <div id="menuLogs" class="collapsible-content">
        <h3>[시스템 로그 제어 패널]</h3>
        <p>실시간 주문 체결 로그 및 5초 주기 스캔 오류 내역을 확인하고 초기화합니다.</p>
    </div>

    <div class="sub-toggle-box" onclick="toggleSubContent()">
        📁 [접기/펼치기] 자주 수정하지 않는 시스템 코드 및 공지사항 관리 목록 보기 ▼
    </div>
    <div id="subContentBox" class="sub-content">
        <h3>시스템 공지사항 및 정적 코드 관리</h3>
        <p>여기에 자주 바뀌지 않는 안내문, 용어 사전, 기본 설정 파일 목록 등이 안전하게 접혀 있습니다.</p>
    </div>

    <div class="content-body">
        
        <div class="table-header-bar">
            <h3>등록된 사용자 및 트레이딩 계정 목록</h3>
            
            <form method="GET" action="admin_index.php">
                <label for="limit">보기 갯수: </label>
                <select name="limit" id="limit" onchange="this.form.submit()" style="padding:4px; background:#2a2a2a; color:#fff; border:1px solid #444; border-radius:3px;">
                    <option value="20" <?php if($limit==20) echo 'selected'; ?>>20개씩 보기</option>
                    <option value="50" <?php if($limit==50) echo 'selected'; ?>>50개씩 보기</option>
                    <option value="100" <?php if($limit==100) echo 'selected'; ?>>100개씩 보기</option>
                    <option value="200" <?php if($limit==200) echo 'selected'; ?>>200개씩 보기</option>
                </select>
            </form>
        </div>

        <div class="table-responsive-wrapper">
            <table class="list-table" style="width: 100%; border-collapse: collapse; text-align: center; min-width: 1040px;">
                <thead>
                    <tr>
                        <th>번호</th>
                        <th>아이디</th>
                        <th>포인트</th>
                        <th>이름</th>
                        <th>추천인 ID</th>
                        <th>가상 예수금</th>
                        <th>프로그램 상태</th>
                        <th style="color: #68d391; background: #242933;">보유</th>
                        <th style="color: #68d391; background: #242933;">실시간 평가 손익</th>
                        <th>거래 종목</th>
                        <th>회원등급</th>
                        <th>관리</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($members)): ?>
                        <?php foreach($members as$m): 
                            $pos_side = isset($m['position_side']) ? $m['position_side'] : '';$pos_qty = isset($m['position_qty']) ? (int)$m['position_qty'] : 0;
                            $pos_entry = isset($m['position_entry']) ? (float)$m['position_entry'] : 0.00;
                            $auto_active = isset($m['auto_active']) ? (int)$m['auto_active'] : 0;

                            $pnl_val = 0;
                            $pnl_class = '';$pnl_str = '0원 (0.00pt)';

                            if ($pos_qty > 0 && !empty($pos_side)) {$pt_diff = ($pos_side === 'BUY') ? ($live_futures_price - $pos_entry) : ($pos_entry - $live_futures_price);$pnl_val = round($pt_diff * 4000 * $pos_qty);
                                $ticks = round($pt_diff / 0.25);
                                
                                if ($pnl_val > 0) {$pnl_class = 'pnl-plus';
                                    $pnl_str = '+' . number_format($pnl_val) . '원 (+' . number_format($pt_diff, 2) . 'pt / +' .$ticks . '틱)';
                                } elseif ($pnl_val < 0) {$pnl_class = 'pnl-minus';
                                    $pnl_str = number_format($pnl_val) . '원 (' . number_format($pt_diff, 2) . 'pt / ' .$ticks . '틱)';
                                } else {
                                    $pnl_str = '0원 (0.00pt / 0틱)';
                                }
                            }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['id'] ?? ''); ?></td>
                            <td>
                                <a href="member_view.php?id=<?php echo $m['id']; ?>" class="member-link" target="_blank"><?php echo htmlspecialchars($m['username'] ?? ''); ?></a>
                            </td>
                            <td>
                                <button class="btn-point" onclick="openPointModalForUser('<?php echo $m['id']; ?>')">포인트</button>
                            </td>
                            <td><?php echo htmlspecialchars($m['name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($m['recommender'] ?? '-'); ?></td>
                            <td><?php echo number_format((float)($m['balance'] ?? 0), 0); ?>원</td>
                            <td>
                                <?php if ($auto_active === 1): ?>
                                    <span class="status-badge-on">🟢 ON (실행중)</span>
                                <?php else: ?>
                                    <span class="status-badge-off">⚪ OFF</span>
                                <?php endif; ?>
                            </td>
                            <td style="background: rgba(0,0,0,0.15);">
                                <?php if ($pos_qty > 0 &&$pos_side === 'BUY'): ?>
                                    <span class="pos-buy">매수 (<?php echo $pos_qty; ?>)</span>
                                <?php elseif ($pos_qty > 0 &&$pos_side === 'SELL'): ?>
                                    <span class="pos-sell">매도 (<?php echo $pos_qty; ?>)</span>
                                <?php else: ?>
                                    <span style="color: #718096;">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="<?php echo $pnl_class; ?>" style="background: rgba(0,0,0,0.15); font-weight: bold;"><?php echo $pnl_str; ?></td>
                            <td><?php echo htmlspecialchars(!empty($m['holding']) ?$m['holding'] : '나스닥100'); ?></td>
                            <td><?php echo htmlspecialchars(!empty($m['grade']) ?$m['grade'] : '일반회원'); ?></td>
                            <td>
                                <button style="background:#3182ce; color:#fff; border:none; padding:6px 14px; border-radius:3px; cursor:pointer; font-weight:bold;" onclick="openEditModal(
                                    '<?php echo $m['id']; ?>',
                                    '<?php echo htmlspecialchars($m['username'] ?? '', ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars($m['name'] ?? '', ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars($m['phone'] ?? '', ENT_QUOTES); ?>',
                                    '<?php echo round((float)($m['balance'] ?? 0)); ?>',
                                    '<?php echo htmlspecialchars(!empty($m['holding']) ?$m['holding'] : '나스닥100', ENT_QUOTES); ?>',
                                    '<?php echo htmlspecialchars(!empty($m['grade']) ?$m['grade'] : '일반회원', ENT_QUOTES); ?>'
                                )">수정</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="12" style="padding: 30px; color: #a0aec0; text-align:center;">등록된 회원 데이터가 없습니다.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- 수정 모달 팝업 -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h3>회원 정보 수정 및 관리</h3>
            <form id="updateForm" method="POST" onsubmit="return confirmUpdate();">
                <input type="hidden" name="action" value="update_member">
                <input type="hidden" name="target_id" id="edit_id">
                
                <div class="form-group">
                    <label>번호:</label>
                    <span id="view_id_text" style="color:#a0aec0; font-weight:bold; font-size:14px;"></span>
                </div>
                <div class="form-group">
                    <label>아이디:</label>
                    <input type="text" id="edit_username" disabled style="background:#151515; color:#718096;">
                </div>
                <div class="form-group">
                    <label>이름:</label>
                    <input type="text" name="name" id="edit_name" required>
                </div>
                <div class="form-group">
                    <label>연락처:</label>
                    <input type="text" name="phone" id="edit_phone" required>
                </div>
                <div class="form-group">
                    <label>가상 예수금:</label>
                    <input type="number" step="1" name="balance" id="edit_balance" required>
                </div>
                <div class="form-group">
                    <label>거래 종목:</label>
                    <select name="holding" id="edit_holding" required>
                        <option value="나스닥100">나스닥100</option>
                        <option value="비트코인">비트코인</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>회원등급:</label>
                    <select name="grade" id="edit_grade" required>
                        <option value="일반회원">일반회원</option>
                        <option value="정회원">정회원</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>비밀번호 변경 (변경시에만 입력):</label>
                    <input type="password" name="password" placeholder="새 비밀번호 입력">
                </div>

                <div class="modal-btns">
                    <button type="submit" class="btn-save">저장</button>
                    <button type="button" class="btn-delete" onclick="deleteMember()">삭제</button>
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">취소</button>
                </div>
            </form>
            <form id="deleteForm" method="POST" style="display:none;" onsubmit="return confirm('정말 이 회원을 삭제하시겠습니까?');">
                <input type="hidden" name="action" value="delete_member">
                <input type="hidden" name="target_id" id="delete_id">
            </form>
        </div>
    </div>

    <!-- 포인트 지급 / 회수 모달 -->
    <div id="pointModal" class="point-modal">
        <div class="modal-content">
            <h3>아이디별 포인트 지급 및 회수</h3>
            <form method="POST" onsubmit="return confirmPoint();">
                <input type="hidden" name="action" value="adjust_point">
                <div class="form-group">
                    <label>대상 회원 선택:</label>
                    <select name="target_id" id="modal_target_id" required>
                        <?php foreach($members as$m): ?>
                        <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['username'] ?? ''); ?> (<?php echo htmlspecialchars($m['name'] ?? ''); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>처리 선택:</label>
                    <select name="pt_type" id="modal_pt_type" required>
                        <option value="give">포인트 지급 (+)</option>
                        <option value="take">포인트 회수 (-)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>금액 (원):</label>
                    <input type="number" step="1" name="amount" id="modal_amount" placeholder="예: 10000" required>
                </div>
                <div class="modal-btns">
                    <button type="submit" class="btn-save">실행</button>
                    <button type="button" class="btn-cancel" onclick="closePointModal()">닫기</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleMenu(menuId) {
            let contents = document.querySelectorAll('.collapsible-content');
            contents.forEach(item => {
                if (item.id === menuId) {
                    item.classList.toggle('active');
                } else {
                    item.classList.remove('active');
                }
            });
        }

        function toggleSubContent() {
            let subBox = document.getElementById('subContentBox');
            subBox.classList.toggle('active');
        }

        function openEditModal(id, username, name, phone, balance, holding, grade) {
            document.getElementById('edit_id').value = id;
            document.getElementById('delete_id').value = id;
            document.getElementById('view_id_text').innerText = id;
            
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_balance').value = Math.round(Number(balance));
            document.getElementById('edit_holding').value = holding;
            document.getElementById('edit_grade').value = grade;
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function confirmUpdate() {
            return confirm('정말 이 회원 정보를 수정(저장)하시겠습니까?');
        }

        function deleteMember() {
            if (confirm('정말 이 회원을 삭제하시겠습니까? (삭제된 데이터는 복구할 수 없습니다)')) {
                document.getElementById('deleteForm').submit();
            }
        }

        function openPointModal() {
            document.getElementById('pointModal').style.display = 'flex';
        }

        function openPointModalForUser(userId) {
            document.getElementById('modal_target_id').value = userId;
            document.getElementById('pointModal').style.display = 'flex';
        }

        function closePointModal() {
            document.getElementById('pointModal').style.display = 'none';
        }

        function confirmPoint() {
            var typeSelect = document.getElementById('modal_pt_type');
            var typeStr = (typeSelect.value === 'give') ? '지급' : '회수';
            var amt = document.getElementById('modal_amount').value;
            return confirm('선택하신 회원에게 ' + Number(amt).toLocaleString() + '원을 정말 ' + typeStr + '하시겠습니까?');
        }

        // 30초마다 대시보드 자동 새로고침
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>