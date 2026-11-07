<?php
// Ngân hàng câu hỏi: danh sách, lọc, ẩn/hiện một hoặc nhiều câu, xem nhanh (UC13: A-17, A-20, A-40).
// Đặt tại: elo_quiz/admin/cauhoi.php
// Thêm/sửa câu hỏi nằm ở cauhoi_form.php. Không có chức năng xóa câu hỏi (A-20).
// Chi tiết một câu (ngăn "Xem nhanh") lấy qua cauhoi_api.php.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CauHoiHelper.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// ---- A-20: ẩn / hiện câu hỏi (kèm nhật ký A-40 trong cùng giao dịch) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hd = (string) ($_POST['hanh_dong'] ?? '');

    if (($hd === 'an' || $hd === 'hien') && (int) ($_POST['id'] ?? 0) > 0) {
        $id = (int) $_POST['id'];
        $an = $hd === 'an';
        $pdo->beginTransaction();
        try {
            // Điều kiện theo trạng thái cũ: bấm lặp hoặc hai admin cùng bấm thì chỉ ghi nhật ký một lần
            $up = $pdo->prepare('UPDATE CAUHOI SET TrangThai = ? WHERE MaCauHoi = ? AND TrangThai = ?');
            $up->execute([$an ? 'Đã ẩn' : 'Hiển thị', $id, $an ? 'Hiển thị' : 'Đã ẩn']);
            if ($up->rowCount() === 1) {
                NhatKy::ghi($an ? 'Ẩn câu hỏi' : 'Hiện lại câu hỏi', 'CAUHOI #' . $id);
                $_SESSION['ch_flash'] = ['ok', $an ? 'Đã ẩn câu hỏi #' . $id . '.' : 'Đã hiện lại câu hỏi #' . $id . '.'];
            } else {
                $_SESSION['ch_flash'] = ['loi', 'Câu hỏi đã ở trạng thái này rồi hoặc không tồn tại.'];
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
    } elseif ($hd === 'xoa' && (int) ($_POST['id'] ?? 0) > 0) {
        // Chỉ xóa thật câu CHƯA dùng trong trận nào. Câu đã dùng còn nằm trong lịch sử trận và bài làm
        // (khóa ngoại + màn xem lại), xóa sẽ làm hỏng dữ liệu cũ nên chỉ cho Ẩn.
        $id = (int) $_POST['id'];
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT NoiDung FROM CAUHOI WHERE MaCauHoi = ? FOR UPDATE');
            $st->execute([$id]);
            $nd = $st->fetchColumn();
            if ($nd === false) {
                $_SESSION['ch_flash'] = ['loi', 'Câu hỏi không tồn tại hoặc đã bị xóa.'];
            } else {
                $st = $pdo->prepare('SELECT COUNT(*) FROM CAUHOITRANDAU WHERE MaCauHoi = ?');
                $st->execute([$id]);
                if ((int) $st->fetchColumn() > 0) {
                    $_SESSION['ch_flash'] = ['loi', 'Câu hỏi #' . $id . ' đã được dùng trong trận đấu nên không xóa được. Hãy ẩn câu hỏi để ngừng dùng.'];
                } else {
                    $pdo->prepare('DELETE FROM DAPAN WHERE MaCauHoi = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM CAUHOI WHERE MaCauHoi = ?')->execute([$id]);
                    // Câu đã mất khỏi CSDL nên chép nội dung vào nhật ký để còn truy vết (A-40)
                    NhatKy::ghi('Xóa câu hỏi', 'CAUHOI #' . $id, 'Nội dung: ' . mb_substr((string) $nd, 0, 300));
                    $_SESSION['ch_flash'] = ['ok', 'Đã xóa câu hỏi #' . $id . '.'];
                }
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $_SESSION['ch_flash'] = ['loi', 'Không xóa được câu hỏi #' . $id . ' vì đang được hệ thống sử dụng. Hãy ẩn câu hỏi thay vì xóa.'];
        }
    } elseif ($hd === 'an_nhieu' || $hd === 'hien_nhieu') {
        // Thao tác nhiều câu cùng lúc (thanh "Đã chọn N câu hỏi"): tối đa 100 câu mỗi lần
        $an  = $hd === 'an_nhieu';
        $ids = array_filter((array) ($_POST['ids'] ?? []), 'is_scalar');
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $x): bool => $x > 0)));
        $ids = array_slice($ids, 0, 100);

        if (!$ids) {
            $_SESSION['ch_flash'] = ['loi', 'Chưa chọn câu hỏi nào.'];
        } else {
            $pdo->beginTransaction();
            try {
                $up = $pdo->prepare('UPDATE CAUHOI SET TrangThai = ? WHERE MaCauHoi = ? AND TrangThai = ?');
                $doi = [];
                foreach ($ids as $ma) {
                    $up->execute([$an ? 'Đã ẩn' : 'Hiển thị', $ma, $an ? 'Hiển thị' : 'Đã ẩn']);
                    if ($up->rowCount() === 1) {
                        $doi[] = $ma;
                    }
                }
                if ($doi) {
                    // Một dòng nhật ký cho cả lượt: số câu ở DoiTuong, danh sách mã ở MoTa
                    NhatKy::ghi($an ? 'Ẩn câu hỏi' : 'Hiện lại câu hỏi', 'CAUHOI (' . count($doi) . ' câu)', 'Mã: #' . implode(', #', $doi));
                    $_SESSION['ch_flash'] = ['ok', ($an ? 'Đã ẩn ' : 'Đã hiện lại ') . count($doi) . ' câu hỏi.'
                        . (count($doi) < count($ids) ? ' ' . (count($ids) - count($doi)) . ' câu đã ở trạng thái này nên được bỏ qua.' : '')];
                } else {
                    $_SESSION['ch_flash'] = ['loi', 'Các câu đã chọn đều đã ở trạng thái này rồi.'];
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                throw $ex;
            }
        }
    }
    redirect((string) $_SERVER['REQUEST_URI']);   // ở lại đúng trang, giữ nguyên bộ lọc
}

$flash = $_SESSION['ch_flash'] ?? null;
unset($_SESSION['ch_flash']);

// ---- A-17: bộ lọc ----
$q    = trim((string) ($_GET['q'] ?? ''));
$mon  = (int) ($_GET['mon'] ?? 0);
$kt   = (string) ($_GET['kt'] ?? '');          // '' = mọi loại, 'chung' = dùng cho cả hai kỳ, số = mã loại
$dk   = (string) ($_GET['dk'] ?? '');
$tt   = (string) ($_GET['tt'] ?? '');
if (!in_array($dk, CauHoiHelper::DO_KHO, true)) { $dk = ''; }
if (!in_array($tt, ['Hiển thị', 'Đã ẩn'], true)) { $tt = ''; }
if ($kt !== 'chung' && !ctype_digit($kt)) { $kt = ''; }

$dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai IN ('Đang mở','Đã ẩn') ORDER BY TenMon")->fetchAll();
$dsKT  = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();

// Điều kiện "cơ sở" (tìm kiếm, môn, loại kỳ thi): dùng cho cả danh sách lẫn số đếm trên các chip
$dieuKien = [];
$tham     = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    if (preg_match('/^#?(\d{1,9})$/', $q, $mm)) {   // gõ "127" hoặc "#127" thì khớp cả mã câu hỏi
        $dieuKien[] = '(c.NoiDung LIKE ? OR c.MaCauHoi = ?)';
        $tham[]     = $like;
        $tham[]     = (int) $mm[1];
    } else {
        $dieuKien[] = 'c.NoiDung LIKE ?';
        $tham[]     = $like;
    }
}
if ($mon > 0)        { $dieuKien[] = 'c.MaMon = ?';        $tham[] = $mon; }
if ($kt === 'chung') { $dieuKien[] = 'c.MaLoaiKT IS NULL'; }
elseif ($kt !== '')  { $dieuKien[] = 'c.MaLoaiKT = ?';     $tham[] = (int) $kt; }
$whereCoSo = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';
$thamCoSo  = $tham;

// Số đếm trên chip: Dễ + Trung bình + Khó = Tất cả; "Đã ẩn" là số riêng nằm trong các số đó
$st = $pdo->prepare(
    "SELECT COUNT(*) AS tong,
            COALESCE(SUM(c.DoKho = 'Dễ'), 0)         AS de,
            COALESCE(SUM(c.DoKho = 'Trung bình'), 0) AS tb,
            COALESCE(SUM(c.DoKho = 'Khó'), 0)        AS kho,
            COALESCE(SUM(c.TrangThai = 'Đã ẩn'), 0)  AS an
     FROM CAUHOI c $whereCoSo"
);
$st->execute($thamCoSo);
$dem = array_map('intval', $st->fetch());

// Thêm độ khó và trạng thái (chip) cho danh sách
if ($dk !== '') { $dieuKien[] = 'c.DoKho = ?';     $tham[] = $dk; }
if ($tt !== '') { $dieuKien[] = 'c.TrangThai = ?'; $tham[] = $tt; }
$where = $dieuKien ? 'WHERE ' . implode(' AND ', $dieuKien) : '';

$st = $pdo->prepare("SELECT COUNT(*) FROM CAUHOI c $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 20);

$st = $pdo->prepare(
    "SELECT c.MaCauHoi, c.MaMon, c.NoiDung, c.DoKho, c.TrangThai, m.TenMon, k.TenLoaiKT,
            EXISTS(SELECT 1 FROM CAUHOITRANDAU t WHERE t.MaCauHoi = c.MaCauHoi) AS da_dung
     FROM CAUHOI c
     JOIN MONHOC m ON m.MaMon = c.MaMon
     LEFT JOIN LOAIKYTHI k ON k.MaLoaiKT = c.MaLoaiKT
     $where
     ORDER BY c.MaCauHoi DESC
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$ds = $st->fetchAll();

// Thẻ "Độ khó theo môn": mỗi môn một thanh 3 đoạn (số câu tính cả câu đã ẩn)
$thanhMon = $pdo->query(
    "SELECT m.MaMon, m.TenMon, COUNT(c.MaCauHoi) AS tong,
            COALESCE(SUM(c.DoKho = 'Dễ'), 0)         AS de,
            COALESCE(SUM(c.DoKho = 'Trung bình'), 0) AS tb,
            COALESCE(SUM(c.DoKho = 'Khó'), 0)        AS kho
     FROM MONHOC m
     LEFT JOIN CAUHOI c ON c.MaMon = m.MaMon
     WHERE m.TrangThai IN ('Đang mở','Đã ẩn')
     GROUP BY m.MaMon, m.TenMon
     ORDER BY tong DESC, m.TenMon"
)->fetchAll();

// ---- Hàm phụ cho phần hiển thị ----
// Link chip: giữ tìm kiếm, môn, loại kỳ thi; chỉ đổi độ khó/trạng thái; về trang 1
$urlChip = static function (string $dkMoi, string $ttMoi) use ($q, $mon, $kt): string {
    $tx = array_filter(['q' => $q, 'mon' => $mon > 0 ? $mon : '', 'kt' => $kt, 'dk' => $dkMoi, 'tt' => $ttMoi],
        static fn ($v): bool => $v !== '' && $v !== null);
    return '?' . http_build_query($tx);
};
$lopDoKho = ['Dễ' => 'pill-de', 'Trung bình' => 'pill-tb', 'Khó' => 'pill-kho'];
$chuMon   = static fn (string $ten): string => mb_strtoupper(mb_substr(trim($ten), 0, 1)) ?: '?';
$phanTram = static fn (int $x, int $tong): string => number_format($tong > 0 ? $x * 100 / $tong : 0, 2, '.', '');

$soMonHien  = 9;   // thẻ "Độ khó theo môn" và danh sách môn ở bộ lọc chỉ hiện sẵn ngần này, còn lại bấm "Xem thêm"
$soMonLocHien = 10;
$tenMonChon = 'Mọi môn';
foreach ($dsMon as $m) {
    if ((int) $m['MaMon'] === $mon) { $tenMonChon = (string) $m['TenMon']; }
}

$tieuDe   = 'Ngân hàng câu hỏi';
$mucChon  = 'cauhoi';
$cssTrang = 'cauhoi';
$jsTrang  = 'cauhoi';
require __DIR__ . '/layout/header.php';
?>
<div class="ch-trang">

  <div class="ch-dau">
    <span class="ch-dau-icon" aria-hidden="true">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
    </span>
    <div class="ch-dau-chu">
      <h2>Ngân hàng câu hỏi</h2>
      <p>Quản lý câu hỏi, độ khó và trạng thái hiển thị trên hệ thống</p>
    </div>
    <div class="ch-dau-nut">
      <a class="ch-nut ch-nut-vien" href="<?= e(BASE_URL) ?>/admin/cauhoi_nhap.php">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
        Nhập từ Word/Excel
      </a>
      <a class="ch-nut ch-nut-chinh" href="<?= e(BASE_URL) ?>/admin/cauhoi_form.php<?= $mon > 0 ? '?mon=' . $mon : '' ?>">+ Thêm câu hỏi</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="<?= $flash[0] === 'ok' ? 'thanhcong' : 'loi' ?>" role="status"><?= e($flash[1]) ?></div>
  <?php endif; ?>

  <!-- Độ khó theo môn -->
  <section class="ch-khoi" aria-labelledby="chTieuDeMon">
    <h3 id="chTieuDeMon" class="ch-tieude">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
      Độ khó theo môn
    </h3>
    <?php if (!$thanhMon): ?>
      <p class="nhan-phu">Chưa có môn học nào.</p>
    <?php else: ?>
      <div class="ch-luoi-mon" id="chLuoiMon">
        <?php foreach ($thanhMon as $i => $m): $tg = (int) $m['tong']; ?>
          <a class="ch-mon<?= (int) $m['MaMon'] === $mon ? ' dang-loc' : '' ?><?= $i >= $soMonHien ? ' ch-mon-them' : '' ?>" href="?mon=<?= (int) $m['MaMon'] ?>"
             title="Lọc theo môn <?= e($m['TenMon']) ?>: <?= (int) $m['de'] ?> dễ, <?= (int) $m['tb'] ?> trung bình, <?= (int) $m['kho'] ?> khó">
            <span class="ch-ico-mon m<?= (int) $m['MaMon'] % 6 ?>" aria-hidden="true"><?= e($chuMon((string) $m['TenMon'])) ?></span>
            <span class="ch-ten-mon"><?= e($m['TenMon']) ?></span>
            <span class="ch-thanh-kho" aria-hidden="true">
              <?php if ($tg > 0): ?>
                <i class="k-de"  style="width:<?= $phanTram((int) $m['de'], $tg) ?>%"></i><i class="k-tb"  style="width:<?= $phanTram((int) $m['tb'], $tg) ?>%"></i><i class="k-kho" style="width:<?= $phanTram((int) $m['kho'], $tg) ?>%"></i>
              <?php endif; ?>
            </span>
            <span class="ch-so-cau"><?= $tg ?> câu</span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if (count($thanhMon) > $soMonHien): ?>
        <button type="button" class="ch-xem-them" id="chMonThem" aria-expanded="false" aria-controls="chLuoiMon"
                data-nhan="Xem thêm <?= count($thanhMon) - $soMonHien ?> môn">Xem thêm <?= count($thanhMon) - $soMonHien ?> môn</button>
      <?php endif; ?>
      <p class="ch-chuthich"><i class="k-de"></i> Dễ <i class="k-tb"></i> Trung bình <i class="k-kho"></i> Khó</p>
    <?php endif; ?>
  </section>

  <!-- Bộ lọc -->
  <section class="ch-khoi">
    <form method="get" action="" class="ch-loc" id="chLoc">
      <?php if ($dk !== ''): ?><input type="hidden" name="dk" value="<?= e($dk) ?>"><?php endif; ?>
      <?php if ($tt !== ''): ?><input type="hidden" name="tt" value="<?= e($tt) ?>"><?php endif; ?>
      <label class="ch-o-tim">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm trong nội dung câu hỏi… (hoặc nhập mã, ví dụ #127)" aria-label="Tìm trong nội dung câu hỏi" autocomplete="off">
      </label>
      <div class="ch-chon-mon" id="chChonMon">
        <input type="hidden" name="mon" value="<?= $mon > 0 ? $mon : 0 ?>">
        <button type="button" class="ch-chon-mon-nut" aria-haspopup="listbox" aria-expanded="false">
          <span class="ch-chon-mon-ten"><?= e($tenMonChon) ?></span>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="ch-chon-mon-hop" hidden>
          <input type="search" class="ch-chon-mon-tim" placeholder="Tìm môn học…" aria-label="Tìm môn học" autocomplete="off">
          <ul role="listbox">
            <li><button type="button" data-id="0" data-ten="Mọi môn"<?= $mon <= 0 ? ' class="dang-chon"' : '' ?>>Mọi môn</button></li>
            <?php foreach ($dsMon as $i => $m): ?>
              <li<?= $i >= $soMonLocHien ? ' data-them="1"' : '' ?>><button type="button" data-id="<?= (int) $m['MaMon'] ?>" data-ten="<?= e($m['TenMon']) ?>"<?= (int) $m['MaMon'] === $mon ? ' class="dang-chon"' : '' ?>><?= e($m['TenMon']) ?></button></li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($dsMon) > $soMonLocHien): ?>
            <button type="button" class="ch-chon-mon-them">Xem thêm <?= count($dsMon) - $soMonLocHien ?> môn</button>
          <?php endif; ?>
          <p class="ch-chon-mon-rong" hidden>Không có môn nào khớp.</p>
        </div>
      </div>
      <select name="kt" aria-label="Loại kỳ thi">
        <option value="">Mọi loại kỳ thi</option>
        <?php foreach ($dsKT as $k): ?>
          <option value="<?= (int) $k['MaLoaiKT'] ?>"<?= $kt === (string) $k['MaLoaiKT'] ? ' selected' : '' ?>><?= e($k['TenLoaiKT']) ?></option>
        <?php endforeach; ?>
        <option value="chung"<?= $kt === 'chung' ? ' selected' : '' ?>>Dùng cho cả hai kỳ</option>
      </select>
      <button type="submit" class="ch-nut ch-nut-chinh">Lọc</button>
      <a class="ch-nut ch-nut-vien" href="?">Xóa lọc</a>
    </form>

    <div class="ch-chips" role="group" aria-label="Lọc nhanh theo độ khó và trạng thái">
      <a class="ch-chip<?= $dk === '' && $tt === '' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('', '')) ?>">Tất cả <b><?= $dem['tong'] ?></b></a>
      <a class="ch-chip<?= $dk === 'Dễ' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('Dễ', '')) ?>">Dễ <b><?= $dem['de'] ?></b></a>
      <a class="ch-chip<?= $dk === 'Trung bình' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('Trung bình', '')) ?>">Trung bình <b><?= $dem['tb'] ?></b></a>
      <a class="ch-chip<?= $dk === 'Khó' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('Khó', '')) ?>">Khó <b><?= $dem['kho'] ?></b></a>
      <a class="ch-chip<?= $tt === 'Đã ẩn' && $dk === '' ? ' dang-chon' : '' ?>" href="<?= e($urlChip('', 'Đã ẩn')) ?>">Đã ẩn <b><?= $dem['an'] ?></b></a>
    </div>
  </section>

  <!-- Danh sách -->
  <section class="ch-khoi" aria-labelledby="chTieuDeDs">
    <h3 id="chTieuDeDs" class="ch-tieude">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
      Danh sách câu hỏi
    </h3>
    <?php if (!$ds): ?>
      <p class="nhan-phu">Không có câu hỏi nào khớp.</p>
    <?php else: ?>
      <div class="ch-cuon">
        <table class="ch-bang" id="chBang">
          <thead>
            <tr>
              <th class="ch-td-chon"><input type="checkbox" id="chChonTatCa" aria-label="Chọn tất cả câu hỏi trong trang này"></th>
              <th>Mã</th><th>Nội dung</th><th>Môn</th><th>Loại kỳ thi</th><th>Độ khó</th><th>Trạng thái</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($ds as $r): $an = $r['TrangThai'] === 'Đã ẩn'; ?>
            <tr class="ch-dong<?= $an ? ' da-an' : '' ?>" data-id="<?= (int) $r['MaCauHoi'] ?>" tabindex="0" title="Bấm để xem nhanh">
              <td class="ch-td-chon"><input type="checkbox" class="ch-chon" value="<?= (int) $r['MaCauHoi'] ?>" aria-label="Chọn câu hỏi #<?= (int) $r['MaCauHoi'] ?>"></td>
              <td class="ch-ma">#<?= (int) $r['MaCauHoi'] ?></td>
              <td class="ch-nd"><?= e(preg_replace('/^(.{90}).+$/su', '$1…', (string) $r['NoiDung'])) ?></td>
              <td><span class="ch-mon-o"><span class="ch-ico-mon m<?= (int) $r['MaMon'] % 6 ?>" aria-hidden="true"><?= e($chuMon((string) $r['TenMon'])) ?></span><b><?= e($r['TenMon']) ?></b></span></td>
              <td><?= $r['TenLoaiKT'] !== null ? '<span class="ch-pill pill-kt">' . e($r['TenLoaiKT']) . '</span>' : '<span class="nhan-phu">Cả hai kỳ</span>' ?></td>
              <td><span class="ch-pill <?= e($lopDoKho[$r['DoKho']] ?? '') ?>"><?= e($r['DoKho']) ?></span></td>
              <td><span class="ch-pill <?= $an ? 'pill-an' : 'pill-hien' ?>"><?= $an ? 'Đã ẩn' : 'Hiển thị' ?></span></td>
              <td class="ch-td-tt khong-ngat">
                <a class="ch-nut ch-nut-nho ch-nut-vien" href="<?= e(BASE_URL) ?>/admin/cauhoi_form.php?id=<?= (int) $r['MaCauHoi'] ?>">Sửa</a>
                <form method="post" action="" class="form-dong">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['MaCauHoi'] ?>">
                  <?php if (!$an): ?>
                    <button type="submit" name="hanh_dong" value="an" class="ch-nut ch-nut-nho ch-nut-vien"
                            data-xac-nhan="Ẩn câu hỏi này? Câu sẽ không được chọn vào trận mới.">Ẩn</button>
                  <?php else: ?>
                    <button type="submit" name="hanh_dong" value="hien" class="ch-nut ch-nut-nho ch-nut-chinh">Hiện lại</button>
                  <?php endif; ?>
                </form>
                <?php if (!(int) $r['da_dung']): ?>
                  <form method="post" action="" class="form-dong">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $r['MaCauHoi'] ?>">
                    <button type="submit" name="hanh_dong" value="xoa" class="ch-nut ch-nut-nho ch-nut-do"
                            data-xac-nhan="Xóa vĩnh viễn câu hỏi #<?= (int) $r['MaCauHoi'] ?>? Không thể hoàn tác.">Xóa</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?= $p->links() ?>
    <p class="nhan-phu ch-ghichu">Câu đã dùng trong trận vẫn sửa được để chữa lỗi (điểm và Elo của trận cũ không tính lại, mỗi lần sửa có ghi nhật ký) nhưng không xóa được, chỉ ẩn để ngừng dùng. Câu đang nằm trong trận chưa kết thúc tạm thời chỉ xem.</p>
  </section>

  <!-- Thanh nổi khi chọn nhiều câu (JS thêm các ô ids[] lúc gửi) -->
  <form method="post" action="" class="ch-thanhchon" id="chThanhChon" hidden>
    <?= csrf_field() ?>
    <span class="ch-thanhchon-so">Đã chọn <b id="chSoChon">0</b> câu hỏi</span>
    <button type="submit" name="hanh_dong" value="an_nhieu" class="ch-tc-nut">Ẩn đã chọn</button>
    <button type="submit" name="hanh_dong" value="hien_nhieu" class="ch-tc-nut">Hiện lại đã chọn</button>
    <button type="button" class="ch-tc-bo" id="chBoChon">Bỏ chọn</button>
  </form>

</div>

<!-- Ngăn "Xem nhanh": JS đổ dữ liệu từ cauhoi_api.php -->
<div class="ch-nen" id="chNen"></div>
<aside class="ch-ngan" id="chNgan" role="dialog" aria-modal="true" aria-labelledby="chNganTieuDe" aria-hidden="true"
       data-api="<?= e(BASE_URL) ?>/admin/cauhoi_api.php" data-form="<?= e(BASE_URL) ?>/admin/cauhoi_form.php">
  <div class="ch-ngan-dau">
    <div>
      <small>Xem nhanh</small>
      <h3 id="chNganTieuDe">Câu hỏi</h3>
    </div>
    <button type="button" class="ch-ngan-dong" id="chNganDong" aria-label="Đóng">&times;</button>
  </div>

  <p class="ch-ngan-tai" id="chNganTai" hidden></p>

  <div class="ch-ngan-than" id="chNganThan" hidden>
    <div class="ch-ngan-meta" id="chMeta"></div>

    <h4 class="ch-nhan">Nội dung</h4>
    <p class="ch-ngan-nd" id="chNoiDung"></p>

    <h4 class="ch-nhan">Các đáp án</h4>
    <ul class="ch-da" id="chDapAn"></ul>

    <div id="chGiaiThichBox">
      <h4 class="ch-nhan">Giải thích</h4>
      <div class="ch-giaithich" id="chGiaiThich"></div>
    </div>

    <p class="ch-ngan-ghichu" id="chGhiChu" hidden></p>

    <div class="ch-ngan-chan">
      <a class="ch-nut ch-nut-chinh" id="chSua" href="#">Sửa câu hỏi</a>
      <form method="post" action="" class="form-dong">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="chAnId" value="">
        <button type="submit" name="hanh_dong" value="an" class="ch-nut ch-nut-xam" id="chAnNut">Ẩn</button>
        <button type="submit" name="hanh_dong" value="xoa" class="ch-nut ch-nut-do" id="chXoaNut" hidden
                data-xac-nhan="Xóa vĩnh viễn câu hỏi này? Không thể hoàn tác.">Xóa</button>
      </form>
    </div>
  </div>
</aside>
<?php require __DIR__ . '/layout/footer.php'; ?>