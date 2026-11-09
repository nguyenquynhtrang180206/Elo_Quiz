<?php
// Ghi nhật ký quản trị (A-40) và dựng điều kiện lọc dùng chung (A-41). Chỉ ghi, không sửa/xóa (A-42).
// Cách dùng: NhatKy::ghi('Khóa người dùng', 'NGUOIDUNG #15', 'Lý do: spam bài đăng');
//            NhatKy::ghiThatBai('Import câu hỏi từ Excel', 'CAUHOI', 'Lỗi dòng 5');   // hoặc ghi(..., 'Thất bại')
// Chỉ gọi cho các thao tác ở A-40: khóa/mở khóa người dùng, ẩn/hiện câu hỏi, import Excel,
// hủy trận, ẩn/hiện nội dung cộng đồng, đổi cấu hình.
class NhatKy
{
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
        $st = Database::pdo()->prepare(
            'INSERT INTO NHATKYQUANTRI (MaAdmin, HanhDong, DoiTuong, MoTa, DiaChiIP, TrangThai) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$maAdmin, $hanhDong, $doiTuong, $moTa, $ip, $trangThai]);
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
        if ($tt !== '') { $dk[] = 'n.TrangThai = ?'; $th[] = $tt;  $loc['tt'] = $tt; }
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
}