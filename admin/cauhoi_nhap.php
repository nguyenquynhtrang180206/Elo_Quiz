<?php
// Nhập câu hỏi hàng loạt (UC13: A-22, A-43, A-44; 6.5, 6.8). Đặt tại: elo_quiz/admin/cauhoi_nhap.php
//   Bước 1 (tùy chọn): tải file Word lên -> hệ thống tách câu -> tải về file Excel kèm cột kiểm tra. KHÔNG lưu CSDL.
//   Bước 2: tải file Excel (mẫu hoặc xuất từ Word đã soát) lên -> kiểm tra từng dòng -> nếu mọi dòng hợp lệ
//           thì lưu tất cả trong MỘT giao dịch; có dòng lỗi/trùng thì không lưu gì và báo lỗi theo dòng.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CauHoiHelper.php';
require_once __DIR__ . '/../core/DocxReader.php';
require_once __DIR__ . '/../core/XlsxReader.php';
require_once __DIR__ . '/../core/XlsxWriter.php';
require_once __DIR__ . '/../core/NhapCauHoi.php';
Auth::requireAdmin(BASE_URL . '/admin/login.php');   // A-04

$pdo = Database::pdo();

// ---- A-22: tải file Excel mẫu ----
if (($_GET['mau'] ?? '') === '1') {
    XlsxWriter::tai('mau_import_cauhoi.xlsx', 'Câu hỏi', NhapCauHoi::COT, [[
        'Tên môn đúng như trong hệ thống', 'Giữa kỳ', 'Trung bình',
        'Nội dung câu hỏi ví dụ (xóa dòng này trước khi import)',
        'Phương án A', 'Phương án B', 'Phương án C', 'Phương án D', 'A', 'Giải thích (không bắt buộc)',
    ]]);
}

$dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai IN ('Đang mở','Đã ẩn') ORDER BY TenMon")->fetchAll();
$dsKT  = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();

$loi      = [];     // lỗi chung của lần gửi
$bangLoi  = [];     // lỗi theo dòng khi import Excel
$tongDong = 0;
$buoc     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $buoc = (string) ($_POST['buoc'] ?? '');

    // ======================= Bước 1: Word -> Excel (A-43), chưa lưu CSDL =======================
    if ($buoc === 'word') {
        $maMon = (int) ($_POST['mon'] ?? 0);
        $maKT  = (string) ($_POST['loai'] ?? '');
        $doKho = (string) ($_POST['dokho'] ?? '');

        $tenMon = '';
        foreach ($dsMon as $m) {
            if ((int) $m['MaMon'] === $maMon) {
                $tenMon = $m['TenMon'];
            }
        }
        $tenKT = 'Cả hai kỳ';
        if ($maKT !== '') {
            $tenKT = '';
            foreach ($dsKT as $k) {
                if ((string) $k['MaLoaiKT'] === $maKT) {
                    $tenKT = $k['TenLoaiKT'];
                }
            }
        }
        if ($tenMon === '') {
            $loi[] = 'Hãy chọn môn học.';
        }
        if ($tenKT === '') {
            $loi[] = 'Loại kỳ thi không hợp lệ.';
        }
        if (!in_array($doKho, CauHoiHelper::DO_KHO, true)) {
            $loi[] = 'Hãy chọn độ khó.';
        }

        $bang = null;
        if (!$loi) {
            $tam = null;
            try {
                $tam  = NhapCauHoi::nhanFileTai('file_word', 'docx', 'word/document.xml');
                $dong = DocxReader::doc($tam);
                $cau  = NhapCauHoi::tachWord($dong);
                if (!$cau) {
                    throw new RuntimeException('Không nhận diện được câu hỏi nào. Mỗi câu phải bắt đầu bằng "Câu 1." (hoặc "1.") và gõ nhãn A. B. C. D. bằng tay, không dùng đánh số tự động của Word.');
                }
                if (count($cau) > NhapCauHoi::TOI_DA_DONG) {
                    throw new RuntimeException('File có quá nhiều câu (tối đa ' . NhapCauHoi::TOI_DA_DONG . ' câu mỗi lần). Hãy chia nhỏ file.');
                }
                $bang = NhapCauHoi::bangTuWord($pdo, $cau, $tenMon, $tenKT, $doKho);
            } catch (RuntimeException $ex) {
                $loi[] = $ex->getMessage();
            } finally {
                if ($tam !== null && is_file($tam)) {
                    @unlink($tam);   // 6.8: xóa file tạm sau khi xử lý
                }
            }
        }
        // Gọi sau khối try vì XlsxWriter::tai() kết thúc chương trình (exit không chạy khối finally)
        if ($bang !== null) {
            XlsxWriter::tai('cauhoi_tu_word_' . date('Ymd_His') . '.xlsx', 'Câu hỏi',
                array_merge(NhapCauHoi::COT, NhapCauHoi::COT_THEM), $bang);
        }
    }

    // ======================= Bước 2: import Excel (A-22), lưu một giao dịch =======================
    if ($buoc === 'excel') {
        $tam    = null;
        $daLuu  = false;
        $soLuu  = 0;
        try {
            $tam   = NhapCauHoi::nhanFileTai('file_excel', 'xlsx', 'xl/workbook.xml');
            $tenGoc = mb_substr(basename((string) ($_FILES['file_excel']['name'] ?? 'file.xlsx')), 0, 100);
            $hang  = XlsxReader::doc($tam, NhapCauHoi::TOI_DA_DONG + 1);   // +1 cho dòng tiêu đề
            if (!$hang) {
                throw new RuntimeException('File Excel trống.');
            }
            NhapCauHoi::kiemTraTieuDe($hang[0]['o']);
            $du = array_slice($hang, 1);
            if (!$du) {
                throw new RuntimeException('File không có dòng dữ liệu nào dưới dòng tiêu đề.');
            }

            $kq       = NhapCauHoi::kiemTraBang($pdo, $du);
            $tongDong = count($kq);
            foreach ($kq as $r) {
                if ($r['trangThai'] !== 'Hợp lệ') {
                    $bangLoi[] = $r;
                }
            }

            if (!$bangLoi) {
                $pdo->beginTransaction();
                try {
                    foreach ($kq as $r) {
                        CauHoiHelper::chen($pdo, $r['sach']);
                    }
                    NhatKy::ghi('Import câu hỏi từ Excel', 'CAUHOI', 'Thêm ' . $tongDong . ' câu từ file ' . $tenGoc);
                    $pdo->commit();   // lỗi giữa chừng thì hoàn tác toàn bộ (6.5)
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Import câu hỏi lỗi: ' . $ex->getMessage());
                    throw new RuntimeException('Lỗi cơ sở dữ liệu, chưa lưu câu nào. Hãy thử lại.');
                }
                $daLuu = true;
                $soLuu = $tongDong;
            }
        } catch (RuntimeException $ex) {
            $loi[] = $ex->getMessage();
        } finally {
            if ($tam !== null && is_file($tam)) {
                @unlink($tam);
            }
        }
        // Chuyển hướng sau khối try vì redirect() gọi exit, mà exit không chạy khối finally (file tạm sẽ không bị xóa)
        if ($daLuu) {
            $_SESSION['ch_flash'] = ['ok', 'Đã import ' . $soLuu . ' câu hỏi.'];
            redirect(BASE_URL . '/admin/cauhoi.php');
        }
    }
}

$tieuDe  = 'Nhập câu hỏi từ Word/Excel';
$mucChon = 'cauhoi';
$cssTrang = 'cauhoi_nhap';
require __DIR__ . '/layout/header.php';
?>
<p><a href="<?= e(BASE_URL) ?>/admin/cauhoi.php">&laquo; Ngân hàng câu hỏi</a></p>
<h2>Nhập câu hỏi từ Word/Excel</h2>

<?php if ($loi): ?>
  <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if ($bangLoi): ?>
  <div class="loi">
    Không lưu câu nào vì có <?= count($bangLoi) ?> trên <?= $tongDong ?> dòng chưa đạt. Hãy sửa file Excel rồi tải lên lại.
  </div>
  <div class="card">
    <table>
      <tr><th>Dòng Excel</th><th>Trạng thái</th><th>Nội dung</th><th>Ghi chú lỗi</th></tr>
      <?php foreach (array_slice($bangLoi, 0, 100) as $r): ?>
        <tr>
          <td><?= (int) $r['so'] ?></td>
          <td><?= e($r['trangThai']) ?></td>
          <td><?= e(mb_substr((string) $r['o'][3], 0, 80)) ?></td>
          <td><?= e(implode('; ', array_unique($r['loi']))) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if (count($bangLoi) > 100): ?>
      <p class="nhan-phu">Chỉ hiển thị 100 dòng lỗi đầu tiên.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" action="" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="buoc" value="word">
  <h3>Bước 1 (tùy chọn): tách câu hỏi từ file Word</h3>
  <p class="nhan-phu">
    Mỗi câu bắt đầu bằng <b>Câu 1.</b> (hoặc <b>1.</b>), các phương án <b>A. B. C. D.</b> (gõ bằng tay, tối đa 4),
    dòng <b>Đáp án: B</b> (hoặc đặt dấu <b>*</b> trước phương án đúng), dòng <b>Giải thích:</b> không bắt buộc.
    Bước này <b>chưa lưu</b> gì vào hệ thống: bạn nhận về một file Excel có thêm cột Trạng thái kiểm tra,
    Ghi chú lỗi và Dòng nguồn trong file Word để soát rồi import ở Bước 2.
  </p>

  <label for="mon">Môn học (áp dụng cho mọi câu trong file)</label>
  <select id="mon" name="mon" required>
    <option value="0">-- Chọn môn --</option>
    <?php foreach ($dsMon as $m): ?>
      <option value="<?= (int) $m['MaMon'] ?>"<?= (int) ($_POST['mon'] ?? 0) === (int) $m['MaMon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="loai">Loại kỳ thi</label>
  <select id="loai" name="loai">
    <option value="">Dùng được cho cả hai kỳ</option>
    <?php foreach ($dsKT as $k): ?>
      <option value="<?= (int) $k['MaLoaiKT'] ?>"<?= (string) ($_POST['loai'] ?? '') === (string) $k['MaLoaiKT'] ? ' selected' : '' ?>><?= e($k['TenLoaiKT']) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="dokho">Độ khó</label>
  <select id="dokho" name="dokho">
    <?php foreach (CauHoiHelper::DO_KHO as $d): ?>
      <option value="<?= e($d) ?>"<?= ((string) ($_POST['dokho'] ?? 'Trung bình')) === $d ? ' selected' : '' ?>><?= e($d) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="file_word">File Word (.docx, tối đa 2 MB, tối đa <?= NhapCauHoi::TOI_DA_DONG ?> câu)</label>
  <input type="file" id="file_word" name="file_word" accept=".docx" required>

  <p><button type="submit" class="btn">Tách câu và tải file Excel</button></p>
</form>

<form method="post" action="" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="buoc" value="excel">
  <h3>Bước 2: import từ file Excel</h3>
  <p class="nhan-phu">
    Dùng <a href="?mau=1">file Excel mẫu</a> hoặc file Excel đã xuất từ Bước 1 (các cột kiểm tra thêm vào được bỏ qua).
    Hệ thống kiểm tra từng dòng trước khi lưu. Chỉ lưu khi <b>mọi dòng đều hợp lệ</b>; câu trùng nội dung với câu đã có
    trong cùng môn (hoặc trùng ngay trong file) không được thêm. Import chỉ thêm câu mới, không ghi đè câu cũ.
  </p>

  <label for="file_excel">File Excel (.xlsx, tối đa 2 MB, tối đa <?= NhapCauHoi::TOI_DA_DONG ?> dòng dữ liệu)</label>
  <input type="file" id="file_excel" name="file_excel" accept=".xlsx" required>

  <p><button type="submit" class="btn">Kiểm tra và import</button></p>
</form>
<?php require __DIR__ . '/layout/footer.php'; ?>