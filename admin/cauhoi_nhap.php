<?php
// Nhập câu hỏi hàng loạt từ Word/Excel kèm bảng xem trước và sửa lỗi trên web.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');

$pdo = Database::pdo();

// Tải file mẫu Excel hoặc Word.
if (($_GET['mau'] ?? '') === '1') {
    XlsxWriter::tai('mau_import_cauhoi.xlsx', 'Câu hỏi', NhapCauHoi::COT, [[
        'Tên môn đúng như trong hệ thống', 'Giữa kỳ', 'Trung bình',
        'Nội dung câu hỏi ví dụ (xóa dòng này trước khi import)',
        'Phương án A', 'Phương án B', 'Phương án C', 'Phương án D', 'A', 'Giải thích (không bắt buộc)',
    ]]);
}
if (($_GET['mau_word'] ?? '') === '1') {
    DocxReader::taiMau('mau_de_trac_nghiem_word.docx');
}

$dsMon = $pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai IN ('Đang mở','Đã ẩn') ORDER BY TenMon")->fetchAll();
$dsKT  = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();

$loi       = [];
$kq        = [];
$tongDong  = 0;
$buoc      = '';
$nguonTen  = '';
$nguonLoai = '';     // 'word' | 'excel' | 'tructiep'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $buoc = (string) ($_POST['buoc'] ?? '');

    // Luồng 1: Nhập từ file Word (.docx).
    if ($buoc === 'word') {
        $maMon   = (int) ($_POST['mon'] ?? 0);
        $maKT    = (string) ($_POST['loai'] ?? '');
        $doKho   = (string) ($_POST['dokho'] ?? 'Trung bình');
        $cheDoW  = (string) ($_POST['che_do_word'] ?? 'xem'); // 'xem' hoặc 'xuat'

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
            $loi[] = 'Hãy chọn Môn học cho đề thi Word.';
        }
        if ($tenKT === '') {
            $loi[] = 'Loại kỳ thi không hợp lệ.';
        }
        if (!in_array($doKho, CauHoiHelper::DO_KHO, true)) {
            $loi[] = 'Hãy chọn độ khó mặc định.';
        }

        $bangExcel = null;
        if (!$loi) {
            $tam = null;
            try {
                $tam      = NhapCauHoi::nhanFileTai('file_word', 'docx', 'word/document.xml');
                $nguonTen = mb_substr(basename((string) ($_FILES['file_word']['name'] ?? 'de_thi.docx')), 0, 100);
                $dongDocx = DocxReader::doc($tam);
                $cauWord  = NhapCauHoi::tachWord($dongDocx);
                if (!$cauWord) {
                    throw new RuntimeException(
                        'Không tìm thấy câu hỏi nào trong file Word này. '
                        . 'Mẹo khắc phục nhanh: Mỗi câu cần bắt đầu bằng "Câu 1." (hoặc "1.") và có các phương án "A. ", "B. ", "C. ", "D. ". '
                        . 'Bạn có thể bấm nút "Tải Word mẫu (.docx)" ở góc trên bên phải để xem mẫu chuẩn.'
                    );
                }
                if (count($cauWord) > NhapCauHoi::TOI_DA_DONG) {
                    throw new RuntimeException('File có quá nhiều câu (' . count($cauWord) . ' câu, tối đa ' . NhapCauHoi::TOI_DA_DONG . ' câu mỗi lần). Hãy chia nhỏ file.');
                }

                if ($cheDoW === 'xuat') {
                    $bangExcel = NhapCauHoi::bangTuWord($pdo, $cauWord, $tenMon, $tenKT, $doKho);
                } else {
                    $dongChuan = NhapCauHoi::chuanBiDongTuWord($cauWord, $tenMon, $tenKT, $doKho);
                    $kq        = NhapCauHoi::kiemTraBang($pdo, $dongChuan);
                    $tongDong  = count($kq);
                    $nguonLoai = 'word';
                }
            } catch (RuntimeException $ex) {
                $loi[] = $ex->getMessage();
            } finally {
                if ($tam !== null && is_file($tam)) {
                    @unlink($tam);   // Dọn file tạm sau khi xử lý.
                }
            }
        }
        if ($bangExcel !== null) {
            XlsxWriter::tai('cauhoi_tu_word_' . date('Ymd_His') . '.xlsx', 'Câu hỏi',
                array_merge(NhapCauHoi::COT, NhapCauHoi::COT_THEM), $bangExcel);
        }
    }

    // Luồng 2: Nhập từ file Excel (.xlsx).
    if ($buoc === 'excel') {
        $tam       = null;
        $daLuu     = false;
        $soLuu     = 0;
        $boQuaLoi  = !empty($_POST['bo_qua_trung']);
        try {
            $tam      = NhapCauHoi::nhanFileTai('file_excel', 'xlsx', 'xl/workbook.xml');
            $nguonTen = mb_substr(basename((string) ($_FILES['file_excel']['name'] ?? 'file.xlsx')), 0, 100);
            $hang     = XlsxReader::doc($tam, NhapCauHoi::TOI_DA_DONG + 1);
            if (!$hang) {
                throw new RuntimeException('File Excel đang trống.');
            }
            NhapCauHoi::kiemTraTieuDe($hang[0]['o']);
            $du = array_slice($hang, 1);
            if (!$du) {
                throw new RuntimeException('File không có dòng dữ liệu nào dưới dòng tiêu đề.');
            }

            $kq        = NhapCauHoi::kiemTraBang($pdo, $du);
            $tongDong  = count($kq);
            $nguonLoai = 'excel';

            $dsHopLe   = [];
            $coDongLoi = false;
            foreach ($kq as $r) {
                if ($r['trangThai'] === 'Hợp lệ' && $r['sach'] !== null) {
                    $dsHopLe[] = $r['sach'];
                } else {
                    $coDongLoi = true;
                }
            }

            if ((!$coDongLoi || ($boQuaLoi && count($dsHopLe) > 0)) && count($dsHopLe) > 0) {
                $pdo->beginTransaction();
                try {
                    foreach ($dsHopLe as $sach) {
                        CauHoiHelper::chen($pdo, $sach);
                    }
                    $moTa = 'Thêm ' . count($dsHopLe) . '/' . $tongDong . ' câu từ file Excel ' . $nguonTen;
                    NhatKy::ghi('Import câu hỏi từ Excel', 'CAUHOI', $moTa);
                    $pdo->commit();
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Import câu hỏi lỗi: ' . $ex->getMessage());
                    throw new RuntimeException('Lỗi cơ sở dữ liệu, chưa lưu câu nào. Hãy thử lại.');
                }
                $daLuu = true;
                $soLuu = count($dsHopLe);
            } else {
                NhatKy::ghiThatBai('Import câu hỏi từ Excel', 'CAUHOI',
                    ($tongDong - count($dsHopLe)) . '/' . $tongDong . ' dòng cần kiểm tra lại. File ' . $nguonTen);
            }
        } catch (RuntimeException $ex) {
            $loi[] = $ex->getMessage();
            NhatKy::ghiThatBai('Import câu hỏi từ Excel', 'CAUHOI', mb_substr($ex->getMessage(), 0, 300));
        } finally {
            if ($tam !== null && is_file($tam)) {
                @unlink($tam);
            }
        }
        if ($daLuu) {
            $boQua = $tongDong - $soLuu;
            $_SESSION['ch_flash'] = ['ok', 'Đã import thành công ' . $soLuu . ' câu hỏi vào ngân hàng.' . ($boQua > 0 ? ' (Đã bỏ qua ' . $boQua . ' câu trùng/lỗi)' : '')];
            redirect(BASE_URL . '/admin/cauhoi.php');
        }
    }

    // Luồng 3: Lưu hoặc kiểm tra lại từ bảng trên web.
    if ($buoc === 'luu_truc_tiep') {
        $hanhDong   = (string) ($_POST['hanh_dong_bang'] ?? 'luu_hop_le');
        $nguonTen   = mb_substr(trim((string) ($_POST['nguon_ten'] ?? 'Trợ lý Web')), 0, 100);
        $dsDongRaw  = (array) ($_POST['dong'] ?? []);
        $dongChuan  = [];

        foreach ($dsDongRaw as $idx => $item) {
            if (!is_array($item) || !empty($item['bo_qua'])) {
                continue;
            }
            $soDong = (int) ($item['so'] ?? ($idx + 1));
            $dongChuan[] = [
                'so' => $soDong,
                'o'  => [
                    trim((string) ($item['mon'] ?? '')),
                    trim((string) ($item['loai'] ?? 'Cả hai kỳ')),
                    trim((string) ($item['dokho'] ?? 'Trung bình')),
                    trim((string) ($item['noidung'] ?? '')),
                    trim((string) ($item['da_a'] ?? '')),
                    trim((string) ($item['da_b'] ?? '')),
                    trim((string) ($item['da_c'] ?? '')),
                    trim((string) ($item['da_d'] ?? '')),
                    trim((string) ($item['dung'] ?? '')),
                    trim((string) ($item['giaithich'] ?? '')),
                ],
            ];
        }

        if (!$dongChuan) {
            $loi[] = 'Không còn câu hỏi nào trong danh sách để xử lý.';
        } elseif (count($dongChuan) > NhapCauHoi::TOI_DA_DONG) {
            $loi[] = 'Danh sách vượt quá ' . NhapCauHoi::TOI_DA_DONG . ' câu hỏi.';
        } else {
            $kq        = NhapCauHoi::kiemTraBang($pdo, $dongChuan);
            $tongDong  = count($kq);
            $nguonLoai = 'tructiep';

            if ($hanhDong === 'xuat_excel_bang') {
                $bangXuat = [];
                foreach ($kq as $r) {
                    $bangXuat[] = array_merge($r['o'], [$r['trangThai'], implode('; ', array_unique($r['loi'])), (int) $r['so']]);
                }
                XlsxWriter::tai('cauhoi_da_soat_' . date('Ymd_His') . '.xlsx', 'Câu hỏi',
                    array_merge(NhapCauHoi::COT, NhapCauHoi::COT_THEM), $bangXuat);
            }

            if ($hanhDong === 'luu_hop_le' || $hanhDong === 'luu_tat_ca') {
                $dsHopLe  = [];
                $soConLoi = 0;
                foreach ($kq as $r) {
                    if ($r['trangThai'] === 'Hợp lệ' && $r['sach'] !== null) {
                        $dsHopLe[] = $r['sach'];
                    } else {
                        $soConLoi++;
                    }
                }

                if ($hanhDong === 'luu_tat_ca' && $soConLoi > 0) {
                    $loi[] = 'Vẫn còn ' . $soConLoi . ' câu chưa hợp lệ (được tô vàng/đỏ bên dưới). Hãy sửa nhanh trên bảng hoặc bấm nút "Lưu ngay ' . count($dsHopLe) . ' câu hợp lệ".';
                } elseif (count($dsHopLe) === 0) {
                    $loi[] = 'Chưa có câu hỏi nào đạt trạng thái Hợp lệ để lưu. Hãy bổ sung đáp án đúng hoặc sửa các ô báo đỏ bên dưới.';
                } else {
                    $daLuu = false;
                    $pdo->beginTransaction();
                    try {
                        foreach ($dsHopLe as $sach) {
                            CauHoiHelper::chen($pdo, $sach);
                        }
                        NhatKy::ghi('Import câu hỏi từ Word/Web', 'CAUHOI',
                            'Thêm ' . count($dsHopLe) . '/' . $tongDong . ' câu từ ' . $nguonTen);
                        $pdo->commit();
                        $daLuu = true;
                    } catch (Throwable $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Lưu câu hỏi từ bảng Web lỗi: ' . $ex->getMessage());
                        $loi[] = 'Lỗi cơ sở dữ liệu khi lưu câu hỏi. Hãy thử lại.';
                    }
                    if ($daLuu) {
                        $boQua = $tongDong - count($dsHopLe);
                        $_SESSION['ch_flash'] = [
                            'ok',
                            'Đã lưu thành công ' . count($dsHopLe) . ' câu hỏi vào Ngân hàng câu hỏi!'
                            . ($boQua > 0 ? ' (Đã tự động bỏ qua ' . $boQua . ' câu trùng hoặc chưa hợp lệ)' : '')
                        ];
                        redirect(BASE_URL . '/admin/cauhoi.php');
                    }
                }
            }
        }
    }
}

// Thống kê trạng thái các câu trên bảng xem trước.
$soLoi     = 0;
$soTrung   = 0;
$soThieuDA = 0;
foreach ($kq as $r) {
    if ($r['trangThai'] === 'Lỗi') {
        $soLoi++;
        if (($r['o'][8] ?? '') === '') {
            $soThieuDA++;
        }
    } elseif ($r['trangThai'] === 'Trùng') {
        $soTrung++;
    }
}
$soHopLe     = max(0, $tongDong - $soLoi - $soTrung);
$macDinhLuoi = 'Bấm vào bất kỳ ô vuông nào để nhảy nhanh tới câu hỏi đó trong bảng bên dưới.';
$dokhoChon   = (string) ($_POST['dokho'] ?? 'Trung bình');
if (!in_array($dokhoChon, CauHoiHelper::DO_KHO, true)) {
    $dokhoChon = 'Trung bình';
}
$hangDoKho = ['Dễ' => ['dong', 'Đồng'], 'Trung bình' => ['bac', 'Bạc'], 'Khó' => ['vang', 'Vàng']];

// Mô tả cấu trúc các cột trong file Excel mẫu.
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

require __DIR__ . '/layout/header.php';

// Khai báo sau header.php để tránh ghi đè $svg.
$ciBt = [
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
    'sua'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
];
$ciSvg = static fn (string $k, int $px = 18, int|float $net = 2): string =>
    '<svg width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $net
    . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($ciBt[$k] ?? '') . '</svg>';
?>
<div class="ci-trang">

  <div class="ci-dau">
    <span class="ci-dau-icon" aria-hidden="true"><?= $ciSvg('tai', 28) ?></span>
    <div class="ci-dau-chu">
      <h2>Nhập câu hỏi từ Word / Excel</h2>
      <p>Hệ thống tự nhận diện danh sách tự động, đáp án gạch chân/in đậm/tô màu và hỗ trợ sửa lỗi trực tiếp trên Web.</p>
    </div>
    <div class="ci-dau-nut">
      <a class="ci-nut ci-nut-vien" href="?mau_word=1" title="Tải file Word mẫu (.docx) có sẵn ví dụ chuẩn"><?= $ciSvg('word', 16) ?>Tải Word mẫu (.docx)</a>
      <a class="ci-nut ci-nut-vien" href="?mau=1" title="Tải file Excel mẫu (.xlsx)"><?= $ciSvg('xuong', 16) ?>Tải Excel mẫu (.xlsx)</a>
      <a class="ci-nut ci-nut-vien" href="<?= e(BASE_URL) ?>/admin/cauhoi.php"><?= $ciSvg('quaylai', 16) ?>Ngân hàng câu hỏi</a>
    </div>
  </div>

  <!-- Quy trình 3 bước nhập câu hỏi -->
  <div class="ci-buoc-bar" aria-label="Quy trình nhập câu hỏi dễ dàng">
    <div class="ci-buoc-item">
      <span class="ci-buoc-so">1</span>
      <div>
        <strong>Chọn Môn &amp; Thả file Word/Excel</strong>
        <small>Chấp nhận cả đề Word đánh số tự động, gạch chân/tô đỏ đáp án hoặc có bảng đáp án cuối đề</small>
      </div>
    </div>
    <div class="ci-buoc-item<?= $kq ? ' dang-active' : '' ?>">
      <span class="ci-buoc-so">2</span>
      <div>
        <strong>Trợ lý Xem trước &amp; Chữa lỗi 1-Click</strong>
        <small>Nếu có câu thiếu đáp án hoặc gõ nhầm, bấm chọn A/B/C/D hoặc sửa chữ trực tiếp ngay trên bảng Web</small>
      </div>
    </div>
    <div class="ci-buoc-item">
      <span class="ci-buoc-so">3</span>
      <div>
        <strong>Lưu ngay vào Ngân hàng câu hỏi</strong>
        <small>Lưu tất cả hoặc chỉ lưu các câu hợp lệ (tự động bỏ qua câu trùng/lỗi) chỉ với 1 nút bấm</small>
      </div>
    </div>
  </div>

  <details class="ci-khoi ci-quytac"<?= ($loi && !$kq) ? ' open' : '' ?>>
    <summary><?= $ciSvg('sach', 18) ?>Hướng dẫn định dạng file Word &amp; Excel (Hệ thống hỗ trợ tự động nhận diện)</summary>
    <div class="ci-quytac-than">
      <div>
        <p class="dam" style="font-weight:700;margin-bottom:8px">4 cách đánh dấu đáp án đúng trong Word đều được tự động hiểu:</p>
        <div class="ci-giay">
          <b>Câu 1 [Dễ].</b> Thủ đô của Việt Nam là gì?<br>
          A. Huế &nbsp;&nbsp; <b><u>B. Hà Nội</u></b> <em>(Cách 1: Gạch chân, In đậm hoặc Tô màu đỏ phương án đúng)</em><br>
          C. Đà Nẵng &nbsp;&nbsp; D. Cần Thơ<br>
          <span class="ci-dung">Đáp án: B</span> <em>(Cách 2: Có dòng "Đáp án: B" hoặc đặt dấu <b>*B.</b> trước phương án)</em><br>
          Giải thích: Hà Nội là thủ đô của nước Việt Nam. <em>(Không bắt buộc)</em><br>
          <hr style="border:0;border-top:1px dashed #cdd6f0;margin:8px 0">
          <em>Cách 4: Đặt bảng đáp án ở cuối file Word:</em> <b>1.B &nbsp; 2.C &nbsp; 3.A &nbsp; 4.D</b>
        </div>
        <p class="nhan-phu ci-giay-ct">
          💡 <b>Mẹo cho Admin không chuyên:</b> Bạn không cần lo lắng nếu file Word chưa đúng chuẩn 100%. Cứ đẩy file lên, hệ thống sẽ mở <b>Bảng Trợ Lý Sửa Nhanh trên Web</b> để bạn click chọn đáp án hoặc chỉnh sửa trực tiếp mà không cần mở lại Word hay Excel!
        </p>
      </div>
      <div>
        <p class="dam" style="font-weight:700;margin-bottom:8px">Các cột của file Excel (nếu bạn dùng Excel)</p>
        <table class="ci-cot">
          <tr><th>Cột</th><th>Ghi chú</th></tr>
          <?php foreach (NhapCauHoi::COT as $i => $ten): ?>
            <tr>
              <td><?= e($ten) ?><?= ($ghiChuCot[$i][0] ?? '') === 'bb' ? ' <span class="bb" title="Bắt buộc">*</span>' : '' ?></td>
              <td><?= e($ghiChuCot[$i][1] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </details>

  <?php if ($loi): ?>
    <div class="ci-thongbao loi" role="alert"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($kq): ?>
  <!-- Bảng xem trước và sửa lỗi trực tiếp -->
  <section class="ci-khoi ci-ketqua" id="ketqua">
    <form method="post" action="" id="formBangTrucTiep">
      <?= csrf_field() ?>
      <input type="hidden" name="buoc" value="luu_truc_tiep">
      <input type="hidden" name="nguon_ten" value="<?= e($nguonTen) ?>">
      <input type="hidden" name="hanh_dong_bang" id="hanhDongBang" value="luu_hop_le">

      <div class="ci-kq-dau">
        <div>
          <h3 class="ci-tieude"><?= $ciSvg('sua', 20) ?>Trợ lý Xem trước &amp; Chữa lỗi trực tiếp trên Web <?= $nguonTen !== '' ? '<span class="ci-file-badge">' . e($nguonTen) . '</span>' : '' ?></h3>
          <p class="nhan-phu">
            Bạn có thể <b>click chọn đáp án A / B / C / D</b> hoặc <b>sửa chữ trực tiếp</b> ở các câu báo lỗi ngay bên dưới rồi bấm Lưu vào Ngân hàng câu hỏi.
          </p>
        </div>
        <div class="ci-kq-actions">
          <button type="submit" class="ci-nut ci-nut-vien" onclick="document.getElementById('hanhDongBang').value='kiem_tra_lai'">
            <?= $ciSvg('kq', 16) ?>Kiểm tra lại sau khi sửa
          </button>
          <button type="submit" class="ci-nut ci-nut-vien" onclick="document.getElementById('hanhDongBang').value='xuat_excel_bang'">
            <?= $ciSvg('xuong', 16) ?>Tải ra file Excel (.xlsx)
          </button>
          <button type="submit" class="ci-nut ci-nut-chinh ci-nut-luu-ngay" id="btnLuuHopLe" onclick="document.getElementById('hanhDongBang').value='luu_hop_le'">
            <?= $ciSvg('xong', 17, 2.6) ?>Lưu ngay <span id="demHopLeNut"><?= (int) $soHopLe ?></span> câu vào Ngân hàng
          </button>
        </div>
      </div>

      <?php if ($soLoi > 0 || $soTrung > 0): ?>
        <div class="ci-goi-y-sua" role="status">
          <strong>💡 Hướng dẫn xử lý nhanh cho Admin:</strong>
          <ul>
            <?php if ($soThieuDA > 0): ?>
              <li>Có <b><?= (int) $soThieuDA ?> câu chưa rõ đáp án đúng</b>: Hãy bấm trực tiếp vào nút tròn <b>A, B, C hoặc D</b> ở cột <em>"Đáp án đúng"</em> của dòng đó (hoặc dùng thanh <em>"Điền nhanh chuỗi đáp án"</em> bên dưới).</li>
            <?php endif; ?>
            <?php if ($soTrung > 0): ?>
              <li>Có <b><?= (int) $soTrung ?> câu bị trùng nội dung</b>: Nếu không muốn sửa, bạn cứ bấm <b>"Lưu ngay <?= (int) $soHopLe ?> câu vào Ngân hàng"</b> — hệ thống sẽ tự động lưu các câu hợp lệ và bỏ qua các câu trùng!</li>
            <?php endif; ?>
            <li>Nếu có dòng nào là tiêu đề thừa hoặc bạn không muốn nạp, hãy bấm nút <b>"Bỏ qua"</b> ở cuối dòng đó.</li>
          </ul>
        </div>
      <?php else: ?>
        <div class="ci-thanhcong-box" role="status">
          🎉 <b>Tuyệt vời! Toàn bộ <?= (int) $soHopLe ?>/<?= (int) $tongDong ?> câu hỏi đều Hợp lệ 100%.</b> Bạn chỉ cần bấm nút <b>"Lưu ngay <?= (int) $soHopLe ?> câu vào Ngân hàng"</b> ở góc phải để hoàn tất!
        </div>
      <?php endif; ?>

      <div class="ci-thanh-cong-cu">
        <div class="ci-so">
          <button type="button" class="ci-loc-tab dang-chon" data-loc="all">Tất cả <b id="countAll"><?= (int) $tongDong ?></b></button>
          <button type="button" class="ci-loc-tab s-ok" data-loc="ok">Hợp lệ <b id="countOk"><?= (int) $soHopLe ?></b></button>
          <button type="button" class="ci-loc-tab s-loi" data-loc="loi">Cần sửa <b id="countLoi"><?= (int) $soLoi ?></b></button>
          <button type="button" class="ci-loc-tab s-trung" data-loc="trung">Trùng <b id="countTrung"><?= (int) $soTrung ?></b></button>
        </div>

        <div class="ci-gan-nhanh">
          <span>⚡ Điền nhanh chuỗi đáp án (VD: <code>1A 2B 3C</code> hoặc <code>ABCD</code>):</span>
          <input type="text" id="inputChuoiDapAn" placeholder="Nhập chuỗi đáp án..." aria-label="Chuỗi đáp án nhanh">
          <button type="button" class="ci-nut ci-nut-vien ci-nut-nho" id="btnApDungChuoiDA">Áp dụng</button>
          <?php if ($soLoi > 0 || $soTrung > 0): ?>
            <button type="button" class="ci-nut ci-nut-vien ci-nut-nho" id="btnBoQuaTatCaLoi" title="Đánh dấu bỏ qua toàn bộ các dòng đang bị Trùng hoặc Lỗi">Bỏ qua tất cả dòng lỗi/trùng</button>
          <?php endif; ?>
        </div>
      </div>

      <div class="ci-o-luoi" id="luoi" data-mac-dinh="<?= e($macDinhLuoi) ?>" role="group" aria-label="Sơ đồ trạng thái từng câu">
        <?php foreach ($kq as $idx => $r): ?>
          <?php
            $so  = (int) $r['so'];
            $tt  = (string) $r['trangThai'];
            $lop = $tt === 'Hợp lệ' ? 'ci-o-ok' : ($tt === 'Trùng' ? 'ci-o-trung' : 'ci-o-loi');
            $tip = 'Câu ' . ($idx + 1) . ' (Dòng ' . $so . '): ' . ($tt === 'Hợp lệ' ? 'Hợp lệ - sẵn sàng lưu.' : implode('; ', array_unique($r['loi'])));
          ?>
          <a class="ci-o <?= $lop ?>" href="#dong-<?= $idx ?>" data-idx="<?= $idx ?>" data-tip="<?= e($tip) ?>" aria-label="<?= e($tip) ?>"><?= $idx + 1 ?></a>
        <?php endforeach; ?>
      </div>
      <p class="ci-thongtin" id="thongtin" aria-live="polite"><?= e($macDinhLuoi) ?></p>

      <div class="ci-cuon">
        <table class="ci-bang ci-bang-sua" id="bangSuaTrucTiep">
          <thead>
            <tr>
              <th style="width:58px">Câu</th>
              <th style="width:115px">Trạng thái</th>
              <th style="min-width:250px">Nội dung câu hỏi &amp; Giải thích</th>
              <th style="min-width:290px">4 Phương án (A, B, C, D)</th>
              <th style="width:155px">Đáp án đúng (1-Click)</th>
              <th style="width:120px">Độ khó</th>
              <th style="width:82px">Thao tác</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($kq as $idx => $r):
            $tt       = (string) $r['trangThai'];
            $loaiDong = $tt === 'Hợp lệ' ? 'ok' : ($tt === 'Trùng' ? 'trung' : 'loi');
            $daChon   = strtoupper(trim((string) ($r['o'][8] ?? '')));
            $dkDong   = (string) ($r['o'][2] ?? 'Trung bình');
            if (!in_array($dkDong, CauHoiHelper::DO_KHO, true)) {
                $dkDong = 'Trung bình';
            }
          ?>
            <tr id="dong-<?= $idx ?>" class="ci-dong-sua dong-<?= $loaiDong ?>" data-idx="<?= $idx ?>" data-trang-thai="<?= $loaiDong ?>">
              <td class="ci-td-dong">
                <b>#<?= $idx + 1 ?></b>
                <small class="nhan-phu" style="display:block;font-size:11px">Dòng <?= (int) $r['so'] ?></small>
                <input type="hidden" name="dong[<?= $idx ?>][so]" value="<?= (int) $r['so'] ?>">
                <input type="hidden" name="dong[<?= $idx ?>][mon]" value="<?= e((string) ($r['o'][0] ?? '')) ?>">
                <input type="hidden" name="dong[<?= $idx ?>][loai]" value="<?= e((string) ($r['o'][1] ?? 'Cả hai kỳ')) ?>">
                <input type="hidden" name="dong[<?= $idx ?>][bo_qua]" value="0" class="input-bo-qua">
              </td>
              <td>
                <span class="ci-pill <?= $tt === 'Hợp lệ' ? 'ci-pill-ok' : ($tt === 'Trùng' ? 'ci-pill-trung' : 'ci-pill-loi') ?>" data-pill-idx="<?= $idx ?>">
                  <?= e($tt) ?>
                </span>
                <?php if (!empty($r['loi'])): ?>
                  <div class="ci-td-loi" data-loi-idx="<?= $idx ?>"><?= e(implode('; ', array_unique($r['loi']))) ?></div>
                <?php else: ?>
                  <div class="ci-td-loi" data-loi-idx="<?= $idx ?>" hidden></div>
                <?php endif; ?>
              </td>
              <td>
                <textarea name="dong[<?= $idx ?>][noidung]" rows="2" class="ci-input-nd" placeholder="Nhập nội dung câu hỏi..." required><?= e((string) ($r['o'][3] ?? '')) ?></textarea>
                <input type="text" name="dong[<?= $idx ?>][giaithich]" value="<?= e((string) ($r['o'][9] ?? '')) ?>" class="ci-input-gt" placeholder="Giải thích thêm (không bắt buộc)...">
              </td>
              <td>
                <div class="ci-luoi-pa">
                  <?php foreach (['A' => 4, 'B' => 5, 'C' => 6, 'D' => 7] as $chu => $cotIdx): ?>
                    <label class="ci-pa-item">
                      <span class="ci-pa-nhan"><?= $chu ?></span>
                      <input type="text" name="dong[<?= $idx ?>][da_<?= strtolower($chu) ?>]" value="<?= e((string) ($r['o'][$cotIdx] ?? '')) ?>" class="ci-input-pa" data-pa="<?= $chu ?>" placeholder="Phương án <?= $chu ?><?= in_array($chu, ['A', 'B'], true) ? ' *' : '' ?>">
                    </label>
                  <?php endforeach; ?>
                </div>
              </td>
              <td>
                <input type="hidden" name="dong[<?= $idx ?>][dung]" value="<?= e($daChon) ?>" class="input-dap-an-dung">
                <div class="ci-chon-da" role="radiogroup" aria-label="Chọn đáp án đúng cho câu #<?= $idx + 1 ?>">
                  <?php foreach (['A', 'B', 'C', 'D'] as $chu): ?>
                    <button type="button" class="ci-nut-da<?= $daChon === $chu ? ' active' : '' ?>" data-da="<?= $chu ?>" title="Chọn <?= $chu ?> làm đáp án đúng"><?= $chu ?></button>
                  <?php endforeach; ?>
                </div>
                <?php if ($daChon === ''): ?>
                  <small class="ci-nhac-da">👆 Bấm chọn 1 đáp án</small>
                <?php endif; ?>
              </td>
              <td>
                <select name="dong[<?= $idx ?>][dokho]" class="ci-select-dk" aria-label="Độ khó câu #<?= $idx + 1 ?>">
                  <?php foreach (CauHoiHelper::DO_KHO as $d): ?>
                    <option value="<?= e($d) ?>"<?= $dkDong === $d ? ' selected' : '' ?>><?= e($d) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td style="text-align:center">
                <button type="button" class="ci-nut-boqua" title="Bỏ qua câu này (không lưu vào CSDL)">Bỏ qua</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <div class="ci-hai-cot">

    <!-- Cách 1: Nhập từ Word (.docx) -->
    <form method="post" action="" enctype="multipart/form-data" class="ci-khoi ci-the-chinh" id="formWord" data-ci-form="word">
      <?= csrf_field() ?>
      <input type="hidden" name="buoc" value="word">
      <input type="hidden" name="che_do_word" id="cheDoWord" value="xem">

      <div class="ci-card-head">
        <h3 class="ci-tieude"><?= $ciSvg('word', 20) ?><span>Cách 1: Nhập trực tiếp từ file Word (.docx)</span></h3>
        <span class="ci-nhan-tuy chinh">Khuyên dùng</span>
      </div>
      <p class="nhan-phu">
        Tự động bóc tách câu hỏi, nhận diện đáp án gạch chân/in đậm/tô đỏ hoặc bảng đáp án cuối đề. Mở <b>Bảng Trợ Lý Xem Trước trên Web</b> để bạn kiểm tra và bấm Lưu ngay chỉ trong 1 bước!
      </p>

      <div class="ci-luoi-2">
        <div>
          <label for="mon">Môn học <span class="ci-sao-bb">*</span></label>
          <select id="mon" name="mon" required>
            <option value="0">-- Chọn môn học --</option>
            <?php foreach ($dsMon as $m): ?>
              <option value="<?= (int) $m['MaMon'] ?>"<?= (int) ($_POST['mon'] ?? 0) === (int) $m['MaMon'] ? ' selected' : '' ?>><?= e($m['TenMon']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="loai">Loại kỳ thi</label>
          <select id="loai" name="loai">
            <option value="">Dùng được cho cả hai kỳ</option>
            <?php foreach ($dsKT as $k): ?>
              <option value="<?= (int) $k['MaLoaiKT'] ?>"<?= (string) ($_POST['loai'] ?? '') === (string) $k['MaLoaiKT'] ? ' selected' : '' ?>><?= e($k['TenLoaiKT']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div>
        <label id="nhanDoKho">Độ khó mặc định (cho những câu không ghi riêng <code>[Dễ]</code>/<code>[Khó]</code>)</label>
        <div class="ci-hang" role="radiogroup" aria-labelledby="nhanDoKho">
          <?php foreach (CauHoiHelper::DO_KHO as $d): ?>
            <?php $h = $hangDoKho[$d] ?? ['bac', '']; ?>
            <label class="ci-hang-o">
              <input type="radio" name="dokho" value="<?= e($d) ?>"<?= $dokhoChon === $d ? ' checked' : '' ?>>
              <span class="ci-hang-the hang-<?= e($h[0]) ?>">
                <?= $ciSvg('huyhieu', 24) ?>
                <strong><?= e($d) ?></strong>
                <small>Hạng <?= e($h[1]) ?></small>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <label class="ci-tha" for="file_word" data-duoi="docx" data-max="<?= (int) NhapCauHoi::TOI_DA_BYTE ?>">
        <input type="file" id="file_word" name="file_word" accept=".docx" required>
        <span class="ci-cong"><span class="ci-i-up"><?= $ciSvg('tai', 30) ?></span><span class="ci-i-ok"><?= $ciSvg('xong', 30, 2.6) ?></span></span>
        <span class="ci-tha-chu">Thả file Word (.docx) vào đây</span>
        <span class="ci-tha-phu">hoặc bấm để chọn · .docx · tối đa <?= (int) round(NhapCauHoi::TOI_DA_BYTE / 1048576) ?> MB · <?= (int) NhapCauHoi::TOI_DA_DONG ?> câu</span>
        <span class="ci-file-tt">
          <span class="ci-file-ten"></span><span class="ci-file-kt"></span>
          <button type="button" class="ci-bo-chon" title="Bỏ file đã chọn" aria-label="Bỏ file đã chọn"><?= $ciSvg('dong', 14, 2.4) ?></button>
        </span>
        <span class="ci-nhac-loi" role="alert" hidden></span>
      </label>

      <div class="ci-nut-nhom">
        <button type="submit" class="ci-nut ci-nut-chinh ci-block" onclick="document.getElementById('cheDoWord').value='xem'">
          <?= $ciSvg('kq', 16) ?>Phân tích &amp; Xem trước trên Web (Lưu trực tiếp)
        </button>
        <button type="submit" class="ci-nut ci-nut-vien ci-block" onclick="document.getElementById('cheDoWord').value='xuat'" title="Tách câu hỏi và tải về file Excel (.xlsx) để chỉnh sửa ngoại tuyến">
          <?= $ciSvg('xuong', 16) ?>Hoặc chỉ tách câu &amp; tải về file Excel (.xlsx)
        </button>
      </div>
    </form>

    <!-- Cách 2: Nhập từ Excel (.xlsx) -->
    <form method="post" action="" enctype="multipart/form-data" class="ci-khoi" data-ci-form="excel">
      <?= csrf_field() ?>
      <input type="hidden" name="buoc" value="excel">

      <div class="ci-card-head">
        <h3 class="ci-tieude"><?= $ciSvg('excel', 20) ?><span>Cách 2: Import từ file Excel (.xlsx)</span></h3>
        <span class="ci-nhan-tuy">Bảng tính</span>
      </div>
      <p class="nhan-phu">
        Dành cho trường hợp bạn đã có sẵn <a href="?mau=1">file Excel theo mẫu</a> hoặc file Excel đã chỉnh sửa ngoại tuyến. Nếu file có dòng lỗi, hệ thống cũng sẽ mở Bảng Trợ Lý trên Web để bạn sửa nhanh.
      </p>

      <ul class="ci-quy">
        <li><?= $ciSvg('xong', 13, 3) ?>Kiểm tra tự động từng dòng</li>
        <li><?= $ciSvg('xong', 13, 3) ?>Hỗ trợ sửa lỗi trực tiếp trên Web</li>
        <li><?= $ciSvg('xong', 13, 3) ?>Chống trùng lặp câu hỏi</li>
        <li><?= $ciSvg('xong', 13, 3) ?>Môn học &amp; Kỳ thi đọc theo từng dòng</li>
      </ul>

      <label class="ci-checkbox-boqua">
        <input type="checkbox" name="bo_qua_trung" value="1" checked>
        <span>Tự động lưu ngay các câu hợp lệ và bỏ qua các câu bị trùng lặp</span>
      </label>

      <label class="ci-tha" for="file_excel" data-duoi="xlsx" data-max="<?= (int) NhapCauHoi::TOI_DA_BYTE ?>">
        <input type="file" id="file_excel" name="file_excel" accept=".xlsx" required>
        <span class="ci-cong"><span class="ci-i-up"><?= $ciSvg('tai', 30) ?></span><span class="ci-i-ok"><?= $ciSvg('xong', 30, 2.6) ?></span></span>
        <span class="ci-tha-chu">Thả file Excel (.xlsx) vào đây</span>
        <span class="ci-tha-phu">hoặc bấm để chọn · .xlsx · tối đa <?= (int) round(NhapCauHoi::TOI_DA_BYTE / 1048576) ?> MB · <?= (int) NhapCauHoi::TOI_DA_DONG ?> dòng dữ liệu</span>
        <span class="ci-file-tt">
          <span class="ci-file-ten"></span><span class="ci-file-kt"></span>
          <button type="button" class="ci-bo-chon" title="Bỏ file đã chọn" aria-label="Bỏ file đã chọn"><?= $ciSvg('dong', 14, 2.4) ?></button>
        </span>
        <span class="ci-nhac-loi" role="alert" hidden></span>
      </label>

      <div class="ci-nut-nhom">
        <button type="submit" class="ci-nut ci-nut-chinh ci-block"><?= $ciSvg('kq', 16) ?>Kiểm tra và Import Excel</button>
        <a class="ci-nut ci-nut-vien ci-block" href="?mau=1"><?= $ciSvg('xuong', 16) ?>Tải file Excel mẫu (.xlsx)</a>
      </div>
    </form>
  </div>

</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
