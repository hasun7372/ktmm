<?php
session_start();

// 회원 전용 세션만 해제
unset($_SESSION['member_logged']);
unset($_SESSION['member_id']);
unset($_SESSION['member_username']);
unset($_SESSION['member_name']);

header("Location: member_login.php");
exit;
?>