<?php
// Ghi nhật ký quản trị (A-40) và dựng điều kiện lọc dùng chung (A-41). Chỉ ghi, không sửa/xóa (A-42).
// Cách dùng: NhatKy::ghi('Khóa người dùng', 'NGUOIDUNG #15', 'Lý do: spam bài đăng');
//            NhatKy::ghiThatBai('Import câu hỏi từ Excel', 'CAUHOI', 'Lỗi dòng 5');   // hoặc ghi(..., 'Thất bại')
// Chỉ gọi cho các thao tác ở A-40: khóa/mở khóa người dùng, ẩn/hiện câu hỏi, import Excel,
// hủy trận, ẩn/hiện nội dung cộng đồng, đổi cấu hình.
class NhatKy
{
    // Chỉ giữ nhật ký trong số tháng này; bản ghi cũ hơn bị xóa tự động mỗi lần có thao tác mới được ghi (xem donCu).
    public const GIU_THANG = 3;

    // Nhóm hành động (dùng cho chip lọc, màu nhãn và biểu đồ cơ cấu). THỨ TỰ TRONG MẢNG LÀ THỨ TỰ ƯU TIÊN:
    // 'Mở khóa người dùng' chứa chữ 'khóa' nhưng phải rơi vào nhóm 'mo' chứ không phải 'khoa', nên 'mo' đứng trước.
    // Mỗi nhóm: khóa => [nhãn hiển thị, lớp màu, [từ khóa trong tên hành động]]
    public const NHOM = [
        'xoa'  => ['Xóa / Hủy',   'do',   ['Xóa', 'Hủy']],
        'mo'   => ['Thêm / Mở',   'luc',  ['Mở khóa', 'Hiện', 'Thêm', 'Import']],
        'khoa' => ['Khóa / Ẩn',   'cam',  ['Khóa', 'Ẩn']],
        'sua'  => ['Cập nhật',    'xanh', ['Sửa', 'Đổi', 'Cập nhật']],
        'xuat' => ['Xuất dữ liệu', 'tim',  ['Xuất']],
    ];
    // Thứ tự hiện các chip trên giao diện
    public const NHOM_HIEN_THI = ['mo', 'sua', 'xoa', 'khoa', 'xuat'];

    // Trả về khóa nhóm của một hành động, hoặc null nếu không thuộc nhóm nào
    public static function nhomCuaHanhDong(string $hanhDong): ?string
    {
        foreach (self::NHOM as $khoa => [, , $tuKhoa]) {
            foreach ($tuKhoa as $k) {
                if (mb_stripos($hanhDong, $k) !== false) {
                    return $khoa;
                }
            }
        }
        return null;
    }

    // Điều kiện SQL cho một nhóm: khớp từ khóa của nhóm VÀ không khớp từ khóa của các nhóm đứng trước (cùng quy tắc ưu tiên như trên)
    private static function dieuKienNhom(string $khoa): array
    {
        $like  = static fn (string $t): string => '%' . addcslashes($t, '\\%_') . '%';
        $truoc = [];
        foreach (self::NHOM as $k => [, , $tuKhoa]) {
            if ($k === $khoa) {
                break;
            }
            array_push($truoc, ...$tuKhoa);
        }
        $rieng = self::NHOM[$khoa][2];
        $cau   = static fn (array $ds): string => '(' . implode(' OR ', array_fill(0, count($ds), 'n.HanhDong LIKE ?')) . ')';
        $sql   = $cau($rieng);
        $tham  = array_map($like, $rieng);
        if ($truoc) {
            $sql .= ' AND NOT ' . $cau($truoc);
            array_push($tham, ...array_map($like, $truoc));
        }
        return ['(' . $sql . ')', $tham];
    }

    public static function damBaoCot(?PDO $pdo = null): bool
    {
        static $coCot = null;
        if ($coCot !== null) {
            return $coCot;
        }
        $pdo = $pdo ?? Database::pdo();
        try {
            $co = $pdo->query("SHOW COLUMNS FROM NHATKYQUANTRI LIKE 'TrangThai'")->fetch();
            if (!$co) {
                $pdo->exec("
                    ALTER TABLE NHATKYQUANTRI
                      ADD COLUMN DiaChiIP  VARCHAR(45) NULL AFTER MoTa,
                      ADD COLUMN TrangThai ENUM('Thành công','Thất bại') NOT NULL DEFAULT 'Thành công' AFTER DiaChiIP,
                      ADD KEY idx_nhatky_tt (TrangThai, ThoiGian)
                ");
                $coCot = true;
            } else {
                $coCot = true;
            }
        } catch (Throwable $e) {
            try {
                $coCot = (bool) $pdo->query("SHOW COLUMNS FROM NHATKYQUANTRI LIKE 'TrangThai'")->fetch();
            } catch (Throwable $ex) {
                $coCot = false;
            }
        }
        return $coCot;
    }

    public static function coCotTrangThai(?PDO $pdo = null): bool
    {
        return self::damBaoCot($pdo);
    }

    public static function ghi(string $hanhDong, ?string $doiTuong = null, ?string $moTa = null,
                               string $trangThai = 'Thành công'): void
    {
        $maAdmin = Auth::adminId();
        if ($maAdmin === null) {
            throw new RuntimeException('Chỉ quản trị viên đã đăng nhập mới ghi được nhật ký.');
        }
        if (!in_array($trangThai, ['Thành công', 'Thất bại'], true)) {
            $trangThai = 'Thành công';
        }
        // Cắt theo độ dài cột (HanhDong, DoiTuong: 100; DiaChiIP: 45) để không lỗi khi chuỗi quá dài
        $hanhDong = mb_substr($hanhDong, 0, 100);
        $doiTuong = $doiTuong !== null ? mb_substr($doiTuong, 0, 100) : null;
        $ip       = isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null;

        $pdo = Database::pdo();
        self::damBaoCot($pdo);
        if (self::coCotTrangThai($pdo)) {
            $st = $pdo->prepare(
                'INSERT INTO NHATKYQUANTRI (MaAdmin, HanhDong, DoiTuong, MoTa, DiaChiIP, TrangThai) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $st->execute([$maAdmin, $hanhDong, $doiTuong, $moTa, $ip, $trangThai]);
        } else {
            $st = $pdo->prepare(
                'INSERT INTO NHATKYQUANTRI (MaAdmin, HanhDong, DoiTuong, MoTa) VALUES (?, ?, ?, ?)'
            );
            $st->execute([$maAdmin, $hanhDong, $doiTuong, $moTa]);
        }
        self::donCu();
    }

    // Xóa bản ghi cũ hơn GIU_THANG tháng (tính theo giờ của máy chủ CSDL, cùng đồng hồ với cột ThoiGian).
    // Đây là dọn dẹp tự động của hệ thống, không phải thao tác xóa của quản trị viên (A-42 vẫn giữ nguyên: giao diện không có nút sửa/xóa).
    // Chạy sau mỗi lần ghi: thao tác quản trị ít khi xảy ra, và cột ThoiGian đã có chỉ mục nên câu lệnh nhẹ.
    // Lỗi khi dọn không được làm hỏng việc ghi nhật ký, nên chỉ ghi vào error_log.
    private static function donCu(): void
    {
        try {
            Database::pdo()
                ->prepare('DELETE FROM NHATKYQUANTRI WHERE ThoiGian < DATE_SUB(NOW(), INTERVAL ' . (int) self::GIU_THANG . ' MONTH)')
                ->execute();
        } catch (Throwable $ex) {
            error_log('Không dọn được nhật ký cũ: ' . $ex->getMessage());
        }
    }

    // Ghi một thao tác THẤT BẠI (hiện ở thẻ "Thao tác thất bại" của trang Nhật ký). Lỗi ghi nhật ký không được làm
    // hỏng luồng xử lý lỗi đang chạy, nên chỉ ghi vào error_log. Gọi SAU khi đã rollBack (nếu đang trong giao dịch),
    // vì bản ghi nằm trong giao dịch bị hoàn tác sẽ mất.
    // Ví dụ: NhatKy::ghiThatBai('Import câu hỏi từ Excel', 'CAUHOI', 'File Excel trống.');
    public static function ghiThatBai(string $hanhDong, ?string $doiTuong = null, ?string $moTa = null): void
    {
        try {
            self::ghi($hanhDong, $doiTuong, $moTa, 'Thất bại');
        } catch (Throwable $ex) {
            error_log('Không ghi được nhật ký thất bại: ' . $ex->getMessage());
        }
    }

    // Dựng điều kiện lọc từ mảng đầu vào ($_GET của trang danh sách hoặc $_POST của file xuất),
    // để cả hai nơi lọc giống hệt nhau. Câu truy vấn phải có: FROM NHATKYQUANTRI n JOIN ADMIN a ON a.MaAdmin = n.MaAdmin
    // Khóa đầu vào: q (tìm chung), ad (mã admin), hd (hành động), tt (trạng thái), tu, den (YYYY-MM-DD).
    // Trả về:
    //   'where' => chuỗi 'WHERE ...' hoặc ''      'tham' => mảng tham số theo thứ tự dấu ?
    //   'loc'   => các bộ lọc đã kiểm tra (dùng dựng link phân trang, link xuất, điền lại form)
    //   'loi'   => thông báo lỗi (chuỗi rỗng nếu không có)
    public static function dieuKien(array $in): array
    {
        $q   = mb_substr(trim((string) ($in['q'] ?? '')), 0, 100);
        $ad  = (int) ($in['ad'] ?? 0);
        $hd  = trim((string) ($in['hd'] ?? ''));
        $tt  = (string) ($in['tt'] ?? '');
        $nhom = (string) ($in['nhom'] ?? '');
        $tu  = (string) ($in['tu'] ?? '');
        $den = (string) ($in['den'] ?? '');

        // Ngày phải đúng dạng YYYY-MM-DD và là ngày có thật (tránh DateTime ném lỗi khi dữ liệu gửi lên bị sửa)
        $ngayHopLe = static function (string $s): bool {
            $d = DateTime::createFromFormat('Y-m-d', $s);
            return $d !== false && $d->format('Y-m-d') === $s;
        };
        if ($tu !== '' && !$ngayHopLe($tu))   { $tu = ''; }
        if ($den !== '' && !$ngayHopLe($den)) { $den = ''; }
        if (!in_array($tt, ['Thành công', 'Thất bại'], true)) { $tt = ''; }
        if (!isset(self::NHOM[$nhom])) { $nhom = ''; }

        $loi = '';
        if ($tu !== '' && $den !== '' && $tu > $den) {
            $loi = 'Ngày bắt đầu không được sau ngày kết thúc.';
            $tu = $den = '';
        }

        $dk  = [];
        $th  = [];
        $loc = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $dk[] = '(a.HoTen LIKE ? OR a.TenDangNhap LIKE ? OR n.HanhDong LIKE ? OR n.DoiTuong LIKE ? OR n.MoTa LIKE ?)';
            array_push($th, $like, $like, $like, $like, $like);
            $loc['q'] = $q;
        }
        if ($ad > 0)    { $dk[] = 'n.MaAdmin = ?';   $th[] = $ad;  $loc['ad'] = $ad; }
        if ($hd !== '') { $dk[] = 'n.HanhDong = ?';  $th[] = $hd;  $loc['hd'] = $hd; }
        if ($tt !== '' && self::coCotTrangThai()) { $dk[] = 'n.TrangThai = ?'; $th[] = $tt;  $loc['tt'] = $tt; }
        if ($nhom !== '') {
            [$sqlNhom, $thNhom] = self::dieuKienNhom($nhom);
            $dk[] = $sqlNhom;
            array_push($th, ...$thNhom);
            $loc['nhom'] = $nhom;
        }
        if ($tu !== '') {
            $dk[] = 'n.ThoiGian >= ?';
            $th[] = $tu . ' 00:00:00';
            $loc['tu'] = $tu;
        }
        if ($den !== '') {   // đến hết ngày kết thúc: nhỏ hơn 00:00 của ngày kế tiếp
            $dk[] = 'n.ThoiGian < ?';
            $th[] = (new DateTime($den))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            $loc['den'] = $den;
        }

        return [
            'where' => $dk ? 'WHERE ' . implode(' AND ', $dk) : '',
            'tham'  => $th,
            'loc'   => $loc,
            'loi'   => $loi,
        ];
    }

    // Chuẩn hóa ngày YYYY-MM-DD từ đầu vào: sai định dạng, ngày không có thật hoặc ngày tương lai thì dùng hôm nay.
    public static function chuanHoaNgay(?string $s): string
    {
        $homNay = date('Y-m-d');
        if ($s === null) {
            return $homNay;
        }
        $d = DateTime::createFromFormat('Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s || $s > $homNay) {
            return $homNay;
        }
        return $s;
    }

    // Số hoạt động theo TỪNG NGÀY cho biểu đồ (mỗi cột = 1 ngày): $soNgay ngày liên tiếp kết thúc ở $denNgay (mặc định hôm nay).
    // $soNgay chỉ nhận 7 (tuần) hoặc 30 (tháng); giá trị khác coi như 7.
    // Trả về mảng $soNgay phần tử, cũ nhất trước: ['ngay' => 'YYYY-MM-DD', 'tong' => số hoạt động, 'loi' => số thất bại].
    public static function nhipTheoNgay(PDO $pdo, int $soNgay = 7, ?string $denNgay = null): array
    {
        $soNgay = $soNgay >= 30 ? 30 : 7;
        $den    = self::chuanHoaNgay($denNgay);
        $tu     = (new DateTime($den))->modify('-' . ($soNgay - 1) . ' days')->format('Y-m-d');
        $sau    = (new DateTime($den))->modify('+1 day')->format('Y-m-d');
        self::damBaoCot($pdo);
        $thatBaiSql = self::coCotTrangThai($pdo) ? "COALESCE(SUM(TrangThai = 'Thất bại'), 0) AS loi" : "0 AS loi";
        $st = $pdo->prepare(
            "SELECT DATE(ThoiGian) AS ngay,
                    COUNT(*) AS tong,
                    $thatBaiSql
             FROM NHATKYQUANTRI
             WHERE ThoiGian >= ? AND ThoiGian < ?
             GROUP BY DATE(ThoiGian)"
        );
        $st->execute([$tu . ' 00:00:00', $sau . ' 00:00:00']);
        $theoNgay = [];
        foreach ($st->fetchAll() as $r) {
            $theoNgay[$r['ngay']] = ['tong' => (int) $r['tong'], 'loi' => (int) $r['loi']];
        }
        $kq = [];
        $d  = new DateTime($tu);
        for ($i = 0; $i < $soNgay; $i++) {
            $k    = $d->format('Y-m-d');
            $kq[] = ['ngay' => $k, 'tong' => $theoNgay[$k]['tong'] ?? 0, 'loi' => $theoNgay[$k]['loi'] ?? 0];
            $d->modify('+1 day');
        }
        return $kq;
    }

    // Cơ cấu hành động 30 ngày gần nhất (cùng mốc 30 ngày với các thẻ số liệu của trang).
    // Trả về danh sách ['khoa' => ..., 'nhan' => ..., 'lop' => ..., 'so' => ..., 'pt' => phần trăm], xếp giảm dần theo số lượng;
    // hành động không thuộc nhóm nào được gộp vào dòng 'Khác'.
    public static function coCau30Ngay(PDO $pdo): array
    {
        $moc30 = date('Y-m-d', strtotime('-29 days')) . ' 00:00:00';
        $st = $pdo->prepare('SELECT HanhDong, COUNT(*) AS so FROM NHATKYQUANTRI WHERE ThoiGian >= ? GROUP BY HanhDong');
        $st->execute([$moc30]);
        $dem = [];
        $tong = 0;
        foreach ($st->fetchAll() as $r) {
            $khoa = self::nhomCuaHanhDong((string) $r['HanhDong']) ?? 'khac';
            $dem[$khoa] = ($dem[$khoa] ?? 0) + (int) $r['so'];
            $tong += (int) $r['so'];
        }
        $kq = [];
        foreach ($dem as $khoa => $so) {
            $kq[] = [
                'khoa' => $khoa,
                'nhan' => $khoa === 'khac' ? 'Khác' : self::NHOM[$khoa][0],
                'lop'  => $khoa === 'khac' ? 'tim' : self::NHOM[$khoa][1],
                'so'   => $so,
                'pt'   => $tong > 0 ? (int) round($so * 100 / $tong) : 0,
            ];
        }
        usort($kq, static fn (array $a, array $b): int => $b['so'] <=> $a['so']);
        return $kq;
    }
}