<?php
// Kiểm tra và ghi câu hỏi + đáp án. Dùng chung cho thêm/sửa câu hỏi (A-18) và sau này cho
// import Excel (A-22), tách Word (A-43), kiểm tra trùng (A-44).
// Đặt tại: elo_quiz/core/CauHoiHelper.php
//
// Quy ước: kiemTra() không ghi gì vào CSDL. chen() và ghiDapAn() KHÔNG tự mở giao dịch,
// để nơi gọi (ví dụ import nhiều dòng) gói tất cả vào một giao dịch (6.5).
class CauHoiHelper
{
    public const DO_KHO       = ['Dễ', 'Trung bình', 'Khó'];
    public const TOI_DA_NOI_DUNG = 2000;
    public const TOI_DA_DAP_AN   = 500;
    public const TOI_DA_GIAI_THICH = 2000;

    // Chuẩn hóa xuống dòng và cắt khoảng trắng hai đầu
    public static function chuan(string $s): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $s));
    }

    // Tìm câu hỏi trùng nội dung trong cùng môn (A-44). Trả về mã câu trùng hoặc null.
    // So sánh theo collation của cột (không phân biệt hoa thường). $boQuaId để bỏ qua chính câu đang sửa.
    public static function timTrung(PDO $pdo, int $maMon, string $noiDung, int $boQuaId = 0): ?int
    {
        $st = $pdo->prepare('SELECT MaCauHoi FROM CAUHOI WHERE MaMon = ? AND NoiDung = ? AND MaCauHoi <> ? LIMIT 1');
        $st->execute([$maMon, $noiDung, $boQuaId]);
        $ma = $st->fetchColumn();
        return $ma === false ? null : (int) $ma;
    }

    // $in: mon, loai ('' hoặc 0 = dùng cho cả hai kỳ), dokho, noidung, giaithich,
    //      dapan (mảng chuỗi, ô trống bị bỏ qua), dung (chỉ số trong mảng dapan gốc)
    // $boQuaTrungCSDL = true: không kiểm tra trùng với CSDL (NhapCauHoi::kiemTraBang tự kiểm tra để gắn nhãn "Trùng")
    // Trả về [mảng lỗi, dữ liệu sạch hoặc null nếu có lỗi]
    public static function kiemTra(PDO $pdo, array $in, int $boQuaId = 0, bool $boQuaTrungCSDL = false): array
    {
        $loi = [];

        $noiDung   = self::chuan((string) ($in['noidung'] ?? ''));
        $giaiThich = self::chuan((string) ($in['giaithich'] ?? ''));
        $maMon     = (int) ($in['mon'] ?? 0);
        $loaiRaw   = $in['loai'] ?? '';
        $maLoai    = ($loaiRaw === '' || $loaiRaw === null || (int) $loaiRaw === 0) ? null : (int) $loaiRaw;
        $doKho     = (string) ($in['dokho'] ?? '');

        // Môn học (chỉ nhận môn đang mở hoặc đã ẩn, không nhận môn chờ duyệt)
        $st = $pdo->prepare("SELECT COUNT(*) FROM MONHOC WHERE MaMon = ? AND TrangThai IN ('Đang mở','Đã ẩn')");
        $st->execute([$maMon]);
        if ((int) $st->fetchColumn() === 0) {
            $loi[] = 'Hãy chọn môn học.';
        }
        if ($maLoai !== null) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM LOAIKYTHI WHERE MaLoaiKT = ?');
            $st->execute([$maLoai]);
            if ((int) $st->fetchColumn() === 0) {
                $loi[] = 'Loại kỳ thi không hợp lệ.';
            }
        }
        if (!in_array($doKho, self::DO_KHO, true)) {
            $loi[] = 'Hãy chọn độ khó.';
        }

        // Nội dung và giải thích
        if ($noiDung === '') {
            $loi[] = 'Hãy nhập nội dung câu hỏi.';
        } elseif (mb_strlen($noiDung) > self::TOI_DA_NOI_DUNG) {
            $loi[] = 'Nội dung câu hỏi tối đa ' . self::TOI_DA_NOI_DUNG . ' ký tự.';
        }
        if (mb_strlen($giaiThich) > self::TOI_DA_GIAI_THICH) {
            $loi[] = 'Phần giải thích tối đa ' . self::TOI_DA_GIAI_THICH . ' ký tự.';
        }

        // Đáp án: bỏ ô trống, giữ thứ tự, phải có 2 đến 4 phương án và đúng một đáp án đúng
        $dungGoc = (int) ($in['dung'] ?? -1);
        $dapAn   = [];
        $dungMoi = null;
        foreach (array_values((array) ($in['dapan'] ?? [])) as $i => $tx) {
            $tx = self::chuan((string) $tx);
            if ($tx === '') {
                if ($i === $dungGoc) {
                    $loi[] = 'Phương án được chọn làm đáp án đúng đang để trống.';
                    $dungGoc = -2;   // đã báo lỗi, không báo thêm "chưa chọn đáp án đúng"
                }
                continue;
            }
            if (mb_strlen($tx) > self::TOI_DA_DAP_AN) {
                $loi[] = 'Mỗi phương án tối đa ' . self::TOI_DA_DAP_AN . ' ký tự.';
            }
            $dapAn[] = $tx;
            if ($i === $dungGoc) {
                $dungMoi = count($dapAn) - 1;
            }
        }
        if (count($dapAn) < 2 || count($dapAn) > 4) {
            $loi[] = 'Câu hỏi cần từ 2 đến 4 phương án trả lời.';
        }
        if ($dungMoi === null && $dungGoc !== -2) {
            $loi[] = 'Hãy chọn đúng một đáp án đúng.';
        }
        if (count(array_unique(array_map('mb_strtolower', $dapAn))) < count($dapAn)) {
            $loi[] = 'Có hai phương án trả lời giống nhau.';
        }

        // A-44: trùng nội dung với câu khác trong cùng môn
        if (!$boQuaTrungCSDL && $noiDung !== '' && $maMon > 0 && !$loi) {
            $trung = self::timTrung($pdo, $maMon, $noiDung, $boQuaId);
            if ($trung !== null) {
                $loi[] = 'Nội dung trùng với câu hỏi #' . $trung . ' trong cùng môn.';
            }
        }

        if ($loi) {
            return [$loi, null];
        }
        return [[], [
            'mon'       => $maMon,
            'loai'      => $maLoai,
            'dokho'     => $doKho,
            'noidung'   => $noiDung,
            'giaithich' => $giaiThich === '' ? null : $giaiThich,
            'dapan'     => $dapAn,
            'dung'      => $dungMoi,
        ]];
    }

    // Thêm câu hỏi mới (trạng thái Hiển thị) kèm đáp án. Không tự mở giao dịch.
    public static function chen(PDO $pdo, array $c): int
    {
        $pdo->prepare(
            "INSERT INTO CAUHOI (MaMon, MaLoaiKT, NoiDung, GiaiThich, DoKho, TrangThai)
             VALUES (?, ?, ?, ?, ?, 'Hiển thị')"
        )->execute([$c['mon'], $c['loai'], $c['noidung'], $c['giaithich'], $c['dokho']]);
        $id = (int) $pdo->lastInsertId();
        self::ghiDapAn($pdo, $id, $c);
        return $id;
    }

    // Ghi các đáp án của một câu (câu chưa có đáp án nào)
    public static function ghiDapAn(PDO $pdo, int $maCauHoi, array $c): void
    {
        $st = $pdo->prepare('INSERT INTO DAPAN (MaCauHoi, NoiDungDapAn, LaDapAnDung) VALUES (?, ?, ?)');
        foreach ($c['dapan'] as $i => $tx) {
            $st->execute([$maCauHoi, $tx, $i === $c['dung'] ? 1 : 0]);
        }
    }
}