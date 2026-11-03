<?php
// Ghi nhật ký quản trị (A-40). Chỉ ghi, không sửa/xóa (A-42).
// Cách dùng: NhatKy::ghi('Khóa người dùng', 'NGUOIDUNG #15', 'Lý do: spam bài đăng');
// Chỉ gọi cho các thao tác ở A-40: khóa/mở khóa người dùng, ẩn/hiện câu hỏi, import Excel,
// hủy trận, ẩn/hiện nội dung cộng đồng, đổi cấu hình.
class NhatKy
{
    public static function ghi(string $hanhDong, ?string $doiTuong = null, ?string $moTa = null): void
    {
        $maAdmin = Auth::adminId();
        if ($maAdmin === null) {
            throw new RuntimeException('Chỉ quản trị viên đã đăng nhập mới ghi được nhật ký.');
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO NHATKYQUANTRI (MaAdmin, HanhDong, DoiTuong, MoTa) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$maAdmin, $hanhDong, $doiTuong, $moTa]);
    }
}