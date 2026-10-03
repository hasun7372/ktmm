<?php
require_once 'db.php';

// 🎯 진짜 회원 테이블 'automeme_members' 연동 확정
$tableName = 'automeme_members'; 

// 1. 관리자 액션 처리 (회원 정보 수정, 삭제, 포인트 지급/회수)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {$action = isset($_POST['action']) ?$_POST['action'] : '';
    $target_id = (int)$_POST['target_id'];

    if ($action === 'update_member') {$phone = mysqli_real_escape_string($conn,$_POST['phone']);
        $balance = (float)$_POST['balance'];
        $holding = mysqli_real_escape_string($conn, $_POST['holding']);$grade = mysqli_real_escape_string($conn,$_POST['grade']);
        $pw = trim($_POST['password']);

        if (!empty($pw)) {
            $hashed_pw = password_hash($pw, PASSWORD_DEFAULT);
            mysqli_query($conn, "UPDATE `$tableName` SET phone = '$phone', balance =$balance, holding = '$holding', grade = '$grade', password = '$hashed_pw' WHERE id =$target_id");
        } else {
            mysqli_query($conn, "UPDATE `$tableName` SET phone = '$phone', balance = $balance, holding = '$holding', grade = '$grade' WHERE id =$target_id");
        }
        header("Location: admin.php?msg=updated");
        exit;
    }

    if ($action === 'delete_member') {
        mysqli_query($conn, "DELETE FROM `$tableName` WHERE id = $target_id");
        header("Location: admin.php?msg=deleted");
        exit;
    }

    if ($action === 'adjust_point') {
        $pt_type =$_POST['pt_type']; // 'give' 또는 'take'
        $amount = (float)$_POST['amount'];
        if ($amount > 0) {
            $sign = ($pt_type === 'give') ? '+' : '-';
            mysqli_query($conn, "UPDATE `$tableName` SET balance = balance $sign $amount WHERE id =$target_id");
        }
        header("Location: admin.php?msg=points_adjusted");
        exit;
    }
}

// 4번: 보기 갯수 설정 (기본 20개)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if (!in_array($limit, [20, 50, 100, 200])) {$limit = 20;
}

// 회원 목록 조회
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
    <title>오토매매 관리자 대시보드</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .member-link { color: #63b3ed; text-decoration: none; font-weight: bold; }
        .member-link:hover { text-decoration: underline; }
        .btn-point { background: #d69e2e; color: #fff; border: none; padding: 2px 6px; border-radius: 3px; cursor: pointer; font-size: 11px; margin-left: 4px; }
        .btn-point:hover { background: #b7791f; }

        /* 수정 모달 팝업 스타일 */
        .modal { display: none; position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); justify-content: center; align-items: center; }
        .modal-content { background: #1e1e1e; padding: 20px; border-radius: 8px; width: 400px; border: 1px solid #444; display: flex; flex-direction: column; gap: 12px; color: #fff; }
        .modal-content h3 { margin: 0 0 10px 0; color: #4CAF50; font-size: 16px; border-bottom: 1px solid #333; padding-bottom: 6px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; font-size: 12px; }
        .form-group input, .form-group select { background: #2a2a2a; border: 1px solid #444; color: #fff; padding: 8px; border-radius: 4px; font-size: 13px; }
        .modal-btns { display: flex; gap: 8px; margin-top: 10px; }
        .btn-save { flex: 1; background: #38a169; color: #fff; border: none; padding: 10px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-delete { flex: 1; background: #e53e3e; color: #fff; border: none; padding: 10px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        .btn-cancel { flex: 1; background: #4a5568; color: #fff; border: none; padding: 10px; border-radius: 4px; font-weight: bold; cursor: pointer; }

        .point-modal { display: none; position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); justify-content: center; align-items: center; }
    </style>
</head>
<body>

    <!-- 상단 네비게이션바 -->
    <div class="header">
        <h1>🤖 오토매매 관리자 대시보드</h1>
        <div class="top-right">
            <button onclick="openPointModal()" style="background:#d69e2e; color:#fff; border:none; padding:6px 12px; border-radius:4px; cursor:pointer; font-weight:bold; margin-right:10px;">포인트 지급/회수</button>
            <a href="qna.php" class="btn-qna">💬 1:1 문의 관리</a>
            <span><b>관리자</b>님 환영합니다! | <a href="admin_logout.php" style="color: #fc8181; text-decoration: none;">로그아웃</a></span>
        </div>
    </div>

    <!-- 1번 구성: 주요 관리 메뉴 -->
    <div class="menu-container">
        <button class="menu-btn" onclick="toggleMenu('menuConfig')">📌 기본환경 설정</button>
        <button class="menu-btn" onclick="toggleMenu('menuMember')">👥 신규 회원 등록</button>
        <button class="menu-btn" onclick="toggleMenu('menuSignal')">📊 시그널 조건 제어</button>
        <button class="menu-btn" onclick="toggleMenu('menuLogs')">⚙️ 시스템 로그 제어</button>
    </div>

    <!-- 1번 연계: 접이식 상세 패널들 -->
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

    <!-- 3번 구성: 공지사항 관리 목록 보기 -->
    <div class="sub-toggle-box" onclick="toggleSubContent()">
        📁 [접기/펼치기] 자주 수정하지 않는 시스템 코드 및 공지사항 관리 목록 보기 ▼
    </div>
    <div id="subContentBox" class="sub-content">
        <h3>시스템 공지사항 및 정적 코드 관리</h3>
        <p>여기에 자주 바뀌지 않는 안내문, 용어 사전, 기본 설정 파일 목록 등이 안전하게 접혀 있습니다.</p>
    </div>

    <!-- 본문 컨텐츠 영역 -->
    <div class="content-body">
        
        <div class="table-header-bar">
            <h3>등록된 사용자 및 트레이딩 계정 목록</h3>
            
            <!-- 4번 구성: 보기 갯수 선택 폼 -->
            <form method="GET" action="admin.php">
                <label for="limit">보기 갯수: </label>
                <select name="limit" id="limit" onchange="this.form.submit()">
                    <option value="20" <?php if($limit==20) echo 'selected'; ?>>20개씩 보기</option>
                    <option value="50" <?php if($limit==50) echo 'selected'; ?>>50개씩 보기</option>
                    <option value="100" <?php if($limit==100) echo 'selected'; ?>>100개씩 보기</option>
                    <option value="200" <?php if($limit==200) echo 'selected'; ?>>200개씩 보기</option>
                </select>
            </form>
        </div>

        <!-- 5번 구성: 목록 나열 테이블 -->
        <table class="list-table">
            <thead>
                <tr>
                    <th>번호</th>
                    <th>아이디</th>
                    <th>이름</th>
                    <th>추천인 ID</th>
                    <th>가상 예수금</th>
                    <th>거래 종목</th>
                    <th>회원등급</th>
                    <th>관리</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($members)): ?>
                    <?php foreach($members as$m): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m['id'] ?? ''); ?></td>
                        <td>
                            <a href="member_view.php?id=<?php echo $m['id']; ?>" class="member-link"><?php echo htmlspecialchars($m['username'] ?? ''); ?></a>
                            <button class="btn-point" onclick="openPointModalForUser('<?php echo $m['id']; ?>')">포인트</button>
                        </td>
                        <td><?php echo htmlspecialchars($m['name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($m['recommender'] ?? '-'); ?></td>
                        <td><?php echo number_format($m['balance'] ?? 0, 4); ?>원</td>
                        <td><?php echo htmlspecialchars(!empty($m['holding']) ?$m['holding'] : '나스닥100'); ?></td>
                        <td><?php echo htmlspecialchars(!empty($m['grade']) ?$m['grade'] : '일반회원'); ?></td>
                        <td>
                            <button style="background:#3182ce; color:#fff; border:none; padding:4px 10px; border-radius:3px; cursor:pointer; font-weight:bold;" onclick="openEditModal(
                                '<?php echo $m['id']; ?>',
                                '<?php echo htmlspecialchars($m['username'] ?? '', ENT_QUOTES); ?>',
                                '<?php echo htmlspecialchars($m['name'] ?? '', ENT_QUOTES); ?>',
                                '<?php echo htmlspecialchars($m['phone'] ?? '', ENT_QUOTES); ?>',
                                '<?php echo $m['balance'] ?? 0; ?>',
                                '<?php echo htmlspecialchars(!empty($m['holding']) ?$m['holding'] : '나스닥100', ENT_QUOTES); ?>',
                                '<?php echo htmlspecialchars(!empty($m['grade']) ?$m['grade'] : '일반회원', ENT_QUOTES); ?>'
                            )">수정</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="padding: 30px; color: #a0aec0;">등록된 회원 데이터가 없습니다. (automeme_members 테이블 연동 확인 필요)</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

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
                    <label>이름:</label>
                    <span id="view_name_text" style="color:#a0aec0; font-weight:bold; font-size:14px;"></span>
                </div>
                <div class="form-group">
                    <label>아이디:</label>
                    <input type="text" id="edit_username" disabled style="background:#151515; color:#718096;">
                </div>
                <div class="form-group">
                    <label>연락처:</label>
                    <input type="text" name="phone" id="edit_phone" required>
                </div>
                <div class="form-group">
                    <label>가상 예수금:</label>
                    <input type="number" step="0.0001" name="balance" id="edit_balance" required>
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

    <!-- 자바스크립트 토글 및 모달 제어 -->
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
            document.getElementById('view_name_text').innerText = name;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_balance').value = balance;
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
    </script>
</body>
</html>