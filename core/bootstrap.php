<?php
// Mọi trang đều bắt đầu bằng: require_once __DIR__ . '/../core/bootstrap.php';
$cfg = require __DIR__ . '/../config/config.php';

date_default_timezone_set($cfg['timezone'] ?? 'Asia/Ho_Chi_Minh');
error_reporting(E_ALL);
ini_set('display_errors', !empty($cfg['debug']) ? '1' : '0');

// BASE_URL: đường dẫn gốc của dự án trên web, tự tính (vd: /Elo_Quiz-main).
// Dùng để viết link và đường dẫn CSS cho đúng dù thư mục đặt ở đâu trong htdocs.
$goc  = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$duan = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
define('BASE_URL', ($goc !== '' && stripos($duan, $goc) === 0) ? rtrim(substr($duan, strlen($goc)), '/') : '');

// =========================================================================
// AUTOLOADER CHUẨN PSR-4 NHẸ (Zero-dependency Lazy Loading)
// Tự động nhận diện và tải class theo cấu trúc module phân tầng:
// - Database: Kết nối CSDL, Schema Migration, Cấu hình DB
// - Security: Xác thực, Mã hóa mật khẩu, Giới hạn tần suất, Phạt AFK
// - Game:     Ghép đội, Thi đấu, Tính Elo, Thách đấu, Đột biến, Tướng LoL
// - Services: Cộng đồng, Bạn bè, Thông báo, Quản lý câu hỏi, Thống kê, Nhật ký
// - Support:  Hàm trợ giúp chung, Phân trang, Nhập/Xuất Excel, PDF, Word
// =========================================================================
spl_autoload_register(function (string $class): void {
    // 1. Ánh xạ trực tiếp các Class vào thư mục Module
    static $classMap = [
        // Database
        'Database'         => __DIR__ . '/Database/Database.php',
        'DbSchema'         => __DIR__ . '/Database/DbSchema.php',
        'Config'           => __DIR__ . '/Database/Config.php',

        // Security
        'XacThuc'          => __DIR__ . '/Security/XacThuc.php',
        'Auth'             => __DIR__ . '/Security/XacThuc.php',
        'BaoMatMatKhau'    => __DIR__ . '/Security/BaoMatMatKhau.php',
        'PasswordSecurity' => __DIR__ . '/Security/BaoMatMatKhau.php',
        'GioiHanTanSuat'   => __DIR__ . '/Security/GioiHanTanSuat.php',
        'RateLimiter'      => __DIR__ . '/Security/GioiHanTanSuat.php',
        'PhatAfk'          => __DIR__ . '/Security/PhatAfk.php',
        'AfkPenalty'       => __DIR__ . '/Security/PhatAfk.php',

        // Game
        'GhepDoi'          => __DIR__ . '/Game/GhepDoi.php',
        'ThiDau'           => __DIR__ . '/Game/ThiDau.php',
        'Elo'              => __DIR__ . '/Game/Elo.php',
        'ThachDau'         => __DIR__ . '/Game/ThachDau.php',
        'DotBien'          => __DIR__ . '/Game/DotBien.php',
        'LoLChampions'     => __DIR__ . '/Game/LoLChampions.php',

        // Services
        'CongDongHelper'   => __DIR__ . '/Services/CongDongHelper.php',
        'BanBeHelper'      => __DIR__ . '/Services/BanBeHelper.php',
        'ThongBaoHelper'   => __DIR__ . '/Services/ThongBaoHelper.php',
        'ThongBaoAdmin'    => __DIR__ . '/Services/ThongBaoAdmin.php',
        'CauHoiHelper'     => __DIR__ . '/Services/CauHoiHelper.php',
        'KhaoSatThongKe'   => __DIR__ . '/Services/KhaoSatThongKe.php',
        'khaosatthongke'   => __DIR__ . '/Services/KhaoSatThongKe.php',
        'ThongKe'          => __DIR__ . '/Services/ThongKe.php',
        'thongke'          => __DIR__ . '/Services/ThongKe.php',
        'NhatKy'           => __DIR__ . '/Services/NhatKy.php',

        // Support
        'PhanTrang'        => __DIR__ . '/Support/PhanTrang.php',
        'Paginator'        => __DIR__ . '/Support/PhanTrang.php',
        'GhiFileXlsx'      => __DIR__ . '/Support/GhiFileXlsx.php',
        'XlsxWriter'       => __DIR__ . '/Support/GhiFileXlsx.php',
        'DocFileXlsx'      => __DIR__ . '/Support/DocFileXlsx.php',
        'XlsxReader'       => __DIR__ . '/Support/DocFileXlsx.php',
        'GhiFilePdf'       => __DIR__ . '/Support/GhiFilePdf.php',
        'PdfWriter'        => __DIR__ . '/Support/GhiFilePdf.php',
        'pdfWriter'        => __DIR__ . '/Support/GhiFilePdf.php',
        'PdfFontTtf'       => __DIR__ . '/Support/GhiFilePdf.php',
        'DocFileDocx'      => __DIR__ . '/Support/DocFileDocx.php',
        'DocxReader'       => __DIR__ . '/Support/DocFileDocx.php',
        'NhapCauHoi'       => __DIR__ . '/Support/NhapCauHoi.php',
    ];

    if (isset($classMap[$class]) && file_exists($classMap[$class])) {
        require_once $classMap[$class];
        return;
    }

    // 2. Tìm kiếm dự phòng linh hoạt trong các phân vùng module
    foreach (['Database', 'Security', 'Game', 'Services', 'Support'] as $mod) {
        $candidate = __DIR__ . '/' . $mod . '/' . $class . '.php';
        if (file_exists($candidate)) {
            require_once $candidate;
            return;
        }
    }
});

// Nạp các hàm tiện ích toàn cục (e, redirect, csrf_token, csrf_field,...)
require_once __DIR__ . '/Support/helpers.php';

// Nạp các thành phần cốt lõi luôn cần thiết
require_once __DIR__ . '/Database/Database.php';
require_once __DIR__ . '/Database/Config.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    ]);
    session_start();
}
