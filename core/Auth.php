<?php
// Kiểm tra đăng nhập ở backend. Site Client và Site Admin dùng hai khóa session tách biệt
// (nd_id cho sinh viên, admin_id cho quản trị) nên tài khoản sinh viên không vào được Admin.
class Auth
{
    public static function clientId(): ?int
    {
        return isset($_SESSION['nd_id']) ? (int) $_SESSION['nd_id'] : null;
    }

    public static function adminId(): ?int
    {
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    // Đặt ở đầu mọi trang cần đăng nhập của sinh viên
    public static function requireClient(string $loginUrl = 'login.php'): int
    {
        $id = self::clientId();
        if ($id === null) {
            redirect($loginUrl);
        }
        return $id;
    }

    // Đặt ở đầu mọi trang và mọi thao tác của Admin (A-04).
    // Ngoài việc có phiên, còn kiểm tra lại trong bảng ADMIN: tài khoản phải còn tồn tại và đang
    // "Hoạt động", nên tài khoản bị khóa giữa chừng sẽ mất quyền ngay ở lần truy cập kế tiếp.
    public static function requireAdmin(string $loginUrl = 'login.php'): int
    {
        $id = self::adminId();
        if ($id !== null) {
            $st = Database::pdo()->prepare('SELECT TrangThai FROM ADMIN WHERE MaAdmin = ?');
            $st->execute([$id]);
            if ($st->fetchColumn() === 'Hoạt động') {
                return $id;
            }
            unset($_SESSION['admin_id']);   // tài khoản đã bị khóa hoặc bị xóa
        }
        redirect($loginUrl);
    }

    // Gọi ngay sau khi đăng nhập thành công, để chống session fixation
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }
}