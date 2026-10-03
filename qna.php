<?php
require_once 'db.php';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>1:1 문의 관리 - 오토매매</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="header">
        <h1>💬 오토매매 - 1:1 문의 관리</h1>
        <div class="top-right">
            <a href="admin.php" class="btn-qna" style="background:#4a5568;">◀ 관리자 홈으로</a>
        </div>
    </div>
    <div class="content-body">
        <h3>사용자 1:1 문의 및 피드백 내역</h3>
        <table class="list-table" style="margin-top: 15px;">
            <thead>
                <tr>
                    <th>번호</th>
                    <th>작성자</th>
                    <th>제목</th>
                    <th>등록일시</th>
                    <th>답변상태</th>
                    <th>관리</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="6" style="padding: 30px; color: #a0aec0;">접수된 1:1 문의 내역이 없습니다.</td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>