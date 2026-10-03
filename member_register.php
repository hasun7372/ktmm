<?php
session_start();
require_once 'db.php';

// 🎯 1. 오토매매 전용 회원 테이블 자동 생성 (grade 기본값 '일반회원')
mysqli_query(
    $conn,
    "CREATE TABLE IF NOT EXISTS `automeme_members` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `username` VARCHAR(50) NOT NULL,
      `password` VARCHAR(255) NOT NULL,
      `name` VARCHAR(50) NOT NULL,
      `phone` VARCHAR(30) NOT NULL,
      `recommender` VARCHAR(50) DEFAULT '-',
      `balance` DECIMAL(18,4) DEFAULT 0.0000,
      `holding` VARCHAR(50) DEFAULT 'US Nas 100',
      `grade` VARCHAR(20) DEFAULT '일반회원',
      `reg_date` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
);

// 🎯 2. 기존 테이블 컬럼 체크 및 추가
$col_check1 = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_members` LIKE 'password'");
if (mysqli_num_rows($col_check1) == 0) {
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD `password` VARCHAR(255) NOT NULL AFTER `username`");
}

$col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_members` LIKE 'phone'");
if (mysqli_num_rows($col_check2) == 0) {
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD `phone` VARCHAR(30) NOT NULL AFTER `name`");
}

$col_check3 = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_members` LIKE 'recommender'");
if (mysqli_num_rows($col_check3) == 0) {
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD `recommender` VARCHAR(50) DEFAULT '-' AFTER `phone`");
}

$msg = "";
$msg_type = "";
$redirect_login = false;

// 🎯 3. 회원가입 폼 전송 처리 (가입 시 grade를 '일반회원'으로 고정 저장)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {$name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';$username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';$recommender = isset($_POST['recommender']) ? trim($_POST['recommender']) : '-';
    if ($recommender === '') {$recommender = '-';
    }

    if ($name !== '' && $phone !== '' &&$username !== '' && $password !== '') {$safe_user = mysqli_real_escape_string($conn,$username);
        $dup_check = mysqli_query($conn, "SELECT id FROM `automeme_members` WHERE username = '" . $safe_user . "'");

        if (mysqli_num_rows($dup_check) > 0) {$msg = "이미 사용 중인 아이디입니다. 다른 아이디를 입력해 주세요.";
            $msg_type = "error";
        } else {
            $safe_name = mysqli_real_escape_string($conn, $name);$safe_phone = mysqli_real_escape_string($conn,$phone);
            $safe_pw = mysqli_real_escape_string($conn, $password);$safe_rec = mysqli_real_escape_string($conn,$recommender);

            // 🎯 grade에 '일반회원' 명시적 삽입
            $insert_ok = mysqli_query($conn,
                "INSERT INTO `automeme_members` (username, password, name, phone, recommender, grade) VALUES ('" .
                $safe_user . "', '" .
                $safe_pw . "', '" .
                $safe_name . "', '" .
                $safe_phone . "', '" .
                $safe_rec . "', '일반회원')"
            );

            if ($insert_ok) {$msg = "회원가입이 완료되었습니다! 로그인 페이지로 이동합니다.";
                $msg_type = "success";
                $redirect_login = true;
            } else {
                $msg = "회원가입 처리 중 오류가 발생했습니다.";
                $msg_type = "error";
            }
        }
    } else {
        $msg = "이름, 연락처, 아이디, 비밀번호를 모두 입력해 주세요.";
        $msg_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>오토매매 - 회원가입</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .signup-wrap { width: 100%; min-height: 100vh; display: flex; justify-content: center; align-items: center; background: #12161c; }
        .signup-box { background: #1e2530; padding: 30px; border-radius: 8px; border: 1px solid #2d3748; width: 350px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .signup-box h2 { color: #fff; text-align: center; margin-bottom: 20px; font-size: 20px; }
        .input-group { margin-bottom: 15px; }
        .input-group label { display: block; margin-bottom: 5px; color: #cbd5e0; font-size: 13px; }
        .input-group input { width: 100%; padding: 10px; box-sizing: border-box; background: #2d3748; border: 1px solid #4a5568; color: #fff; border-radius: 4px; }
        .signup-btn { width: 100%; background: #3182ce; color: #fff; border: none; padding: 11px; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 10px; font-size: 14px; }
        .signup-btn:hover { background: #2b6cb0; }
        .back-login-btn { width: 100%; background: #4a5568; color: #fff; border: none; padding: 10px; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 8px; font-size: 13px; text-decoration: none; display: block; text-align: center; box-sizing: border-box; }
        .back-login-btn:hover { background: #718096; }
        .alert-error { background: #742a2a; color: #fed7d7; padding: 10px; border-radius: 4px; font-size: 13px; text-align: center; margin-bottom: 15px; }
        .alert-success { background: #22543d; color: #c6f6d5; padding: 10px; border-radius: 4px; font-size: 13px; text-align: center; margin-bottom: 15px; }
    </style>
    <?php if ($redirect_login): ?>
    <script>
        setTimeout(function() {
            window.location.href = 'member_login.php';
        }, 1500); // 1.5초 후 로그인 페이지로 자동 이동
    </script>
    <?php endif; ?>
</head>
<body>
    <div class="signup-wrap">
        <div class="signup-box">
            <h2>🤖 오토매매 회원가입</h2>

            <?php if ($msg !== ''): ?>
                <div class="<?php echo ($msg_type === 'success') ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endif; ?>

            <form action="member_register.php" method="POST">
                <div class="input-group">
                    <label>이름</label>
                    <input type="text" name="name" placeholder="이름을 입력하세요" required autofocus>
                </div>
                <div class="input-group">
                    <label>연락처</label>
                    <input type="text" name="phone" placeholder="예: 010-1234-5678" required>
                </div>
                <div class="input-group">
                    <label>아이디</label>
                    <input type="text" name="username" placeholder="사용할 아이디 입력" required>
                </div>
                <div class="input-group">
                    <label>비밀번호</label>
                    <input type="password" name="password" placeholder="비밀번호 입력" required>
                </div>
                <div class="input-group">
                    <label>추천인 아이디 (선택)</label>
                    <input type="text" name="recommender" placeholder="추천인 ID 입력 (선택사항)">
                </div>
                <button type="submit" class="signup-btn">회원가입 완료</button>
            </form>

            <a href="member_login.php" class="back-login-btn">👤 회원 로그인으로 이동</a>
        </div>
    </div>
</body>
</html>