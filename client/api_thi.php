<?php
// API cho phòng thi đấu (trả JSON). Đặt tại: elo_quiz/client/api_thi.php
//   GET  ?tran=ID                      -> trạng thái trận (trình duyệt gọi định kỳ, C-26)
//   POST ?tran=ID  macau, dapan, csrf  -> gửi đáp án của câu đang làm
//   POST ?tran=ID  hanhdong=chiuthua, csrf -> chịu thua (người bấm thua, đối thủ thắng)
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/ThiDau.php';
require_once __DIR__ . '/../core/GhepDoi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function traJson(array $d, int $ma = 200): never
{
    http_response_code($ma);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (Auth::laAdminSinhVien()) {   // admin không đấu trận
    traJson(['loi' => 'Tài khoản quản trị không thi đấu.'], 403);
}

$maND = Auth::clientId();
if ($maND === null) {
    traJson(['loi' => 'Chưa đăng nhập.'], 401);
}
$pdo    = Database::pdo();
$maTran = (int) ($_GET['tran'] ?? 0);

// Chỉ người chơi của trận mới được gọi
$st = $pdo->prepare('SELECT 1 FROM NGUOICHOITRANDAU WHERE MaTran = ? AND MaND = ?');
$st->execute([$maTran, $maND]);
if (!$st->fetchColumn()) {
    traJson(['loi' => 'Không thuộc trận này.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = $_POST['hanhdong'] ?? '';
    if ($hanhDong === 'chiuthua') {
        traJson(ThiDau::chiuThua($pdo, $maTran, $maND));
    }
    if ($hanhDong === 'pharoi') {
        $loai = (string) ($_POST['loai'] ?? '');
        traJson(ThiDau::phongChieuPhaRoi($pdo, $maTran, $maND, $loai));
    }
    traJson(ThiDau::traLoi($pdo, $maTran, $maND, (int) ($_POST['macau'] ?? 0), (int) ($_POST['dapan'] ?? 0)));
}
traJson(ThiDau::trangThai($pdo, $maTran, $maND));