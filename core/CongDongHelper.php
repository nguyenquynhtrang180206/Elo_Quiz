<?php
// Hàm dùng chung cho cộng đồng học tập (C-42 đến C-44). Đặt tại: elo_quiz/core/CongDongHelper.php
class CongDongHelper
{
    public const TIEU_DE_TOI_DA   = 200;
    public const NOI_DUNG_TOI_DA  = 5000;
    public const BINH_LUAN_TOI_DA = 1000;

    // Các môn đang mở: [MaMon => TenMon]
    public static function monDangMo(): array
    {
        $rows = Database::pdo()
            ->query("SELECT MaMon, TenMon FROM MONHOC WHERE TrangThai = 'Đang mở' ORDER BY TenMon")
            ->fetchAll();
        $kq = [];
        foreach ($rows as $r) {
            $kq[(int) $r['MaMon']] = $r['TenMon'];
        }
        return $kq;
    }

    // Kiểm tra dữ liệu bài đăng. Trả về [danh sách lỗi, MaMon hợp lệ hoặc null (không gắn môn)]
    public static function kiemTraBai(string $tieuDe, string $noiDung, $maMonGui, array $monChoPhep): array
    {
        $loi = [];

        if ($tieuDe === '') {
            $loi[] = 'Vui lòng nhập tiêu đề.';
        } elseif (mb_strlen($tieuDe) > self::TIEU_DE_TOI_DA) {
            $loi[] = 'Tiêu đề tối đa ' . self::TIEU_DE_TOI_DA . ' ký tự.';
        }

        if ($noiDung === '') {
            $loi[] = 'Vui lòng nhập nội dung.';
        } elseif (mb_strlen($noiDung) > self::NOI_DUNG_TOI_DA) {
            $loi[] = 'Nội dung tối đa ' . self::NOI_DUNG_TOI_DA . ' ký tự.';
        }

        $maMon = null;
        $gui = (string) $maMonGui;
        if ($gui !== '' && $gui !== '0') {
            $id = (int) $gui;
            if (isset($monChoPhep[$id])) {
                $maMon = $id;
            } else {
                $loi[] = 'Môn học không hợp lệ.';
            }
        }
        return [$loi, $maMon];
    }

    public static function ngay(string $thoiGian): string
    {
        $t = strtotime($thoiGian);
        return $t ? date('d/m/Y H:i', $t) : '';
    }

    // Thông báo một lần sau khi đăng, sửa, xóa
    public static function flash(?string $noiDung = null): ?string
    {
        if ($noiDung !== null) {
            $_SESSION['flash_cd'] = $noiDung;
            return null;
        }
        $m = $_SESSION['flash_cd'] ?? null;
        unset($_SESSION['flash_cd']);
        return $m;
    }
}