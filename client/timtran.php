<?php
// Tìm trận: chọn môn trong danh sách, chọn loại kỳ thi, vào / hủy hàng đợi (C-16 đến C-19, C-22).
// Không thấy môn của mình thì được đề xuất môn mới để quản trị viên duyệt (cần chạy them_de_xuat_mon.sql).
// Mỗi lần trang chờ tải lại thì gọi GhepDoi::thuGhep() (C-20); ghép được hoặc đã có trận thì chuyển sang phong.php.
// Đặt tại: elo_quiz/client/timtran.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/GhepDoi.php';

$maND = Auth::clientId();   // khách chưa đăng nhập vẫn xem được môn/kỳ thi; vào hàng đợi và đề xuất môn cần đăng nhập
$pdo  = Database::pdo();

$soCau      = max(1, Config::int('SO_CAU', 10));
$choToiDa   = max(1, Config::int('THOI_GIAN_CHO_GHEP_TOI_DA', 60));
$eloKhoiTao = Config::int('ELO_KHOI_TAO', 1000);
const TOI_DA_DE_XUAT_CHO = 3;   // mỗi người tối đa 3 đề xuất đang chờ duyệt, tránh gửi tràn

function flashTT(?string $loai = null, ?string $nd = null): ?array
{
    if ($loai !== null) {
        $_SESSION['flash_tt'] = ['loai' => $loai, 'nd' => (string) $nd];
        return null;
    }
    $m = $_SESSION['flash_tt'] ?? null;
    unset($_SESSION['flash_tt']);
    return $m;
}

// Bỏ dấu đ/Đ để so tên môn không phân biệt hoa thường và dấu (collation unicode_ci không gộp đ với d)
function chuanTen(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_C) ?: $s;
    }
    return $s;
}
const SQL_TEN_KHONG_D = "REPLACE(REPLACE(TenMon, 'đ', 'd'), 'Đ', 'D')";

// ---- Dọn dòng chờ quá hạn (C-19: hết thời gian chờ tối đa thì rút khỏi hàng đợi) ----
if ($maND !== null) {
    $st = $pdo->prepare('DELETE FROM HANGDOIGHEPDOI WHERE MaND = ? AND TIMESTAMPDIFF(SECOND, ThoiGianVaoHang, NOW()) > ?');
    $st->execute([$maND, $choToiDa]);
    if ($st->rowCount() > 0) {
        flashTT('loi', 'Hết thời gian chờ mà chưa tìm được đối thủ. Bạn có thể thử lại.');
    }
}

$mon = (int) ($_GET['mon'] ?? 0);

// ---- Xử lý thao tác (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($maND === null) {
        redirect(BASE_URL . '/client/login.php');
    }
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'huy') {
        $pdo->prepare('DELETE FROM HANGDOIGHEPDOI WHERE MaND = ?')->execute([$maND]);
        flashTT('ok', 'Đã hủy tìm trận.');
        redirect(BASE_URL . '/client/timtran.php');
    }

    if ($hanhDong === 'vao') {
        $maMon = (int) ($_POST['mon'] ?? 0);
        $maKT  = (int) ($_POST['loaikt'] ?? 0);
        $loi   = null;

        $st = $pdo->prepare("SELECT 1 FROM MONHOC WHERE MaMon = ? AND TrangThai = 'Đang mở'");
        $st->execute([$maMon]);
        $monHopLe = (bool) $st->fetchColumn();
        $st = $pdo->prepare('SELECT 1 FROM LOAIKYTHI WHERE MaLoaiKT = ?');
        $st->execute([$maKT]);
        $ktHopLe = (bool) $st->fetchColumn();

        if (!$monHopLe || !$ktHopLe) {
            $loi = 'Môn học hoặc loại kỳ thi không hợp lệ.';
        } elseif (GhepDoi::tranDangCo($pdo, $maND) !== null) {
            $loi = 'Bạn đang có một trận chưa kết thúc.';
        } elseif (GhepDoi::demCauHoi($pdo, $maMon, $maKT) < $soCau) {
            $loi = 'Môn này chưa đủ câu hỏi cho loại kỳ thi đã chọn, chưa thể tìm trận.';
        } else {
            try {
                $pdo->beginTransaction();
                // C-22: lần đầu chơi môn này thì tạo Elo khởi tạo
                $pdo->prepare('INSERT IGNORE INTO ELOMONHOC (MaND, MaMon, DiemElo) VALUES (?, ?, ?)')
                    ->execute([$maND, $maMon, $eloKhoiTao]);
                $st = $pdo->prepare('SELECT DiemElo FROM ELOMONHOC WHERE MaND = ? AND MaMon = ?');
                $st->execute([$maND, $maMon]);
                $elo = (int) $st->fetchColumn();
                // C-21: MaND là duy nhất ở hàng đợi, nên mỗi người chỉ có một dòng chờ
                $pdo->prepare('INSERT INTO HANGDOIGHEPDOI (MaND, MaMon, MaLoaiKT, EloTaiThoiDiemCho) VALUES (?, ?, ?, ?)')
                    ->execute([$maND, $maMon, $maKT, $elo]);
                $pdo->commit();
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($ex->getCode() === '23000') {
                    $loi = 'Bạn đang trong hàng đợi rồi.';
                } else {
                    throw $ex;
                }
            }
        }
        if ($loi !== null) {
            flashTT('loi', $loi);
            redirect(BASE_URL . '/client/timtran.php?mon=' . $maMon);
        }
        redirect(BASE_URL . '/client/timtran.php');
    }

    // Đề xuất môn mới: lưu ở trạng thái "Chờ duyệt", chỉ Admin mới duyệt cho hiện ra
    if ($hanhDong === 'dexuat') {
        $ten  = chuanTen((string) ($_POST['ten_mon'] ?? ''));
        $moTa = trim((string) ($_POST['mo_ta'] ?? ''));
        $loi  = null;

        if ($ten === '') {
            $loi = 'Hãy nhập tên môn học.';
        } elseif (mb_strlen($ten) > 150) {
            $loi = 'Tên môn tối đa 150 ký tự.';
        } elseif (mb_strlen($moTa) > 500) {
            $loi = 'Mô tả tối đa 500 ký tự.';
        } else {
            // Trùng tên với môn đã có (kể cả đang chờ duyệt hoặc đang ẩn)?
            $st = $pdo->prepare('SELECT TrangThai FROM MONHOC WHERE ' . SQL_TEN_KHONG_D . ' = ? LIMIT 1');
            $st->execute([str_replace(['đ', 'Đ'], ['d', 'D'], $ten)]);
            $trung = $st->fetchColumn();
            if ($trung === 'Đang mở') {
                $loi = 'Môn này đã có trong danh sách, hãy chọn ở ô "Môn học".';
            } elseif ($trung === 'Chờ duyệt') {
                $loi = 'Môn này đã được đề xuất và đang chờ quản trị viên duyệt.';
            } elseif ($trung !== false) {
                $loi = 'Môn này hiện đang tạm ẩn, chưa thể thi đấu.';
            } else {
                $st = $pdo->prepare("SELECT COUNT(*) FROM MONHOC WHERE MaNguoiDeXuat = ? AND TrangThai = 'Chờ duyệt'");
                $st->execute([$maND]);
                if ((int) $st->fetchColumn() >= TOI_DA_DE_XUAT_CHO) {
                    $loi = 'Bạn đang có ' . TOI_DA_DE_XUAT_CHO . ' đề xuất chờ duyệt, hãy đợi quản trị viên xử lý trước.';
                } else {
                    $pdo->prepare("INSERT INTO MONHOC (TenMon, MoTa, TrangThai, MaNguoiDeXuat) VALUES (?, ?, 'Chờ duyệt', ?)")
                        ->execute([$ten, $moTa === '' ? null : $moTa, $maND]);
                }
            }
        }
        flashTT($loi === null ? 'ok' : 'loi', $loi ?? 'Đã gửi đề xuất. Môn sẽ xuất hiện trong danh sách sau khi quản trị viên duyệt.');
        redirect(BASE_URL . '/client/timtran.php');
    }
    redirect(BASE_URL . '/client/timtran.php');
}

$thongBao = flashTT();

// ---- Đang chờ trong hàng đợi? ----
$dangCho = null;
if ($maND !== null) {
    $st = $pdo->prepare(
        'SELECT h.MaMon, m.TenMon, k.TenLoaiKT, h.EloTaiThoiDiemCho,
                TIMESTAMPDIFF(SECOND, h.ThoiGianVaoHang, NOW()) AS DaCho
           FROM HANGDOIGHEPDOI h
           JOIN MONHOC m ON m.MaMon = h.MaMon
           JOIN LOAIKYTHI k ON k.MaLoaiKT = h.MaLoaiKT
          WHERE h.MaND = ?'
    );
    $st->execute([$maND]);
    $dangCho = $st->fetch() ?: null;

    // Đang chờ thì thử ghép; ghép được (hoặc người kia vừa ghép mình) thì vào phòng
    if ($dangCho) {
        GhepDoi::thuGhep($pdo, $maND);
    }
    $maTranDangCo = GhepDoi::tranDangCo($pdo, $maND);
    if ($maTranDangCo !== null) {
        redirect(BASE_URL . '/client/phong.php?tran=' . $maTranDangCo);
    }
}

$tieuDe = 'Tìm trận';
$cssTrang = 'timtran';
$jsTrang  = 'timtran';
require __DIR__ . '/layout/header.php';
?>
<h2>Tìm trận</h2>

<?php if ($thongBao): ?>
  <div class="<?= $thongBao['loai'] === 'ok' ? 'thanhcong' : 'loi' ?>"><?= e($thongBao['nd']) ?></div>
<?php endif; ?>

<?php if ($dangCho): ?>
  <div class="card" data-tai-lai="2000">
    <h3>Đang tìm đối thủ...</h3>
    <p>Môn: <strong><?= e($dangCho['TenMon']) ?></strong> &middot; Kỳ thi: <strong><?= e($dangCho['TenLoaiKT']) ?></strong>
       &middot; Elo của bạn: <strong><?= (int) $dangCho['EloTaiThoiDiemCho'] ?></strong></p>
    <p>Đã chờ: <strong id="dacho"><?= (int) $dangCho['DaCho'] ?></strong> giây (tối đa <?= $choToiDa ?> giây)</p>
    <form method="post" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="hanh_dong" value="huy">
      <button type="submit" class="btn btn-phu">Hủy tìm</button>
    </form>
  </div>
<?php else: ?>
  <?php
  // ---- Danh sách môn đang mở (C-16): chọn trong ô thả xuống ----
  $dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai = 'Đang mở' ORDER BY TenMon")->fetchAll();
  $monChon = null;
  foreach ($dsMon as $m) {
      if ((int) $m['MaMon'] === $mon) {
          $monChon = $m;
      }
  }
  $dsKT = [];
  if ($monChon) {
      foreach ($pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll() as $k) {
          $k['SoCau'] = GhepDoi::demCauHoi($pdo, (int) $monChon['MaMon'], (int) $k['MaLoaiKT']);
          $dsKT[] = $k;
      }
  }
  ?>

  <div class="card">
    <form method="get" action="">
      <label for="mon">Môn học</label>
      <?php if ($dsMon): ?>
        <select name="mon" id="mon">
          <option value="">-- Chọn môn học --</option>
          <?php foreach ($dsMon as $m): ?>
            <option value="<?= (int) $m['MaMon'] ?>" <?= (int) $m['MaMon'] === $mon ? 'selected' : '' ?>><?= e($m['TenMon']) ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><p><button type="submit" class="btn">Chọn</button></p></noscript>
      <?php else: ?>
        <p>Chưa có môn học nào đang mở. Bạn có thể đề xuất môn mới ở bên dưới.</p>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($monChon): ?>
    <div class="card">
      <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="hanh_dong" value="vao">
        <input type="hidden" name="mon" value="<?= (int) $monChon['MaMon'] ?>">
        <label for="loaikt">Loại kỳ thi</label>
        <select name="loaikt" id="loaikt">
          <?php foreach ($dsKT as $k): ?>
            <option value="<?= (int) $k['MaLoaiKT'] ?>" <?= $k['SoCau'] < $soCau ? 'disabled' : '' ?>>
              <?= e($k['TenLoaiKT']) ?> (<?= (int) $k['SoCau'] ?> câu khả dụng<?= $k['SoCau'] < $soCau ? ', chưa đủ ' . $soCau . ' câu' : '' ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <?php $coThe = array_filter($dsKT, fn($k) => $k['SoCau'] >= $soCau); ?>
        <?php if (!$coThe): ?>
          <p class="loi">Môn này chưa đủ câu hỏi để thi đấu (cần ít nhất <?= $soCau ?> câu cho một loại kỳ thi).</p>
        <?php endif; ?>
        <?php if ($maND === null): ?>
          <p><a class="btn" href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập để vào hàng đợi</a></p>
        <?php else: ?>
        <p><button type="submit" class="btn" <?= $coThe ? '' : 'disabled' ?>>Vào hàng đợi</button></p>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>

  <?php
  $choDuyet = [];
  if ($maND !== null) {
      $st = $pdo->prepare("SELECT TenMon FROM MONHOC WHERE MaNguoiDeXuat = ? AND TrangThai = 'Chờ duyệt' ORDER BY MaMon");
      $st->execute([$maND]);
      $choDuyet = $st->fetchAll(PDO::FETCH_COLUMN);
  }
  ?>
  <div class="card">
    <?php if ($maND === null): ?>
      <p>Không thấy môn của bạn? <a href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập</a> để đề xuất môn mới.</p>
    <?php else: ?>
    <details <?= $dsMon ? '' : 'open' ?>>
      <summary><strong>Không thấy môn của bạn? Đề xuất môn mới</strong></summary>
      <form method="post" action="">
        <?= csrf_field() ?>
        <input type="hidden" name="hanh_dong" value="dexuat">
        <label for="ten_mon">Tên môn học</label>
        <input type="text" id="ten_mon" name="ten_mon" maxlength="150" required>
        <label for="mo_ta">Mô tả (không bắt buộc)</label>
        <textarea id="mo_ta" name="mo_ta" rows="2" maxlength="500"></textarea>
        <p><button type="submit" class="btn btn-phu">Gửi đề xuất</button></p>
        <p>Quản trị viên sẽ duyệt; môn được duyệt mới hiện trong danh sách chọn.</p>
      </form>
    </details>
    <?php endif; ?>
    <?php if ($choDuyet): ?>
      <p>Đề xuất của bạn đang chờ duyệt: <?= e(implode(', ', $choDuyet)) ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>