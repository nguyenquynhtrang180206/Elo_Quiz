<?php
// Cấu hình hệ thống: xem và sửa tham số luật chơi (UC16: A-37, A-38, A-39; ghi nhật ký A-40).
// Đặt tại: elo_quiz/admin/cauhinh.php
// Giao diện giống trang Tổng quan: giữ khung cũ (nền ngoài và sidebar), nội dung nằm trong một khối sáng bo góc.
// Thẻ theo nhóm, menu nhóm bên trái, đánh dấu thay đổi và kiểm tra ngay khi gõ (assets/js/admin/cauhinh.js).
// Phần xử lý lưu (kiểm tra hợp lệ, transaction, nhật ký, xóa cache) giữ nguyên logic cũ.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// Nhóm hiển thị: mã nhóm => [tên, tên ngắn ở menu, loại huy hiệu, nội dung huy hiệu, ghi chú, biểu tượng, các tham số].
// Mỗi tham số: tên => [nhãn, nhỏ nhất, lớn nhất, đơn vị]. Mọi tham số đều là số nguyên không âm;
// khoảng giá trị là mức chặn hợp lý do người làm chọn, chỉnh được ở đây.
// loai: 'moi' = chỉ áp dụng cho trận tạo sau khi lưu; 'ngay' = có hiệu lực ngay khi lưu.
$nhom = [
    'luat' => [
        'ten'     => 'Luật một trận đấu',
        'menu'    => 'Luật một trận',
        'loai'    => 'moi',
        'huyHieu' => 'Áp dụng cho trận mới',
        'ghiChu'  => 'Giá trị mới chỉ áp dụng cho các trận được tạo sau khi lưu (A-39).',
        'icon'    => 'co',
        'muc'     => [
            'SO_CAU'            => ['Số câu mỗi trận',                  1,  50,    'câu'],
            'THOI_GIAN_MOI_CAU' => ['Thời gian trả lời mỗi câu',        1,  300,   'giây'],
            'DIEM_CO_BAN'       => ['Điểm cơ bản cho câu đúng',         1,  10000, 'điểm'],
            'HE_SO_THUONG'      => ['Hệ số thưởng tốc độ (tối đa)',     0,  10000, 'điểm'],
        ],
    ],
    'elo' => [
        'ten'     => 'Tính Elo',
        'menu'    => 'Tính Elo',
        'loai'    => 'ngay',
        'huyHieu' => 'Có hiệu lực ngay',
        'ghiChu'  => 'Đọc tại lúc tính Elo, nên đổi khi đang có trận diễn ra có thể ảnh hưởng trận đó.',
        'icon'    => 'xuhuong',
        'muc'     => [
            'K_NGUOI_MOI'          => ['Hệ số K của người chơi mới',          1, 200,   ''],
            'K_NGUOI_CU'           => ['Hệ số K từ ngưỡng số trận trở lên',   1, 200,   ''],
            'NGUONG_SO_TRAN_DOI_K' => ['Ngưỡng số trận để đổi hệ số K',       0, 10000, 'trận'],
            'ELO_KHOI_TAO'         => ['Elo khởi tạo mỗi môn',                0, 10000, 'điểm'],
        ],
    ],
    'ghep' => [
        'ten'     => 'Ghép đôi, vào phòng và kết nối',
        'menu'    => 'Ghép đôi và kết nối',
        'loai'    => 'ngay',
        'huyHieu' => 'Có hiệu lực ngay',
        'ghiChu'  => 'Đọc tại thời điểm xử lý.',
        'icon'    => 'ghep',
        'muc'     => [
            'NGUONG_ELO_GHEP_DOI'            => ['Chênh lệch Elo ban đầu khi ghép đôi', 0, 5000, 'điểm'],
            'THOI_GIAN_CHO_GHEP_TOI_DA'      => ['Thời gian chờ ghép tối đa',            1, 3600, 'giây'],
            'THOI_GIAN_CHO_VAO_PHONG'        => ['Thời gian chờ vào phòng sau khi ghép', 1, 600,  'giây'],
            'THOI_GIAN_CHO_PHEP_MAT_KET_NOI' => ['Thời gian cho phép mất kết nối',       1, 600,  'giây'],
            'DUNG_SAI_DO_TRE'                => ['Dung sai độ trễ mạng',                 0, 30,   'giây'],
        ],
    ],
    'bxh' => [
        'ten'     => 'Bảng xếp hạng, tài khoản và theo dõi bất thường',
        'menu'    => 'BXH và bất thường',
        'loai'    => 'ngay',
        'huyHieu' => 'Có hiệu lực ngay',
        'ghiChu'  => 'Đọc tại thời điểm xử lý.',
        'icon'    => 'canhbao',
        'muc'     => [
            'SO_TRAN_TOI_THIEU_BXH'     => ['Số trận tối thiểu để vào BXH toàn trường', 0, 1000, 'trận'],
            'THOI_HAN_LIEN_KET_DAT_LAI' => ['Thời hạn liên kết đặt lại mật khẩu',       1, 1440, 'phút'],
            'NGUONG_TRAN_KEO_DAI'       => ['Ngưỡng trận kéo dài bất thường',           1, 1440, 'phút'],
            'NGUONG_BIEN_DONG_ELO'      => ['Ngưỡng biến động Elo bất thường',          1, 5000, 'điểm'],
            'KHOANG_BIEN_DONG_ELO'      => ['Khoảng thời gian xét biến động Elo',       1, 720,  'giờ'],
        ],
    ],
];
$luat = [];
foreach ($nhom as $g) {
    foreach ($g['muc'] as $ten => $m) {
        $luat[$ten] = $m;
    }
}

// Biểu tượng SVG nội tuyến (kiểu Lucide, không cần mạng), dùng riêng cho trang này.
$duongIcon = [
    'co'      => '<path d="M4 22V4"/><path d="M4 4h13l-2 4 2 4H4"/>',
    'xuhuong' => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
    'ghep'    => '<circle cx="8" cy="12" r="4"/><circle cx="16" cy="12" r="4"/>',
    'canhbao' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
    'info'    => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    'hoantac' => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
    'luu'     => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
];
$ic = static fn (string $k, int $px = 18): string =>
    '<svg class="ch-bt" width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
    . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $duongIcon[$k] . '</svg>';

// ---- Giá trị hiện tại trong CAUHINH ----
$hienTai = [];
foreach ($pdo->query('SELECT TenCauHinh, GiaTri, MoTa FROM CAUHINH') as $r) {
    $hienTai[$r['TenCauHinh']] = $r;
}

$loi     = [];
$loiTheo = [];   // tên tham số => câu lỗi ngắn hiện ngay dưới ô nhập (khi máy chủ từ chối lưu)
$nhap    = [];   // giá trị người dùng vừa gửi, để giữ lại trong form khi có lỗi
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
            $loiTheo[$ten] = 'Phải là số nguyên không âm.';
            continue;
        }
        $so = (int) $v;
        if ($so < $min || $so > $max) {
            $loi[] = $nhan . ': phải từ ' . $min . ' đến ' . $max . ($donVi !== '' ? ' ' . $donVi : '') . '.';
            $loiTheo[$ten] = 'Phải từ ' . $min . ' đến ' . $max . ($donVi !== '' ? ' ' . $donVi : '') . '.';
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

// Chỉ hiện các tham số đang có trong CSDL; nhóm không còn tham số nào thì bỏ cả nhóm.
$nhomHienThi = [];
foreach ($nhom as $ma => $g) {
    $cac = array_filter($g['muc'], static fn ($k) => isset($hienTai[$k]), ARRAY_FILTER_USE_KEY);
    if ($cac) {
        $g['muc'] = $cac;
        $nhomHienThi[$ma] = $g;
    }
}

$tieuDe   = 'Cấu hình hệ thống';
$mucChon  = 'cauhinh';
$cssTrang = 'cauhinh';
$jsTrang  = 'cauhinh';
require __DIR__ . '/layout/header.php';
?>
<div class="ch">
  <div class="ch-dau">
    <div>
      <h2>Cấu hình hệ thống</h2>
      <p class="nhan-phu">Tham số luật chơi áp dụng cho toàn bộ hệ thống.</p>
    </div>
    <span class="ch-pill js-trangthai" role="status">Chưa có thay đổi</span>
  </div>

  <?php if ($flash): ?>
    <div class="<?= $flash[0] === 'ok' ? 'thanhcong' : 'loi' ?>"><?= e($flash[1]) ?></div>
  <?php endif; ?>
  <?php if ($loi): ?>
    <div class="loi">
      Chưa lưu vì có giá trị không hợp lệ:
      <?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="" id="chForm" novalidate>
    <?= csrf_field() ?>
    <div class="ch-than">
      <nav class="ch-menu" aria-label="Các nhóm tham số">
        <?php foreach ($nhomHienThi as $ma => $g): ?>
          <a href="#nhom-<?= e($ma) ?>" class="ch-menu-muc" data-nhom="<?= e($ma) ?>"><span><?= e($g['menu']) ?></span><i class="ch-cham" aria-hidden="true"></i></a>
        <?php endforeach; ?>
      </nav>

      <div class="ch-ds">
        <?php foreach ($nhomHienThi as $ma => $g): ?>
          <section class="ch-the" id="nhom-<?= e($ma) ?>" data-nhom="<?= e($ma) ?>">
            <header class="ch-the-dau">
              <div class="ch-the-ten"><?= $ic($g['icon'], 20) ?><h3><?= e($g['ten']) ?></h3></div>
              <span class="ch-hh ch-hh-<?= e($g['loai']) ?>"><?= e($g['huyHieu']) ?></span>
            </header>
            <p class="ch-ghichu"><?= e($g['ghiChu']) ?></p>

            <?php foreach ($g['muc'] as $ten => [$nhan, $min, $max, $donVi]): ?>
              <?php $coLoi = isset($loiTheo[$ten]); $mota = (string) ($hienTai[$ten]['MoTa'] ?? ''); ?>
              <div class="ch-hang<?= $coLoi ? ' co-loi' : '' ?>">
                <div class="ch-ten">
                  <label for="cf-<?= e($ten) ?>"><?= e($nhan) ?></label>
                  <span class="ch-ma"><?= e($ten) ?> · cho phép <?= $min ?> – <?= $max ?></span>
                </div>
                <div class="ch-nhap">
                  <div class="ch-o-hang">
                    <input type="text" id="cf-<?= e($ten) ?>" name="gt[<?= e($ten) ?>]" class="ch-o" inputmode="numeric" maxlength="6" autocomplete="off"
                           value="<?= e($nhap[$ten] ?? $hienTai[$ten]['GiaTri']) ?>"
                           data-goc="<?= e($hienTai[$ten]['GiaTri']) ?>" data-min="<?= $min ?>" data-max="<?= $max ?>" data-donvi="<?= e($donVi) ?>"
                           <?= $coLoi ? 'aria-invalid="true"' : '' ?>>
                    <?php if ($donVi !== ''): ?><span class="ch-dv"><?= e($donVi) ?></span><?php endif; ?>
                    <?php if ($mota !== ''): ?>
                      <button type="button" class="ch-i" aria-label="Ý nghĩa của <?= e($nhan) ?>" data-mota="<?= e($mota) ?>"><?= $ic('info', 16) ?></button>
                    <?php endif; ?>
                  </div>
                  <span class="ch-ghi<?= $coLoi ? ' loi-ghi' : '' ?>" role="status"><?= $coLoi ? e($loiTheo[$ten]) : '' ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="ch-luu">
      <span class="ch-luu-tt js-trangthai" role="status">Chưa có thay đổi</span>
      <div class="ch-luu-nut">
        <button type="button" class="ch-btn ch-btn-phu" id="chHoanTac"><?= $ic('hoantac', 16) ?>Hoàn tác thay đổi</button>
        <button type="submit" class="ch-btn ch-btn-chinh"><?= $ic('luu', 16) ?>Lưu cấu hình</button>
      </div>
    </div>
  </form>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>