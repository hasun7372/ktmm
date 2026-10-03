<?php
session_start();
require_once 'db.php';

// automeme_members 테이블에 session_token 컬럼이 없으면 자동 생성
$tok_chk = mysqli_query($conn, "SHOW COLUMNS FROM `automeme_members` LIKE 'session_token'");
if ($tok_chk && mysqli_num_rows($tok_chk) == 0) {
    mysqli_query($conn, "ALTER TABLE `automeme_members` ADD COLUMN `session_token` VARCHAR(64) DEFAULT ''");
}

$username = isset($_POST['username']) ? trim($_POST['username']) : '';$password = isset($_POST['password']) ? trim($_POST['password']) : '';

if ($username !== '' &&$password !== '') {
    $safe_user = mysqli_real_escape_string($conn, $username);$safe_pass = mysqli_real_escape_string($conn,$password);

    $query = "SELECT * FROM `automeme_members` WHERE username = '" . $safe_user . "' AND password = '" . $safe_pass . "' LIMIT 1";
    $result = mysqli_query($conn,$query);

    if ($result && mysqli_num_rows($result) == 1) {
        $member = mysqli_fetch_assoc($result);

        // 🎯 고유 접속 토큰 생성 (새로 로그인할 때마다 갱신되므로 기존 창은 무효화됨)
        $session_token = md5(uniqid(mt_rand(), true));
        mysqli_query($conn, "UPDATE `automeme_members` SET session_token = '{$session_token}' WHERE id = " . (int)$member['id']);

        $_SESSION['member_logged'] = true;
        $_SESSION['member_id'] =$member['id'];
        $_SESSION['member_username'] =$member['username'];
        $_SESSION['member_name'] =$member['name'];
        $_SESSION['member_token'] =$session_token; // 세션 토큰 저장

        header("Location: member_index.php");
        exit;
    }
}

header("Location: member_login.php?error=1");
exit;
?>