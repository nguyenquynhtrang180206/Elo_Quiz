<?php
// Bạn bè: tìm sinh viên, gửi / chấp nhận / từ chối / hủy lời mời, hủy kết bạn (C-41). Đặt tại: elo_quiz/client/banbe.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/BanBeHelper.php';

$maND = Auth::requireClient(BASE_URL . '/client/dangnhap.php');
$pdo  = Database::pdo();

$q    = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$trang = (int) ($_GET['page'] ?? 1);

// ---- Xử lý thao tác (POST), xong thì quay lại đúng trang tìm kiếm ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');
    $nguoi    = (int) ($_POST['nguoi'] ?? 0);
    $qGui     = mb_substr(trim((string) ($_POST['q'] ?? '')), 0, 100);
    $trangGui = max(1, (int) ($_POST['page'] ?? 1));

    if ($hanhDong === 'gui') {
        $kq = BanBeHelper::guiLoiMoi($maND, $nguoi);
        BanBeHelper::flash($kq === BanBeHelper::OK ? 'ok' : 'loi', BanBeHelper::THONG_BAO[$kq]);
    } elseif ($hanhDong === 'huy') {
        $ok = BanBeHelper::huyLoiMoi($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã hủy lời mời.' : 'Lời mời không còn hiệu lực.');
    } elseif ($hanhDong === 'chapnhan') {
        $ok = BanBeHelper::chapNhan($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã chấp nhận lời mời.' : 'Lời mời không còn hiệu lực.');
    } elseif ($hanhDong === 'tuchoi') {
        $ok = BanBeHelper::tuChoi($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã từ chối lời mời.' : 'Lời mời không còn hiệu lực.');
    } elseif ($hanhDong === 'huyketban') {
        $ok = BanBeHelper::huyKetBan($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã hủy kết bạn.' : 'Hai bạn không còn là bạn bè.');
    }

    $url = BASE_URL . '/client/banbe.php';
    if ($qGui !== '') {
        $url .= '?' . http_build_query(['q' => $qGui, 'page' => $trangGui]);
    }
    redirect($url);
}

$thongBao = BanBeHelper::flash();

// Nút bấm nhỏ gửi một thao tác (kèm CSRF, giữ nguyên từ khóa tìm kiếm và trang).
// $xacNhan khác rỗng thì hiện modal hỏi lại trước khi gửi (dùng cho thao tác khó hoàn tác như hủy kết bạn).
function nutBanBe(string $hanhDong, int $nguoi, string $nhan, string $q, int $trang, string $lop = 'btn', string $xacNhan = ''): string
{
    return '<form method="post" action="" class="form-dong"' . ($xacNhan !== '' ? ' data-xacnhan="' . e($xacNhan) . '"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="hanh_dong" value="' . e($hanhDong) . '">'
        . '<input type="hidden" name="nguoi" value="' . $nguoi . '">'
        . '<input type="hidden" name="q" value="' . e($q) . '">'
        . '<input type="hidden" name="page" value="' . $trang . '">'
        . '<button type="submit" class="' . e($lop) . '">' . e($nhan) . '</button></form> ';
}

// ---- Tìm sinh viên theo họ tên (không tìm theo email để khỏi lộ email nào đã đăng ký) ----
$ketQua = [];
$p = null;
if ($q !== '') {
    $mau = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $st = $pdo->prepare("SELECT COUNT(*) FROM NGUOIDUNG WHERE MaND <> ? AND TrangThai = 'Hoạt động' AND HoTen LIKE ?
                          AND MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)");
    $st->execute([$maND, $mau]);
    $p = new Paginator((int) $st->fetchColumn(), 10);

    // Quan hệ giữa mình và từng người tìm được: một dòng BANBE duy nhất cho mỗi cặp
    $st = $pdo->prepare(
        "SELECT u.MaND, u.HoTen, u.AnhDaiDien, b.MaNguoiGui, b.TrangThai AS TrangThaiBan
           FROM NGUOIDUNG u
           LEFT JOIN BANBE b ON b.CapNho = LEAST(?, u.MaND) AND b.CapLon = GREATEST(?, u.MaND)
          WHERE u.MaND <> ? AND u.TrangThai = 'Hoạt động' AND u.HoTen LIKE ?
            AND u.MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)
          ORDER BY u.HoTen
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute([$maND, $maND, $maND, $mau]);
    $ketQua = $st->fetchAll();
}
$trangDangXem = $p ? $p->trang() : 1;

// ---- Lời mời đã nhận, đã gửi, danh sách bạn bè (chỉ hiện tài khoản còn hoạt động) ----
$st = $pdo->prepare(
    "SELECT u.MaND, u.HoTen, u.AnhDaiDien, b.NgayGui
       FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = b.MaNguoiGui
      WHERE b.MaNguoiNhan = ? AND b.TrangThai = 'Chờ' AND u.TrangThai = 'Hoạt động'
      ORDER BY b.NgayGui DESC"
);
$st->execute([$maND]);
$daNhan = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT u.MaND, u.HoTen, u.AnhDaiDien, b.NgayGui
       FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = b.MaNguoiNhan
      WHERE b.MaNguoiGui = ? AND b.TrangThai = 'Chờ' AND u.TrangThai = 'Hoạt động'
      ORDER BY b.NgayGui DESC"
);
$st->execute([$maND]);
$daGui = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT u.MaND, u.HoTen, u.AnhDaiDien,
            (SELECT MAX(e.DiemElo) FROM ELOMONHOC e WHERE e.MaND = u.MaND) AS EloCao
       FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = IF(b.MaNguoiGui = ?, b.MaNguoiNhan, b.MaNguoiGui)
      WHERE (b.MaNguoiGui = ? OR b.MaNguoiNhan = ?) AND b.TrangThai = 'Đã chấp nhận' AND u.TrangThai = 'Hoạt động'
      ORDER BY u.HoTen"
);
$st->execute([$maND, $maND, $maND]);
$banBe = $st->fetchAll();

function anhNguoi(array $r): string
{
    if (!empty($r['AnhDaiDien'])) {
        return '<img class="avatar-nho" src="' . e(BASE_URL . '/' . ltrim((string) $r['AnhDaiDien'], '/')) . '" alt="">';
    }
    return '<span class="avatar-nho avatar-chu" aria-hidden="true">'
        . e(mb_strtoupper(mb_substr((string) $r['HoTen'], 0, 1))) . '</span>';
}

function thongTinNguoi(array $r): string
{
    return '<div class="nguoi">' . anhNguoi($r) . '<div>'
        . '<b>' . e($r['HoTen']) . '</b>'
        . (isset($r['EloCao']) ? '<div class="ghichu">Elo cao nhất: <b>' . (int) $r['EloCao'] . '</b></div>' : '')
        . '</div></div>';
}

// Khối "Lời mời đã nhận": có lời mời thì đặt lên đầu trang để dễ thấy, không có thì nằm sau ô tìm kiếm
$veLoiMoiNhan = function () use ($daNhan, $q, $trangDangXem): void { ?>
  <div class="card<?= $daNhan ? ' co-moi' : '' ?>" id="loimoi">
    <h3>Lời mời đã nhận (<?= count($daNhan) ?>)</h3>
    <?php if (!$daNhan): ?><p class="ghichu">Không có lời mời nào đang chờ.</p><?php endif; ?>
    <?php foreach ($daNhan as $r): ?>
      <div class="dong">
        <div><?= thongTinNguoi($r) ?></div>
        <div>
          <?= nutBanBe('chapnhan', (int) $r['MaND'], 'Chấp nhận', $q, $trangDangXem) ?>
          <?= nutBanBe('tuchoi', (int) $r['MaND'], 'Từ chối', $q, $trangDangXem, 'btn btn-phu') ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php };

$tieuDe = 'Bạn bè';
$cssTrang = 'banbe';
require __DIR__ . '/layout/header.php';
?>
<div class="container">
  <h2>Bạn bè</h2>

  <?php if ($thongBao): ?>
    <div class="<?= $thongBao['loai'] === 'ok' ? 'thanhcong' : 'loi' ?>"><?= e($thongBao['nd']) ?></div>
  <?php endif; ?>

  <?php if ($daNhan) { $veLoiMoiNhan(); } ?>

  <div class="card">
    <h3>Tìm sinh viên</h3>
    <form method="get" action="" class="timkiem">
      <input type="text" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Nhập họ tên cần tìm">
      <button type="submit" class="btn">Tìm</button>
    </form>

    <?php if ($q !== ''): ?>
      <div class="khoi-ketqua">
        <?php if (!$ketQua): ?>
          <p class="ghichu">Không tìm thấy sinh viên nào.</p>
        <?php endif; ?>
        <?php foreach ($ketQua as $r):
            $tt  = $r['TrangThaiBan'];
            $gui = (int) ($r['MaNguoiGui'] ?? 0);
            $id  = (int) $r['MaND'];
        ?>
          <div class="dong">
            <div><?= thongTinNguoi($r) ?></div>
            <div>
              <?php if ($tt === 'Đã chấp nhận'): ?>
                <span class="ghichu">Đã là bạn bè</span>
              <?php elseif ($tt === 'Chờ' && $gui === $maND): ?>
                <span class="ghichu">Đã gửi lời mời</span>
                <?= nutBanBe('huy', $id, 'Hủy lời mời', $q, $trangDangXem, 'btn btn-phu') ?>
              <?php elseif ($tt === 'Chờ'): ?>
                <span class="ghichu">Đã gửi lời mời cho bạn</span>
                <?= nutBanBe('chapnhan', $id, 'Chấp nhận', $q, $trangDangXem) ?>
                <?= nutBanBe('tuchoi', $id, 'Từ chối', $q, $trangDangXem, 'btn btn-phu') ?>
              <?php else: /* chưa có dòng, hoặc đã "Từ chối" thì được gửi lại */ ?>
                <?= nutBanBe('gui', $id, 'Kết bạn', $q, $trangDangXem) ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?= $p ? $p->links() : '' ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$daNhan) { $veLoiMoiNhan(); } ?>

  <div class="card">
    <h3>Lời mời đã gửi (<?= count($daGui) ?>)</h3>
    <?php if (!$daGui): ?><p class="ghichu">Bạn chưa gửi lời mời nào đang chờ.</p><?php endif; ?>
    <?php foreach ($daGui as $r): ?>
      <div class="dong">
        <div><?= thongTinNguoi($r) ?></div>
        <div><?= nutBanBe('huy', (int) $r['MaND'], 'Hủy lời mời', $q, $trangDangXem, 'btn btn-phu') ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card" id="danhsach">
    <h3>Bạn bè của tôi (<?= count($banBe) ?>)</h3>
    <?php if (!$banBe): ?><p class="ghichu">Bạn chưa có bạn bè nào. Hãy tìm và gửi lời mời ở trên.</p><?php endif; ?>
    <?php foreach ($banBe as $r): ?>
      <div class="dong">
        <div><?= thongTinNguoi($r) ?></div>
        <div style="display: flex; align-items: center; gap: 6px;">
          <a href="<?= e(BASE_URL) ?>/client/timtran.php" class="btn btn-sm" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%); color: #fff; font-weight: 700; text-decoration: none; padding: 5px 12px; border-radius: 6px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);">⚔️ Thách đấu</a>
          <?= nutBanBe('huyketban', (int) $r['MaND'], 'Hủy kết bạn', $q, $trangDangXem, 'btn btn-phu',
                       'Hủy kết bạn với ' . $r['HoTen'] . '? Hai bạn sẽ không còn thấy nhau trong bảng xếp hạng bạn bè.') ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($banBe): ?>
      <p class="ghichu lien-ket-bxh"><a href="<?= e(BASE_URL) ?>/client/bxh.php?pv=banbe">Xem bảng xếp hạng bạn bè →</a></p>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>