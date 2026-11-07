<?php
// Kiểm tra đăng nhập ở backend. Site Client và Site Admin dùng hai khóa session tách biệt
// (nd_id cho sinh viên, admin_id cho quản trị) nên tài khoản sinh viên không vào được Admin.
//
// Chiều ngược lại: admin bấm "Về trang sinh viên" thì được vào khu sinh viên ngay, không phải đăng nhập lại,
// bằng tài khoản sinh viên liên kết (cột ADMIN.MaND). Khi đó session có thêm khóa nd_la_admin = mã admin.
// Admin không tìm trận và không đấu trận (các trang đó gọi Auth::laAdminSinhVien() để chặn).
class Auth
{
    public static function clientId(): ?int
    {
        if (isset($_SESSION['nd_id']) && isset($_SESSION['nd_la_admin'])) {
            self::kiemTraPhienAdminSV();
        }
        return isset($_SESSION['nd_id']) ? (int) $_SESSION['nd_id'] : null;
    }

    public static function adminId(): ?int
    {
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    // Đang là admin dùng khu sinh viên (đã vào bằng nút "Về trang sinh viên")
    public static function laAdminSinhVien(): bool
    {
        return self::clientId() !== null && isset($_SESSION['nd_la_admin']);
    }

    // Admin đang dùng khu sinh viên và phiên admin còn hiệu lực (tài khoản chưa bị khóa):
    // được kiểm duyệt ngay trên trang Cộng đồng, giống trang quản trị.
    public static function laQuanTriKiemDuyet(): bool
    {
        static $kq = null;
        if ($kq !== null) {
            return $kq;
        }
        $kq = false;
        if (self::laAdminSinhVien() && self::adminId() !== null
            && (int) $_SESSION['nd_la_admin'] === self::adminId()) {
            $st = Database::pdo()->prepare('SELECT TrangThai FROM ADMIN WHERE MaAdmin = ?');
            $st->execute([self::adminId()]);
            $kq = $st->fetchColumn() === 'Hoạt động';
        }
        return $kq;
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

    // Chuyển admin sang khu sinh viên: dùng tài khoản sinh viên liên kết, chưa có thì tạo ở lần đầu.
    // Tài khoản liên kết có email nội bộ ngẫu nhiên và mật khẩu ngẫu nhiên không ai biết,
    // nên chỉ vào được qua phiên admin, không đăng nhập trực tiếp ở trang sinh viên được.
    public static function vaoKhuSinhVien(PDO $pdo, int $maAdmin): void
    {
        $st = $pdo->prepare('SELECT MaND, HoTen FROM ADMIN WHERE MaAdmin = ?');
        $st->execute([$maAdmin]);
        $ad = $st->fetch();
        if (!$ad) {
            return;
        }
        $maND = $ad['MaND'] !== null ? (int) $ad['MaND'] : null;

        if ($maND === null) {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare(
                    "INSERT INTO NGUOIDUNG (HoTen, Email, MatKhau, TrangThaiKhaoSat) VALUES (?, ?, ?, 'Bỏ qua')"
                );
                $st->execute([
                    $ad['HoTen'],
                    sprintf('quantri-%d-%s@quantri.elo.local', $maAdmin, bin2hex(random_bytes(4))),
                    password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                ]);
                $moi = (int) $pdo->lastInsertId();

                // Chỉ gán nếu chưa ai gán (hai lần bấm cùng lúc không tạo trùng)
                $st = $pdo->prepare('UPDATE ADMIN SET MaND = ? WHERE MaAdmin = ? AND MaND IS NULL');
                $st->execute([$moi, $maAdmin]);
                if ($st->rowCount() === 1) {
                    $pdo->commit();
                    $maND = $moi;
                } else {
                    $pdo->rollBack();            // lần bấm khác đã tạo xong: dùng tài khoản đó
                    $st = $pdo->prepare('SELECT MaND FROM ADMIN WHERE MaAdmin = ?');
                    $st->execute([$maAdmin]);
                    $maND = (int) $st->fetchColumn();
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $ex;
            }
        }

        self::regenerate();
        $_SESSION['nd_id']       = $maND;
        $_SESSION['nd_la_admin'] = $maAdmin;
    }

    // Phiên sinh viên do admin chuyển sang được kiểm tra lại (mỗi request một lần):
    // admin bị khóa hoặc bị xóa thì mất luôn quyền ở khu sinh viên;
    // nếu đã đăng nhập tài khoản sinh viên khác thì không còn tính là phiên của admin.
    private static function kiemTraPhienAdminSV(): void
    {
        static $daKiemTra = false;
        if ($daKiemTra) {
            return;
        }
        $daKiemTra = true;

        $st = Database::pdo()->prepare('SELECT TrangThai, MaND FROM ADMIN WHERE MaAdmin = ?');
        $st->execute([(int) $_SESSION['nd_la_admin']]);
        $ad = $st->fetch();
        if (!$ad || $ad['TrangThai'] !== 'Hoạt động') {
            unset($_SESSION['nd_id'], $_SESSION['nd_la_admin']);
        } elseif ((int) $ad['MaND'] !== (int) $_SESSION['nd_id']) {
            unset($_SESSION['nd_la_admin']);
        }
    }
}