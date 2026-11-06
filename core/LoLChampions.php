<?php
// Danh sách Đấu Thần Plants vs Zombies trong Đấu Trường QuizUp Arena (Màu Sắc Nhã Nhặn, Cân Đối)
class LoLChampions
{
    // Mapping tương thích ngược cho session hoặc dữ liệu cũ
    private const ALIASES = [
        'faker'  => 'sunflower',
        'yasuo'  => 'snowpea',
        'ahri'   => 'wallnut',
        'leesin' => 'buckethead',
        'jinx'   => 'flagzombie',
        'zed'    => 'drzomboss'
    ];

    public const DANH_SACH = [
        'sunflower' => [
            'id'          => 'sunflower',
            'ten'         => 'Hoa Hướng Dương (Sunflower)',
            'tenNgan'     => 'Sunflower',
            'danhHieu'    => 'Thần Mặt Trời Ấm Áp',
            'vaiTro'      => 'Hỗ Trợ Leo Rank',
            'phe'         => 'plant',
            'pheTen'      => 'Phe Cây Thủ',
            'pheIcon'     => '🌱',
            'monHoc'      => 'Mọi Bộ Môn (Đặc biệt: Kinh Tế & Ngoại Ngữ)',
            'avatar'      => '/assets/images/champions/sunflower.jpg',
            'cauNoi'      => 'There\'s a zombie on your lawn! Hãy cùng ta tích lũy năng lượng mặt trời!',
            'chieuQ'      => 'Quang Hợp Hàng Đợi (+20% Elo Thắng)',
            'chieuR'      => 'Ánh Sáng Khởi Sắc (Bảo Vệ Tinh Thần)',
            'tacDungMatch'=> '+20% Điểm Elo khi chiến thắng',
            'tacDungArena'=> 'Tích Năng Lượng Mặt Trời x1.5 điểm cống hiến',
            'buffLoai'    => 'bonus_elo',
            'mauSac'      => '#FBBF24',
            'mauRgb'      => '251, 191, 36',
            'mauNen'      => 'linear-gradient(135deg, rgba(251, 191, 36, 0.18), rgba(217, 119, 6, 0.12))'
        ],
        'snowpea' => [
            'id'          => 'snowpea',
            'ten'         => 'Cây Đậu Băng (Snow Pea)',
            'tenNgan'     => 'Snow Pea',
            'danhHieu'    => 'Xạ Thủ Băng Hàn',
            'vaiTro'      => 'Khống Chế Thời Gian',
            'phe'         => 'plant',
            'pheTen'      => 'Phe Cây Thủ',
            'pheIcon'     => '🌱',
            'monHoc'      => 'Lập Trình PHP, CSDL & Thuật Toán',
            'avatar'      => '/assets/images/champions/snowpea.jpg',
            'cauNoi'      => 'Bình tĩnh nào, cái đầu lạnh sẽ đóng băng mọi dòng code hóc búa!',
            'chieuQ'      => 'Radar Băng Hàn (±50 Elo Cân Trình)',
            'chieuR'      => 'Đóng Băng Thời Gian (+4 Giây Mỗi Câu)',
            'tacDungMatch'=> 'Thu hẹp radar (±50 Elo) để gặp đối thủ cân tài',
            'tacDungArena'=> '+4 Giây suy nghĩ mỗi câu hỏi (19s thay vì 15s)',
            'buffLoai'    => 'extra_time',
            'mauSac'      => '#38BDF8',
            'mauRgb'      => '56, 189, 248',
            'mauNen'      => 'linear-gradient(135deg, rgba(56, 189, 248, 0.18), rgba(14, 165, 233, 0.12))'
        ],
        'wallnut' => [
            'id'          => 'wallnut',
            'ten'         => 'Quả Óc Chó (Wall-nut)',
            'tenNgan'     => 'Wall-nut',
            'danhHieu'    => 'Bức Tường Kiên Cường',
            'vaiTro'      => 'Phòng Thủ Bất Bại',
            'phe'         => 'plant',
            'pheTen'      => 'Phe Cây Thủ',
            'pheIcon'     => '🌱',
            'monHoc'      => 'Lịch Sử, Pháp Luật & An Toàn Thông Tin',
            'avatar'      => '/assets/images/champions/wallnut.jpg',
            'cauNoi'      => 'Cứ đứng sau lưng ta! Không câu hỏi mẹo nào có thể xuyên thủng lớp giáp kiên cố này!',
            'chieuQ'      => 'Bảo Toàn Hàng Chờ (Kiên Nhẫn Vàng)',
            'chieuR'      => 'Giáp Vỏ Thép (-50% Phạt Elo & Giữ Chuỗi)',
            'tacDungMatch'=> 'Bảo toàn 100% điểm phòng chờ sau 30s',
            'tacDungArena'=> 'Giảm 50% Elo mất khi thua & bảo vệ chuỗi thắng',
            'buffLoai'    => 'elo_shield',
            'mauSac'      => '#D97706',
            'mauRgb'      => '217, 119, 6',
            'mauNen'      => 'linear-gradient(135deg, rgba(217, 119, 6, 0.18), rgba(180, 83, 9, 0.12))'
        ],
        'buckethead' => [
            'id'          => 'buckethead',
            'ten'         => 'Zombie Xô Sắt (Buckethead)',
            'tenNgan'     => 'Buckethead',
            'danhHieu'    => 'Chiến Binh Kim Loại',
            'vaiTro'      => 'Bậc Thầy Khắc Chế Bot',
            'phe'         => 'zombie',
            'pheTen'      => 'Phe Thây Ma',
            'pheIcon'     => '🧟',
            'monHoc'      => 'Tin Học Ứng Dụng, Mạng & Hệ Điều Hành',
            'avatar'      => '/assets/images/champions/buckethead.jpg',
            'cauNoi'      => 'Brains... Chiếc xô sắt này chống lại mọi đòn tấn công của thuật toán AI!',
            'chieuQ'      => 'Bậc Thầy Đấu Máy (100% Elo Thật)',
            'chieuR'      => 'Vỏ Xô Chống Choáng (Giữ Combo Khi Sai)',
            'tacDungMatch'=> 'Đấu với máy AI nhận 100% điểm Elo như người thật',
            'tacDungArena'=> 'Không bị trừ chuỗi combo khi chọn sai 1 câu trước Bot',
            'buffLoai'    => 'bot_master',
            'mauSac'      => '#94A3B8',
            'mauRgb'      => '148, 163, 184',
            'mauNen'      => 'linear-gradient(135deg, rgba(148, 163, 184, 0.18), rgba(100, 116, 139, 0.12))'
        ],
        'flagzombie' => [
            'id'          => 'flagzombie',
            'ten'         => 'Zombie Cầm Cờ (Flag Zombie)',
            'tenNgan'     => 'Flag Zombie',
            'danhHieu'    => 'Tiên Phong Dẫn Đầu',
            'vaiTro'      => 'Tốc Biến Hàng Đợi',
            'phe'         => 'zombie',
            'pheTen'      => 'Phe Thây Ma',
            'pheIcon'     => '🧟',
            'monHoc'      => 'Toán Cao Cấp, Thống Kê & Tư Duy Phản Xạ',
            'avatar'      => '/assets/images/champions/flagzombie.jpg',
            'cauNoi'      => 'Brains! Đàn zombie đang tới! Không ai có thể làm chậm bước tiến của ta!',
            'chieuQ'      => 'Tiên Phong Hàng Chờ (Ghép Trận <8 Giây)',
            'chieuR'      => 'Xung Trận Bạo Kích (x1.5 Điểm Tốc Độ)',
            'tacDungMatch'=> 'Hàng chờ ưu tiên số 1, tìm trận siêu tốc dưới 8s',
            'tacDungArena'=> 'Bạo kích x1.5 điểm tốc độ khi trả lời trong 3s',
            'buffLoai'    => 'fast_queue',
            'mauSac'      => '#F87171',
            'mauRgb'      => '248, 113, 113',
            'mauNen'      => 'linear-gradient(135deg, rgba(248, 113, 113, 0.18), rgba(220, 38, 38, 0.12))'
        ],
        'drzomboss' => [
            'id'          => 'drzomboss',
            'ten'         => 'Tiến Sĩ Zomboss (Dr. Zomboss)',
            'tenNgan'     => 'Dr. Zomboss',
            'danhHieu'    => 'Chúa Tể Trí Tuệ Não Tàn',
            'vaiTro'      => 'Áp Chế & Phá Bẫy',
            'phe'         => 'zombie',
            'pheTen'      => 'Phe Thây Ma',
            'pheIcon'     => '🧟',
            'monHoc'      => 'Triết Học, Khoa Học Dữ Liệu & Phản Biện',
            'avatar'      => '/assets/images/champions/drzomboss.jpg',
            'cauNoi'      => 'Mọi câu đố của các ngươi chỉ là thức ăn cho bộ não vĩ đại của ta!',
            'chieuQ'      => 'Áp Chế Radar (Hào Quang Quỷ Dị)',
            'chieuR'      => 'Chiến Thuật Nuốt Não (Bùa 50:50 Tự Động)',
            'tacDungMatch'=> 'Áp chế tâm lý đối thủ bằng hào quang tím trên radar',
            'tacDungArena'=> 'Nuốt 1 đáp án sai trong 3 câu hỏi khó nhất',
            'buffLoai'    => 'eliminate_wrong',
            'mauSac'      => '#C084FC',
            'mauRgb'      => '192, 132, 252',
            'mauNen'      => 'linear-gradient(135deg, rgba(192, 132, 252, 0.18), rgba(147, 51, 234, 0.12))'
        ]
    ];

    public static function layTuong(?string $id): array
    {
        if ($id === null) {
            return self::DANH_SACH['sunflower'];
        }
        $normalizedId = self::ALIASES[$id] ?? $id;
        return self::DANH_SACH[$normalizedId] ?? self::DANH_SACH['sunflower'];
    }

    public static function ngauNhien(): array
    {
        $keys = array_keys(self::DANH_SACH);
        $k = $keys[array_rand($keys)];
        return self::DANH_SACH[$k];
    }
}
