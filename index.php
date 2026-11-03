<?php
// Trang gốc của dự án: mặc định chuyển vào Site Client.
// Đặt tại: elo_quiz/index.php
require_once __DIR__ . '/core/bootstrap.php';
redirect(BASE_URL . '/client/index.php');
