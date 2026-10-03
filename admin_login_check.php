<?php
session_start();
$admin_id = isset($_POST['admin_id']) ? trim($_POST['admin_id']) : '';
$admin_pw = isset($_POST['admin_pw']) ? trim($_POST['admin_pw']) : '';

$correct_id = "admin";
$correct_pw = "1234"; 

if ($admin_id === $correct_id && $admin_pw === $correct_pw) {
    $_SESSION['admin_logged'] = true;
    $_SESSION['admin_user'] = $admin_id;
    header("Location: admin_index.php");
    exit;
} else {
    header("Location: admin_login.php?error=1");
    exit;
}
?>