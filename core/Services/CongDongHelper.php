<?php
// Hàm dùng chung cho cộng đồng học tập (C-42 đến C-44). Đặt tại: elo_quiz/core/CongDongHelper.php
class CongDongHelper
{
    public const TIEU_DE_TOI_DA   = 200;
    public const NOI_DUNG_TOI_DA  = 5000;
    public const BINH_LUAN_TOI_DA = 1000;
    public const SUA_TOI_DA_PHUT  = 15;   // chủ bài chỉ được sửa trong 15 phút kể từ lúc đăng

    // Phân loại bài đăng (cột BAIDANG.LoaiBai). 'Thảo luận' là loại mặc định cho bài cũ và bài không chọn loại.
    public const LOAI_BAI     = ['Thảo luận', 'Hỏi đáp', 'Chia sẻ mẹo', 'Tìm bạn ôn thi', 'Khác'];
    public const LOAI_MAC_DINH = 'Thảo luận';
    private const LOAI_LOP = [
        'Thảo luận'      => 'cd-loai-tl',
        'Hỏi đáp'        => 'cd-loai-hd',
        'Chia sẻ mẹo'    => 'cd-loai-meo',
        'Tìm bạn ôn thi' => 'cd-loai-ban',
        'Khác'           => 'cd-loai-khac',
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
    // [Elo tối thiểu, tên hạng, lớp CSS], xếp từ cao xuống thấp; điểm khởi đầu là 1000
    private const HANG_ELO = [
        [1400, 'Kim cương', 'cd-elo-kc'],
        [1250, 'Vàng',      'cd-elo-vang'],
        [1100, 'Bạc',       'cd-elo-bac'],
        [0,    'Đồng',      'cd-elo-dong'],
    ];

    public static function iconLua(int $size = 19): string
    {
        return '<svg class="svg-flame" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" style="vertical-align:-2px;margin-right:4px;display:inline-block;filter:drop-shadow(0 0 5px rgba(255,109,0,0.6));" aria-hidden="true">'
             . '<defs>'
             . '<linearGradient id="phxFlameOut" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="#FFF0A3"/><stop offset="25%" stop-color="#FF9100"/><stop offset="70%" stop-color="#FF3D00"/><stop offset="100%" stop-color="#C62828"/>'
             . '</linearGradient>'
             . '<linearGradient id="phxFlameMid" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="#FFFFFF"/><stop offset="35%" stop-color="#FFF59D"/><stop offset="75%" stop-color="#FFB300"/><stop offset="100%" stop-color="#FF5722"/>'
             . '</linearGradient>'
             . '<linearGradient id="phxFlameIn" x1="0" y1="0" x2="0" y2="1">'
             . '<stop offset="0%" stop-color="#FFFFFF"/><stop offset="100%" stop-color="#FFE082"/>'
             . '</linearGradient>'
             . '</defs>'
             . '<path d="M12 1.5C12 1.5 13.8 5.2 13.8 7.5C13.8 8.8 13.2 9.9 12.5 10.8C15 10.3 17.5 12 18.2 14.5C19 17.2 17.5 20.3 14.8 21.6C16 20.5 16.5 19.1 16.2 17.7C15.8 16 14.4 14.8 12.8 14.5C13.3 16.5 12.6 18.5 11.2 19.8C9.5 21.4 7 21.6 5.2 20.2C3.2 18.6 2.5 15.8 3.5 13.4C4.3 11.5 6.2 10.2 8.2 10C7.8 11.2 8.2 12.5 9.2 13.2C9.5 10.8 10.8 8.6 12 6.5C12.8 5.1 12.8 3.2 12 1.5Z" fill="url(#phxFlameOut)"/>'
             . '<path d="M12 7.5C12.8 9.2 14.2 10.6 14.6 12.5C15 14.4 14.1 16.4 12.5 17.5C13.2 16.6 13.4 16.6 13 15.7C12.5 14.5 11.5 13.8 10.5 13.8C10.8 14.2 10.4 15.5 9.5 16.4C8.4 17.5 6.8 17.5 5.7 16.5C4.8 15.6 4.6 14.2 5.2 13.1C6 11.8 7.4 11 8.8 11C9.6 11 10.3 11.3 10.8 11.8C10.8 10.2 11.4 8.8 12 7.5Z" fill="url(#phxFlameMid)"/>'
             . '<path d="M12 12.5C12.5 13.5 13 14.3 12.8 15.3C12.6 16.1 12 16.8 11.2 17.2C11.5 16.6 11.5 16.1 11.2 15.6C10.8 15 10.2 14.7 9.6 14.8C9.8 15.5 9.6 16.1 9.2 16.5C8.6 17.1 7.8 17 7.3 16.5C7 16.2 7 15.6 7.3 15.1C7.8 14.3 8.7 13.8 9.6 13.8C10.4 13.8 11.2 13.2 12 12.5Z" fill="url(#phxFlameIn)"/>'
             . '<circle cx="12" cy="1.6" r="0.9" fill="#FFF"/>'
             . '<circle cx="17.2" cy="11.5" r="0.6" fill="#FFE082" opacity="0.85"/>'
             . '<circle cx="4.8" cy="9.8" r="0.5" fill="#FFB300" opacity="0.75"/>'
             . '</svg>';
    }

    public static function iconHang(string $css): string
    {
        switch ($css) {
            case 'cd-elo-kc':
                return '<svg class="svg-elo-rank" viewBox="0 0 16 16" width="13" height="13" fill="none" style="vertical-align:-1.5px;margin-right:3px" aria-hidden="true"><polygon points="8,1.5 14,7.5 8,13.5 2,7.5" fill="#38BDF8" stroke="#E0F2FE" stroke-width="1.3"/><polygon points="8,4 11.5,7.5 8,11 4.5,7.5" fill="#FFF"/></svg>';
            case 'cd-elo-vang':
                return '<svg class="svg-elo-rank" viewBox="0 0 16 16" width="13" height="13" fill="none" style="vertical-align:-1.5px;margin-right:3px" aria-hidden="true"><circle cx="8" cy="8" r="6.2" fill="#F59E0B" stroke="#FDE047" stroke-width="1.3"/><polygon points="8,4.2 9.1,6.5 11.6,6.7 9.8,8.4 10.3,10.9 8,9.6 5.7,10.9 6.2,8.4 4.4,6.7 6.9,6.5" fill="#FFF"/></svg>';
            case 'cd-elo-bac':
                return '<svg class="svg-elo-rank" viewBox="0 0 16 16" width="13" height="13" fill="none" style="vertical-align:-1.5px;margin-right:3px" aria-hidden="true"><circle cx="8" cy="8" r="6.2" fill="#94A3B8" stroke="#E2E8F0" stroke-width="1.3"/><polygon points="8,4.2 9.1,6.5 11.6,6.7 9.8,8.4 10.3,10.9 8,9.6 5.7,10.9 6.2,8.4 4.4,6.7 6.9,6.5" fill="#FFF"/></svg>';
            default:
                return '<svg class="svg-elo-rank" viewBox="0 0 16 16" width="13" height="13" fill="none" style="vertical-align:-1.5px;margin-right:3px" aria-hidden="true"><circle cx="8" cy="8" r="6.2" fill="#B45309" stroke="#FBBF24" stroke-width="1.3"/><polygon points="8,4.2 9.1,6.5 11.6,6.7 9.8,8.4 10.3,10.9 8,9.6 5.7,10.9 6.2,8.4 4.4,6.7 6.9,6.5" fill="#FFF"/></svg>';
        }
    }

    // Bảng hạng để hiển thị phần mô tả huy hiệu. Đọc thẳng từ HANG_ELO nên đổi mốc điểm ở một chỗ là mô tả tự khớp theo.
    // Trả về từ cao xuống thấp: [['ten' => ..., 'icon' => ..., 'css' => ..., 'tu' => int, 'khoang' => 'chuỗi mô tả khoảng điểm'], ...]
    public static function bangHangElo(): array
    {
        $kq   = [];
        $tren = null; // mốc tối thiểu của hạng ngay phía trên
        foreach (self::HANG_ELO as [$tu, $ten, $css]) {
            if ($tren === null) {
                $khoang = 'từ ' . number_format($tu) . ' trở lên';
            } elseif ($tu <= 0) {
                $khoang = 'dưới ' . number_format($tren);
            } else {
                $khoang = number_format($tu) . ' – ' . number_format($tren - 1);
            }
            $kq[] = ['ten' => $ten, 'icon' => self::iconHang($css), 'css' => $css, 'tu' => $tu, 'khoang' => $khoang];
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
             . self::iconHang($hang[2]) . ' ' . e($hang[1]) . ' · ' . number_format($d['elo']) . '</span>';
    }

    // ================= KIỂM DUYỆT CỦA QUẢN TRỊ (dùng chung: trang admin và trang Cộng đồng) =================
    // Chỉ ẩn/hiện, không xóa cứng. Bắt buộc lý do, ghi nhật ký, gửi thông báo kèm lý do cho chủ bài / chủ bình luận.
    // Bài sinh viên đã tự xóa ("Đã xóa") không khớp điều kiện trạng thái cũ nên không hiện lại được (A-33).
    // Trả về: 'ok' | 'khong' (trạng thái đã đổi) | 'thieuly' | 'sai' (hành động không hợp lệ).
    // Nơi gọi phải tự kiểm tra quyền admin trước (Auth::requireAdmin hoặc Auth::laQuanTriKiemDuyet).
    private const KIEM_DUYET = [
        // hành động => [bảng, cột khóa, trạng thái mới, trạng thái cũ bắt buộc, tên nhật ký, tiền tố đối tượng]
        'an_bai'   => ['BAIDANG', 'MaBaiDang', 'Bị ẩn',    'Hiển thị', 'Ẩn bài đăng',    'BAIDANG'],
        'hien_bai' => ['BAIDANG', 'MaBaiDang', 'Hiển thị', 'Bị ẩn',    'Hiện bài đăng',  'BAIDANG'],
        'an_bl'    => ['BINHLUAN', 'MaBinhLuan', 'Bị ẩn',    'Hiển thị', 'Ẩn bình luận',  'BINHLUAN'],
        'hien_bl'  => ['BINHLUAN', 'MaBinhLuan', 'Hiển thị', 'Bị ẩn',    'Hiện bình luận', 'BINHLUAN'],
    ];

    public static function kiemDuyet(PDO $pdo, string $hanhDong, int $ma, string $lyDo): string
    {
        require_once __DIR__ . '/ThongBaoHelper.php';
        if (!isset(self::KIEM_DUYET[$hanhDong]) || $ma <= 0) {
            return 'sai';
        }
        $ly = preg_replace('/^(.{300}).+$/su', '$1', trim($lyDo));
        if ($ly === '') {
            return 'thieuly';
        }
        [$bang, $cotKhoa, $moi, $cu, $tenNk, $doiTuong] = self::KIEM_DUYET[$hanhDong];

        $pdo->beginTransaction();
        try {
            // Bấm lặp hoặc hai quản trị viên cùng bấm thì chỉ ghi nhật ký một lần.
            // Ghi nhận ai ẩn: quản trị ẩn thì chủ không tự mở lại được; hiện lại thì xóa dấu.
            $set    = 'TrangThai = ?';
            $thamUp = [$moi];
            if (($bang === 'BAIDANG' && self::coCotNguoiAn()) || ($bang === 'BINHLUAN' && self::coCotNguoiAnBL())) {
                $set .= ', NguoiAn = ?';
                $thamUp[] = $moi === 'Bị ẩn' ? 'Quản trị' : null;
            }
            $up = $pdo->prepare("UPDATE $bang SET $set WHERE $cotKhoa = ? AND TrangThai = ?");
            $up->execute(array_merge($thamUp, [$ma, $cu]));
            $doi = $up->rowCount() === 1;
            if ($doi) {
                NhatKy::ghi($tenNk, $doiTuong . ' #' . $ma, 'Lý do: ' . $ly);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
        if (!$doi) {
            return 'khong';
        }

        // Thông báo cho chủ bài / chủ bình luận (kèm lý do). Lỗi gửi thông báo không làm hỏng việc ẩn/hiện.
        try {
            $an = $moi === 'Bị ẩn';
            if ($bang === 'BAIDANG') {
                $st = $pdo->prepare('SELECT MaND, TieuDe FROM BAIDANG WHERE MaBaiDang = ?');
                $st->execute([$ma]);
                if ($r = $st->fetch()) {
                    ThongBaoHelper::taoThongBaoQuanTri(
                        $pdo, (int) $r['MaND'], $ma, null, $an ? 'an_bai' : 'hien_bai',
                        ($an ? 'đã ẩn bài đăng "' : 'đã hiển thị lại bài đăng "') . mb_substr((string) $r['TieuDe'], 0, 60) . '" của bạn.',
                        'Lý do: ' . $ly,
                        BASE_URL . '/client/baidang.php?id=' . $ma   // chủ bài vẫn mở được bài của mình khi bị ẩn (chỉ đọc)
                    );
                }
            } else {
                $st = $pdo->prepare('SELECT c.MaND, c.MaBaiDang, b.TieuDe FROM BINHLUAN c JOIN BAIDANG b ON b.MaBaiDang = c.MaBaiDang WHERE c.MaBinhLuan = ?');
                $st->execute([$ma]);
                if ($r = $st->fetch()) {
                    ThongBaoHelper::taoThongBaoQuanTri(
                        $pdo, (int) $r['MaND'], (int) $r['MaBaiDang'], $ma, $an ? 'an_bl' : 'hien_bl',
                        ($an ? 'đã ẩn bình luận của bạn trong bài "' : 'đã hiển thị lại bình luận của bạn trong bài "') . mb_substr((string) $r['TieuDe'], 0, 60) . '".',
                        'Lý do: ' . $ly,
                        BASE_URL . '/client/baidang.php?id=' . (int) $r['MaBaiDang'] . '#bl-' . $ma
                    );
                }
            }
        } catch (Throwable $ex) {
            // bỏ qua
        }
        return 'ok';
    }

    // Ô kiểm duyệt hiện sẵn: nhập lý do (bắt buộc) rồi bấm nút để ẩn/hiện, gửi thông báo cho người đăng.
    // $hanhDong: an_bai | hien_bai | an_bl | hien_bl (form gửi đi với tiền tố qt_). $them: các trường ẩn kèm theo.
    public static function formQuanTri(string $action, string $hanhDong, int $ma, string $nhan, bool $nutDo, array $them = []): string
    {
        $h = '<form method="post" action="' . e($action) . '" class="qt-form">' . csrf_field()
           . '<input type="hidden" name="hanh_dong" value="qt_' . e($hanhDong) . '">'
           . '<input type="hidden" name="ma" value="' . $ma . '">'
           . '<input type="hidden" name="ma_bl" value="' . $ma . '">';
        foreach ($them as $k => $v) {
            $h .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
        }
        return $h . '<input type="text" name="ly_do" maxlength="300" required placeholder="Lý do (bắt buộc, gửi cho người đăng)" class="qt-lydo">'
             . '<button type="submit" class="btn btn-nho' . ($nutDo ? ' btn-do' : '') . '">' . e($nhan) . '</button>'
             . '</form>';
    }
}