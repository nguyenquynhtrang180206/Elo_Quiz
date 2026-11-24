<?php
// Sửa bài đăng của chính mình (C-43). Đặt tại: elo_quiz/client/suabai.php
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::requireClient(BASE_URL . '/client/dangnhap.php');
$pdo  = Database::pdo();
$id   = (int) ($_GET['id'] ?? 0);
$coLoai = CongDongHelper::coCotLoaiBai();

// Chỉ sửa được bài của mình, đang "Hiển thị", và trong vòng 15 phút kể từ lúc đăng
$st = $pdo->prepare(
    "SELECT b.MaBaiDang, b.MaMon, b.TieuDe, b.NoiDung, b.AnhDinhKem, m.TenMon,
            " . ($coLoai ? 'b.LoaiBai' : "'" . CongDongHelper::LOAI_MAC_DINH . "' AS LoaiBai") . ",
            TIMESTAMPDIFF(SECOND, b.NgayDang, NOW()) AS GiayTuDang
       FROM BAIDANG b LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
      WHERE b.MaBaiDang = ? AND b.MaND = ? AND b.TrangThai = 'Hiển thị'"
);
$st->execute([$id, $maND]);
$bai = $st->fetch();
$hetHan = $bai && !CongDongHelper::conHanSua((int) $bai['GiayTuDang']);
$phutCon = $bai && !$hetHan
    ? max(1, (int) ceil((CongDongHelper::SUA_TOI_DA_PHUT * 60 - (int) $bai['GiayTuDang']) / 60))
    : 0;

$monDangMo = CongDongHelper::monDangMo();
// Bài đang gắn môn đã bị ẩn: vẫn giữ môn đó trong danh sách để không vô tình bỏ gắn
if ($bai && $bai['MaMon'] && !isset($monDangMo[(int) $bai['MaMon']])) {
    $monDangMo[(int) $bai['MaMon']] = $bai['TenMon'] . ' (đã ẩn)';
}

$loi = [];
$tieuDeBai  = $bai['TieuDe'] ?? '';
$noiDungBai = $bai['NoiDung'] ?? '';
$monChon    = $bai ? (string) ($bai['MaMon'] ?? '') : '';
$loaiChon   = $bai['LoaiBai'] ?? CongDongHelper::LOAI_MAC_DINH;

$quaLon = $bai && !$hetHan && CongDongHelper::postQuaLon();   // ảnh làm yêu cầu vượt post_max_size: $_POST rỗng
if ($quaLon) {
    $loi[] = 'Ảnh quá lớn (tối đa ' . CongDongHelper::gioiHanAnhChu() . '), bài chưa được lưu. Hãy chọn ảnh nhỏ hơn rồi lưu lại.';
}

if ($bai && !$hetHan && !$quaLon && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tieuDeBai  = trim((string) ($_POST['tieu_de'] ?? ''));
    $noiDungBai = trim((string) ($_POST['noi_dung'] ?? ''));
    $monChon    = (string) ($_POST['mon'] ?? '');
    $loaiChon   = CongDongHelper::kiemTraLoai($_POST['loai'] ?? '');

    [$loi, $maMon] = CongDongHelper::kiemTraBai($tieuDeBai, $noiDungBai, $monChon, $monDangMo);

    // Ảnh đính kèm: chọn ảnh mới thì thay ảnh cũ; tích "Xóa ảnh" (không chọn ảnh mới) thì gỡ ảnh; còn lại giữ nguyên
    $anhCu = $bai['AnhDinhKem'] ?? null;
    $anhMoi = $anhCu;
    $anhVuaLuu = null;
    [$loiAnh, $duoiAnh] = CongDongHelper::kiemTraAnh($_FILES['anh_dinh_kem'] ?? null);
    $loi = array_merge($loi, $loiAnh);

    if (!$loi) {
        if ($duoiAnh !== null) {
            $anhVuaLuu = CongDongHelper::luuAnh($_FILES['anh_dinh_kem'], $duoiAnh, $cfg['upload_dir']);
            if ($anhVuaLuu === null) {
                $loi[] = 'Không lưu được ảnh lên máy chủ. Kiểm tra quyền ghi thư mục uploads/baidang.';
            } else {
                $anhMoi = $anhVuaLuu;
            }
        } elseif (!empty($_POST['xoa_anh'])) {
            $anhMoi = null;
        }
    }

    if (!$loi) {
        $up = $pdo->prepare(
            "UPDATE BAIDANG SET TieuDe = ?, NoiDung = ?, MaMon = ?, AnhDinhKem = ?" . ($coLoai ? ', LoaiBai = ?' : '') . "
              WHERE MaBaiDang = ? AND MaND = ? AND TrangThai = 'Hiển thị'
                AND NgayDang >= DATE_SUB(NOW(), INTERVAL " . CongDongHelper::SUA_TOI_DA_PHUT . " MINUTE)"
        );
        $up->execute($coLoai
            ? [$tieuDeBai, $noiDungBai, $maMon, $anhMoi, $loaiChon, $id, $maND]
            : [$tieuDeBai, $noiDungBai, $maMon, $anhMoi, $id, $maND]);

        if ($up->rowCount() >= 1 || $anhMoi === $anhCu) {
            // Cập nhật được: dọn file ảnh cũ nếu đã bị thay hoặc gỡ
            if ($anhMoi !== $anhCu) {
                CongDongHelper::xoaFileAnh($anhCu, $cfg['upload_dir']);
            }
        } else {
            // Không cập nhật được (hết hạn sửa...): bỏ file ảnh vừa tải lên để khỏi thành file mồ côi
            CongDongHelper::xoaFileAnh($anhVuaLuu, $cfg['upload_dir']);
        }
        CongDongHelper::flash('Đã cập nhật bài đăng.');
        redirect(BASE_URL . '/client/baidang.php?id=' . $id);
    }
}

$tieuDe = 'Sửa bài đăng';
$cssTrang = 'suabai';
require __DIR__ . '/layout/header.php';
?>
<div class="container khung-suabai">
  <?php if (!$bai): ?>
    <div class="card">Không tìm thấy bài đăng, hoặc bạn không có quyền sửa bài này.</div>
    <p><a href="<?= e(BASE_URL) ?>/client/congdong.php">&laquo; Về bảng tin</a></p>
  <?php elseif ($hetHan): ?>
    <div class="card">Đã quá <?= CongDongHelper::SUA_TOI_DA_PHUT ?> phút kể từ lúc đăng nên bạn không thể sửa bài này nữa. Bạn vẫn có thể ẩn hoặc xóa bài.</div>
    <p><a href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $id ?>">&laquo; Về bài đăng</a></p>
  <?php else: ?>
    <div class="card">
      <h2 class="tieu-de-sua">Sửa bài đăng</h2>
      <p class="meta">Bạn chỉ được sửa bài trong <?= CongDongHelper::SUA_TOI_DA_PHUT ?> phút kể từ lúc đăng (còn khoảng <?= $phutCon ?> phút).</p>
      <?php if ($loi): ?>
        <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
      <?php endif; ?>
      <form method="post" action="?id=<?= (int) $id ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <label for="tieu_de">Tiêu đề</label>
        <input type="text" id="tieu_de" name="tieu_de" maxlength="200" value="<?= e($tieuDeBai) ?>" required>

        <label for="mon">Môn học (không bắt buộc)</label>
        <select id="mon" name="mon">
          <option value="">Không gắn môn</option>
          <?php foreach ($monDangMo as $mid => $ten): ?>
            <option value="<?= (int) $mid ?>" <?= (string) $mid === $monChon ? 'selected' : '' ?>><?= e($ten) ?></option>
          <?php endforeach; ?>
        </select>

        <?php if ($coLoai): ?>
        <label for="loai">Loại bài</label>
        <select id="loai" name="loai">
          <?php foreach (CongDongHelper::LOAI_BAI as $l): ?>
            <option value="<?= e($l) ?>" <?= $l === $loaiChon ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <label for="noi_dung">Nội dung</label>
        <textarea id="noi_dung" name="noi_dung" maxlength="5000" required><?= e($noiDungBai) ?></textarea>

        <label for="anh_dinh_kem">Ảnh đính kèm (không bắt buộc, JPG/PNG/WEBP, tối đa <?= e(CongDongHelper::gioiHanAnhChu()) ?>)</label>
        <input type="hidden" name="xoa_anh" id="xoa_anh_hidden" value="0">
        <?php $srcAnhHienTai = !empty($bai['AnhDinhKem']) ? BASE_URL . '/' . ltrim((string) $bai['AnhDinhKem'], '/') : ''; ?>
        <div class="cd-img-preview-box" id="previewBoxSuaBai"<?= $srcAnhHienTai !== '' ? '' : ' style="display:none;"' ?>>
          <div class="cd-img-preview-wrap">
            <img id="previewImgSuaBai" src="<?= e($srcAnhHienTai) ?>" alt="Xem trước ảnh đính kèm">
            <button type="button" class="cd-img-remove-btn" id="btnXoaAnhSuaBai" title="Xóa ảnh này" aria-label="Xóa ảnh này">&times;</button>
          </div>
          <span class="cd-img-preview-name" id="previewNameSuaBai"><?= $srcAnhHienTai !== '' ? 'Ảnh hiện tại của bài viết (bấm dấu × để gỡ ảnh)' : '' ?></span>
        </div>
        <input type="file" id="anh_dinh_kem" name="anh_dinh_kem" accept="image/jpeg,image/png,image/webp" data-toi-da="<?= (int) CongDongHelper::gioiHanAnhByte() ?>" data-toi-da-chu="<?= e(CongDongHelper::gioiHanAnhChu()) ?>">
        <script>
          (function () {
            var i = document.getElementById('anh_dinh_kem');
            var box = document.getElementById('previewBoxSuaBai');
            var img = document.getElementById('previewImgSuaBai');
            var nameEl = document.getElementById('previewNameSuaBai');
            var btnXoa = document.getElementById('btnXoaAnhSuaBai');
            var xoaHidden = document.getElementById('xoa_anh_hidden');
            var objUrl = null;
            if (!i || !box || !img) { return; }

            function donUrl() {
              if (objUrl) { URL.revokeObjectURL(objUrl); objUrl = null; }
            }

            i.addEventListener('change', function () {
              donUrl();
              var f = i.files && i.files[0];
              var max = parseInt(i.getAttribute('data-toi-da') || '3145728', 10);
              var maxChu = i.getAttribute('data-toi-da-chu') || '3 MB';
              if (!f) { return; }
              if (max && f.size > max) {
                alert('Ảnh quá lớn, tối đa ' + maxChu + '. Hãy chọn ảnh nhỏ hơn.');
                i.value = '';
                return;
              }
              objUrl = URL.createObjectURL(f);
              img.src = objUrl;
              if (nameEl) { nameEl.textContent = f.name + ' (' + (f.size / 1048576).toFixed(2) + ' MB)'; }
              if (xoaHidden) { xoaHidden.value = '0'; }
              box.style.display = 'inline-flex';
            });

            if (btnXoa) {
              btnXoa.addEventListener('click', function () {
                donUrl();
                i.value = '';
                img.src = '';
                box.style.display = 'none';
                if (xoaHidden) { xoaHidden.value = '1'; }
              });
            }
          })();
        </script>

        <p><button type="submit" class="btn">Lưu</button>
           <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/baidang.php?id=<?= (int) $id ?>">Hủy</a></p>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>