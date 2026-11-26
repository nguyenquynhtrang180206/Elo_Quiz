<?php
// Xử lý kết bạn qua bảng BANBE (chỉ mục duy nhất CapNho/CapLon tính từ LEAST/GREATEST).
// Thông báo kết bạn được đồng bộ qua THONGBAO.
class BanBeHelper
{
    // Kết quả của guiLoiMoi()
    public const OK = 'ok';
    public const TU_CHINH = 'tuchinh';
    public const KHONG_TON_TAI = 'khongton';
    public const DA_LA_BAN = 'dalabanbe';
    public const DA_GUI = 'dagui';
    public const CO_CHO_XU_LY = 'cochoxuly';   // phía kia đã gửi cho mình, chưa xử lý
    public const XUNG_DOT = 'conflict';

    // Loại thông báo (cột THONGBAO.Loai)
    public const LOAI_LOI_MOI   = 'loi_moi_kb';
    public const LOAI_CHAP_NHAN = 'chap_nhan_kb';

    public const THONG_BAO = [
        self::OK            => 'Đã gửi lời mời kết bạn.',
        self::TU_CHINH      => 'Bạn không thể kết bạn với chính mình.',
        self::KHONG_TON_TAI => 'Không tìm thấy sinh viên này.',
        self::DA_LA_BAN     => 'Hai bạn đã là bạn bè.',
        self::DA_GUI        => 'Bạn đã gửi lời mời cho người này rồi.',
        self::CO_CHO_XU_LY  => 'Người này đã gửi lời mời cho bạn. Hãy chấp nhận ở mục "Lời mời đã nhận".',
        self::XUNG_DOT      => 'Có thay đổi xảy ra cùng lúc, vui lòng thử lại.',
    ];

    public static function guiLoiMoi(int $toi, int $nguoiKia): string
    {
        if ($toi === $nguoiKia) {
            return self::TU_CHINH;
        }
        $pdo = Database::pdo();

        $st = $pdo->prepare("SELECT 1 FROM NGUOIDUNG WHERE MaND = ? AND TrangThai = 'Hoạt động'");
        $st->execute([$nguoiKia]);
        if (!$st->fetchColumn()) {
            return self::KHONG_TON_TAI;
        }

        try {
            $pdo->beginTransaction();

            // Khóa dòng của cặp này (nếu có) để hai thao tác đồng thời không chen nhau
            $st = $pdo->prepare(
                'SELECT MaNguoiGui, TrangThai FROM BANBE
                  WHERE CapNho = LEAST(?, ?) AND CapLon = GREATEST(?, ?) FOR UPDATE'
            );
            $st->execute([$toi, $nguoiKia, $toi, $nguoiKia]);
            $dong = $st->fetch();

            if (!$dong) {
                $pdo->prepare('INSERT INTO BANBE (MaNguoiGui, MaNguoiNhan) VALUES (?, ?)')
                    ->execute([$toi, $nguoiKia]);
                $kq = self::OK;
            } elseif ($dong['TrangThai'] === 'Đã chấp nhận') {
                $kq = self::DA_LA_BAN;
            } elseif ($dong['TrangThai'] === 'Chờ') {
                $kq = ((int) $dong['MaNguoiGui'] === $toi) ? self::DA_GUI : self::CO_CHO_XU_LY;
            } else {
                // "Từ chối": gửi lại bằng cách cập nhật dòng cũ. Gán thẳng hai giá trị
                // (không viết SET Gui = Nhan, Nhan = Gui vì MySQL gán từ trái sang phải sẽ sai).
                $pdo->prepare(
                    "UPDATE BANBE SET MaNguoiGui = ?, MaNguoiNhan = ?, TrangThai = 'Chờ', NgayGui = NOW()
                      WHERE CapNho = LEAST(?, ?) AND CapLon = GREATEST(?, ?)"
                )->execute([$toi, $nguoiKia, $toi, $nguoiKia, $toi, $nguoiKia]);
                $kq = self::OK;
            }

            $pdo->commit();

            if ($kq === self::OK) {
                ThongBaoHelper::taoThongBaoBanBe(
                    $pdo, $nguoiKia, $toi, self::LOAI_LOI_MOI,
                    'đã gửi cho bạn lời mời kết bạn', self::lienKet('loimoi')
                );
            }
            return $kq;
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 23000: vi phạm duy nhất (hai bên gửi cùng lúc); 40001: deadlock
            if (in_array($ex->getCode(), ['23000', '40001'], true)) {
                return self::XUNG_DOT;
            }
            throw $ex;
        }
    }

    // Người gửi hủy lời mời còn "Chờ": xóa dòng
    public static function huyLoiMoi(int $toi, int $nguoiKia): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "DELETE FROM BANBE WHERE MaNguoiGui = ? AND MaNguoiNhan = ? AND TrangThai = 'Chờ'"
        );
        $st->execute([$toi, $nguoiKia]);
        if ($st->rowCount() < 1) {
            return false;
        }
        // Lời mời đã rút lại thì thông báo bên người nhận không còn đúng
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $nguoiKia, $toi, self::LOAI_LOI_MOI);
        return true;
    }

    // Người nhận chấp nhận lời mời đang "Chờ"
    public static function chapNhan(int $toi, int $nguoiGui): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "UPDATE BANBE SET TrangThai = 'Đã chấp nhận'
              WHERE MaNguoiGui = ? AND MaNguoiNhan = ? AND TrangThai = 'Chờ'"
        );
        $st->execute([$nguoiGui, $toi]);
        if ($st->rowCount() < 1) {
            return false;
        }
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $toi, $nguoiGui, self::LOAI_LOI_MOI);
        ThongBaoHelper::taoThongBaoBanBe(
            $pdo, $nguoiGui, $toi, self::LOAI_CHAP_NHAN,
            'đã chấp nhận lời mời kết bạn của bạn', self::lienKet('danhsach')
        );
        return true;
    }

    // Người nhận từ chối: giữ dòng, trạng thái "Từ chối"
    public static function tuChoi(int $toi, int $nguoiGui): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "UPDATE BANBE SET TrangThai = 'Từ chối'
              WHERE MaNguoiGui = ? AND MaNguoiNhan = ? AND TrangThai = 'Chờ'"
        );
        $st->execute([$nguoiGui, $toi]);
        if ($st->rowCount() < 1) {
            return false;
        }
        // Từ chối thì im lặng với người gửi, chỉ dọn thông báo lời mời của chính mình
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $toi, $nguoiGui, self::LOAI_LOI_MOI);
        return true;
    }

    // Hủy kết bạn: xóa dòng (chỉ khi hai bên đang là bạn bè)
    public static function huyKetBan(int $toi, int $nguoiKia): bool
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "DELETE FROM BANBE
              WHERE CapNho = LEAST(?, ?) AND CapLon = GREATEST(?, ?) AND TrangThai = 'Đã chấp nhận'"
        );
        $st->execute([$toi, $nguoiKia, $toi, $nguoiKia]);
        if ($st->rowCount() < 1) {
            return false;
        }
        // Thông báo "đã chấp nhận" của cả hai chiều không còn đúng nữa
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $nguoiKia, $toi, self::LOAI_CHAP_NHAN);
        ThongBaoHelper::xoaThongBaoBanBe($pdo, $toi, $nguoiKia, self::LOAI_CHAP_NHAN);
        return true;
    }

    // Số lời mời đang chờ mình xử lý (hiện trên menu)
    public static function demLoiMoiCho(int $maND): int
    {
        if ($maND <= 0) {
            return 0;
        }
        try {
            $st = Database::pdo()->prepare(
                "SELECT COUNT(*) FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = b.MaNguoiGui
                  WHERE b.MaNguoiNhan = ? AND b.TrangThai = 'Chờ' AND u.TrangThai = 'Hoạt động'"
            );
            $st->execute([$maND]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // Đường dẫn tới trang Bạn bè (kèm mốc #...) để gắn vào thông báo
    private static function lienKet(string $moc): string
    {
        return (defined('BASE_URL') ? BASE_URL : '') . '/client/banbe.php#' . $moc;
    }

    // Thông báo một lần: ['loai' => 'ok'|'loi', 'nd' => '...']
    public static function flash(?string $loai = null, ?string $noiDung = null): ?array
    {
        if ($loai !== null) {
            $_SESSION['flash_bb'] = ['loai' => $loai, 'nd' => (string) $noiDung];
            return null;
        }
        $m = $_SESSION['flash_bb'] ?? null;
        unset($_SESSION['flash_bb']);
        return $m;
    }

    /**
     * Lấy trạng thái bạn bè hàng loạt giữa $toi và danh sách $dsMaND (tránh N+1 query).
     * Trả về: [$maNguoiKia => ['trangThai' => 'ban_be'|'da_gui'|'cho_minh_duyet'|'chua_ket_ban']]
     */
    public static function layTrangThaiCacNguoi(PDO $pdo, int $toi, array $dsMaND): array
    {
        if ($toi <= 0) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $dsMaND),
            static fn (int $id): bool => $id > 0 && $id !== $toi
        )));
        if (!$ids) {
            return [];
        }

        $kq = [];
        foreach ($ids as $id) {
            $kq[$id] = ['trangThai' => 'chua_ket_ban'];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT MaNguoiGui, MaNguoiNhan, TrangThai
                  FROM BANBE
                 WHERE (MaNguoiGui = ? AND MaNguoiNhan IN ($placeholders))
                    OR (MaNguoiNhan = ? AND MaNguoiGui IN ($placeholders))";
        $params = array_merge([$toi], $ids, [$toi], $ids);
        $st = $pdo->prepare($sql);
        $st->execute($params);

        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $gui  = (int) $r['MaNguoiGui'];
            $nhan = (int) $r['MaNguoiNhan'];
            $kia  = ($gui === $toi) ? $nhan : $gui;
            $tt   = (string) $r['TrangThai'];

            if ($tt === 'Đã chấp nhận') {
                $kq[$kia] = ['trangThai' => 'ban_be'];
            } elseif ($tt === 'Chờ') {
                $kq[$kia] = ['trangThai' => ($gui === $toi) ? 'da_gui' : 'cho_minh_duyet'];
            } else {
                $kq[$kia] = ['trangThai' => 'chua_ket_ban'];
            }
        }

        return $kq;
    }

    /**
     * Lấy danh sách bạn bè đã chấp nhận của $toi (dùng chung cho Sảnh Thách Đấu PIN và các module khác).
     */
    public static function layDanhSachBanBe(PDO $pdo, int $toi): array
    {
        if ($toi <= 0) {
            return [];
        }
        $st = $pdo->prepare(
            "SELECT u.MaND, u.HoTen, u.AnhDaiDien, u.TuongChon,
                    COALESCE((SELECT MAX(e.DiemElo) FROM ELOMONHOC e WHERE e.MaND = u.MaND), 1000) AS EloCao
               FROM BANBE b
               JOIN NGUOIDUNG u ON u.MaND = IF(b.MaNguoiGui = ?, b.MaNguoiNhan, b.MaNguoiGui)
              WHERE (b.MaNguoiGui = ? OR b.MaNguoiNhan = ?)
                AND b.TrangThai = 'Đã chấp nhận'
                AND u.TrangThai = 'Hoạt động'
              ORDER BY u.HoTen"
        );
        $st->execute([$toi, $toi, $toi]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sinh HTML nút/huy hiệu kết bạn đồng bộ toàn hệ thống (Cộng đồng, Bài đăng,...).
     */
    public static function renderNutKetBan(int $toi, int $nguoiKia, string $trangThai = 'chua_ket_ban', string $hoTen = ''): string
    {
        if ($toi <= 0 || $nguoiKia <= 0 || $toi === $nguoiKia || $nguoiKia === 999999) {
            return '';
        }

        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        $tenEsc  = e($hoTen);
        $noiDung = match ($trangThai) {
            'ban_be' => '<span class="cd-fbadge is-friend" title="Đã là bạn bè">🤝 Bạn bè</span>'
                      . '<a href="' . e($baseUrl) . '/client/timtran.php?che_do=pin&amp;moi_ban=' . $nguoiKia . '" class="cd-fbtn is-challenge" title="Thách đấu 1v1 với ' . $tenEsc . '">⚔️ Thách đấu</a>',
            'da_gui' => '<button type="button" class="cd-fbtn is-pending" data-friend-action="huy" data-friend-uid="' . $nguoiKia . '" title="Đã gửi lời mời kết bạn • Bấm để hủy lời mời">⏳ Đã gửi</button>',
            'cho_minh_duyet' => '<button type="button" class="cd-fbtn is-accept" data-friend-action="chapnhan" data-friend-uid="' . $nguoiKia . '" title="Người này đã gửi lời mời cho bạn • Bấm để chấp nhận">✓ Chấp nhận</button>',
            default => '<button type="button" class="cd-fbtn is-add" data-friend-action="gui" data-friend-uid="' . $nguoiKia . '" title="Gửi lời mời kết bạn với ' . $tenEsc . '">+ Kết bạn</button>',
        };

        return '<span class="cd-friend-widget" data-friend-widget="' . $nguoiKia . '" data-friend-name="' . $tenEsc . '">' . $noiDung . '</span>';
    }

    /**
     * Xử lý AJAX thao tác kết bạn từ Cộng đồng / Bài đăng và trả về trạng thái + HTML đồng bộ mới.
     */
    public static function xuLyAjax(int $toi, string $hanhDong, int $nguoiKia): array
    {
        if ($toi <= 0) {
            return ['ok' => false, 'msg' => 'Vui lòng đăng nhập để thực hiện thao tác này.'];
        }
        if ($nguoiKia <= 0 || $nguoiKia === $toi) {
            return ['ok' => false, 'msg' => 'Người dùng không hợp lệ.'];
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT HoTen FROM NGUOIDUNG WHERE MaND = ? AND TrangThai = 'Hoạt động'");
        $st->execute([$nguoiKia]);
        $hoTenKia = $st->fetchColumn();
        if ($hoTenKia === false) {
            return ['ok' => false, 'msg' => 'Không tìm thấy tài khoản sinh viên này.'];
        }
        $hoTenKia = (string) $hoTenKia;

        $ok = false;
        $msg = 'Hành động không hợp lệ.';

        if ($hanhDong === 'gui') {
            $kq = self::guiLoiMoi($toi, $nguoiKia);
            $ok = ($kq === self::OK);
            $msg = $ok ? ('Đã gửi lời mời kết bạn tới ' . $hoTenKia . '.') : (self::THONG_BAO[$kq] ?? 'Không thể gửi lời mời.');
        } elseif ($hanhDong === 'huy') {
            $ok = self::huyLoiMoi($toi, $nguoiKia);
            $msg = $ok ? ('Đã hủy lời mời kết bạn với ' . $hoTenKia . '.') : 'Lời mời không còn hiệu lực.';
        } elseif ($hanhDong === 'chapnhan') {
            $ok = self::chapNhan($toi, $nguoiKia);
            $msg = $ok ? ('Bạn và ' . $hoTenKia . ' đã trở thành bạn bè!') : 'Lời mời không còn hiệu lực.';
        } elseif ($hanhDong === 'tuchoi') {
            $ok = self::tuChoi($toi, $nguoiKia);
            $msg = $ok ? ('Đã từ chối lời mời của ' . $hoTenKia . '.') : 'Lời mời không còn hiệu lực.';
        } elseif ($hanhDong === 'huyketban') {
            $ok = self::huyKetBan($toi, $nguoiKia);
            $msg = $ok ? ('Đã hủy kết bạn với ' . $hoTenKia . '.') : 'Hai bạn không còn là bạn bè.';
        }

        $map = self::layTrangThaiCacNguoi($pdo, $toi, [$nguoiKia]);
        $ttMoi = $map[$nguoiKia]['trangThai'] ?? 'chua_ket_ban';

        return [
            'ok'          => $ok,
            'trangThai'   => $ttMoi,
            'msg'         => $msg,
            'html'        => self::renderNutKetBan($toi, $nguoiKia, $ttMoi, $hoTenKia),
            'soLoiMoiCho' => self::demLoiMoiCho($toi),
        ];
    }
}