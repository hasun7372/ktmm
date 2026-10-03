<?php
session_start();
if (isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true) {
    header("Location: admin_index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>관리자 로그인 - 오토매매</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .login-wrap { width: 100%; height: 100vh; display: flex; justify-content: center; align-items: center; background: #12161c; }
        .login-box { background: #1e2530; padding: 30px; border-radius: 8px; border: 1px solid #2d3748; width: 320px; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .login-box h2 { color: #fff; text-align: center; margin-bottom: 20px; font-size: 20px; }
        .input-group { margin-bottom: 15px; }
        .input-group label { display: block; margin-bottom: 5px; color: #cbd5e0; font-size: 13px; }
        .input-group input { width: 100%; padding: 10px; box-sizing: border-box; background: #2d3748; border: 1px solid #4a5568; color: #fff; border-radius: 4px; }
        .login-btn { width: 100%; background: #3182ce; color: #fff; border: none; padding: 10px; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .login-btn:hover { background: #2b6cb0; }
        .error-msg { color: #fc8181; font-size: 12px; text-align: center; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="login-wrap">
        <div class="login-box">
            <h2>🤖 오토매매 관리자</h2>
            <?php if (isset($_GET['error'])): ?>
                <div class="error-msg">아이디 또는 비밀번호가 일치하지 않습니다.</div>
            <?php endif; ?>
            <form action="admin_login_check.php" method="POST">
                <div class="input-group">
                    <label>관리자 아이디</label>
                    <input type="text" name="admin_id" required autofocus>
                </div>
                <div class="input-group">
                    <label>비밀번호</label>
                    <input type="password" name="admin_pw" required>
                </div>
                <button type="submit" class="login-btn">로그인</button>
            </form>
        </div>
    </div>
</body>
</html>