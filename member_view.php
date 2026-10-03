<?php
error_reporting(E_ERROR | E_PARSE);
session_start();
require_once 'db.php';

$tableName = 'automeme_members';$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    echo "<script>alert('잘못된 접근입니다.'); location.href='admin_index.php';</script>";
    exit;
}

// 선택한 회원 정보 조회
$query = "SELECT * FROM `$tableName` WHERE id = $id";
$result = mysqli_query($conn,$query);
$member = mysqli_fetch_assoc($result);

if (!$member) {
    echo "<script>alert('존재하지 않는 회원입니다.'); location.href='admin_index.php';</script>";
    exit;
}

// 해당 회원의 로그인 화면(member_index.php)이 그대로 보이도록 세션 연동
$_SESSION['member_logged']   = true;
$_SESSION['member_username'] = $member['username'];$_SESSION['user_id']         = $member['id'];$_SESSION['member_id']       = $member['id'];$_SESSION['id']              = $member['id'];$_SESSION['username']        = $member['username'];$_SESSION['name']            = $member['name'];$_SESSION['balance']         = $member['balance'];$_SESSION['holding']         = $member['holding'];$_SESSION['grade']           = $member['grade'];$_SESSION['is_admin_view']   = true;

// 실제 회원 트레이딩 화면 불러오기
include 'member_index.php';
?>
<script>
// 우측 상단 환영 문구 / 로그아웃 옆에 '관리자 돌아가기' 버튼 추가
document.addEventListener("DOMContentLoaded", function() {
    const links = document.querySelectorAll('a');
    let placed = false;

    links.forEach(function(link) {
        if (link.innerText.includes('로그아웃') || (link.getAttribute('href') && link.getAttribute('href').includes('logout'))) {
            const adminBtn = document.createElement('a');
            adminBtn.href = 'admin_index.php';
            adminBtn.innerText = '⬅ 관리자 돌아가기';
            adminBtn.style.cssText = 'background:#3182ce; color:#fff; padding:4px 10px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:12px; margin-right:8px; display:inline-block;';
            link.parentNode.insertBefore(adminBtn, link);
            placed = true;
        }
    });

    // 만약 로그아웃 링크를 못 찾았을 경우 우측 상단에 고정 버튼 표시
    if (!placed) {
        const floatingBtn = document.createElement('a');
        floatingBtn.href = 'admin_index.php';
        floatingBtn.innerText = '⬅ 관리자 돌아가기';
        floatingBtn.style.cssText = 'position:fixed; top:10px; right:120px; z-index:9999; background:#3182ce; color:#fff; padding:6px 12px; border-radius:4px; text-decoration:none; font-weight:bold; font-size:12px; box-shadow:0 2px 6px rgba(0,0,0,0.4);';
        document.body.appendChild(floatingBtn);
    }
});
</script>