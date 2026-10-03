<?php
// 🎯 가비아(Gabia) 및 일반 호스팅 환경 최적화 설정
$host = "127.0.0.1"; 
$user = "hasun7372";
$pass = "aa998800@@";
$db   = "dbhasun7372";

// 🎯 DB 연결 실행
$conn = mysqli_connect($host, $user, $pass, $db);

// 🎯 접속 확인 및 오류 출력
if (!$conn) {
    die("🚨 [사령부 통신 장애] DB 접속에 실패했습니다. 설정을 확인하세요.");
}

// 🎯 한글 깨짐 방지
mysqli_set_charset($conn, "utf8");
?>