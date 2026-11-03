<?php
// Cấu hình hệ thống: xem và sửa tham số luật chơi (UC16: A-37, A-38, A-39; ghi nhật ký A-40).
// Đặt tại: elo_quiz/admin/cauhinh.php
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// Nhóm hiển thị và khoảng giá trị hợp lệ của từng tham số: tên => [nhãn, nhỏ nhất, lớn nhất, đơn vị].
// Mọi tham số đều là số nguyên không âm; khoảng giá trị là mức chặn hợp lý do người làm chọn, chỉnh được ở đây.
$nhom = [
    'Luật một trận đấu (giá trị mới chỉ áp dụng cho các trận được tạo sau khi lưu, A-39)' => [
        'SO_CAU'            => ['Số câu mỗi trận',                  1,  50,    'câu'],
        'THOI_GIAN_MOI_CAU' => ['Thời gian trả lời mỗi câu',        1,  300,   'giây'],
        'DIEM_CO_BAN'       => ['Điểm cơ bản cho câu đúng',         1,  10000, 'điểm'],
        'HE_SO_THUONG'      => ['Hệ số thưởng tốc độ (tối đa)',     0,  10000, 'điểm'],
    ],
    'Tính Elo (đọc tại lúc tính Elo, nên đổi khi đang có trận diễn ra có thể ảnh hưởng trận đó)' => [
        'K_NGUOI_MOI'          => ['Hệ số K của người chơi mới',          1, 200,   ''],
        'K_NGUOI_CU'           => ['Hệ số K từ ngưỡng số trận trở lên',   1, 200,   ''],
        'NGUONG_SO_TRAN_DOI_K' => ['Ngưỡng số trận để đổi hệ số K',       0, 10000, 'trận'],
        'ELO_KHOI_TAO'         => ['Elo khởi tạo mỗi môn',                0, 10000, 'điểm'],
    ],
    'Ghép đôi, vào phòng và kết nối (đọc tại thời điểm xử lý)' => [
        'NGUONG_ELO_GHEP_DOI'            => ['Chênh lệch Elo ban đầu khi ghép đôi', 0, 5000, 'điểm'],
        'THOI_GIAN_CHO_GHEP_TOI_DA'      => ['Thời gian chờ ghép tối đa',            1, 3600, 'giây'],
        'THOI_GIAN_CHO_VAO_PHONG'        => ['Thời gian chờ vào phòng sau khi ghép', 1, 600,  'giây'],
        'THOI_GIAN_CHO_PHEP_MAT_KET_NOI' => ['Thời gian cho phép mất kết nối',       1, 600,  'giây'],
        'DUNG_SAI_DO_TRE'                => ['Dung sai độ trễ mạng',                 0, 30,   'giây'],
    ],
    'Bảng xếp hạng, tài khoản và theo dõi bất thường' => [
        'SO_TRAN_TOI_THIEU_BXH'     => ['Số trận tối thiểu để vào BXH toàn trường', 0, 1000, 'trận'],
        'THOI_HAN_LIEN_KET_DAT_LAI' => ['Thời hạn liên kết đặt lại mật khẩu',       1, 1440, 'phút'],
        'NGUONG_TRAN_KEO_DAI'       => ['Ngưỡng trận kéo dài bất thường',           1, 1440, 'phút'],
        'NGUONG_BIEN_DONG_ELO'      => ['Ngưỡng biến động Elo bất thường',          1, 5000, 'điểm'],
        'KHOANG_BIEN_DONG_ELO'      => ['Khoảng thời gian xét biến động Elo',       1, 720,  'giờ'],
    ],
];
$luat = [];
foreach ($nhom as $cacMuc) {
    foreach ($cacMuc as $ten => $m) {
        $luat[$ten] = $m;
    }
}

// ---- Giá trị hiện tại trong CAUHINH ----
$hienTai = [];
foreach ($pdo->query('SELECT TenCauHinh, GiaTri, MoTa FROM CAUHINH') as $r) {
    $hienTai[$r['TenCauHinh']] = $r;
}

$loi   = [];
$nhap  = [];   // giá trị người dùng vừa gửi, để giữ lại trong form khi có lỗi
$flash = $_SESSION['cfg_flash'] ?? null;
unset($_SESSION['cfg_flash']);

// ---- A-38: lưu, kiểm tra hợp lệ trước ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $gui   = is_array($_POST['gt'] ?? null) ? $_POST['gt'] : [];
    $moi   = [];
    foreach ($luat as $ten => [$nhan, $min, $max, $donVi]) {
        if (!isset($hienTai[$ten])) {
            continue;   // tham số chưa có trong CSDL thì không sửa
        }
        $v = trim((string) ($gui[$ten] ?? ''));
        $nhap[$ten] = $v;
        if ($v === '' || !ctype_digit($v) || strlen($v) > 6) {
            $loi[] = $nhan . ': phải là số nguyên không âm (từ ' . $min . ' đến ' . $max . ').';
            continue;
        }
        $so = (int) $v;
        if ($so < $min || $so > $max) {
            $loi[] = $nhan . ': phải từ ' . $min . ' đến ' . $max . ($donVi !== '' ? ' ' . $donVi : '') . '.';
            continue;
        }
        $moi[$ten] = (string) $so;
    }

    if (!$loi) {
        try {
            $pdo->beginTransaction();
            // Khóa các dòng cấu hình rồi so với giá trị đang có (hai admin sửa cùng lúc thì ghi nhật ký đúng giá trị cũ)
            $cu = [];
            foreach ($pdo->query('SELECT TenCauHinh, GiaTri FROM CAUHINH FOR UPDATE') as $r) {
                $cu[$r['TenCauHinh']] = $r['GiaTri'];
            }
            $doi = [];
            $up  = $pdo->prepare('UPDATE CAUHINH SET GiaTri = ? WHERE TenCauHinh = ?');
            foreach ($moi as $ten => $gt) {
                if (isset($cu[$ten]) && (string) $cu[$ten] !== $gt) {
                    $up->execute([$gt, $ten]);
                    $doi[] = $ten . ': ' . $cu[$ten] . ' -> ' . $gt;
                }
            }
            if ($doi) {
                NhatKy::ghi('Đổi cấu hình', 'CAUHINH', implode('; ', $doi));
            }
            $pdo->commit();
            Config::clearCache();
            $_SESSION['cfg_flash'] = $doi
                ? ['ok', 'Đã lưu ' . count($doi) . ' tham số.']
                : ['ok', 'Không có giá trị nào thay đổi.'];
            redirect((string) $_SERVER['REQUEST_URI']);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Lưu cấu hình lỗi: ' . $ex->getMessage());
            $loi[] = 'Lỗi cơ sở dữ liệu, chưa lưu được. Hãy thử lại.';
        }
    }
}

$tieuDe  = 'Cấu hình hệ thống';
$mucChon = 'cauhinh';
$cssTrang = 'cauhinh';
require __DIR__ . '/layout/header.php';
?>
<h2>Cấu hình hệ thống</h2>

<?php if ($flash): ?>
  <div class="<?= $flash[0] === 'ok' ? 'thanhcong' : 'loi' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if ($loi): ?>
  <div class="loi">
    Chưa lưu vì có giá trị không hợp lệ:
    <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" action="">
  <?= csrf_field() ?>
  <?php foreach ($nhom as $tenNhom => $cacMuc): ?>
    <div class="card">
      <h3 class="tieu-de-nhom"><?= e($tenNhom) ?></h3>
      <table>
        <tr><th>Tham số</th><th class="cot-giatri">Giá trị</th><th>Ý nghĩa</th></tr>
        <?php foreach ($cacMuc as $ten => [$nhan, $min, $max, $donVi]): ?>
          <?php if (!isset($hienTai[$ten])) { continue; } ?>
          <tr>
            <td><b><?= e($nhan) ?></b><br><span class="nhan-phu"><?= e($ten) ?></span></td>
            <td>
              <input type="text" inputmode="numeric" name="gt[<?= e($ten) ?>]" maxlength="6" class="o-giatri"
                     value="<?= e($nhap[$ten] ?? $hienTai[$ten]['GiaTri']) ?>">
              <span class="nhan-phu"><?= e($donVi) ?></span>
            </td>
            <td class="nhan-phu"><?= e($hienTai[$ten]['MoTa']) ?><br>Cho phép: <?= $min ?> đến <?= $max ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endforeach; ?>
  <p><button type="submit" class="btn">Lưu cấu hình</button></p>
</form>
<?php require __DIR__ . '/layout/footer.php'; ?>