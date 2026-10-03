<?php
session_start();
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

session_start();
if (!empty($_SESSION['member_logged'])) {
    header("Location: member_index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>회원 로그인 - 오토매매</title>
    <link rel="stylesheet" href="style.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #12161c; font-family: 'Malgun Gothic', sans-serif; }
        .login-wrap { width: 100%; min-height: 100vh; display: flex; justify-content: center; align-items: center; background: #12161c; padding: 20px; }
        .login-box { background: #1e2530; padding: 32px 26px; border-radius: 10px; border: 1px solid #2d3748; width: 100%; max-width: 380px; box-shadow: 0 6px 18px rgba(0,0,0,0.45); }
        .login-box h2 { color: #fff; text-align: center; margin-top: 0; margin-bottom: 24px; font-size: 21px; }
        .input-group { margin-bottom: 18px; }
        .input-group label { display: block; margin-bottom: 6px; color: #cbd5e0; font-size: 14px; font-weight: bold; }
        .input-group input { width: 100%; padding: 13px 12px; box-sizing: border-box; background: #2d3748; border: 1px solid #4a5568; color: #fff; border-radius: 6px; font-size: 16px; }
        .input-group input:focus { outline: none; border-color: #3182ce; }
        .login-btn { width: 100%; background: #3182ce; color: #fff; border: none; padding: 14px; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .login-btn:hover { background: #2b6cb0; }
        .error-msg { color: #fc8181; font-size: 13px; text-align: center; margin-bottom: 14px; }
        .bottom-links { text-align: center; margin-top: 18px; font-size: 14px; color: #a0aec0; }
        .bottom-links a { color: #63b3ed; text-decoration: none; font-weight: bold; margin-left: 4px; }
        .bottom-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-wrap">
        <div class="login-box">
            <h2>📈 오토매매 회원 로그인</h2>
            <?php if (isset($_GET['error'])): ?>
                <div class="error-msg">아이디 또는 비밀번호가 일치하지 않습니다.</div>
            <?php endif; ?>
            <form action="member_login_check.php" method="POST">
                <div class="input-group">
                    <label>아이디</label>
                    <input type="text" name="username" required autofocus>
                </div>
                <div class="input-group">
                    <label>비밀번호</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit" class="login-btn">로그인</button>
            </form>
            <div class="bottom-links">
                아직 회원이 아니신가요? <a href="member_register.php">회원가입</a>
            </div>
        </div>
    </div>
</body>
</html>
```[cite: 2]