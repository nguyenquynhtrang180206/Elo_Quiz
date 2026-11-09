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

            if ($bangLoi) {
                // Có dòng không hợp lệ: không lưu câu nào, ghi nhật ký là thao tác thất bại (chưa mở giao dịch nên không bị hoàn tác)
                NhatKy::ghiThatBai('Import câu hỏi từ Excel', 'CAUHOI',
                    count($bangLoi) . '/' . $tongDong . ' dòng không hợp lệ, chưa lưu câu nào. File ' . $tenGoc);
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
            // Đã rollBack (nếu có) ở trên nên bản ghi thất bại này được giữ lại
            NhatKy::ghiThatBai('Import câu hỏi từ Excel', 'CAUHOI', mb_substr($ex->getMessage(), 0, 300));
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

// ---- Dữ liệu cho giao diện (chỉ để hiển thị, không ảnh hưởng xử lý phía trên) ----
$kq       = $kq ?? [];
$soLoi    = 0;
$soTrung  = 0;
foreach ($kq as $r) {
    if ($r['trangThai'] === 'Lỗi') {
        $soLoi++;
    } elseif ($r['trangThai'] === 'Trùng') {
        $soTrung++;
    }
}
$soHopLe     = max(0, $tongDong - $soLoi - $soTrung);
$dsHienThi   = array_slice($bangLoi, 0, 100);
$coTrongBang = [];
foreach ($dsHienThi as $r) {
    $coTrongBang[(int) $r['so']] = true;
}
$macDinhLuoi = 'Rê chuột vào ô đỏ hoặc vàng để xem lỗi của dòng đó.';
$dokhoChon = (string) ($_POST['dokho'] ?? 'Trung bình');
if (!in_array($dokhoChon, CauHoiHelper::DO_KHO, true)) {
    $dokhoChon = 'Trung bình';
}
$hangDoKho = ['Dễ' => ['dong', 'Đồng'], 'Trung bình' => ['bac', 'Bạc'], 'Khó' => ['vang', 'Vàng']];

// Mô tả từng cột Excel (theo đúng thứ tự NhapCauHoi::COT): [bắt buộc?, ghi chú]
$ghiChuCot = [
    ['bb', 'Đúng tên môn trong hệ thống.'],
    ['',   'Để trống hoặc ghi "Cả hai kỳ" nếu dùng cho cả hai kỳ.'],
    ['bb', 'Dễ, Trung bình hoặc Khó.'],
    ['bb', 'Nội dung câu hỏi.'],
    ['bb', 'Cần ít nhất hai phương án (A và B).'],
    ['bb', 'Cần ít nhất hai phương án (A và B).'],
    ['',   'Không bắt buộc.'],
    ['',   'Không bắt buộc.'],
    ['bb', 'Chỉ nhận A, B, C hoặc D.'],
    ['',   'Không bắt buộc.'],
];

$tieuDe   = 'Nhập câu hỏi từ Word/Excel';
$mucChon  = 'cauhoi';
$cssTrang = 'cauhoi_nhap';
$jsTrang  = 'cauhoi_nhap';

// Biểu tượng nội tuyến (kiểu Lucide, không cần mạng)
$bt = [
    'tai'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/>',
    'xuong'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
    'word'     => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
    'excel'    => '<path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/>',
    'xong'     => '<path d="M20 6 9 17l-5-5"/>',
    'dong'     => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    'huyhieu'  => '<circle cx="12" cy="15" r="6"/><path d="m8.2 10-2.7-7h5l1.5 4"/><path d="m15.8 10 2.7-7h-5L12 7"/>',
    'quaylai'  => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
    'kq'       => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'sach'     => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
];
$svg = static fn (string $k, int $px = 18, int|float $net = 2): string =>
    '<svg width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $net
    . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $bt[$k] . '</svg>';

require __DIR__ . '/layout/header.php';
?>
<div class="ci-trang">

  <div class="ci-dau">
    <span class="ci-dau-icon" aria-hidden="true"><?= $svg('tai', 28) ?></span>
    <div class="ci-dau-chu">
      <h2>Nhập câu hỏi từ Word/Excel</h2>
      <p>Thêm hàng loạt câu hỏi vào ngân hàng. Tối đa <?= (int) NhapCauHoi::TOI_DA_DONG ?> câu mỗi lần, mỗi file không quá 2 MB.</p>
    </div>
    <div class="ci-dau-nut">
      <a class="ci-nut ci-nut-vien" href="?mau=1"><?= $svg('xuong', 16) ?>Tải Excel mẫu</a>
      <a class="ci-nut ci-nut-vien" href="<?= e(BASE_URL) ?>/admin/cauhoi.php"><?= $svg('quaylai', 16) ?>Ngân hàng câu hỏi</a>
    </div>
  </div>

  <details class="ci-khoi ci-quytac"<?= $loi ? ' open' : '' ?>>
    <summary><?= $svg('sach', 18) ?>Quy tắc định dạng file</summary>
    <div class="ci-quytac-than">
      <div>
        <p class="dam" style="font-weight:700;margin-bottom:8px">Cách trình bày trong file Word</p>
        <div class="ci-giay">
          <b>Câu 1.</b> Thủ đô của Việt Nam là gì?<br>
          A. Huế<br>
          B. Hà Nội<br>
          C. Đà Nẵng<br>
          D. Cần Thơ<br>
          <span class="ci-dung">Đáp án: B</span><br>
          Giải thích: Hà Nội là thủ đô của nước Việt Nam.
        </div>
        <p class="nhan-phu ci-giay-ct">
          Mỗi câu bắt đầu bằng <b>Câu 1.</b> (hoặc <b>1.</b>). Gõ nhãn A. B. C. D. bằng tay (tối đa 4 phương án), không dùng
          đánh số tự động của Word. Có thể đặt dấu <b>*</b> trước phương án đúng thay cho dòng Đáp án. Dòng Giải thích không bắt buộc.
        </p>
      </div>
      <div>
        <p class="dam" style="font-weight:700;margin-bottom:8px">Các cột của file Excel</p>
        <table class="ci-cot">
          <tr><th>Cột</th><th>Ghi chú</th></tr>
          <?php foreach (NhapCauHoi::COT as $i => $ten): ?>
            <tr>
              <td><?= e($ten) ?><?= ($ghiChuCot[$i][0] ?? '') === 'bb' ? ' <span class="bb" title="Bắt buộc">*</span>' : '' ?></td>
              <td><?= e($ghiChuCot[$i][1] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="nhan-phu ci-giay-ct"><span class="bb" style="color:#b3262c;font-weight:800">*</span> là cột bắt buộc. Tên môn phải đúng như trong hệ thống.</p>
      </div>
    </div>
  </details>

  <?php if ($loi): ?>
    <div class="ci-thongbao loi" role="alert"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($bangLoi): ?>
  <section class="ci-khoi ci-ketqua" id="ketqua">
    <h3 class="ci-tieude"><?= $svg('kq', 20) ?>Kết quả kiểm tra file Excel</h3>
    <div class="loi" role="alert">
      Không lưu câu nào vì có <?= count($bangLoi) ?> trên <?= (int) $tongDong ?> dòng chưa đạt. Hãy sửa file Excel rồi tải lên lại.
    </div>

    <div class="ci-so">
      <span>Tổng <b><?= (int) $tongDong ?></b></span>
      <span class="s-ok">Hợp lệ <b><?= (int) $soHopLe ?></b></span>
      <span class="s-trung">Trùng <b><?= (int) $soTrung ?></b></span>
      <span class="s-loi">Lỗi <b><?= (int) $soLoi ?></b></span>
    </div>

    <div class="ci-o-luoi" id="luoi" data-mac-dinh="<?= e($macDinhLuoi) ?>" role="group" aria-label="Kết quả từng dòng Excel">
      <?php foreach ($kq as $r): ?>
        <?php
          $so  = (int) $r['so'];
          $tt  = (string) $r['trangThai'];
          $lop = $tt === 'Hợp lệ' ? 'ci-o-ok' : ($tt === 'Trùng' ? 'ci-o-trung' : 'ci-o-loi');
          $tip = 'Dòng ' . $so . ': ' . ($tt === 'Hợp lệ' ? 'hợp lệ.' : implode('; ', array_unique($r['loi'])));
        ?>
        <?php if ($tt !== 'Hợp lệ' && isset($coTrongBang[$so])): ?>
          <a class="ci-o <?= $lop ?>" href="#dong-<?= $so ?>" data-tip="<?= e($tip) ?>" aria-label="<?= e($tip) ?>"></a>
        <?php else: ?>
          <span class="ci-o <?= $lop ?>" data-tip="<?= e($tip) ?>"></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <p class="ci-thongtin" id="thongtin" aria-live="polite"><?= e($macDinhLuoi) ?></p>
    <div class="ci-chuthich">
      <span><i class="ci-o-ok"></i>Hợp lệ</span>
      <span><i class="ci-o-trung"></i>Trùng</span>
      <span><i class="ci-o-loi"></i>Lỗi</span>
      <span>Bấm vào ô đỏ hoặc vàng để nhảy tới dòng trong bảng bên dưới.</span>
    </div>

    <div class="ci-cuon">
      <table class="ci-bang">
        <tr><th>Dòng Excel</th><th>Trạng thái</th><th>Nội dung</th><th>Ghi chú lỗi</th></tr>
        <?php foreach ($dsHienThi as $r): ?>
          <tr id="dong-<?= (int) $r['so'] ?>">
            <td class="ci-td-dong"><?= (int) $r['so'] ?></td>
            <td><span class="ci-pill <?= $r['trangThai'] === 'Trùng' ? 'ci-pill-trung' : 'ci-pill-loi' ?>"><?= e((string) $r['trangThai']) ?></span></td>
            <td><?= e(mb_substr((string) $r['o'][3], 0, 80)) ?></td>
            <td class="ci-td-loi"><?= e(implode('; ', array_unique($r['loi']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php if (count($bangLoi) > 100): ?>
      <p class="nhan-phu ci-ghichu">Chỉ hiển thị 100 dòng lỗi đầu tiên.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <div class="ci-khoi ci-thietlap">
    <div>
      <h3 class="ci-tieude"><?= $svg('word', 20) ?>Thiết lập cho file Word</h3>
      <p class="nhan-phu">Áp dụng cho mọi câu trong file Word ở bước 1. Ở bước 2, môn học và loại kỳ thi được ghi ngay trong từng dòng Excel.</p>
    </div>
    <div class="ci-luoi-2">
      <div>
        <label for="mon">Môn học</label>
        <select id="mon" name="mon" form="formWord" required>
          <option value="0">-- Chọn môn --</option>
          <?php foreach ($dsMon as $m): ?>
            <option value="<?= (int) $m['MaMon'] ?>"<?= (int) ($_POST['mon'] ?? 0) === (int) $m['MaMon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="loai">Loại kỳ thi</label>
        <select id="loai" name="loai" form="formWord">
          <option value="">Dùng được cho cả hai kỳ</option>
          <?php foreach ($dsKT as $k): ?>
            <option value="<?= (int) $k['MaLoaiKT'] ?>"<?= (string) ($_POST['loai'] ?? '') === (string) $k['MaLoaiKT'] ? ' selected' : '' ?>><?= e($k['TenLoaiKT']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="ci-hai-cot">

    <!-- ===== Thẻ 1: Word -> Excel ===== -->
    <form method="post" action="" enctype="multipart/form-data" class="ci-khoi" id="formWord" data-ci-form="word">
      <?= csrf_field() ?>
      <input type="hidden" name="buoc" value="word">
      <h3 class="ci-tieude"><?= $svg('word', 20) ?>Tách câu từ file Word<span class="ci-nhan-tuy">Tùy chọn</span></h3>
      <p class="nhan-phu">
        Bước này <b>chưa lưu</b> gì vào hệ thống. Bạn nhận về một file Excel kèm cột Trạng thái kiểm tra, Ghi chú lỗi và
        Dòng nguồn trong file Word để soát rồi import ở bước 2.
      </p>

      <div>
        <label id="nhanDoKho">Độ khó</label>
        <div class="ci-hang" role="radiogroup" aria-labelledby="nhanDoKho">
          <?php foreach (CauHoiHelper::DO_KHO as $d): ?>
            <?php $h = $hangDoKho[$d] ?? ['bac', '']; ?>
            <label class="ci-hang-o">
              <input type="radio" name="dokho" value="<?= e($d) ?>"<?= $dokhoChon === $d ? ' checked' : '' ?>>
              <span class="ci-hang-the hang-<?= e($h[0]) ?>">
                <?= $svg('huyhieu', 26) ?>
                <strong><?= e($d) ?></strong>
                <small>Hạng <?= e($h[1]) ?></small>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <label class="ci-tha" for="file_word" data-duoi="docx" data-max="<?= (int) NhapCauHoi::TOI_DA_BYTE ?>">
        <input type="file" id="file_word" name="file_word" accept=".docx" required>
        <span class="ci-cong"><span class="ci-i-up"><?= $svg('tai', 30) ?></span><span class="ci-i-ok"><?= $svg('xong', 30, 2.6) ?></span></span>
        <span class="ci-tha-chu">Thả file Word vào cổng</span>
        <span class="ci-tha-phu">hoặc bấm để chọn · .docx · tối đa 2 MB · <?= (int) NhapCauHoi::TOI_DA_DONG ?> câu</span>
        <span class="ci-file-tt">
          <span class="ci-file-ten"></span><span class="ci-file-kt"></span>
          <button type="button" class="ci-bo-chon" aria-label="Bỏ file đã chọn"><?= $svg('dong', 14, 2.4) ?></button>
        </span>
        <span class="ci-nhac-loi" role="alert" hidden></span>
      </label>

      <button type="submit" class="ci-nut ci-nut-vien ci-block"><?= $svg('xuong', 16) ?>Tách câu và tải file Excel</button>
    </form>

    <!-- ===== Thẻ 2: Excel -> CSDL ===== -->
    <form method="post" action="" enctype="multipart/form-data" class="ci-khoi ci-the-chinh" data-ci-form="excel">
      <?= csrf_field() ?>
      <input type="hidden" name="buoc" value="excel">
      <h3 class="ci-tieude"><?= $svg('excel', 20) ?>Import từ file Excel<span class="ci-nhan-tuy chinh">Bước chính</span></h3>
      <p class="nhan-phu">
        Dùng <a href="?mau=1">file Excel mẫu</a> hoặc file Excel xuất từ bước 1 (các cột kiểm tra thêm vào được bỏ qua).
        Hệ thống kiểm tra từng dòng trước khi lưu.
      </p>

      <ul class="ci-quy">
        <li><?= $svg('xong', 13, 3) ?>Kiểm tra từng dòng</li>
        <li><?= $svg('xong', 13, 3) ?>Lưu tất cả hoặc không lưu gì</li>
        <li><?= $svg('xong', 13, 3) ?>Chỉ thêm mới, không ghi đè</li>
        <li><?= $svg('xong', 13, 3) ?>Câu trùng nội dung bị từ chối</li>
      </ul>

      <label class="ci-tha" for="file_excel" data-duoi="xlsx" data-max="<?= (int) NhapCauHoi::TOI_DA_BYTE ?>">
        <input type="file" id="file_excel" name="file_excel" accept=".xlsx" required>
        <span class="ci-cong"><span class="ci-i-up"><?= $svg('tai', 30) ?></span><span class="ci-i-ok"><?= $svg('xong', 30, 2.6) ?></span></span>
        <span class="ci-tha-chu">Thả file Excel vào cổng</span>
        <span class="ci-tha-phu">hoặc bấm để chọn · .xlsx · tối đa 2 MB · <?= (int) NhapCauHoi::TOI_DA_DONG ?> dòng dữ liệu</span>
        <span class="ci-file-tt">
          <span class="ci-file-ten"></span><span class="ci-file-kt"></span>
          <button type="button" class="ci-bo-chon" aria-label="Bỏ file đã chọn"><?= $svg('dong', 14, 2.4) ?></button>
        </span>
        <span class="ci-nhac-loi" role="alert" hidden></span>
      </label>

      <button type="submit" class="ci-nut ci-nut-chinh ci-block"><?= $svg('kq', 16) ?>Kiểm tra và import</button>
    </form>
  </div>

</div>
<?php require __DIR__ . '/layout/footer.php'; ?>