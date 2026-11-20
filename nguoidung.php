<?php
// Danh sách người dùng (UC11: A-11, A-45). Đặt tại: elo_quiz/admin/nguoidung.php
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();

// ---- Bộ lọc: từ khóa (họ tên, email, lớp, khoa) và trạng thái ----
$q  = trim((string) ($_GET['q'] ?? ''));
$tt = (string) ($_GET['tt'] ?? '');
if (!in_array($tt, ['Hoạt động', 'Bị khóa'], true)) {
    $tt = '';
}

$dieuKien = [];
$tham     = [];
$dieuKien[] = 'n.MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)';   // không liệt kê tài khoản sinh viên liên kết của admin
if ($q !== '') {
    $like       = '%' . addcslashes($q, '\\%_') . '%';   // thoát % và _ để tìm đúng chữ người dùng gõ
    $dieuKien[] = '(n.HoTen LIKE ? OR n.Email LIKE ?)';
    array_push($tham, $like, $like);
}
if ($tt !== '') {
    $dieuKien[] = 'n.TrangThai = ?';
    $tham[]     = $tt;
}
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

$cotChon = "n.MaND, n.HoTen, n.Email, n.TrangThai, n.NgayTao,
            (SELECT COALESCE(SUM(e.SoTran), 0) FROM ELOMONHOC e WHERE e.MaND = n.MaND) AS so_tran,
            (SELECT ROUND(AVG(e.DiemElo)) FROM ELOMONHOC e WHERE e.MaND = n.MaND AND e.SoTran > 0) AS elo_trung_binh";

// ---- Khóa / mở khóa ngay trên danh sách (A-12): đổi trạng thái + ghi nhật ký cùng một giao dịch (A-40), rồi báo cho người dùng ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');
    $maND     = (int) ($_POST['id'] ?? 0);
    $ketQua   = 'loi';

    if (($hanhDong === 'khoa' || $hanhDong === 'mokhoa') && $maND > 0) {
        $khoa = $hanhDong === 'khoa';
        $lyDo = preg_replace('/^(.{300}).+$/su', '$1', trim((string) ($_POST['ly_do'] ?? '')));

        $pdo->beginTransaction();
        try {
            // Điều kiện theo trạng thái cũ: bấm lặp hoặc hai quản trị viên cùng bấm thì chỉ xử lý một lần.
            // Không cho khóa tài khoản sinh viên đang liên kết với quản trị viên.
            $up = $pdo->prepare(
                'UPDATE NGUOIDUNG SET TrangThai = ?
                 WHERE MaND = ? AND TrangThai = ? AND MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)'
            );
            $up->execute([$khoa ? 'Bị khóa' : 'Hoạt động', $maND, $khoa ? 'Hoạt động' : 'Bị khóa']);
            $daDoi = $up->rowCount() === 1;
            if ($daDoi) {
                NhatKy::ghi(
                    $khoa ? 'Khóa người dùng' : 'Mở khóa người dùng',
                    'NGUOIDUNG #' . $maND,
                    $lyDo !== '' ? 'Lý do: ' . $lyDo : null
                );
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }

        if ($daDoi) {
            // Gửi thông báo sau khi đã lưu: thông báo lỗi thì tài khoản vẫn được khóa, chỉ báo lại cho quản trị viên biết
            $daBao  = ThongBaoHelper::taoThongBaoTaiKhoan($pdo, $maND, $khoa, $lyDo);
            $ketQua = ($khoa ? 'khoa' : 'mokhoa') . ($daBao ? '' : '_chuabao');
        }
    }
    redirect('?' . http_build_query(array_merge(array_diff_key($_GET, ['kq' => 1, 'page' => 1]), ['kq' => $ketQua])));
}
$thongBao = [
    'khoa'           => ['ok',  'Đã khóa tài khoản và gửi thông báo cho người dùng.'],
    'mokhoa'         => ['ok',  'Đã mở khóa tài khoản và gửi thông báo cho người dùng.'],
    'khoa_chuabao'   => ['loi', 'Đã khóa tài khoản nhưng chưa gửi được thông báo cho người dùng.'],
    'mokhoa_chuabao' => ['loi', 'Đã mở khóa tài khoản nhưng chưa gửi được thông báo cho người dùng.'],
    'loi'            => ['loi', 'Không thực hiện được: tài khoản đã đổi trạng thái trước đó hoặc không được phép khóa.'],
][$_GET['kq'] ?? ''] ?? null;

// ---- A-45: xuất Excel / PDF qua hộp thoại "Xuất" (xử lý ở admin/nguoidung_xuat.php, theo đúng bộ lọc đang xem) ----

// ---- Danh sách có phân trang (20 dòng/trang, có hiển thị tổng số) ----
$st = $pdo->prepare("SELECT COUNT(*) FROM NGUOIDUNG n $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT $cotChon FROM NGUOIDUNG n $where
     ORDER BY n.NgayTao DESC, n.MaND DESC
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

// ---- Bảng xếp hạng Elo tích hợp trên trang Người dùng ----
$monHocBxh = $pdo->query('SELECT MaMon, TenMon FROM MONHOC ORDER BY TenMon')->fetchAll();
$tenMonBxh = array_column($monHocBxh, 'TenMon', 'MaMon');
$monBxh = (int) ($_GET['bxh_mon'] ?? 0);
if ($monBxh !== 0 && !isset($tenMonBxh[$monBxh])) {
    $monBxh = 0;
}
$qBxh = trim((string) ($_GET['bxh_q'] ?? ''));
$nMinBxh = max(1, Config::int('SO_TRAN_TOI_THIEU_BXH', 10));

$dkBxh = [];
$thamBxh = [];
if ($monBxh > 0) {
    $dkBxh[] = 'e.MaMon = ?';
    $thamBxh[] = $monBxh;
    $cotBxh = 'n.MaND, n.HoTen, n.Email, n.TrangThai, e.DiemElo AS elo, e.SoTran, e.SoThang';
    $nhomBxh = '';
    $orderBxh = 'ORDER BY elo DESC, SoTran DESC, n.MaND ASC';
} else {
    $dkBxh[] = 'e.SoTran >= ?';
    $thamBxh[] = $nMinBxh;
    $cotBxh = 'n.MaND, n.HoTen, n.Email, n.TrangThai, ROUND(AVG(e.DiemElo)) AS elo, SUM(e.SoTran) AS SoTran, SUM(e.SoThang) AS SoThang';
    $nhomBxh = 'GROUP BY n.MaND, n.HoTen, n.Email, n.TrangThai';
    $orderBxh = 'ORDER BY elo DESC, SoTran DESC, n.MaND ASC';
}
if ($qBxh !== '') {
    $likeBxh = '%' . addcslashes($qBxh, '\\%_') . '%';
    $dkBxh[] = '(n.HoTen LIKE ? OR n.Email LIKE ?)';
    array_push($thamBxh, $likeBxh, $likeBxh);
}
$fromBxh = 'FROM ELOMONHOC e JOIN NGUOIDUNG n ON n.MaND = e.MaND WHERE ' . implode(' AND ', $dkBxh);
$nhomSqlBxh = $nhomBxh !== '' ? ' ' . $nhomBxh : '';
$st = $pdo->prepare(
    $monBxh > 0
        ? "SELECT COUNT(*) $fromBxh"
        : "SELECT COUNT(*) FROM (SELECT n.MaND $fromBxh $nhomSqlBxh) x"
);
$st->execute($thamBxh);
$pBxh = new Paginator((int) $st->fetchColumn(), 20, 'bxh_page');
$st = $pdo->prepare(
    "SELECT $cotBxh $fromBxh $nhomSqlBxh $orderBxh
     LIMIT " . $pBxh->limit() . ' OFFSET ' . $pBxh->offset()
);
$st->execute($thamBxh);
$dsBxh = $st->fetchAll();

$eloKhoiTaoBxh = Config::int('ELO_KHOI_TAO', 1000);
$bacCuaBxh = static function (int $elo) use ($eloKhoiTaoBxh): array {
    if ($elo >= $eloKhoiTaoBxh + 450) {
        return ['Cao thủ', 'cao'];
    }
    if ($elo >= $eloKhoiTaoBxh + 300) {
        return ['Kim cương', 'kim-cuong'];
    }
    if ($elo >= $eloKhoiTaoBxh + 150) {
        return ['Vàng', 'vang'];
    }
    if ($elo >= $eloKhoiTaoBxh + 50) {
        return ['Bạc', 'bac'];
    }
    return ['Đồng', 'dong'];
};
$tyLeThangBxh = static fn (int $thang, int $tran): string =>
    $tran > 0 ? number_format($thang * 100 / $tran, 1, ',', '.') . '%' : '—';



// ---- Số liệu cho sơ đồ phía trên (không phụ thuộc bộ lọc) ----
$loaiAdmin = 'n.MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)';
$tk = $pdo->query(
    "SELECT COUNT(*) AS tong,
            COALESCE(SUM(n.TrangThai = 'Hoạt động'), 0) AS hoat_dong,
            COALESCE(SUM(n.TrangThai = 'Bị khóa'), 0)   AS bi_khoa,
            COALESCE(SUM(n.NgayTao >= NOW() - INTERVAL 7 DAY), 0) AS moi_7_ngay,
            COALESCE(SUM(NOT EXISTS (SELECT 1 FROM ELOMONHOC e WHERE e.MaND = n.MaND AND e.SoTran > 0)), 0) AS chua_dau
     FROM NGUOIDUNG n WHERE $loaiAdmin"
)->fetch();

// Số tài khoản mới mỗi ngày trong 30 ngày gần nhất (ngày không có ai đăng ký vẫn hiện cột 0)
$theoNgay = $pdo->query(
    "SELECT DATE(n.NgayTao) AS ngay, COUNT(*) AS so
     FROM NGUOIDUNG n
     WHERE $loaiAdmin AND n.NgayTao >= CURDATE() - INTERVAL 29 DAY
     GROUP BY DATE(n.NgayTao)"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$nhanNgay = [];
$soNgay   = [];
for ($i = 29; $i >= 0; $i--) {
    $d          = date('Y-m-d', strtotime("-$i day"));
    $nhanNgay[] = date('d/m', strtotime($d));
    $soNgay[]   = (int) ($theoNgay[$d] ?? 0);
}
$jsonFlag = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

$tieuDe  = 'Người dùng';
$mucChon = 'nguoidung';
$cssTrang = ['thongke_xuat', 'nguoidung'];   // nguoidung nạp sau để ghi đè giao diện hộp thoại Xuất (nền trắng)
$jsTrang  = ['nguoidung', 'nguoidung_xuat'];
require __DIR__ . '/layout/header.php';
?>
<div class="nd-trang">

  <header class="nd-dau">
    <h1 class="nd-tieude">Người dùng</h1>
    <span class="nd-tong">Tổng <?= (int) $tk['tong'] ?> tài khoản</span>
  </header>

  <?php if ($thongBao): ?>
    <div class="nd-flash nd-flash-<?= e($thongBao[0]) ?>" role="status"><?= e($thongBao[1]) ?></div>
  <?php endif; ?>

  <!-- Sơ đồ quản lý người dùng -->
  <section class="nd-so-do" aria-label="Sơ đồ người dùng">
    <div class="nd-so-do-trai">
      <div class="nd-so-do-tieude">
        <b>Tài khoản mới 30 ngày gần nhất</b>
        <span><?= (int) $tk['moi_7_ngay'] ?> trong 7 ngày qua</span>
      </div>
      <div class="nd-cot-khung">
        <canvas id="ndBieuDoNgay" height="150"
                data-nhan="<?= e(json_encode($nhanNgay, $jsonFlag)) ?>"
                data-so="<?= e(json_encode($soNgay, $jsonFlag)) ?>"></canvas>
      </div>
    </div>
    <div class="nd-so-do-phai">
      <div class="nd-so-do-tieude"><b>Trạng thái tài khoản</b></div>
      <div class="nd-banh">
        <div class="nd-banh-hinh">
          <canvas id="ndBieuDoTT" width="130" height="130"
                  data-nhan="<?= e(json_encode(['Hoạt động', 'Bị khóa'], $jsonFlag)) ?>"
                  data-so="<?= e(json_encode([(int) $tk['hoat_dong'], (int) $tk['bi_khoa']], $jsonFlag)) ?>"></canvas>
        </div>
        <ul class="nd-chuthich">
          <li><i class="c-xanh"></i>Hoạt động <b><?= (int) $tk['hoat_dong'] ?></b></li>
          <li><i class="c-do"></i>Bị khóa <b><?= (int) $tk['bi_khoa'] ?></b></li>
          <li class="phu">Chưa đấu trận nào <b><?= (int) $tk['chua_dau'] ?></b></li>
        </ul>
      </div>
    </div>
  </section>

  <section class="nd-khung nd-khung-bxh" id="bxh">
    <div class="nd-bxh-dau">
      <div>
        <h2 class="nd-bxh-tieude">Bảng xếp hạng Elo</h2>
        <p class="nd-bxh-mota">
          <?= $monBxh > 0
              ? 'Xếp hạng theo Elo môn ' . e($tenMonBxh[$monBxh]) . '.'
              : 'Xếp hạng toàn trường theo Elo trung bình các môn đã đủ ' . $nMinBxh . ' trận.' ?>
          Elo chỉ thay đổi theo kết quả trận đấu.
        </p>
      </div>
    </div>
    <form method="get" action="#bxh" class="nd-thanh-cu nd-bxh-loc">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <?php if ($tt !== ''): ?><input type="hidden" name="tt" value="<?= e($tt) ?>"><?php endif; ?>
      <input type="text" name="bxh_q" value="<?= e($qBxh) ?>" placeholder="Tìm trong bảng xếp hạng" class="nd-o-tim" autocomplete="off">
      <select name="bxh_mon" class="nd-chon">
        <option value="0"<?= $monBxh === 0 ? ' selected' : '' ?>>Toàn trường</option>
        <?php foreach ($monHocBxh as $m): ?>
          <option value="<?= (int) $m['MaMon'] ?>"<?= $monBxh === (int) $m['MaMon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="nd-nut">Lọc BXH</button>
      <a class="nd-nut nd-nut-vien" href="<?= e(BASE_URL) ?>/admin/nguoidung.php#bxh">Xóa lọc BXH</a>
    </form>
    <?php if (!$dsBxh): ?>
      <p class="nd-trong">Chưa có người chơi đủ điều kiện xếp hạng hoặc không có dữ liệu khớp.</p>
    <?php else: ?>
      <div class="nd-cuon">
        <table class="nd-bang nd-bang-bxh">
          <thead>
            <tr><th>Hạng</th><th>Người dùng</th><th>Bậc</th><th><?= $monBxh > 0 ? 'Elo' : 'Elo trung bình' ?></th><th>Số trận</th><th>Tỷ lệ thắng</th><th>Trạng thái</th></tr>
          </thead>
          <tbody>
          <?php foreach ($dsBxh as $i => $r):
              $hangBxh = $pBxh->offset() + $i + 1;
              [$tenBacBxh, $lopBacBxh] = $bacCuaBxh((int) $r['elo']);
              $biKhoaBxh = $r['TrangThai'] === 'Bị khóa';
          ?>
            <tr>
              <td class="nd-bxh-hang" data-label="Hạng"><?= $hangBxh <= 3 ? '★ ' : '' ?>#<?= $hangBxh ?></td>
              <td data-label="Người dùng">
                <a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a>
                <small class="nd-bxh-email"><?= e($r['Email']) ?></small>
              </td>
              <td data-label="Bậc"><span class="nd-bxh-bac <?= e($lopBacBxh) ?>"><?= e($tenBacBxh) ?></span></td>
              <td data-label="<?= $monBxh > 0 ? 'Elo' : 'Elo trung bình' ?>"><b><?= number_format((int) $r['elo'], 0, ',', '.') ?></b></td>
              <td data-label="Số trận"><?= (int) $r['SoTran'] ?></td>
              <td data-label="Tỷ lệ thắng"><?= e($tyLeThangBxh((int) $r['SoThang'], (int) $r['SoTran'])) ?></td>
              <td data-label="Trạng thái"><?= $biKhoaBxh
                    ? '<span class="tt-badge tt-khoa"><span class="badge-dot"></span> Bị khóa</span>'
                    : '<span class="tt-badge tt-hoatdong"><span class="badge-dot"></span> Hoạt động</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="nd-chan"><?= $pBxh->links() ?></div>
    <?php endif; ?>
  </section>

  <section class="nd-khung">
    <form method="get" action="" class="nd-thanh-cu">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Tìm theo họ tên, email" class="nd-o-tim" autocomplete="off">
      <select name="tt" class="nd-chon">
        <option value="">Mọi trạng thái</option>
        <option value="Hoạt động"<?= $tt === 'Hoạt động' ? ' selected' : '' ?>>Hoạt động</option>
        <option value="Bị khóa"<?= $tt === 'Bị khóa' ? ' selected' : '' ?>>Bị khóa</option>
      </select>
      <button type="submit" class="nd-nut">Tìm</button>
      <a class="nd-nut nd-nut-vien" href="?">Xóa lọc</a>
      <span class="nd-giua"></span>
      <button type="button" class="nd-nut nd-nut-vien" id="ndMoXuat" aria-haspopup="dialog">Xuất Excel / PDF</button>
    </form>

    <?php if (!$ds): ?>
      <p class="nd-trong">Không có người dùng nào khớp.</p>
    <?php else: ?>
      <div class="nd-cuon">
        <table class="nd-bang">
          <thead>
            <tr><th>Mã</th><th>Họ tên</th><th>Email</th><th>Elo trung bình</th><th>Số trận</th><th>Trạng thái</th><th>Ngày tạo</th><th>Khóa tài khoản</th></tr>
          </thead>
          <tbody>
          <?php foreach ($ds as $r): $biKhoa = $r['TrangThai'] === 'Bị khóa'; ?>
            <tr>
              <td class="nd-ma" data-label="Mã"><?= (int) $r['MaND'] ?></td>
              <td data-label="Họ tên"><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a></td>
              <td data-label="Email"><?= e($r['Email']) ?></td>
              <td data-label="Elo trung bình" title="Trung bình Elo của các môn đã thi đấu">
                <?= $r['elo_trung_binh'] !== null ? number_format((int) $r['elo_trung_binh'], 0, ',', '.') : '—' ?>
              </td>
              <td data-label="Số trận"><?= (int) $r['so_tran'] ?></td>
              <td data-label="Trạng thái"><?= $biKhoa
                    ? '<span class="tt-badge tt-khoa"><span class="badge-dot"></span> Bị khóa</span>'
                    : '<span class="tt-badge tt-hoatdong"><span class="badge-dot"></span> Hoạt động</span>' ?></td>
              <td data-label="Ngày tạo"><?= e(date('d/m/Y H:i', strtotime($r['NgayTao']))) ?></td>
              <td data-label="Thao tác">
                <button type="button" class="nd-khoa-nut <?= $biKhoa ? 'mo' : 'khoa' ?>"
                        data-id="<?= (int) $r['MaND'] ?>" data-ten="<?= e($r['HoTen']) ?>"
                        data-hanh-dong="<?= $biKhoa ? 'mokhoa' : 'khoa' ?>">
                  <?= $biKhoa ? 'Mở khóa' : 'Khóa' ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="nd-chan"><?= $p->links() ?></div>
  </section>
</div>

<!-- Hộp xác nhận khóa / mở khóa: nhập lý do, người dùng sẽ nhận thông báo kèm lý do này -->
<dialog class="nd-hop" id="ndHopKhoa">
  <form method="post" action="" class="nd-hop-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="ndHopId" value="">
    <input type="hidden" name="hanh_dong" id="ndHopHanhDong" value="">
    <h3 id="ndHopTieuDe">Khóa tài khoản</h3>
    <p id="ndHopMoTa" class="nd-hop-mota"></p>
    <label class="nd-hop-nhan" for="ndHopLyDo" id="ndHopNhanLyDo">Lý do (người dùng sẽ thấy trong thông báo)</label>
    <textarea name="ly_do" id="ndHopLyDo" rows="3" maxlength="300" placeholder="Ví dụ: Spam bài đăng trong cộng đồng"></textarea>
    <div class="nd-hop-nut">
      <button type="button" class="nd-nut nd-nut-vien" id="ndHopHuy">Hủy</button>
      <button type="submit" class="nd-nut nd-nut-do" id="ndHopXacNhan">Khóa tài khoản</button>
    </div>
  </form>
</dialog>

<script src="<?= e(BASE_URL) ?>/assets/vendor/chart.umd.js"></script>
<!-- Hộp thoại Xuất người dùng (Excel / PDF): cùng giao diện với hộp thoại Xuất của trang Tổng quan (modal-nen, modal xuat-hop);
     cột trái chọn phạm vi, trường và định dạng, cột phải xem trước. Xử lý ở nguoidung_xuat.php -->
<div class="modal-nen" id="ndXuatHop" hidden>
  <form class="modal xuat-hop nd-xuat-hop" id="ndXuatForm" method="post" action="<?= e(BASE_URL) ?>/admin/nguoidung_xuat.php" role="dialog" aria-modal="true" aria-labelledby="ndXuatTieuDe">
    <h3 id="ndXuatTieuDe">Xuất danh sách người dùng</h3>
    <?= csrf_field() ?>
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <input type="hidden" name="tt" value="<?= e($tt) ?>">
    <div class="xuat-luoi">
      <div class="xuat-chonCot">
        <fieldset>
          <legend>Xuất phạm vi nào?</legend>
          <label class="xuat-chon"><input type="radio" name="pham_vi" value="loc" checked> Đang lọc <span class="xuat-phu">(<?= number_format($p->tong(), 0, ',', '.') ?> người dùng, mọi trang)</span></label>
          <label class="xuat-chon"><input type="radio" name="pham_vi" value="tatca"> Toàn bộ người dùng <span class="xuat-phu">(<?= number_format((int) $tk['tong'], 0, ',', '.') ?> người dùng)</span></label>
        </fieldset>
        <fieldset>
          <legend>Nội dung xuất</legend>
          <label class="xuat-chon"><input type="checkbox" name="noidung[]" value="ds" checked> Danh sách người dùng <span class="xuat-phu">(bảng)</span></label>
          <label class="xuat-chon"><input type="checkbox" name="noidung[]" value="tk" checked> Sơ đồ trạng thái tài khoản</label>
          <label class="xuat-chon"><input type="checkbox" name="noidung[]" value="ngay" checked> Sơ đồ tài khoản mới 30 ngày</label>
        </fieldset>
        <fieldset id="ndTruongBox">
          <legend>Chọn trường của danh sách</legend>
          <?php foreach ([
              'ma' => 'Mã', 'ten' => 'Họ tên', 'email' => 'Email', 'tt' => 'Trạng thái',
              'sotran' => 'Số trận', 'ngay' => 'Ngày tạo', 'elo' => 'Điểm Elo theo từng môn',
          ] as $k => $ten): ?>
            <label class="xuat-chon"><input type="checkbox" name="truong[]" value="<?= e($k) ?>" checked> <?= e($ten) ?></label>
          <?php endforeach; ?>
          <div class="nd-xuat-nut">
            <button type="button" class="btn btn-phu xuat-tatca" id="ndChonHet">Chọn tất cả</button>
            <button type="button" class="btn btn-phu xuat-tatca" id="ndBoChon">Bỏ chọn</button>
          </div>
        </fieldset>
        <fieldset>
          <legend>Định dạng</legend>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="excel" checked> Excel (.xlsx)</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="pdf"> PDF</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="ca"> Cả hai (tải liên tiếp 2 file)</label>
        </fieldset>
      </div>
      <div class="xuat-xemCot">
        <div class="xuat-xemDau">Xem trước <small id="ndXemGhiChu"></small></div>
        <div class="xuat-xem" id="ndXem"><p class="xuat-ghichu">Đang tải...</p></div>
      </div>
    </div>
    <p class="xuat-loi" id="ndXuatLoi" hidden></p>
    <div class="hanh-dong">
      <button type="button" class="btn btn-phu" id="ndXuatHuy">Hủy</button>
      <button type="submit" class="btn" id="ndXuatOk">Xuất</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>