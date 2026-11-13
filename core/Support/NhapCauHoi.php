<?php
// Tách câu hỏi từ file Word (A-43), kiểm tra bảng câu hỏi (A-22, A-44) và nhận file tải lên (6.8).
// Đặt tại: elo_quiz/core/NhapCauHoi.php   (cần thêm DocxReader.php, XlsxReader.php, CauHoiHelper.php)
class NhapCauHoi
{
    public const TOI_DA_DONG = 500;               // số câu tối đa mỗi lần
    public const TOI_DA_BYTE = 2 * 1024 * 1024;   // 2 MB, khớp upload_max_filesize mặc định của XAMPP

    // 10 cột của file Excel mẫu (A-22), theo đúng thứ tự
    public const COT = ['Môn học', 'Loại kỳ thi', 'Độ khó', 'Nội dung', 'Phương án A', 'Phương án B',
                        'Phương án C', 'Phương án D', 'Đáp án đúng', 'Giải thích'];
    // 3 cột thêm khi xuất từ file Word (A-43)
    public const COT_THEM = ['Trạng thái kiểm tra', 'Ghi chú lỗi', 'Dòng nguồn trong file Word'];

    // ------------------------------------------------------------------
    // Nhận file tải lên: kiểm tra đuôi, dung lượng, loại file thật, đặt tên ngẫu nhiên khi lưu tạm.
    // Trả về đường dẫn file tạm; nơi gọi PHẢI unlink sau khi xử lý xong.
    // ------------------------------------------------------------------
    public static function nhanFileTai(string $truong, string $duoi, string $phanBatBuoc): string
    {
        $f  = $_FILES[$truong] ?? null;
        $ma = is_array($f) ? ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($ma !== UPLOAD_ERR_OK) {
            if ($ma === UPLOAD_ERR_INI_SIZE || $ma === UPLOAD_ERR_FORM_SIZE) {
                throw new RuntimeException('File vượt quá dung lượng cho phép (tối đa 2 MB).');
            }
            throw new RuntimeException('Hãy chọn file để tải lên.');
        }
        if (!is_uploaded_file((string) $f['tmp_name'])) {
            throw new RuntimeException('File tải lên không hợp lệ.');
        }
        if ((int) $f['size'] > self::TOI_DA_BYTE) {
            throw new RuntimeException('File vượt quá dung lượng cho phép (tối đa 2 MB).');
        }
        if (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== $duoi) {
            throw new RuntimeException('Chỉ nhận file .' . $duoi . '.');
        }
        // Loại file thật: .docx/.xlsx là file zip, bắt đầu bằng "PK" và phải có phần nội dung đặc trưng
        $dau = (string) file_get_contents((string) $f['tmp_name'], false, null, 0, 4);
        if ($dau !== "PK\x03\x04") {
            throw new RuntimeException('Nội dung file không đúng định dạng .' . $duoi . ' thật.');
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Máy chủ chưa bật extension zip của PHP (bật extension=zip trong php.ini rồi khởi động lại Apache).');
        }
        $zip = new ZipArchive();
        if ($zip->open((string) $f['tmp_name']) !== true) {
            throw new RuntimeException('Nội dung file không đúng định dạng .' . $duoi . ' thật.');
        }
        $co = $zip->statName($phanBatBuoc) !== false;
        $zip->close();
        if (!$co) {
            throw new RuntimeException('Nội dung file không đúng định dạng .' . $duoi . ' thật.');
        }

        $dich = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eloquiz_' . bin2hex(random_bytes(8)) . '.' . $duoi;
        if (!move_uploaded_file((string) $f['tmp_name'], $dich)) {
            throw new RuntimeException('Không lưu được file tạm trên máy chủ.');
        }
        return $dich;
    }

    // ------------------------------------------------------------------
    // A-43: tách câu hỏi từ các dòng chữ của file Word (kết quả của DocxReader::doc)
    //
    // Mẫu nhận diện:
    //   Câu 1. Nội dung câu hỏi (có thể nhiều dòng)
    //   A. Phương án   B. ...   C. ...   D. ...      (mỗi phương án một dòng; chấp nhận cả A) và A:)
    //   Đáp án: B                                     (hoặc đánh dấu * trước chữ cái: *B. ...)
    //   Giải thích: ...                               (không bắt buộc, có thể nhiều dòng)
    // ------------------------------------------------------------------
    public static function tachWord(array $dong): array
    {
        $reCau = '/^C[âa]u\s*\d+\s*[\.\):\-]?\s*(.*)$/iu';
        $reSo  = '/^\d+\s*[\.\)]\s+(.*)$/u';

        // Nếu file có dòng bắt đầu bằng "Câu N" thì chỉ coi đó là đầu câu hỏi; chỉ khi không có mới
        // chấp nhận kiểu "1." (tránh nhầm các ý "1. 2. 3." nằm trong nội dung câu hỏi).
        $coCau = false;
        foreach ($dong as $d) {
            if (preg_match($reCau, trim($d['text']))) {
                $coCau = true;
                break;
            }
        }
        $reBatDau = $coCau ? $reCau : $reSo;

        $cauHoi = [];
        $hien   = null;
        $tt     = '';     // noidung | phuongan | giaithich | sauDapAn
        $cuoi   = -1;     // chỉ số phương án vừa đọc (0 = A)

        foreach ($dong as $d) {
            $so = (int) $d['so'];
            $t  = trim($d['text']);
            if ($t === '') {
                continue;
            }

            if (preg_match($reBatDau, $t, $m)) {
                if ($hien !== null) {
                    $cauHoi[] = $hien;
                }
                $hien = ['dong' => $so, 'noidung' => trim($m[1]), 'dapan' => ['', '', '', ''],
                         'dung' => null, 'giaithich' => '', 'loi' => []];
                $tt   = 'noidung';
                $cuoi = -1;
                continue;
            }
            if ($hien === null) {
                continue;   // tiêu đề hoặc lời dẫn trước câu đầu tiên
            }

            if (preg_match('/^(?:Giải\s*thích|Lời\s*giải|Hướng\s*dẫn\s*giải)\s*[:\.\-]\s*(.*)$/iu', $t, $m)) {
                $hien['giaithich'] .= ($hien['giaithich'] === '' ? '' : "\n") . trim($m[1]);
                $tt = 'giaithich';
                continue;
            }
            if (preg_match('/^(?:Đáp\s*án(?:\s*đúng)?|ĐA|Dap\s*an|Answer)\s*[:\.\-]\s*\**\s*([A-Da-d])(?![\p{L}\d])/iu', $t, $m)) {
                self::datDung($hien, ord(strtoupper($m[1])) - 65);
                $tt = 'sauDapAn';
                continue;
            }

            if ($tt === 'noidung' || $tt === 'phuongan') {
                if ($tt === 'phuongan' && preg_match('/^\*?\s*[E-H]\s*[\.\):]\s*\S/u', $t)) {
                    $hien['loi'][] = 'Có hơn 4 phương án trả lời (chỉ nhận A đến D).';
                    continue;
                }
                if (preg_match('/^\*?\s*[A-D]\s*[\.\):]\s*\S/u', $t)) {
                    $ds = self::tachPhuongAn($t);
                    if ($ds[0]['idx'] > $cuoi) {   // phương án phải đi theo thứ tự A, B, C, D
                        foreach ($ds as $p) {
                            $hien['dapan'][$p['idx']] = $p['text'];
                            if ($p['sao']) {
                                self::datDung($hien, $p['idx']);
                            }
                            $cuoi = $p['idx'];
                        }
                        $tt = 'phuongan';
                        continue;
                    }
                }
            }

            // Dòng nối tiếp của phần đang đọc
            if ($tt === 'noidung') {
                $hien['noidung'] .= "\n" . $t;
            } elseif ($tt === 'phuongan') {
                $hien['dapan'][$cuoi] .= ' ' . $t;
            } else {
                $hien['giaithich'] .= ($hien['giaithich'] === '' ? '' : "\n") . $t;
            }
        }
        if ($hien !== null) {
            $cauHoi[] = $hien;
        }
        return $cauHoi;
    }

    private static function datDung(array &$hien, int $idx): void
    {
        if ($hien['dung'] === null) {
            $hien['dung'] = $idx;
        } elseif ($hien['dung'] !== $idx) {
            $hien['loi'][] = 'Có nhiều hơn một đáp án đúng được đánh dấu.';
        }
    }

    // Một dòng có thể chứa nhiều phương án ("A. x   B. y   C. z   D. w"): tách khi các chữ cái tăng dần
    private static function tachPhuongAn(string $dong): array
    {
        $manh = preg_split('/\s+(?=\*?\s*[A-D]\s*[\.\):]\s*\S)/u', $dong);
        $kq   = [];
        $truoc = -1;
        foreach ($manh as $m) {
            if (!preg_match('/^(\*)?\s*([A-D])\s*[\.\):]\s*(.*)$/su', $m, $mm)) {
                return self::mot($dong);
            }
            $idx = ord($mm[2]) - 65;
            if ($idx <= $truoc) {
                return self::mot($dong);
            }
            $truoc = $idx;
            $kq[]  = ['idx' => $idx, 'sao' => $mm[1] !== '', 'text' => trim($mm[3])];
        }
        return $kq ?: self::mot($dong);
    }

    private static function mot(string $dong): array
    {
        preg_match('/^(\*)?\s*([A-D])\s*[\.\):]\s*(.*)$/su', $dong, $mm);
        return [['idx' => ord($mm[2]) - 65, 'sao' => $mm[1] !== '', 'text' => trim($mm[3])]];
    }

    // Biến các câu tách được thành các dòng Excel (10 cột mẫu + 3 cột kiểm tra) kèm trạng thái
    public static function bangTuWord(PDO $pdo, array $cauWord, string $tenMon, string $tenKT, string $doKho): array
    {
        $dong = [];
        foreach ($cauWord as $q) {
            $dong[] = ['so' => $q['dong'], 'o' => [
                $tenMon, $tenKT, $doKho, $q['noidung'],
                $q['dapan'][0], $q['dapan'][1], $q['dapan'][2], $q['dapan'][3],
                $q['dung'] === null ? '' : chr(65 + $q['dung']), $q['giaithich'],
            ]];
        }
        $kq  = self::kiemTraBang($pdo, $dong);
        $bang = [];
        foreach ($kq as $i => $r) {
            $loi  = array_merge($cauWord[$i]['loi'], $r['loi']);
            $tt   = $r['trangThai'];
            if ($cauWord[$i]['loi']) {
                $tt = 'Lỗi';
            }
            $bang[] = array_merge($r['o'], [$tt, implode('; ', array_unique($loi)), (int) $cauWord[$i]['dong']]);
        }
        return $bang;
    }

    // ------------------------------------------------------------------
    // Kiểm tra dòng tiêu đề của file Excel import
    // ------------------------------------------------------------------
    public static function kiemTraTieuDe(array $o): void
    {
        foreach (self::COT as $i => $ten) {
            if (self::khoa((string) ($o[$i] ?? '')) !== self::khoa($ten)) {
                throw new RuntimeException('Dòng tiêu đề không đúng mẫu (cột ' . ($i + 1) . ' phải là "' . $ten . '"). '
                    . 'Hãy tải file mẫu về điền hoặc dùng file Excel xuất ra từ file Word.');
            }
        }
    }

    // ------------------------------------------------------------------
    // A-22, A-44: kiểm tra từng dòng, chưa ghi gì vào CSDL.
    // $dong: danh sách ['so' => số dòng nguồn, 'o' => các ô (chỉ số 0..9, theo COT)]
    // Trả về cùng thứ tự: ['so', 'o' (đã chuẩn hóa 10 ô), 'trangThai' (Hợp lệ|Lỗi|Trùng), 'loi' => [], 'sach' => dữ liệu để ghi hoặc null]
    // ------------------------------------------------------------------
    public static function kiemTraBang(PDO $pdo, array $dong): array
    {
        $mon = [];
        foreach ($pdo->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai IN ('Đang mở','Đã ẩn')") as $r) {
            $mon[self::khoa($r['TenMon'])] = (int) $r['MaMon'];
        }
        $kt = [];
        foreach ($pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI') as $r) {
            $kt[self::khoa($r['TenLoaiKT'])] = (int) $r['MaLoaiKT'];
        }
        $doKhoMap = [];
        foreach (CauHoiHelper::DO_KHO as $d) {
            $doKhoMap[self::khoa($d)] = $d;
        }

        $ketQua = [];
        $daThay = [];   // khóa (môn + nội dung) => số dòng đầu tiên, để bắt trùng ngay trong file
        foreach ($dong as $d) {
            $o = [];
            for ($i = 0; $i < 10; $i++) {
                $o[$i] = trim((string) ($d['o'][$i] ?? ''));
            }
            $loi = [];

            // Môn học
            $maMon = $mon[self::khoa($o[0])] ?? 0;
            if ($o[0] === '') {
                $loi[] = 'Thiếu tên môn học.';
            } elseif ($maMon === 0) {
                $loi[] = 'Không tìm thấy môn học "' . $o[0] . '" (phải đúng tên môn trong hệ thống).';
            }

            // Loại kỳ thi: để trống hoặc ghi "Cả hai kỳ" nghĩa là dùng cho cả hai kỳ (A-19)
            $maKT = '';
            $kk   = self::khoa($o[1]);
            if ($kk !== '' && !in_array($kk, ['cả hai', 'cả hai kỳ', 'cả hai loại', 'chung'], true)) {
                if (isset($kt[$kk])) {
                    $maKT = $kt[$kk];
                } else {
                    $loi[] = 'Không tìm thấy loại kỳ thi "' . $o[1] . '".';
                }
            }

            $doKho = $doKhoMap[self::khoa($o[2])] ?? $o[2];

            // Đáp án đúng: chữ cái A đến D
            $dung = -1;
            if ($o[8] !== '') {
                $c = mb_strtoupper(mb_substr($o[8], 0, 1));
                if (in_array($c, ['A', 'B', 'C', 'D'], true)) {
                    $dung = ord($c) - 65;
                } else {
                    $loi[] = 'Cột Đáp án đúng chỉ nhận A, B, C hoặc D.';
                    $dung  = -2;   // đã báo lỗi, kiemTra() không báo thêm
                }
            }

            [$l2, $sach] = CauHoiHelper::kiemTra($pdo, [
                'mon' => $maMon, 'loai' => $maKT, 'dokho' => $doKho, 'noidung' => $o[3], 'giaithich' => $o[9],
                'dapan' => [$o[4], $o[5], $o[6], $o[7]], 'dung' => $dung,
            ], 0, true);
            if ($maMon === 0) {
                $l2 = array_values(array_diff($l2, ['Hãy chọn môn học.']));   // đã báo riêng ở trên
            }
            $loi = array_merge($loi, $l2);

            $tt = 'Hợp lệ';
            if ($loi || $sach === null) {
                $tt   = 'Lỗi';
                $sach = null;
            } else {
                $khoa = $maMon . '|' . self::khoa($sach['noidung']);
                $trungDB = CauHoiHelper::timTrung($pdo, $sach['mon'], $sach['noidung']);
                if (isset($daThay[$khoa])) {
                    $tt    = 'Trùng';
                    $loi[] = 'Trùng nội dung với dòng ' . $daThay[$khoa] . ' trong cùng file.';
                } elseif ($trungDB !== null) {
                    $tt    = 'Trùng';
                    $loi[] = 'Đã có câu hỏi #' . $trungDB . ' cùng nội dung trong môn này.';
                    $daThay[$khoa] = $d['so'];
                } else {
                    $daThay[$khoa] = $d['so'];
                }
                if ($tt === 'Trùng') {
                    $sach = null;   // câu trùng không được thêm (A-44)
                }
            }

            $ketQua[] = ['so' => $d['so'], 'o' => $o, 'trangThai' => $tt, 'loi' => $loi, 'sach' => $sach];
        }
        return $ketQua;
    }

    // Chuẩn hóa để so sánh tên: bỏ khoảng trắng thừa, không phân biệt hoa thường
    private static function khoa(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}