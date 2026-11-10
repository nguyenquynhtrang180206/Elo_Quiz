<?php
// Số liệu Thống kê khảo sát dùng chung cho hộp thoại "Xuất" (admin/khaosat_du_lieu.php xem trước, admin/khaosat_xuat.php xuất file).
// Công thức đếm giống admin/khaosat.php: ?tu=YYYY-MM-DD&den=YYYY-MM-DD, "Chưa hoàn thành" = trạng thái 'Bỏ qua'.
class KhaoSatThongKe
{
    public const MUC_TIEU = [
        'kiem_tra_kien_thuc' => 'Kiểm tra đánh giá kiến thức',
        'on_luyen_nhanh'     => 'Ôn luyện nhanh chóng',
        'ghi_nho_lau'        => 'Ghi nhớ lâu',
        'ap_luc_thoi_gian'   => 'Rèn luyện áp lực thời gian',
        'so_hoa_du_lieu'     => 'Số hóa dữ liệu học tập',
        'xoa_so_thi'         => 'Xóa bỏ tâm lý sợ thi cử',
    ];
    public const LINH_VUC = [
        'toan_hoc'  => 'Toán học',
        'tin_hoc'   => 'Tin học',
        'khoa_hoc'  => 'Khoa học',
        'tieng_anh' => 'Tiếng Anh',
        'lich_su'   => 'Lịch sử',
    ];
    public const TRINH_DO = ['Mới bắt đầu', 'Trung bình', 'Khá', 'Nâng cao'];

    // Các phần có thể chọn xuất (mã => nhãn hiển thị trong hộp thoại)
    public const TRUONG = [
        'the'      => 'Số liệu tổng quan (4 ô)',
        'mucTieu'  => 'Mục tiêu tham gia',
        'linhVuc'  => 'Lĩnh vực yêu thích',
        'trinhDo'  => 'Trình độ tự đánh giá',
        'chiTiet'  => 'Chi tiết câu hỏi khảo sát',
        'sinhVien' => 'Danh sách từng sinh viên',
    ];
    // Các cột của danh sách sinh viên (mã => tiêu đề cột)
    public const COT_SV = [
        'hoTen'     => 'Sinh viên',
        'lop'       => 'Lớp',
        'trangThai' => 'Trạng thái',
        'thoiGian'  => 'Thời gian',
        'mucTieu'   => 'Mục tiêu tham gia',
        'linhVuc'   => 'Lĩnh vực yêu thích',
        'trinhDo'   => 'Trình độ',
    ];
    public const TOI_DA_SV = 500;

    public static function docNgay(?string $s): ?DateTimeImmutable
    {
        if ($s === null || $s === '') { return null; }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        $loi = DateTimeImmutable::getLastErrors();
        if (!$d || ($loi && ($loi['warning_count'] || $loi['error_count']))) { return null; }
        return $d;
    }

    // Khoảng ngày từ tham số tu/den (mặc định tháng hiện tại; chọn ngược thì đổi chỗ)
    public static function khoang(array $nguon): array
    {
        $hn  = new DateTimeImmutable('today');
        $tu  = self::docNgay($nguon['tu'] ?? null)  ?? $hn->modify('first day of this month');
        $den = self::docNgay($nguon['den'] ?? null) ?? $hn->modify('last day of this month');
        return $tu > $den ? [$den, $tu] : [$tu, $den];
    }

    public static function so(int|float $v, int $le = 0): string { return number_format($v, $le, ',', '.'); }

    private static function demTrangThai(PDO $pdo, DateTimeImmutable $tu, DateTimeImmutable $den): array
    {
        $kq = ['Hoàn tất' => 0, 'Bỏ qua' => 0];
        $st = $pdo->prepare(
            "SELECT TrangThaiKhaoSat, COUNT(*) FROM NGUOIDUNG
             WHERE TrangThaiKhaoSat <> 'Chưa làm' AND NgayKhaoSat >= ? AND NgayKhaoSat < ? GROUP BY TrangThaiKhaoSat"
        );
        $st->execute([$tu->format('Y-m-d'), $den->modify('+1 day')->format('Y-m-d')]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$tt, $so]) { $kq[$tt] = (int) $so; }
        return $kq;
    }

    private static function demNhieuMa(array $dong, string $cot, array $ds): array
    {
        $dem = array_fill_keys(array_keys($ds), 0);
        foreach ($dong as $r) {
            foreach (array_unique(array_map('trim', explode(',', (string) $r[$cot]))) as $ma) {
                if (isset($dem[$ma])) { $dem[$ma]++; }
            }
        }
        return $dem;
    }

    private static function tyLe(array $dem): array
    {
        $t = array_sum($dem);
        return array_map(static fn ($v) => $t > 0 ? round($v * 100 / $t, 1) : 0, $dem);
    }

    private static function nhan(?string $chuoi, array $ds): string
    {
        $kq = [];
        foreach (array_unique(array_map('trim', explode(',', (string) $chuoi))) as $ma) {
            if ($ma !== '' && isset($ds[$ma])) { $kq[] = $ds[$ma]; }
        }
        return $kq ? implode(', ', $kq) : '—';
    }

    private static function nhieuNhat(array $dem, array $nhan): array
    {
        if (!$dem || max($dem) === 0) { return ['dapAn' => '—', 'so' => 0]; }
        $ma = array_search(max($dem), $dem, true);
        return ['dapAn' => $nhan[$ma] ?? $ma, 'so' => $dem[$ma]];
    }

    public static function tinh(PDO $pdo, DateTimeImmutable $tu, DateTimeImmutable $den): array
    {
        $soNgay = (int) $tu->diff($den)->days + 1;
        $tuS = $tu->format('Y-m-d');
        $denS = $den->modify('+1 day')->format('Y-m-d');

        $ht = self::demTrangThai($pdo, $tu, $den);
        $hoanTat = $ht['Hoàn tất'];
        $chua = $ht['Bỏ qua'];
        $tong = $hoanTat + $chua;
        $truoc = self::demTrangThai($pdo, $tu->modify('-' . $soNgay . ' days'), $tu->modify('-1 day'));
        $tongTruoc = $truoc['Hoàn tất'] + $truoc['Bỏ qua'];
        $soVoi = $tongTruoc > 0 ? round(($tong - $tongTruoc) * 100 / $tongTruoc, 1) : null;

        $st = $pdo->prepare("SELECT MAX(NgayKhaoSat) FROM NGUOIDUNG WHERE TrangThaiKhaoSat <> 'Chưa làm' AND NgayKhaoSat >= ? AND NgayKhaoSat < ?");
        $st->execute([$tuS, $denS]);
        $gn = $st->fetchColumn() ?: null;

        $st = $pdo->prepare("SELECT MucTieuKhaoSat, LinhVucYeuThich, TrinhDoTuDanhGia FROM NGUOIDUNG WHERE TrangThaiKhaoSat = 'Hoàn tất' AND NgayKhaoSat >= ? AND NgayKhaoSat < ?");
        $st->execute([$tuS, $denS]);
        $dong = $st->fetchAll(PDO::FETCH_ASSOC);

        $lMT = self::demNhieuMa($dong, 'MucTieuKhaoSat', self::MUC_TIEU);
        $lLV = self::demNhieuMa($dong, 'LinhVucYeuThich', self::LINH_VUC);
        $lTD = array_fill_keys(self::TRINH_DO, 0);
        foreach ($dong as $r) { if (isset($lTD[$r['TrinhDoTuDanhGia']])) { $lTD[$r['TrinhDoTuDanhGia']]++; } }

        $chiTiet = [
            ['cau' => 'Bạn tham gia Elo_Quiz với mục đích gì?']              + self::nhieuNhat($lMT, self::MUC_TIEU),
            ['cau' => 'Bạn yêu thích lĩnh vực nào nhất?']                     + self::nhieuNhat($lLV, self::LINH_VUC),
            ['cau' => 'Bạn tự đánh giá trình độ hiện tại của mình như thế nào?'] + self::nhieuNhat($lTD, array_combine(self::TRINH_DO, self::TRINH_DO)),
        ];
        foreach ($chiTiet as &$c) { $c['tyLe'] = $hoanTat > 0 ? round($c['so'] * 100 / $hoanTat, 1) : 0; }
        unset($c);

        // Từng sinh viên (mới nhất trước), bỏ tài khoản admin như trang chính
        $st = $pdo->prepare(
            "SELECT HoTen, Lop, TrangThaiKhaoSat, NgayKhaoSat, MucTieuKhaoSat, LinhVucYeuThich, TrinhDoTuDanhGia FROM NGUOIDUNG
             WHERE TrangThaiKhaoSat <> 'Chưa làm' AND NgayKhaoSat >= ? AND NgayKhaoSat < ?
               AND MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)
             ORDER BY NgayKhaoSat DESC, MaND DESC LIMIT " . self::TOI_DA_SV
        );
        $st->execute([$tuS, $denS]);
        $sv = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $xong = $r['TrangThaiKhaoSat'] === 'Hoàn tất';
            $sv[] = [
                'hoTen' => (string) $r['HoTen'], 'lop' => (string) ($r['Lop'] ?: '—'),
                'trangThai' => $xong ? 'Hoàn tất' : 'Chưa hoàn thành',
                'thoiGian' => date('d/m/Y H:i', strtotime($r['NgayKhaoSat'])),
                'mucTieu' => $xong ? self::nhan($r['MucTieuKhaoSat'], self::MUC_TIEU) : '—',
                'linhVuc' => $xong ? self::nhan($r['LinhVucYeuThich'], self::LINH_VUC) : '—',
                'trinhDo' => $xong ? ($r['TrinhDoTuDanhGia'] ?: '—') : '—',
            ];
        }

        return [
            'khoang' => $tu->format('d/m/Y') . ' – ' . $den->format('d/m/Y') . ' (' . $soNgay . ' ngày)',
            'the' => [
                ['Tổng lượt khảo sát', self::so($tong), $soVoi === null ? 'Kỳ trước chưa có dữ liệu' : ($soVoi >= 0 ? '+' : '') . self::so($soVoi, 1) . '% so với kỳ trước'],
                ['Đã hoàn thành', self::so($hoanTat), ($tong > 0 ? self::so(round($hoanTat * 100 / $tong, 1), 1) : '0') . '%'],
                ['Chưa hoàn thành', self::so($chua), ($tong > 0 ? self::so(round($chua * 100 / $tong, 1), 1) : '0') . '%'],
                ['Thời gian khảo sát gần nhất', $gn ? date('d/m/Y H:i:s', strtotime($gn)) : '—', ''],
            ],
            'mucTieu' => ['nhan' => array_values(self::MUC_TIEU), 'luot' => array_values($lMT), 'tyLe' => array_values(self::tyLe($lMT))],
            'linhVuc' => ['nhan' => array_values(self::LINH_VUC), 'luot' => array_values($lLV), 'tyLe' => array_values(self::tyLe($lLV))],
            'trinhDo' => ['nhan' => self::TRINH_DO,               'luot' => array_values($lTD), 'tyLe' => array_values(self::tyLe($lTD))],
            'chiTiet' => $chiTiet,
            'hoanTat' => $hoanTat,
            'sinhVien' => $sv,
        ];
    }
}