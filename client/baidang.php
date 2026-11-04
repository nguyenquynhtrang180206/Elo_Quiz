<?php
// Chi tiết bài đăng, bình luận, xóa bài của mình (C-43, C-44). Đặt tại: elo_quiz/client/baidang.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CongDongHelper.php';

$maND = Auth::clientId();   // khách chưa đăng nhập vẫn xem được bài; bình luận/xóa/sửa cần đăng nhập
$pdo  = Database::pdo();
$id   = (int) ($_GET['id'] ?? 0);

// Bảng LUOTTHICH có thể chưa được tạo (chạy migration_luotthich.sql); chưa có thì ẩn nút thích
$coLike = true;
try {
    $pdo->query('SELECT 1 FROM LUOTTHICH LIMIT 1');
} catch (PDOException $ex) {
    $coLike = false;
}

// C-44: chỉ thấy bài đang ở trạng thái "Hiển thị"
$st = $pdo->prepare(
    "SELECT b.MaBaiDang, b.MaND, b.TieuDe, b.NoiDung, b.NgayDang, u.HoTen, m.TenMon,
            TIMESTAMPDIFF(SECOND, b.NgayDang, NOW()) AS GiayTuDang,
            " . ($coLike
                ? '(SELECT COUNT(*) FROM LUOTTHICH l WHERE l.MaBaiDang = b.MaBaiDang) AS SoThich,
                   (SELECT COUNT(*) FROM LUOTTHICH l2 WHERE l2.MaBaiDang = b.MaBaiDang AND l2.MaND = ' . (int) $maND . ') AS DaThich'
                : '0 AS SoThich, 0 AS DaThich') . "
       FROM BAIDANG b
       JOIN NGUOIDUNG u ON u.MaND = b.MaND
       LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
      WHERE b.MaBaiDang = ? AND b.TrangThai = 'Hiển thị'"
);
$st->execute([$id]);
$bai = $st->fetch();

$loi = [];
$noiDungBL = '';
$loiSua = [];          // lỗi khi sửa bình luận
$dangSua = (int) ($_GET['sua'] ?? 0);   // bình luận đang mở khung sửa
$noiDungSua = null;

// Bình luận hiện trong danh sách: bình luận "Hiển thị" của mọi người, cộng bình luận đang ẩn của chính mình
// (để chủ bình luận tự mở lại được). Bình luận "Đã xóa" không ai thấy.
$dkBL = "c.MaBaiDang = :id AND (c.TrangThai = 'Hiển thị' OR (c.TrangThai = 'Bị ẩn' AND c.MaND = :me))";
$demBL = function () use ($pdo, $id, $maND, $dkBL): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM BINHLUAN c WHERE $dkBL");
    $s->execute([':id' => $id, ':me' => (int) $maND]);
    return (int) $s->fetchColumn();
};
$coLikeBL = CongDongHelper::coLikeBL();
$coAnBL   = CongDongHelper::coCotNguoiAnBL();

if ($bai && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($maND === null) {
        redirect(BASE_URL . '/client/login.php');
    }
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'binhluan') {
        $noiDungBL = trim((string) ($_POST['noi_dung'] ?? ''));
        if ($noiDungBL === '') {
            $loi[] = 'Vui lòng nhập nội dung bình luận.';
        } elseif (mb_strlen($noiDungBL) > CongDongHelper::BINH_LUAN_TOI_DA) {
            $loi[] = 'Bình luận tối đa ' . CongDongHelper::BINH_LUAN_TOI_DA . ' ký tự.';
        } else {
            $ins = $pdo->prepare('INSERT INTO BINHLUAN (MaBaiDang, MaND, NoiDung) VALUES (?, ?, ?)');
            $ins->execute([$id, $maND, $noiDungBL]);

            // Quay về trang cuối để thấy bình luận vừa viết
            $trangCuoi = max(1, (int) ceil($demBL() / 10));
            redirect(BASE_URL . '/client/baidang.php?id=' . $id . '&page=' . $trangCuoi . '#form-bl');
        }
    } elseif ($hanhDong === 'thich') {
        // ---- Thích / bỏ thích bài đăng này (giống ở bảng tin) ----
        if ($coLike) {
            $st = $pdo->prepare('INSERT IGNORE INTO LUOTTHICH (MaBaiDang, MaND) VALUES (?, ?)');
            $st->execute([$id, $maND]);
            if ($st->rowCount() === 0) {
                $st = $pdo->prepare('DELETE FROM LUOTTHICH WHERE MaBaiDang = ? AND MaND = ?');
                $st->execute([$id, $maND]);
            }
        }
        redirect(BASE_URL . '/client/baidang.php?id=' . $id);
    } elseif (in_array($hanhDong, ['like_bl', 'an_bl', 'mo_bl', 'xoa_bl', 'sua_bl'], true)) {
        // ---- Bình luận: thích, ẩn, mở lại, xóa (mọi lúc), sửa (trong 15 phút). Chỉ tác động lên bình luận của chính mình ----
        $maBL     = (int) ($_POST['ma_bl'] ?? 0);
        $trangGui = max(1, (int) ($_POST['page'] ?? 1));
        $veLai = static fn () => redirect(
            BASE_URL . '/client/baidang.php?id=' . $id . ($trangGui > 1 ? '&page=' . $trangGui : '') . '#bl-' . $maBL
        );

        if ($hanhDong === 'like_bl') {
            CongDongHelper::thichBL($pdo, $maBL, $maND);
            $veLai();
        } elseif ($hanhDong === 'an_bl') {
            $ok = CongDongHelper::anBL($pdo, $maBL, $maND);
            CongDongHelper::flash($ok ? 'Đã ẩn bình luận. Chỉ bạn còn thấy bình luận này và có thể mở lại.' : 'Không ẩn được bình luận này.', 'flash_bl');
            $veLai();
        } elseif ($hanhDong === 'mo_bl') {
            $ok = CongDongHelper::moLaiBL($pdo, $maBL, $maND);
            CongDongHelper::flash($ok ? 'Đã mở lại bình luận.' : 'Không mở lại được: bình luận này do quản trị viên ẩn hoặc đã thay đổi trạng thái.', 'flash_bl');
            $veLai();
        } elseif ($hanhDong === 'xoa_bl') {
            $ok = CongDongHelper::xoaBL($pdo, $maBL, $maND);
            CongDongHelper::flash($ok ? 'Đã xóa bình luận.' : 'Không xóa được bình luận này.', 'flash_bl');
            redirect(BASE_URL . '/client/baidang.php?id=' . $id . ($trangGui > 1 ? '&page=' . $trangGui : '') . '#binh-luan');
        } else {
            $dangSua    = $maBL;
            $noiDungSua = trim((string) ($_POST['noi_dung'] ?? ''));
            if ($noiDungSua === '') {
                $loiSua[] = 'Vui lòng nhập nội dung bình luận.';
            } elseif (mb_strlen($noiDungSua) > CongDongHelper::BINH_LUAN_TOI_DA) {
                $loiSua[] = 'Bình luận tối đa ' . CongDongHelper::BINH_LUAN_TOI_DA . ' ký tự.';
            } else {
                $kq = CongDongHelper::suaBL($pdo, $maBL, $maND, $noiDungSua);
                if ($kq === 'ok') {
                    CongDongHelper::flash('Đã cập nhật bình luận.', 'flash_bl');
                    $veLai();
                }
                $loiSua[] = $kq === 'het_han'
                    ? 'Đã quá ' . CongDongHelper::SUA_TOI_DA_PHUT . ' phút kể từ lúc viết nên không thể sửa bình luận này.'
                    : 'Không sửa được bình luận này.';
            }
        }
    } elseif ($hanhDong === 'xoa' && (int) $bai['MaND'] === $maND) {
        // C-43: xóa bài nghĩa là chuyển sang "Đã xóa", không xóa cứng; chủ bài xóa được mọi lúc
        CongDongHelper::xoaBai($pdo, $id, $maND);
        CongDongHelper::flash('Đã xóa bài đăng.');
        redirect(BASE_URL . '/client/congdong.php');
    } elseif ($hanhDong === 'an' && (int) $bai['MaND'] === $maND) {
        // Chủ bài ẩn bài của mình mọi lúc; mở lại ở mục "Bài của tôi"
        $ok = CongDongHelper::anBai($pdo, $id, $maND);
        CongDongHelper::flash($ok ? 'Đã ẩn bài đăng. Bạn có thể mở lại trong mục "Bài của tôi".' : 'Không ẩn được bài này.');
        redirect(BASE_URL . '/client/congdong.php?tab=cuatoi');
    }
}

$thanhCong   = CongDongHelper::flash();
$thanhCongBL = CongDongHelper::flash(null, 'flash_bl');
$binhLuan = [];
$p = null;
$soBLHienThi = 0;

if ($bai) {
    // Số bình luận ghi trên tiêu đề: chỉ tính bình luận đang hiển thị
    $st = $pdo->prepare("SELECT COUNT(*) FROM BINHLUAN WHERE MaBaiDang = ? AND TrangThai = 'Hiển thị'");
    $st->execute([$id]);
    $soBLHienThi = (int) $st->fetchColumn();

    $p = new Paginator($demBL(), 10);

    $subLike = $coLikeBL
        ? '(SELECT COUNT(*) FROM LUOTTHICH_BL l WHERE l.MaBinhLuan = c.MaBinhLuan) AS SoThich,
           (SELECT COUNT(*) FROM LUOTTHICH_BL l2 WHERE l2.MaBinhLuan = c.MaBinhLuan AND l2.MaND = :nd) AS DaThich'
        : '0 AS SoThich, 0 AS DaThich';
    $thamBL = [':id' => $id, ':me' => (int) $maND];
    if ($coLikeBL) {
        $thamBL[':nd'] = (int) $maND;
    }
    $st = $pdo->prepare(
        "SELECT c.MaBinhLuan, c.MaND, c.NoiDung, c.NgayBinhLuan, c.TrangThai, u.HoTen,
                " . ($coAnBL ? 'c.NguoiAn' : 'NULL AS NguoiAn') . ",
                TIMESTAMPDIFF(SECOND, c.NgayBinhLuan, NOW()) AS GiayTuLuc,
                $subLike
           FROM BINHLUAN c JOIN NGUOIDUNG u ON u.MaND = c.MaND
          WHERE $dkBL
          ORDER BY c.NgayBinhLuan, c.MaBinhLuan
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute($thamBL);
    $binhLuan = $st->fetchAll();
}

$tieuDe = $bai ? $bai['TieuDe'] : 'Bài đăng';
$cssTrang = 'baidang';
$jsTrang  = 'baidang';
require __DIR__ . '/layout/header.php';
?>
<div class="container">
  <p><a href="<?= e(BASE_URL) ?>/client/congdong.php">&laquo; Về bảng tin</a></p>

  <?php if (!$bai): ?>
    <div class="card">Bài đăng không tồn tại hoặc không còn hiển thị.</div>
  <?php else: ?>
    <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>

    <div class="card">
      <h2 class="tieu-de-bai"><?= e($bai['TieuDe']) ?></h2>
      <div class="meta">
        <?= e($bai['HoTen']) ?> · <?= e(CongDongHelper::ngay($bai['NgayDang'])) ?>
        <?php if ($bai['TenMon']): ?> · <?= e($bai['TenMon']) ?><?php endif; ?>
      </div>
      <p><?= nl2br(e($bai['NoiDung'])) ?></p>

      <?php if ($coLike): ?>
        <form method="post" action="?id=<?= (int) $id ?>" class="form-dong">
          <?= csrf_field() ?>
          <input type="hidden" name="hanh_dong" value="thich">
          <button type="submit" class="cd-chi-so cd-thich<?= (int) $bai['DaThich'] > 0 ? ' da-thich' : '' ?>"
                  title="<?= (int) $bai['DaThich'] > 0 ? 'Bỏ thích' : 'Thích bài này' ?>">
            👍 <?= (int) $bai['SoThich'] ?>
          </button>
        </form>
      <?php endif; ?>

      <?php if ((int) $bai['MaND'] === $maND): ?>
        <div class="thao-tac-chu-bai">
          <?php if (CongDongHelper::conHanSua((int) $bai['GiayTuDang'])): ?>
            <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/suabai.php?id=<?= (int) $id ?>">Sửa bài</a>
          <?php else: ?>
            <span class="meta">Đã quá <?= CongDongHelper::SUA_TOI_DA_PHUT ?> phút kể từ lúc đăng nên không thể sửa bài.</span>
          <?php endif; ?>
          <?php if (CongDongHelper::coCotNguoiAn()): ?>
            <form method="post" action="?id=<?= (int) $id ?>" class="form-dong"
                  data-xac-nhan="Ẩn bài đăng này? Bạn có thể mở lại trong mục Bài của tôi.">
              <?= csrf_field() ?>
              <input type="hidden" name="hanh_dong" value="an">
              <button type="submit" class="btn btn-phu">Ẩn bài</button>
            </form>
          <?php endif; ?>
          <form method="post" action="?id=<?= (int) $id ?>" class="form-dong"
                data-xac-nhan="Xóa bài đăng này?">
            <?= csrf_field() ?>
            <input type="hidden" name="hanh_dong" value="xoa">
            <button type="submit" class="btn btn-phu">Xóa bài</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" id="binh-luan">
      <h3 class="tieu-de-binhluan">Bình luận (<?= (int) $soBLHienThi ?>)</h3>
      <?php if ($thanhCongBL): ?><div class="thanhcong"><?= e($thanhCongBL) ?></div><?php endif; ?>

      <?php if (!$binhLuan): ?>
        <p class="meta">Chưa có bình luận nào.</p>
      <?php endif; ?>
      <?php $loiSuaDaHien = false; ?>
      <?php foreach ($binhLuan as $c):
        $laChu   = $maND !== null && (int) $c['MaND'] === $maND;
        $dangAn  = $c['TrangThai'] === 'Bị ẩn';
        $conSua  = $laChu && !$dangAn && CongDongHelper::conHanSua((int) $c['GiayTuLuc']);
        $moSua   = $conSua && $dangSua === (int) $c['MaBinhLuan'];
        $trangHt = (int) $p->trang();
        // Hai trường ẩn dùng chung cho mọi form của bình luận này
        $truongBL = '<input type="hidden" name="ma_bl" value="' . (int) $c['MaBinhLuan'] . '">'
                  . '<input type="hidden" name="page" value="' . $trangHt . '">';
      ?>
        <div class="binhluan" id="bl-<?= (int) $c['MaBinhLuan'] ?>">
          <div class="meta">
            <b><?= e($c['HoTen']) ?></b> · <?= e(CongDongHelper::ngay($c['NgayBinhLuan'])) ?>
            <?php if ($dangAn): ?><span class="nhan-an">Đang ẩn (chỉ bạn thấy)</span><?php endif; ?>
          </div>

          <?php if ($moSua): ?>
            <form method="post" action="?id=<?= (int) $id ?>" novalidate class="form-sua-bl">
              <?= csrf_field() ?>
              <input type="hidden" name="hanh_dong" value="sua_bl">
              <?= $truongBL ?>
              <?php if ($loiSua): $loiSuaDaHien = true; ?>
                <div class="loi"><?php foreach ($loiSua as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
              <?php endif; ?>
              <textarea name="noi_dung" maxlength="1000" required><?= e($noiDungSua ?? $c['NoiDung']) ?></textarea>
              <p>
                <button type="submit" class="btn">Lưu</button>
                <a class="btn btn-phu" href="?id=<?= (int) $id ?><?= $trangHt > 1 ? '&amp;page=' . $trangHt : '' ?>#bl-<?= (int) $c['MaBinhLuan'] ?>">Hủy</a>
              </p>
            </form>
          <?php else: ?>
            <div><?= nl2br(e($c['NoiDung'])) ?></div>
          <?php endif; ?>

          <?php if (!$moSua): ?>
          <div class="thao-tac-bl">
            <?php if ($dangAn): ?>
              <span class="cd-chi-so">👍 <?= (int) $c['SoThich'] ?></span>
            <?php elseif ($coLikeBL): ?>
              <form method="post" action="?id=<?= (int) $id ?>" class="form-dong">
                <?= csrf_field() ?>
                <input type="hidden" name="hanh_dong" value="like_bl">
                <?= $truongBL ?>
                <button type="submit" class="cd-chi-so cd-thich<?= (int) $c['DaThich'] > 0 ? ' da-thich' : '' ?>"
                        title="<?= (int) $c['DaThich'] > 0 ? 'Bỏ thích' : 'Thích bình luận này' ?>">👍 <?= (int) $c['SoThich'] ?></button>
              </form>
            <?php endif; ?>

            <?php if ($laChu): ?>
              <?php if ($conSua): ?>
                <a class="btn btn-phu btn-nho" href="?id=<?= (int) $id ?><?= $trangHt > 1 ? '&amp;page=' . $trangHt : '' ?>&amp;sua=<?= (int) $c['MaBinhLuan'] ?>#bl-<?= (int) $c['MaBinhLuan'] ?>">Sửa</a>
              <?php endif; ?>

              <?php if ($coAnBL): ?>
                <?php if (!$dangAn): ?>
                  <form method="post" action="?id=<?= (int) $id ?>" class="form-dong"
                        data-xac-nhan="Ẩn bình luận này? Bạn vẫn thấy và có thể mở lại.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="hanh_dong" value="an_bl">
                    <?= $truongBL ?>
                    <button type="submit" class="btn btn-phu btn-nho">Ẩn</button>
                  </form>
                <?php elseif ($c['NguoiAn'] === 'Chủ bình luận'): ?>
                  <form method="post" action="?id=<?= (int) $id ?>" class="form-dong">
                    <?= csrf_field() ?>
                    <input type="hidden" name="hanh_dong" value="mo_bl">
                    <?= $truongBL ?>
                    <button type="submit" class="btn btn-phu btn-nho">Mở lại</button>
                  </form>
                <?php else: ?>
                  <span class="meta">Bình luận này do quản trị viên ẩn, bạn không thể mở lại.</span>
                <?php endif; ?>

                <form method="post" action="?id=<?= (int) $id ?>" class="form-dong"
                      data-xac-nhan="Xóa bình luận này?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="hanh_dong" value="xoa_bl">
                  <?= $truongBL ?>
                  <button type="submit" class="btn btn-phu btn-nho">Xóa</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($loiSua && !$loiSuaDaHien): ?>
        <div class="loi"><?php foreach ($loiSua as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
      <?php endif; ?>

      <?= $p->links() ?>

      <div id="form-bl">
        <?php if ($loi): ?>
          <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($maND === null): ?>
          <p class="meta"><a href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập</a> để viết bình luận.</p>
        <?php else: ?>
        <form method="post" action="?id=<?= (int) $id ?>" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="hanh_dong" value="binhluan">
          <label for="noi_dung">Viết bình luận</label>
          <textarea id="noi_dung" name="noi_dung" maxlength="1000" required><?= e($noiDungBL) ?></textarea>
          <p><button type="submit" class="btn">Gửi bình luận</button></p>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>