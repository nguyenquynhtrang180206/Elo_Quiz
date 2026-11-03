<?php
// Trang kiểm tra: mở http://localhost/elo_quiz/ketnoi.php
require_once __DIR__ . '/core/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = Database::pdo();
    $soBang = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )->fetchColumn();
    echo '<p>Kết nối thành công. Database: <b>' . e((string) $pdo->query('SELECT DATABASE()')->fetchColumn()) . '</b></p>';
    echo '<p>Số bảng: <b>' . $soBang . '</b> (cần 18)</p>';
    echo '<p>SO_CAU = <b>' . Config::int('SO_CAU') . '</b> (cần 10), THOI_GIAN_MOI_CAU = <b>'
        . Config::int('THOI_GIAN_MOI_CAU') . '</b> (cần 15)</p>';
} catch (Throwable $ex) {
    echo '<p style="color:red">Lỗi: ' . e($ex->getMessage()) . '</p>';
}
