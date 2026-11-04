<?php
// Hàm dùng chung cho cộng đồng học tập (C-42 đến C-44). Đặt tại: elo_quiz/core/CongDongHelper.php
class CongDongHelper
{
    public const TIEU_DE_TOI_DA   = 200;
    public const NOI_DUNG_TOI_DA  = 5000;
    public const BINH_LUAN_TOI_DA = 1000;
    public const SUA_TOI_DA_PHUT  = 15;   // chủ bài chỉ được sửa trong 15 phút kể từ lúc đăng

    // Phân loại bài đăng (cột BAIDANG.LoaiBai). 'Thảo luận' là loại mặc định cho bài cũ và bài không chọn loại.
    public const LOAI_BAI     = ['Thảo luận', 'Hỏi đáp', 'Chia sẻ mẹo', 'Tìm bạn ôn thi'];
    public const LOAI_MAC_DINH = 'Thảo luận';
    private const LOAI_LOP = [
        'Thảo luận'      => 'cd-loai-tl',
        'Hỏi đáp'        => 'cd-loai-hd',
        'Chia sẻ mẹo'    => 'cd-loai-meo',
        'Tìm bạn ôn thi' => 'cd-loai-ban',
    ];

    // Cột BAIDANG.LoaiBai có thể chưa tạo (chạy migration_baidang_loaibai.sql); chưa có thì ẩn mọi chức năng phân loại
    public static function coCotLoaiBai(): bool
    {
        static $co = null;
        if ($co === null) {
            $co = Database::pdo()->query("SHOW COLUMNS FROM BAIDANG LIKE 'LoaiBai'")->fetch() !== false;
        }
        return $co;
    }

    // Giá trị loại bài gửi lên -> loại hợp lệ; sai hoặc rỗng thì dùng loại mặc định
    public static function kiemTraLoai($gui): string
    {
        $gui = (string) $gui;
        return in_array($gui, self::LOAI_BAI, true) ? $gui : self::LOAI_MAC_DINH;
    }

    // Nhãn loại bài; loại mặc định không hiện nhãn để danh sách đỡ rối
    public static function nhanLoai(?string $loai): string
    {
        if ($loai === null || $loai === '' || $loai === self::LOAI_MAC_DINH || !isset(self::LOAI_LOP[$loai])) {
            return '';
        }
        return '<span class="cd-loai ' . self::LOAI_LOP[$loai] . '">' . e($loai) . '</span>';
    }

    // Cột BAIDANG.NguoiAn (ai đã ẩn bài: 'Chủ bài' hoặc 'Quản trị') có thể chưa tạo (chạy migration_baidang_nguoian.sql)
    public static function coCotNguoiAn(): bool
    {
        static $co = null;
        if ($co === null) {
            $co = Database::pdo()->query("SHOW COLUMNS FROM BAIDANG LIKE 'NguoiAn'")->fetch() !== false;
        }
        return $co;
    }

    // Còn trong thời hạn sửa bài không ($giayTuDang = số giây từ lúc đăng, tính bằng TIMESTAMPDIFF để cùng múi giờ với DB)
    public static function conHanSua(int $giayTuDang): bool
    {
        return $giayTuDang <= self::SUA_TOI_DA_PHUT * 60;
    }

    // Chủ bài ẩn bài của mình (bài đang "Hiển thị"). Cần cột NguoiAn để sau này biết ai được mở lại.
    public static function anBai(PDO $pdo, int $maBai, int $maND): bool
    {
        if (!self::coCotNguoiAn()) {
            return false;
        }
        $st = $pdo->prepare(
            "UPDATE BAIDANG SET TrangThai = 'Bị ẩn', NguoiAn = 'Chủ bài'
              WHERE MaBaiDang = ? AND MaND = ? AND TrangThai = 'Hiển thị'"
        );
        $st->execute([$maBai, $maND]);
        return $st->rowCount() === 1;
    }

    // Chủ bài mở lại bài: chỉ khi chính chủ bài đã ẩn. Bài do Quản trị ẩn (hoặc bài cũ chưa rõ ai ẩn) thì không mở được.
    public static function moLaiBai(PDO $pdo, int $maBai, int $maND): bool
    {
        if (!self::coCotNguoiAn()) {
            return false;
        }
        $st = $pdo->prepare(
            "UPDATE BAIDANG SET TrangThai = 'Hiển thị', NguoiAn = NULL
              WHERE MaBaiDang = ? AND MaND = ? AND TrangThai = 'Bị ẩn' AND NguoiAn = 'Chủ bài'"
        );
        $st->execute([$maBai, $maND]);
        return $st->rowCount() === 1;
    }

    // Chủ bài xóa bài mọi lúc (chuyển sang "Đã xóa", không xóa cứng)
    public static function xoaBai(PDO $pdo, int $maBai, int $maND): bool
    {
        $st = $pdo->prepare(
            "UPDATE BAIDANG SET TrangThai = 'Đã xóa'
              WHERE MaBaiDang = ? AND MaND = ? AND TrangThai IN ('Hiển thị', 'Bị ẩn')"
        );
        $st->execute([$maBai, $maND]);
        return $st->rowCount() === 1;
    }

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

    // "Vừa xong", "5 phút trước", "2 giờ trước", "3 ngày trước"; quá 30 ngày thì in ngày d/m/Y
    public static function truocDo(string $thoiGian): string
    {
        $t = strtotime($thoiGian);
        if (!$t) {
            return '';
        }
        $d = max(0, time() - $t);
        if ($d < 60) {
            return 'Vừa xong';
        }
        if ($d < 3600) {
            return floor($d / 60) . ' phút trước';
        }
        if ($d < 86400) {
            return floor($d / 3600) . ' giờ trước';
        }
        if ($d < 30 * 86400) {
            return floor($d / 86400) . ' ngày trước';
        }
        return date('d/m/Y', $t);
    }

    // Ảnh đại diện tròn; chưa có ảnh thì hiện chữ cái cuối của tên
    public static function avatar(string $hoTen, ?string $anh, string $lop = 'cd-av'): string
    {
        if ($anh !== null && $anh !== '') {
            $src = (defined('BASE_URL') ? BASE_URL : '') . '/' . ltrim($anh, '/');
            return '<img class="' . e($lop) . '" src="' . e($src) . '" alt="' . e($hoTen) . '" loading="lazy">';
        }
        $tu = preg_split('/\s+/u', trim($hoTen)) ?: ['?'];
        $chu = mb_strtoupper(mb_substr((string) end($tu), 0, 1));
        return '<span class="' . e($lop) . ' cd-av-chu" aria-hidden="true">' . e($chu) . '</span>';
    }

    public static function ngay(string $thoiGian): string
    {
        $t = strtotime($thoiGian);
        return $t ? date('d/m/Y H:i', $t) : '';
    }

    // Thông báo một lần sau khi đăng, sửa, xóa ($khoa riêng cho thông báo của bình luận)
    public static function flash(?string $noiDung = null, string $khoa = 'flash_cd'): ?string
    {
        if ($noiDung !== null) {
            $_SESSION[$khoa] = $noiDung;
            return null;
        }
        $m = $_SESSION[$khoa] ?? null;
        unset($_SESSION[$khoa]);
        return $m;
    }

    // ================= BÌNH LUẬN: thích, sửa (15 phút), ẩn, mở lại, xóa =================
    // Cần chạy migration_binhluan.sql (cột BINHLUAN.NguoiAn, trạng thái "Đã xóa", bảng LUOTTHICH_BL)

    // Đã có cột NguoiAn và trạng thái "Đã xóa" cho bình luận chưa (điều kiện để ẩn/mở lại/xóa)
    public static function coCotNguoiAnBL(): bool
    {
        static $co = null;
        if ($co === null) {
            $co = Database::pdo()->query("SHOW COLUMNS FROM BINHLUAN LIKE 'NguoiAn'")->fetch() !== false;
        }
        return $co;
    }

    // Đã có bảng LUOTTHICH_BL chưa (điều kiện để hiện nút thích bình luận)
    public static function coLikeBL(): bool
    {
        static $co = null;
        if ($co === null) {
            try {
                Database::pdo()->query('SELECT 1 FROM LUOTTHICH_BL LIMIT 1');
                $co = true;
            } catch (PDOException $ex) {
                $co = false;
            }
        }
        return $co;
    }

    // Thích / bỏ thích một bình luận (chỉ bình luận đang hiển thị, thuộc bài đang hiển thị)
    public static function thichBL(PDO $pdo, int $maBL, int $maND): void
    {
        if (!self::coLikeBL()) {
            return;
        }
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM BINHLUAN c JOIN BAIDANG b ON b.MaBaiDang = c.MaBaiDang
              WHERE c.MaBinhLuan = ? AND c.TrangThai = 'Hiển thị' AND b.TrangThai = 'Hiển thị'"
        );
        $st->execute([$maBL]);
        if ((int) $st->fetchColumn() !== 1) {
            return;
        }
        $st = $pdo->prepare('INSERT IGNORE INTO LUOTTHICH_BL (MaBinhLuan, MaND) VALUES (?, ?)');
        $st->execute([$maBL, $maND]);
        if ($st->rowCount() === 0) {
            $st = $pdo->prepare('DELETE FROM LUOTTHICH_BL WHERE MaBinhLuan = ? AND MaND = ?');
            $st->execute([$maBL, $maND]);
        }
    }

    // Sửa bình luận của mình, chỉ trong 15 phút kể từ lúc viết. Trả về 'ok' | 'het_han' | 'khong'
    public static function suaBL(PDO $pdo, int $maBL, int $maND, string $noiDung): string
    {
        $st = $pdo->prepare(
            'SELECT MaND, TrangThai, TIMESTAMPDIFF(SECOND, NgayBinhLuan, NOW()) AS Giay FROM BINHLUAN WHERE MaBinhLuan = ?'
        );
        $st->execute([$maBL]);
        $r = $st->fetch();
        if (!$r || (int) $r['MaND'] !== $maND || $r['TrangThai'] !== 'Hiển thị') {
            return 'khong';
        }
        if (!self::conHanSua((int) $r['Giay'])) {
            return 'het_han';
        }
        $up = $pdo->prepare(
            "UPDATE BINHLUAN SET NoiDung = ?
              WHERE MaBinhLuan = ? AND MaND = ? AND TrangThai = 'Hiển thị'
                AND NgayBinhLuan >= DATE_SUB(NOW(), INTERVAL " . self::SUA_TOI_DA_PHUT . ' MINUTE)'
        );
        $up->execute([$noiDung, $maBL, $maND]);
        return 'ok';
    }

    // Chủ bình luận ẩn bình luận của mình (mọi lúc)
    public static function anBL(PDO $pdo, int $maBL, int $maND): bool
    {
        if (!self::coCotNguoiAnBL()) {
            return false;
        }
        $st = $pdo->prepare(
            "UPDATE BINHLUAN SET TrangThai = 'Bị ẩn', NguoiAn = 'Chủ bình luận'
              WHERE MaBinhLuan = ? AND MaND = ? AND TrangThai = 'Hiển thị'"
        );
        $st->execute([$maBL, $maND]);
        return $st->rowCount() === 1;
    }

    // Mở lại: chỉ khi chính chủ đã ẩn. Bình luận do Quản trị ẩn (hoặc cũ chưa rõ ai ẩn) thì không mở được.
    public static function moLaiBL(PDO $pdo, int $maBL, int $maND): bool
    {
        if (!self::coCotNguoiAnBL()) {
            return false;
        }
        $st = $pdo->prepare(
            "UPDATE BINHLUAN SET TrangThai = 'Hiển thị', NguoiAn = NULL
              WHERE MaBinhLuan = ? AND MaND = ? AND TrangThai = 'Bị ẩn' AND NguoiAn = 'Chủ bình luận'"
        );
        $st->execute([$maBL, $maND]);
        return $st->rowCount() === 1;
    }

    // Chủ bình luận xóa mọi lúc (chuyển sang "Đã xóa", không xóa cứng)
    public static function xoaBL(PDO $pdo, int $maBL, int $maND): bool
    {
        if (!self::coCotNguoiAnBL()) {
            return false;
        }
        $st = $pdo->prepare(
            "UPDATE BINHLUAN SET TrangThai = 'Đã xóa'
              WHERE MaBinhLuan = ? AND MaND = ? AND TrangThai IN ('Hiển thị', 'Bị ẩn')"
        );
        $st->execute([$maBL, $maND]);
        return $st->rowCount() === 1;
    }

    // ================= HUY HIỆU ELO CẠNH TÊN (không thêm bảng, đọc từ ELOMONHOC) =================
    // Chỉ hiện huy hiệu khi người đó đã đấu từ ngần này trận trở lên ở môn tương ứng (tránh nhãn ảo cho người mới)
    public const HUY_HIEU_TOI_THIEU_TRAN = 5;
    // [Elo tối thiểu, tên hạng, lớp CSS, biểu tượng], xếp từ cao xuống thấp; điểm khởi đầu là 1000
    private const HANG_ELO = [
        [1400, 'Kim cương', 'cd-elo-kc',   '💎'],
        [1250, 'Vàng',      'cd-elo-vang', '🥇'],
        [1100, 'Bạc',       'cd-elo-bac',  '🥈'],
        [0,    'Đồng',      'cd-elo-dong', '🥉'],
    ];

    // Bảng hạng để hiển thị phần mô tả huy hiệu. Đọc thẳng từ HANG_ELO nên đổi mốc điểm ở một chỗ là mô tả tự khớp theo.
    // Trả về từ cao xuống thấp: [['ten' => ..., 'icon' => ..., 'css' => ..., 'tu' => int, 'khoang' => 'chuỗi mô tả khoảng điểm'], ...]
    public static function bangHangElo(): array
    {
        $kq   = [];
        $tren = null; // mốc tối thiểu của hạng ngay phía trên
        foreach (self::HANG_ELO as [$tu, $ten, $css, $icon]) {
            if ($tren === null) {
                $khoang = 'từ ' . number_format($tu) . ' trở lên';
            } elseif ($tu <= 0) {
                $khoang = 'dưới ' . number_format($tren);
            } else {
                $khoang = number_format($tu) . ' – ' . number_format($tren - 1);
            }
            $kq[] = ['ten' => $ten, 'icon' => $icon, 'css' => $css, 'tu' => $tu, 'khoang' => $khoang];
            $tren = $tu;
        }
        return $kq;
    }

    // Lấy Elo của nhiều người bằng MỘT truy vấn (không truy vấn từng dòng).
    // Trả về [MaND => [MaMon => ['elo' => int, 'tran' => int, 'mon' => TenMon]]], chỉ gồm môn đã đủ số trận tối thiểu.
    public static function layElo(PDO $pdo, array $dsMaND): array
    {
        $ds = array_values(array_unique(array_map('intval', $dsMaND)));
        if (!$ds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ds), '?'));
        $st = $pdo->prepare(
            "SELECT e.MaND, e.MaMon, m.TenMon, e.DiemElo, e.SoTran
               FROM ELOMONHOC e JOIN MONHOC m ON m.MaMon = e.MaMon
              WHERE e.SoTran >= ? AND e.MaND IN ($in)"
        );
        $st->execute(array_merge([self::HUY_HIEU_TOI_THIEU_TRAN], $ds));
        $kq = [];
        foreach ($st->fetchAll() as $r) {
            $kq[(int) $r['MaND']][(int) $r['MaMon']] = [
                'elo'  => (int) $r['DiemElo'],
                'tran' => (int) $r['SoTran'],
                'mon'  => (string) $r['TenMon'],
            ];
        }
        return $kq;
    }

    // Huy hiệu cạnh tên: bài/bình luận gắn môn thì lấy Elo của đúng môn đó; không gắn môn thì lấy môn có Elo cao nhất.
    // $eloNguoi = phần tử của layElo() ứng với một người. Chưa đủ điều kiện thì trả về chuỗi rỗng.
    public static function huyHieuElo(array $eloNguoi, ?int $maMon): string
    {
        if (!$eloNguoi) {
            return '';
        }
        $d = null;
        if ($maMon !== null) {
            $d = $eloNguoi[$maMon] ?? null;
        } else {
            foreach ($eloNguoi as $x) {
                if ($d === null || $x['elo'] > $d['elo']) {
                    $d = $x;
                }
            }
        }
        if ($d === null) {
            return '';
        }
        $hang = self::HANG_ELO[count(self::HANG_ELO) - 1];
        foreach (self::HANG_ELO as $cap) {
            if ($d['elo'] >= $cap[0]) {
                $hang = $cap;
                break;
            }
        }
        $goiY = 'Elo ' . number_format($d['elo']) . ' môn ' . $d['mon'] . ' · ' . $d['tran'] . ' trận';
        return '<span class="cd-elo ' . $hang[2] . '" title="' . e($goiY) . '">'
             . $hang[3] . ' ' . e($hang[1]) . ' · ' . number_format($d['elo']) . '</span>';
    }
}