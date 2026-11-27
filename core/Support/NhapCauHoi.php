<?php
// Tách câu hỏi từ Word (.docx), kiểm tra bảng câu hỏi và nhận file tải lên.
class NhapCauHoi
{
    public const TOI_DA_DONG = 1000;              // số câu tối đa mỗi lần
    public const TOI_DA_BYTE = 10 * 1024 * 1024;  // 10 MB

    // 10 cột của file Excel mẫu
    public const COT = ['Môn học', 'Loại kỳ thi', 'Độ khó', 'Nội dung', 'Phương án A', 'Phương án B',
                        'Phương án C', 'Phương án D', 'Đáp án đúng', 'Giải thích'];
    // 3 cột thêm khi xuất từ file Word
    public const COT_THEM = ['Trạng thái kiểm tra', 'Ghi chú lỗi', 'Dòng nguồn trong file Word'];

    // Nhận file tải lên, kiểm tra chữ ký PK/nội dung và lưu tạm (bên gọi phải unlink sau khi dùng).
    public static function nhanFileTai(string $truong, string $duoi, string $phanBatBuoc): string
    {
        $f  = $_FILES[$truong] ?? null;
        $ma = is_array($f) ? ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($ma !== UPLOAD_ERR_OK) {
            if ($ma === UPLOAD_ERR_INI_SIZE || $ma === UPLOAD_ERR_FORM_SIZE) {
                $maxMb = (int) round(self::TOI_DA_BYTE / 1048576);
                throw new RuntimeException('File vượt quá dung lượng cho phép (tối đa ' . $maxMb . ' MB, hoặc vượt giới hạn upload_max_filesize=' . ini_get('upload_max_filesize') . ' của PHP). Hãy chia nhỏ file rồi tải lên lại.');
            }
            throw new RuntimeException('Hãy chọn một file để tải lên.');
        }
        if (!is_uploaded_file((string) $f['tmp_name'])) {
            throw new RuntimeException('File tải lên không hợp lệ.');
        }
        if ((int) $f['size'] > self::TOI_DA_BYTE) {
            $maxMb = (int) round(self::TOI_DA_BYTE / 1048576);
            throw new RuntimeException('File vượt quá dung lượng cho phép (tối đa ' . $maxMb . ' MB).');
        }
        $duoiThuc = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if ($duoiThuc !== $duoi) {
            if ($duoi === 'docx' && $duoiThuc === 'doc') {
                throw new RuntimeException('Bạn đang chọn file .doc (Word 97-2003 cũ). Cách xử lý trong 5 giây: Mở file này trong Word -> Bấm F12 (Save As) -> Chọn "Word Document (*.docx)" rồi tải lên lại.');
            }
            if ($duoi === 'xlsx' && in_array($duoiThuc, ['xls', 'csv'], true)) {
                throw new RuntimeException('Bạn đang chọn file .' . $duoiThuc . '. Hãy mở file trong Excel -> Bấm F12 (Save As) -> Chọn "Excel Workbook (*.xlsx)" rồi tải lên lại.');
            }
            throw new RuntimeException('Chỉ nhận file định dạng .' . $duoi . ' (file bạn chọn có đuôi .' . ($duoiThuc ?: 'không xác định') . ').');
        }
        // Loại file thật: .docx/.xlsx là file zip, bắt đầu bằng "PK" và phải có phần nội dung đặc trưng
        $dau = (string) file_get_contents((string) $f['tmp_name'], false, null, 0, 4);
        if ($dau !== "PK\x03\x04") {
            if ($dau === "\xD0\xCF\x11\xE0") {
                throw new RuntimeException('File này là định dạng Office 97-2003 cũ (.doc/.xls) được đổi tên thành .' . $duoi . '. Hãy mở file và chọn Save As (F12) sang định dạng .' . $duoi . ' chuẩn.');
            }
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

    // Tách câu hỏi từ các dòng chữ của file Word (hỗ trợ tiêu đề Câu/Question/số, đáp án A-D, dấu *, gạch chân/tô màu và bảng đáp án cuối đề).
    public static function tachWord(array $dong): array
    {
        $reCau = '/^(?:C[âa]u(?:\s*h[ỏo]i)?|Question)\s*(\d+)\s*(?:[\[\(]\s*(D[ễe]|Trung\s*b[ìi]nh|TB|Kh[óo])\s*[\]\)])?\s*[\.\):\-]?\s*(.*)$/iu';
        $reSo  = '/^(\d+)\s*(?:[\[\(]\s*(D[ễe]|Trung\s*b[ìi]nh|TB|Kh[óo])\s*[\]\)])?\s*[\.\)]\s*(.+)$/u';

        $coCau = false;
        foreach ($dong as $d) {
            if (preg_match($reCau, trim((string) $d['text']))) {
                $coCau = true;
                break;
            }
        }
        $reBatDau = $coCau ? $reCau : $reSo;

        $cauHoi = [];
        $hien   = null;
        $tt     = '';     // noidung | phuongan | giaithich | sauDapAn
        $cuoi   = -1;     // chỉ số phương án vừa đọc (0 = A)
        $bangDapAnCuoi = []; // [số thứ tự câu => chỉ số đáp án 0..3] từ bảng đáp án cuối file

        foreach ($dong as $d) {
            $so    = (int) $d['so'];
            $t     = trim((string) $d['text']);
            $marks = is_array($d['marks'] ?? null) ? $d['marks'] : [];
            if ($t === '') {
                continue;
            }

            // Kiểm tra xem dòng này có phải là dòng "Bảng đáp án cuối đề" không (ví dụ: "1.A 2.B 3.C 4.D" hoặc "1-A, 2-B, 3-C")
            if (self::thuTachBangDapAnCuoi($t, $bangDapAnCuoi)) {
                continue;
            }

            // Bỏ qua dòng tiêu đề "BẢNG ĐÁP ÁN" hoặc "--- HẾT ---" ở cuối đề
            if (preg_match('/^(?:BẢNG\s*ĐÁP\s*ÁN|ĐÁP\s*ÁN\s*THAM\s*KHẢO|[\-\=\*]{2,}\s*HẾT\s*[\-\=\*]{2,}|HẾT)$/iu', $t)) {
                $tt = 'sauDapAn';
                continue;
            }

            if (preg_match($reBatDau, $t, $m)) {
                // Nếu đang ở kiểu số "1." mà nội dung bắt đầu bằng A. B. C. D. thì không phải câu mới
                if ($hien !== null) {
                    self::chotDapAnTuMark($hien);
                    $cauHoi[] = $hien;
                }
                $soThuTu = (int) $m[1];
                $dkRieng = self::chuanDoKhoNhan((string) ($m[2] ?? ''));
                $ndDau   = trim((string) ($m[3] ?? ''));
                // Nếu đầu nội dung vẫn còn nhãn [Khó] hoặc (Dễ)
                if ($dkRieng === '' && preg_match('/^[\[\(]\s*(D[ễe]|Trung\s*b[ìi]nh|TB|Kh[óo])\s*[\]\)]\s*[:\.\-]?\s*(.*)$/iu', $ndDau, $mdk)) {
                    $dkRieng = self::chuanDoKhoNhan($mdk[1]);
                    $ndDau   = trim($mdk[2]);
                }

                $hien = [
                    'so_cau'     => $soThuTu,
                    'dong'       => $so,
                    'noidung'    => $ndDau,
                    'dapan'      => ['', '', '', ''],
                    'dung'       => null,
                    'dokho'      => $dkRieng,
                    'giaithich'  => '',
                    'loi'        => [],
                    'marks_full' => [],
                    'marks_let'  => [],
                ];
                $tt   = 'noidung';
                $cuoi = -1;
                continue;
            }

            if ($hien === null) {
                continue;   // tiêu đề hoặc lời dẫn trước câu đầu tiên
            }

            // Dòng khai báo Độ khó riêng (ví dụ: "Độ khó: Khó" hoặc "Mức độ: Dễ")
            if (preg_match('/^(?:Độ\s*khó|Mức\s*độ)\s*[:\.\-]\s*(D[ễe]|Trung\s*b[ìi]nh|TB|Kh[óo])\s*$/iu', $t, $m)) {
                $hien['dokho'] = self::chuanDoKhoNhan($m[1]);
                continue;
            }

            // Dòng Giải thích / Lời giải
            if (preg_match('/^(?:Giải\s*thích|Lời\s*giải|Hướng\s*dẫn(?:\s*giải)?|Ghi\s*chú)\s*[:\.\-]\s*(.*)$/iu', $t, $m)) {
                $hien['giaithich'] .= ($hien['giaithich'] === '' ? '' : "\n") . trim($m[1]);
                $tt = 'giaithich';
                continue;
            }

            // Dòng Đáp án đúng (chấp nhận cả "Đáp án: B", "Đáp án đúng là: Câu B", "ĐA: B. Hà Nội", "Chọn B")
            if (preg_match('/^(?:Đáp\s*án(?:\s*đúng)?(?:\s*là)?|ĐA|Dap\s*an|Answer|Chọn(?:\s*đáp\s*án)?)\s*[:\.\-\s]\s*(?:câu\s*|phương\s*án\s*)?\**\s*([A-Da-d])(?![\p{L}\d])/iu', $t, $m)) {
                self::datDung($hien, ord(strtoupper($m[1])) - 65);
                $tt = 'sauDapAn';
                continue;
            }

            if ($tt === 'noidung' || $tt === 'phuongan') {
                if ($tt === 'phuongan' && preg_match('/^\*?\s*[E-H]\s*[\.\):]\s*\S/u', $t)) {
                    $hien['loi'][] = 'Có hơn 4 phương án trả lời (hệ thống chỉ nhận 4 phương án A, B, C, D).';
                    continue;
                }
                // Chấp nhận cả trường hợp viết sát "A.Hà Nội" hoặc "*A. Hà Nội" hoặc "A*. Hà Nội"
                if (preg_match('/^(?:\*\s*)?[A-D](?:\s*\*)?\s*[\.\):]\s*\S/u', $t)) {
                    $ds = self::tachPhuongAn($t);
                    if ($ds[0]['idx'] > $cuoi) {   // phương án đi theo thứ tự tăng dần A, B, C, D
                        foreach ($ds as $p) {
                            $hien['dapan'][$p['idx']] = $p['text'];
                            if ($p['sao']) {
                                self::datDung($hien, $p['idx']);
                            }
                            $chuCai = chr(65 + $p['idx']);
                            if (($marks[$chuCai] ?? '') === 'full') {
                                $hien['marks_full'][$p['idx']] = true;
                            } elseif (($marks[$chuCai] ?? '') === 'letter') {
                                $hien['marks_let'][$p['idx']] = true;
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
                $hien['noidung'] .= ($hien['noidung'] === '' ? '' : "\n") . $t;
            } elseif ($tt === 'phuongan' && $cuoi >= 0) {
                $hien['dapan'][$cuoi] .= ' ' . $t;
            } elseif ($tt === 'giaithich') {
                $hien['giaithich'] .= ($hien['giaithich'] === '' ? '' : "\n") . $t;
            }
        }

        if ($hien !== null) {
            self::chotDapAnTuMark($hien);
            $cauHoi[] = $hien;
        }

        // Nếu có bảng đáp án ở cuối đề (1.A 2.B ...), tự động điền đáp án cho những câu chưa có đáp án
        if ($bangDapAnCuoi) {
            foreach ($cauHoi as $i => &$q) {
                if ($q['dung'] === null) {
                    $soCau = (int) ($q['so_cau'] ?? 0);
                    $thuTu = $i + 1;
                    if ($soCau > 0 && isset($bangDapAnCuoi[$soCau])) {
                        $q['dung'] = $bangDapAnCuoi[$soCau];
                    } elseif (isset($bangDapAnCuoi[$thuTu])) {
                        $q['dung'] = $bangDapAnCuoi[$thuTu];
                    }
                }
                unset($q['marks_full'], $q['marks_let'], $q['so_cau']);
            }
            unset($q);
        } else {
            foreach ($cauHoi as &$q) {
                unset($q['marks_full'], $q['marks_let'], $q['so_cau']);
            }
            unset($q);
        }

        return $cauHoi;
    }

    // Nếu câu hỏi chưa có dòng "Đáp án:" hoặc dấu "*", kiểm tra xem có đúng 1 phương án được gạch chân/tô đỏ/in đậm không
    private static function chotDapAnTuMark(array &$hien): void
    {
        if ($hien['dung'] !== null) {
            return;
        }
        $full = array_keys($hien['marks_full'] ?? []);
        if (count($full) === 1) {
            $hien['dung'] = (int) $full[0];
            return;
        }
        $let = array_keys($hien['marks_let'] ?? []);
        if (count($let) === 1) {
            $hien['dung'] = (int) $let[0];
        }
    }

    // Nhận diện dòng chứa bảng đáp án cuối đề, ví dụ: "1.A 2.B 3.C 4.D" hoặc "1-A | 2-B | 3-C" hoặc "Câu 1: A, Câu 2: B"
    private static function thuTachBangDapAnCuoi(string $t, array &$bangDapAnCuoi): bool
    {
        // Phải có ít nhất 2 cặp (Số - Chữ cái A..D) trên cùng một dòng và toàn bộ dòng chỉ chứa các cặp đáp án
        $sach = trim((string) preg_replace('/^(?:Đáp\s*án|Bảng\s*đáp\s*án)\s*[:\.\-]?\s*/iu', '', $t));
        if (!preg_match_all('/(?:C[âa]u\s*)?(\d{1,3})\s*[\.\-:\)\s]\s*([A-Da-d])(?![\p{L}\d])/iu', $sach, $mm, PREG_SET_ORDER)) {
            return false;
        }
        if (count($mm) < 2) {
            return false;
        }
        // Kiểm tra sau khi xóa hết các cặp "1.A" và dấu ngăn cách (, ; | - khoảng trắng) thì dòng phải trống
        $conLai = preg_replace('/(?:C[âa]u\s*)?\d{1,3}\s*[\.\-:\)\s]\s*[A-Da-d](?![\p{L}\d])/iu', '', $sach);
        $conLai = trim((string) preg_replace('/[\s,;|\/\-]+/u', '', (string) $conLai));
        if ($conLai !== '') {
            return false;
        }
        foreach ($mm as $cap) {
            $bangDapAnCuoi[(int) $cap[1]] = ord(strtoupper($cap[2])) - 65;
        }
        return true;
    }

    private static function chuanDoKhoNhan(string $s): string
    {
        $k = self::khoa($s);
        if ($k === '') {
            return '';
        }
        if (in_array($k, ['dễ', 'de'], true)) {
            return 'Dễ';
        }
        if (in_array($k, ['khó', 'kho'], true)) {
            return 'Khó';
        }
        if (in_array($k, ['trung bình', 'trung binh', 'tb'], true)) {
            return 'Trung bình';
        }
        return '';
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
        $manh = preg_split('/\s+(?=(?:\*\s*)?[A-D](?:\s*\*)?\s*[\.\):]\s*\S)/u', $dong);
        $kq   = [];
        $truoc = -1;
        foreach ((array) $manh as $m) {
            if (!preg_match('/^(\*)?\s*([A-D])\s*(\*)?\s*[\.\):]\s*(.*)$/su', (string) $m, $mm)) {
                return self::mot($dong);
            }
            $idx = ord($mm[2]) - 65;
            if ($idx <= $truoc) {
                return self::mot($dong);
            }
            $truoc = $idx;
            $sao = ($mm[1] !== '') || ($mm[3] !== '');
            $kq[] = ['idx' => $idx, 'sao' => $sao, 'text' => trim($mm[4])];
        }
        return $kq ?: self::mot($dong);
    }

    private static function mot(string $dong): array
    {
        preg_match('/^(\*)?\s*([A-D])\s*(\*)?\s*[\.\):]\s*(.*)$/su', $dong, $mm);
        $sao = !empty($mm[1]) || !empty($mm[3]);
        return [['idx' => ord($mm[2]) - 65, 'sao' => $sao, 'text' => trim((string) ($mm[4] ?? ''))]];
    }

    // Chuyển danh sách câu hỏi tách từ Word sang định dạng dòng chuẩn để kiểm tra bằng kiemTraBang()
    public static function chuanBiDongTuWord(array $cauWord, string $tenMon, string $tenKT, string $doKhoMacDinh): array
    {
        $dong = [];
        foreach ($cauWord as $q) {
            $dk = !empty($q['dokho']) ? (string) $q['dokho'] : $doKhoMacDinh;
            $dong[] = [
                'so'  => (int) $q['dong'],
                'loi_word' => $q['loi'] ?? [],
                'o'   => [
                    $tenMon,
                    $tenKT,
                    $dk,
                    (string) $q['noidung'],
                    (string) ($q['dapan'][0] ?? ''),
                    (string) ($q['dapan'][1] ?? ''),
                    (string) ($q['dapan'][2] ?? ''),
                    (string) ($q['dapan'][3] ?? ''),
                    $q['dung'] === null ? '' : chr(65 + (int) $q['dung']),
                    (string) ($q['giaithich'] ?? ''),
                ],
            ];
        }
        return $dong;
    }

    // Biến các câu tách được thành các dòng Excel (10 cột mẫu + 3 cột kiểm tra) kèm trạng thái
    public static function bangTuWord(PDO $pdo, array $cauWord, string $tenMon, string $tenKT, string $doKho): array
    {
        $dong = self::chuanBiDongTuWord($cauWord, $tenMon, $tenKT, $doKho);
        $kq   = self::kiemTraBang($pdo, $dong);
        $bang = [];
        foreach ($kq as $i => $r) {
            $loi = array_merge($cauWord[$i]['loi'] ?? [], $r['loi']);
            $tt  = $r['trangThai'];
            if (!empty($cauWord[$i]['loi'])) {
                $tt = 'Lỗi';
            }
            $bang[] = array_merge($r['o'], [$tt, implode('; ', array_unique($loi)), (int) $cauWord[$i]['dong']]);
        }
        return $bang;
    }

    public static function kiemTraTieuDe(array $o): void
    {
        foreach (self::COT as $i => $ten) {
            if (self::khoa((string) ($o[$i] ?? '')) !== self::khoa($ten)) {
                throw new RuntimeException('Dòng tiêu đề không đúng mẫu (cột ' . ($i + 1) . ' phải là "' . $ten . '"). '
                    . 'Hãy tải file mẫu về điền hoặc dùng trực tiếp chức năng Nhập từ file Word.');
            }
        }
    }

    // Kiểm tra từng dòng từ Excel/Word (chưa ghi CSDL). Trả về trạng thái Hợp lệ | Lỗi | Trùng.
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
        $doKhoMap['tb'] = 'Trung bình';
        $doKhoMap['de'] = 'Dễ';
        $doKhoMap['kho'] = 'Khó';

        // Nạp MỘT lần nội dung câu hỏi đã có của các môn xuất hiện trong file
        $monTrongFile = [];
        foreach ($dong as $d) {
            $id = $mon[self::khoa((string) ($d['o'][0] ?? ''))] ?? 0;
            if ($id > 0) {
                $monTrongFile[$id] = true;
            }
        }
        $daCoTrongDB = self::taiNoiDungDaCo($pdo, array_keys($monTrongFile));

        $ketQua = [];
        $daThay = [];   // khóa (môn + nội dung) => số dòng đầu tiên, để bắt trùng ngay trong file
        foreach ($dong as $d) {
            $o = [];
            for ($i = 0; $i < 10; $i++) {
                $o[$i] = trim((string) ($d['o'][$i] ?? ''));
            }
            $loi = is_array($d['loi_word'] ?? null) ? $d['loi_word'] : [];

            // Môn học
            $maMon = $mon[self::khoa($o[0])] ?? 0;
            if ($o[0] === '') {
                $loi[] = 'Thiếu tên môn học.';
            } elseif ($maMon === 0) {
                $loi[] = 'Không tìm thấy môn học "' . $o[0] . '" (phải đúng tên môn trong hệ thống).';
            }

            // Loại kỳ thi: để trống hoặc "Cả hai kỳ" nghĩa là dùng cho cả hai kỳ
            $maKT = '';
            $kk   = self::khoa($o[1]);
            if ($kk !== '' && !in_array($kk, ['cả hai', 'cả hai kỳ', 'cả hai loại', 'chung', 'dùng được cho cả hai kỳ'], true)) {
                if (isset($kt[$kk])) {
                    $maKT = $kt[$kk];
                } else {
                    $loi[] = 'Không tìm thấy loại kỳ thi "' . $o[1] . '".';
                }
            }

            $doKho = $doKhoMap[self::khoa($o[2])] ?? $o[2];
            $o[2]  = $doKho;

            // Đáp án đúng: chữ cái A đến D
            $dung = -1;
            if ($o[8] !== '') {
                $c = mb_strtoupper(mb_substr($o[8], 0, 1));
                if (in_array($c, ['A', 'B', 'C', 'D'], true)) {
                    $dung = ord($c) - 65;
                    $o[8] = $c;
                } else {
                    $loi[] = 'Đáp án đúng chỉ nhận A, B, C hoặc D.';
                    $dung  = -2;
                }
            }

            [$l2, $sach] = CauHoiHelper::kiemTra($pdo, [
                'mon' => $maMon, 'loai' => $maKT, 'dokho' => $doKho, 'noidung' => $o[3], 'giaithich' => $o[9],
                'dapan' => [$o[4], $o[5], $o[6], $o[7]], 'dung' => $dung,
            ], 0, true, true);
            if ($maMon === 0) {
                $l2 = array_values(array_diff($l2, ['Hãy chọn môn học.']));
            }
            $loi = array_values(array_unique(array_merge($loi, $l2)));

            $tt = 'Hợp lệ';
            if ($loi || $sach === null) {
                $tt   = 'Lỗi';
                $sach = null;
            } else {
                $khoa = $maMon . '|' . self::khoa($sach['noidung']);
                $trungDB = $daCoTrongDB[$khoa] ?? null;
                if (isset($daThay[$khoa])) {
                    $tt    = 'Trùng';
                    $loi[] = 'Trùng nội dung với câu ở dòng ' . $daThay[$khoa] . ' trong cùng file.';
                } elseif ($trungDB !== null) {
                    $tt    = 'Trùng';
                    $loi[] = 'Đã có câu hỏi #' . $trungDB . ' cùng nội dung trong môn này.';
                    $daThay[$khoa] = $d['so'];
                } else {
                    $daThay[$khoa] = $d['so'];
                }
                if ($tt === 'Trùng') {
                    $sach = null;   // câu trùng không được thêm
                }
            }

            $ketQua[] = ['so' => $d['so'], 'o' => $o, 'trangThai' => $tt, 'loi' => $loi, 'sach' => $sach];
        }
        return $ketQua;
    }

    // Trả về [ 'MaMon|nội dung đã chuẩn hóa' => mã câu hỏi nhỏ nhất ] của các câu đã có trong CSDL thuộc các môn $dsMon
    private static function taiNoiDungDaCo(PDO $pdo, array $dsMon): array
    {
        $kq = [];
        if (!$dsMon) {
            return $kq;
        }
        $st = $pdo->prepare('SELECT MaMon, MaCauHoi, NoiDung FROM CAUHOI WHERE MaMon IN ('
            . implode(',', array_fill(0, count($dsMon), '?')) . ') ORDER BY MaCauHoi');
        $st->execute(array_values($dsMon));
        foreach ($st as $r) {
            $k = (int) $r['MaMon'] . '|' . self::khoa(CauHoiHelper::chuan((string) $r['NoiDung']));
            if (!isset($kq[$k])) {
                $kq[$k] = (int) $r['MaCauHoi'];
            }
        }
        return $kq;
    }

    // Chuẩn hóa để so sánh tên: bỏ khoảng trắng thừa, không phân biệt hoa thường
    private static function khoa(string $s): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
    }
}
