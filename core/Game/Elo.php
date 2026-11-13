<?php
// Tính Elo sau trận (C-32, C-33). Đặt tại: elo_quiz/core/Elo.php
// Gọi bên trong giao dịch đang giữ khóa dòng TRANDAU, ngay sau khi trận chuyển sang "Kết thúc".
// - Khóa hai dòng ELOMONHOC theo thứ tự MaND tăng dần để nhiều trận kết thúc cùng lúc không deadlock.
// - Chỉ chạy một lần cho mỗi trận (kiểm tra LICHSUELO + ràng buộc duy nhất MaND, MaMon, MaTran).
// - Trận Hủy không gọi hàm này nên không đổi Elo.
class Elo
{
    // Elo mới = Elo cũ + K x (S - E), E = 1 / (1 + 10^((Elo đối thủ - Elo mình) / 400))
    public static function tinhMoi(int $eloMinh, int $eloDoiThu, float $s, int $k): int
    {
        $e = 1 / (1 + pow(10, ($eloDoiThu - $eloMinh) / 400));
        return (int) round($eloMinh + $k * ($s - $e));
    }

    public static function capNhatSauTran(PDO $pdo, int $maTran): void
    {
        $st = $pdo->prepare('SELECT MaMon, TrangThai FROM TRANDAU WHERE MaTran = ?');
        $st->execute([$maTran]);
        $tran = $st->fetch();
        if (!$tran || $tran['TrangThai'] !== 'Kết thúc') {
            return;
        }
        $maMon = (int) $tran['MaMon'];

        // Đã tính cho trận này rồi thì thôi (gọi lặp không cộng trừ hai lần)
        $st = $pdo->prepare('SELECT 1 FROM LICHSUELO WHERE MaTran = ? LIMIT 1');
        $st->execute([$maTran]);
        if ($st->fetchColumn()) {
            return;
        }

        $st = $pdo->prepare('SELECT MaND, KetQua FROM NGUOICHOITRANDAU WHERE MaTran = ? ORDER BY MaND');
        $st->execute([$maTran]);
        $ds = $st->fetchAll();
        if (count($ds) !== 2 || $ds[0]['KetQua'] === null || $ds[1]['KetQua'] === null) {
            return;
        }
        $a = (int) $ds[0]['MaND'];
        $b = (int) $ds[1]['MaND'];
        $kq = [$a => (string) $ds[0]['KetQua'], $b => (string) $ds[1]['KetQua']];

        // Đảm bảo có dòng Elo (C-22), rồi khóa theo thứ tự MaND tăng dần
        $khoiTao = max(0, Config::int('ELO_KHOI_TAO', 1000));
        $ins = $pdo->prepare('INSERT IGNORE INTO ELOMONHOC (MaND, MaMon, DiemElo) VALUES (?, ?, ?)');
        $ins->execute([$a, $maMon, $khoiTao]);
        $ins->execute([$b, $maMon, $khoiTao]);

        $st = $pdo->prepare('SELECT MaND, DiemElo, SoTran FROM ELOMONHOC WHERE MaMon = ? AND MaND IN (?, ?) ORDER BY MaND FOR UPDATE');
        $st->execute([$maMon, $a, $b]);
        $cu = [];
        foreach ($st->fetchAll() as $r) {
            $cu[(int) $r['MaND']] = ['elo' => (int) $r['DiemElo'], 'soTran' => (int) $r['SoTran']];
        }
        if (count($cu) !== 2) {
            return;
        }

        $nguong = max(0, Config::int('NGUONG_SO_TRAN_DOI_K', 30));
        $kMoi   = Config::int('K_NGUOI_MOI', 32);
        $kCu    = Config::int('K_NGUOI_CU', 24);
        $diemS  = ['Thắng' => 1.0, 'Hòa' => 0.5, 'Thua' => 0.0];

        $upElo = $pdo->prepare(
            'UPDATE ELOMONHOC
                SET DiemElo = ?, SoTran = SoTran + 1,
                    SoThang = SoThang + ?, SoThua = SoThua + ?, SoHoa = SoHoa + ?
              WHERE MaND = ? AND MaMon = ?'
        );
        $insLs = $pdo->prepare(
            'INSERT INTO LICHSUELO (MaND, MaMon, MaTran, EloTruoc, EloSau, ThayDoi) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $upTran = $pdo->prepare('UPDATE NGUOICHOITRANDAU SET EloTruoc = ?, EloSau = ? WHERE MaTran = ? AND MaND = ?');

        // Nhận diện hiệu ứng bùa lợi từ Đấu Thần PvZ đang xuất trận
        require_once __DIR__ . '/LoLChampions.php';
        $champId = $_SESSION['tuong_chon'] ?? 'sunflower';
        $champInfo = LoLChampions::layTuong($champId);
        $buffLoai = $champInfo['buffLoai'] ?? '';
        $currentLoggedND = (int) ($_SESSION['user']['MaND'] ?? 0);

        foreach ([$a => $b, $b => $a] as $minh => $doiThu) {
            $k    = $cu[$minh]['soTran'] < $nguong ? $kMoi : $kCu;   // dưới ngưỡng số trận dùng K lớn
            $moi  = self::tinhMoi($cu[$minh]['elo'], $cu[$doiThu]['elo'], $diemS[$kq[$minh]], $k);
            $eloC = $cu[$minh]['elo'];

            // Áp dụng bùa Đấu Thần PvZ cho người chơi đang đăng nhập
            if ($minh === $currentLoggedND) {
                $delta = $moi - $eloC;
                // 1. Sunflower (+20% Elo khi chiến thắng)
                if ($buffLoai === 'bonus_elo' && $kq[$minh] === 'Thắng' && $delta > 0) {
                    $delta = (int) round($delta * 1.20);
                    $moi = $eloC + $delta;
                }
                // 2. Wall-nut (Giảm 50% lượng Elo bị trừ khi thua trận)
                elseif ($buffLoai === 'elo_shield' && $kq[$minh] === 'Thua' && $delta < 0) {
                    $delta = (int) round($delta * 0.50);
                    $moi = $eloC + $delta;
                }
            }

            $upElo->execute([
                $moi,
                $kq[$minh] === 'Thắng' ? 1 : 0,
                $kq[$minh] === 'Thua' ? 1 : 0,
                $kq[$minh] === 'Hòa' ? 1 : 0,
                $minh, $maMon,
            ]);
            $insLs->execute([$minh, $maMon, $maTran, $eloC, $moi, $moi - $eloC]);
            $upTran->execute([$eloC, $moi, $maTran, $minh]);
        }
    }
}