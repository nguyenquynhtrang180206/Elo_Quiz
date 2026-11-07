<?php
// Số liệu thống kê cho chức năng xuất báo cáo (admin/thongke_xuat.php).
// Dùng đúng cách tính của trang Tổng quan (admin/index.php) để file xuất khớp với số trên màn hình.
class ThongKe
{
    public const KHOANG = [7, 30, 90, 365];
    public const MAU_MON = ['#2f6bff', '#8b5cf6', '#f5a524', '#1fb88a', '#ef476f', '#94a3b8'];   // như biểu đồ trên màn hình

    // Danh sách mốc hợp lệ (7, 30, 90, 365) từ dữ liệu gửi lên, sắp từ ngắn đến dài, bỏ trùng
    public static function locKhoang($ds): array
    {
        $kq = [];
        foreach ((array) $ds as $v) {
            $n = (int) $v;
            if (in_array($n, self::KHOANG, true)) { $kq[$n] = $n; }
        }
        sort($kq);
        return array_values($kq);
    }

    public static function nhanKhoang(int $ngay): string
    {
        return $ngay === 365 ? '1 năm' : $ngay . ' ngày';
    }

    // Khoảng ngày cụ thể của mốc, tính đến hôm nay: 7 ngày -> "01/11 đến 07/11/2026"
    // (khác năm thì ghi rõ năm cả hai đầu: 1 năm -> "08/11/2025 đến 07/11/2026")
    public static function khoangNgay(int $ngay): string
    {
        $tu  = strtotime('-' . ($ngay - 1) . ' days');
        $den = time();
        $khacNam = date('Y', $tu) !== date('Y', $den);
        return date($khacNam ? 'd/m/Y' : 'd/m', $tu) . ' đến ' . date('d/m/Y', $den);
    }

    public static function chuanHoaNgay($v): int
    {
        $n = (int) $v;
        return in_array($n, self::KHOANG, true) ? $n : 7;
    }

    // % thay đổi so với kỳ trước, dạng chữ (giống thẻ số liệu trên màn hình)
    private static function chuThayDoi(float $nay, float $truoc, bool $diem): string
    {
        if ($diem) {
            $c = round($nay - $truoc, 1);
            return ($c > 0 ? '+' : '') . $c . ' điểm %';
        }
        if ($truoc > 0) {
            $c = round(($nay - $truoc) * 100 / $truoc);
            return ($c > 0 ? '+' : '') . $c . '%';
        }
        return $nay > 0 ? 'mới' : '0%';
    }

    // Trang 1: Thống kê tổng quan
    public static function tongQuan(PDO $pdo, int $ngay): array
    {
        $tuNgay   = date('Y-m-d', strtotime('-' . ($ngay - 1) . ' days'));
        $moc      = $tuNgay . ' 00:00:00';
        $mocTruoc = date('Y-m-d', strtotime('-' . (2 * $ngay - 1) . ' days')) . ' 00:00:00';

        $tong = $pdo->query(
            'SELECT (SELECT COUNT(*) FROM NGUOIDUNG) AS nguoidung, (SELECT COUNT(*) FROM MONHOC) AS monhoc,
                    (SELECT COUNT(*) FROM CAUHOI) AS cauhoi, (SELECT COUNT(*) FROM TRANDAU) AS trandau'
        )->fetch();

        $theoTrangThai = ['Đã ghép' => 0, 'Đang diễn ra' => 0, 'Kết thúc' => 0, 'Hủy' => 0];
        foreach ($pdo->query('SELECT TrangThai, COUNT(*) AS so FROM TRANDAU GROUP BY TrangThai') as $r) {
            $theoTrangThai[$r['TrangThai']] = (int) $r['so'];
        }

        // Gom theo ngày (7, 30 ngày), tuần (90 ngày), tháng (1 năm) như biểu đồ trên màn hình
        $kieu = $ngay <= 30 ? 'ngay' : ($ngay <= 90 ? 'tuan' : 'thang');
        $bd = [];
        if ($kieu === 'ngay') {
            for ($i = $ngay - 1; $i >= 0; $i--) {
                $t = strtotime("-$i days");
                $bd[date('Y-m-d', $t)] = ['nhan' => date('d/m', $t), 'dangky' => 0, 'tran' => 0];
            }
            $batDau = $tuNgay;
        } elseif ($kieu === 'tuan') {
            for ($k = 0; $k < (int) ceil($ngay / 7); $k++) {
                $bd[$k] = ['nhan' => date('d/m', strtotime($tuNgay . ' +' . ($k * 7) . ' days')), 'dangky' => 0, 'tran' => 0];
            }
            $batDau = $tuNgay;
        } else {
            for ($i = 11; $i >= 0; $i--) {
                $t = strtotime(date('Y-m-01') . " -$i months");
                $bd[date('Y-m', $t)] = ['nhan' => date('m/Y', $t), 'dangky' => 0, 'tran' => 0];
            }
            $batDau = date('Y-m-01', strtotime(date('Y-m-01') . ' -11 months'));
        }
        $mocBD = $batDau . ' 00:00:00';
        $khoa = static function (string $d) use ($kieu, $tuNgay) {
            if ($kieu === 'ngay') { return $d; }
            if ($kieu === 'tuan') { return (int) floor((strtotime($d) - strtotime($tuNgay)) / 86400 / 7); }
            return substr($d, 0, 7);
        };

        $st = $pdo->prepare('SELECT DATE(COALESCE(ThoiGianBatDau, ThoiGianKetThuc)) AS ngay, COUNT(*) AS so FROM TRANDAU
                             WHERE COALESCE(ThoiGianBatDau, ThoiGianKetThuc) >= ? GROUP BY ngay');
        $st->execute([$mocBD]);
        foreach ($st as $r) {
            $k = $khoa($r['ngay']);
            if (isset($bd[$k])) { $bd[$k]['tran'] += (int) $r['so']; }
        }
        $st = $pdo->prepare('SELECT DATE(NgayTao) AS ngay, COUNT(*) AS so FROM NGUOIDUNG WHERE NgayTao >= ? GROUP BY ngay');
        $st->execute([$mocBD]);
        foreach ($st as $r) {
            $k = $khoa($r['ngay']);
            if (isset($bd[$k])) { $bd[$k]['dangky'] += (int) $r['so']; }
        }

        // Thẻ số liệu: kỳ này so với kỳ trước
        $st = $pdo->prepare('SELECT SUM(NgayTao >= ?), SUM(NgayTao >= ? AND NgayTao < ?) FROM NGUOIDUNG WHERE NgayTao >= ?');
        $st->execute([$moc, $mocTruoc, $moc, $mocTruoc]);
        [$ndNay, $ndTruoc] = array_map('intval', $st->fetch(PDO::FETCH_NUM));

        $st = $pdo->prepare('SELECT SUM(t >= ?), SUM(t >= ? AND t < ?)
                             FROM (SELECT COALESCE(ThoiGianBatDau, ThoiGianKetThuc) AS t FROM TRANDAU) x WHERE t >= ?');
        $st->execute([$moc, $mocTruoc, $moc, $mocTruoc]);
        [$trNay, $trTruoc] = array_map('intval', $st->fetch(PDO::FETCH_NUM));

        $tongBai = (int) $pdo->query("SELECT COUNT(*) FROM BAIDANG WHERE TrangThai <> 'Đã xóa'")->fetchColumn();
        $st = $pdo->prepare("SELECT SUM(NgayDang >= ?), SUM(NgayDang >= ? AND NgayDang < ?) FROM BAIDANG
                             WHERE TrangThai <> 'Đã xóa' AND NgayDang >= ?");
        $st->execute([$moc, $mocTruoc, $moc, $mocTruoc]);
        [$baiNay, $baiTruoc] = array_map('intval', $st->fetch(PDO::FETCH_NUM));

        $hd = $pdo->prepare(
            "SELECT COUNT(DISTINCT n.MaND) FROM NGUOICHOITRANDAU n JOIN TRANDAU t ON t.MaTran = n.MaTran
             WHERE n.TrangThaiThamGia <> 'Chưa vào'
               AND COALESCE(t.ThoiGianBatDau, t.ThoiGianKetThuc) >= ? AND COALESCE(t.ThoiGianBatDau, t.ThoiGianKetThuc) < ?"
        );
        $hd->execute([$moc, '9999-12-31 00:00:00']);
        $hoatDong = (int) $hd->fetchColumn();
        $hd->execute([$mocTruoc, $moc]);
        $hoatDongTruoc = (int) $hd->fetchColumn();
        $nd = (int) $tong['nguoidung'];
        $tyNay   = $nd > 0 ? round($hoatDong * 100 / $nd, 1) : 0;
        $tyTruoc = $nd > 0 ? round($hoatDongTruoc * 100 / $nd, 1) : 0;

        $f = static fn (int $n): string => number_format($n, 0, ',', '.');
        return [
            'ngay'   => $ngay,
            'kieu'   => $kieu === 'ngay' ? 'ngày' : ($kieu === 'tuan' ? 'tuần (nhãn là ngày đầu tuần)' : 'tháng'),
            'the'    => [
                ['Tổng người dùng',    $f($nd),               self::chuThayDoi($ndNay, $ndTruoc, false)],
                ['Tổng trận đấu',      $f((int) $tong['trandau']), self::chuThayDoi($trNay, $trTruoc, false)],
                ['Bài đăng cộng đồng', $f($tongBai),          self::chuThayDoi($baiNay, $baiTruoc, false)],
                ['Tỷ lệ hoạt động',    $tyNay . '%',          self::chuThayDoi($tyNay, $tyTruoc, true)],
            ],
            'tongMon'   => (int) $tong['monhoc'],
            'tongCauHoi' => (int) $tong['cauhoi'],
            'nhan'   => array_column($bd, 'nhan'),
            'dangky' => array_column($bd, 'dangky'),
            'tran'   => array_column($bd, 'tran'),
            'trangThai' => $theoTrangThai,
        ];
    }

    // Trang 2: Thống kê theo môn học (5 môn nhiều trận nhất + "Khác")
    public static function theoMon(PDO $pdo, int $ngay): array
    {
        $moc = date('Y-m-d', strtotime('-' . ($ngay - 1) . ' days')) . ' 00:00:00';
        $st = $pdo->prepare(
            'SELECT m.TenMon, COUNT(*) AS so FROM TRANDAU t JOIN MONHOC m ON m.MaMon = t.MaMon
             WHERE COALESCE(t.ThoiGianBatDau, t.ThoiGianKetThuc) >= ?
             GROUP BY m.MaMon, m.TenMon ORDER BY so DESC, m.TenMon'
        );
        $st->execute([$moc]);
        $ds = [];
        $khac = 0;
        $tong = 0;
        foreach ($st->fetchAll() as $i => $r) {
            $tong += (int) $r['so'];
            if ($i < 5) { $ds[] = ['ten' => $r['TenMon'], 'so' => (int) $r['so']]; }
            else        { $khac += (int) $r['so']; }
        }
        if ($khac > 0) { $ds[] = ['ten' => 'Khác', 'so' => $khac]; }
        foreach ($ds as $i => $m) {
            $ds[$i]['phantram'] = round($m['so'] * 100 / $tong, 1);
            $ds[$i]['mau'] = self::MAU_MON[$i];
        }
        return ['ngay' => $ngay, 'tong' => $tong, 'ds' => $ds];
    }
}