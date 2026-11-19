<?php
// Dùng chung cho trang Trận đấu (admin/trandau.php) và biểu đồ (admin/trandau_nhip.php):
// tiêu chí "trận bất thường" (A-25) chỉ viết một lần ở đây để bộ lọc, thẻ số liệu và biểu đồ luôn khớp nhau.
// Đặt tại: elo_quiz/core/Game/TranDauGiamSat.php
final class TranDauGiamSat
{
    // Bảng TRANDAU đã có cột ThoiGianTao (được DbSchema::damBao bảo đảm)
    public static function coCotTao(PDO $pdo): bool
    {
        return true;
    }

    public static function nguongGiay(): int
    {
        return max(1, Config::int('NGUONG_TRAN_KEO_DAI', 10)) * 60;
    }

    // Trả về [chuỗi SQL, mảng tham số] của điều kiện bất thường trên bảng TRANDAU đặt bí danh t
    public static function dieuKienBatThuong(bool $coCotTao, int $nguongGiay): array
    {
        $sql = "(t.LyDoKetThuc IN ('Hủy do lỗi hệ thống', 'Đối thủ bỏ trận (xử thua)')
            OR (t.TrangThai = 'Đang diễn ra' AND TIMESTAMPDIFF(SECOND, t.ThoiGianBatDau, NOW(3)) > ?)";
        $tham = [$nguongGiay];
        if ($coCotTao) {
            $sql  .= " OR (t.TrangThai = 'Đã ghép' AND TIMESTAMPDIFF(SECOND, t.ThoiGianTao, NOW(3)) > ?)";
            $tham[] = $nguongGiay;
        }
        return [$sql . ')', $tham];
    }

    // Nhãn bất thường của một dòng (cần các cột TrangThai, LyDoKetThuc, giay_dien_ra, giay_da_ghep)
    public static function nhan(array $r, int $nguongGiay): ?string
    {
        if ($r['LyDoKetThuc'] === 'Hủy do lỗi hệ thống') {
            return 'Hủy do lỗi';
        }
        if ($r['LyDoKetThuc'] === 'Đối thủ bỏ trận (xử thua)') {
            return 'Có người bị xử thua';
        }
        if ($r['TrangThai'] === 'Đang diễn ra' && $r['giay_dien_ra'] !== null && (int) $r['giay_dien_ra'] > $nguongGiay) {
            return 'Đang diễn ra quá lâu';
        }
        if ($r['TrangThai'] === 'Đã ghép' && $r['giay_da_ghep'] !== null && (int) $r['giay_da_ghep'] > $nguongGiay) {
            return 'Đã ghép quá lâu';
        }
        return null;
    }

    // 231 giây -> "3p 51s"; null -> "—"
    public static function thoiLuong($giay): string
    {
        if ($giay === null) {
            return '—';
        }
        $giay = (int) round((float) $giay);
        return intdiv($giay, 60) . 'p ' . str_pad((string) ($giay % 60), 2, '0', STR_PAD_LEFT) . 's';
    }
}
