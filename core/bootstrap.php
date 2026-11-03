<?php
// Mọi trang đều bắt đầu bằng: require_once __DIR__ . '/../core/bootstrap.php';
$cfg = require __DIR__ . '/../config/config.php';

date_default_timezone_set($cfg['timezone']);
error_reporting(E_ALL);
ini_set('display_errors', $cfg['debug'] ? '1' : '0');

// BASE_URL: đường dẫn gốc của dự án trên web, tự tính (vd: /elo_quiz_khung_du_an/elo_quiz).
// Dùng để viết link và đường dẫn CSS cho đúng dù thư mục đặt ở đâu trong htdocs.
$goc  = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$duan = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
define('BASE_URL', ($goc !== '' && stripos($duan, $goc) === 0) ? rtrim(substr($duan, strlen($goc)), '/') : '');

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/NhatKy.php';
require_once __DIR__ . '/Paginator.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}