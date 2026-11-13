<?php
// Quản lý môn học và loại kỳ thi (UC12: A-14, A-15, A-16) + duyệt môn do sinh viên đề xuất.
// Đặt tại: elo_quiz/admin/monhoc.php
// A-15: không có nút xóa môn đã tồn tại; chỉ ẩn/hiện. Môn "Chờ duyệt" bị từ chối thì xóa (chưa ai tham chiếu).
// Theo ghi chú trong core/NhatKy.php, thao tác môn học không nằm trong danh sách A-40 nên không ghi nhật ký.
require_once __DIR__ . '/../core/bootstrap.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo   = Database::pdo();
$sach  = BASE_URL . '/admin/monhoc.php';

function mh_chuan(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

// Biểu tượng của môn: CSDL chưa có cột biểu tượng nên chọn theo từ khóa trong tên môn, không khớp thì dùng quyển sách.
function mh_bieu_tuong(string $ten): string
{
    $t = mb_strtolower($ten);
    $bang = [
        'calc'  => ['toán'],
        'atom'  => ['vật lý', 'vật lí', 'lý ', 'physics'],
        'flask' => ['hóa', 'hoá', 'chem'],
        'dna'   => ['sinh', 'bio'],
        'globe' => ['tiếng', 'english', 'ngoại ngữ', 'anh văn'],
        'bank'  => ['lịch sử', 'địa lý', 'địa lí', 'history', 'giáo dục công dân', 'gdcd'],
    ];
    foreach ($bang as $k => $tuKhoa) {
        foreach ($tuKhoa as $tk) {
            if (mb_strpos($t, $tk) !== false) {
                return $k;
            }
        }
    }
    return 'book';
}

// ---------------------------------------------------------------------
// Xử lý POST (mô hình POST -> redirect -> GET)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hd   = (string) ($_POST['hanh_dong'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);
    $loi  = null;
    $ok   = null;

    try {
        switch ($hd) {
            case 'them_mon':
            case 'sua_mon':
                $ten  = mh_chuan((string) ($_POST['ten_mon'] ?? ''));
                $moTa = trim((string) ($_POST['mo_ta'] ?? ''));
                if ($ten === '') {
                    $loi = 'Hãy nhập tên môn học.';
                } elseif (mb_strlen($ten) > 150) {
                    $loi = 'Tên môn tối đa 150 ký tự.';
                } elseif (mb_strlen($moTa) > 500) {
                    $loi = 'Mô tả tối đa 500 ký tự.';
                } else {
                    // Trùng tên với môn khác (mọi trạng thái, không phân biệt hoa thường)
                    $st = $pdo->prepare('SELECT TrangThai FROM MONHOC WHERE TenMon = ? AND MaMon <> ? LIMIT 1');
                    $st->execute([$ten, $hd === 'sua_mon' ? $id : 0]);
                    $trung = $st->fetchColumn();
                    if ($trung === 'Chờ duyệt') {
                        $loi = 'Tên này đang có một đề xuất chờ duyệt, hãy duyệt đề xuất đó ở mục bên dưới.';
                    } elseif ($trung !== false) {
                        $loi = 'Đã có môn học trùng tên (trạng thái: ' . $trung . ').';
                    } elseif ($hd === 'them_mon') {
                        $pdo->prepare("INSERT INTO MONHOC (TenMon, MoTa, TrangThai) VALUES (?, ?, 'Đang mở')")
                            ->execute([$ten, $moTa === '' ? null : $moTa]);
                        $ok = 'Đã thêm môn học.';
                    } else {
                        $up = $pdo->prepare("UPDATE MONHOC SET TenMon = ?, MoTa = ? WHERE MaMon = ? AND TrangThai IN ('Đang mở','Đã ẩn')");
                        $up->execute([$ten, $moTa === '' ? null : $moTa, $id]);
                        // rowCount = 0 có thể do không đổi gì; kiểm tra tồn tại riêng
                        $co = $pdo->prepare("SELECT COUNT(*) FROM MONHOC WHERE MaMon = ? AND TrangThai IN ('Đang mở','Đã ẩn')");
                        $co->execute([$id]);
                        if ((int) $co->fetchColumn() === 0) {
                            $loi = 'Không tìm thấy môn học này.';
                        } else {
                            $ok = 'Đã lưu thay đổi.';
                        }
                    }
                }
                break;

            case 'an_mon':
            case 'hien_mon':
                $an = $hd === 'an_mon';
                $up = $pdo->prepare('UPDATE MONHOC SET TrangThai = ? WHERE MaMon = ? AND TrangThai = ?');
                $up->execute([$an ? 'Đã ẩn' : 'Đang mở', $id, $an ? 'Đang mở' : 'Đã ẩn']);
                $ok = $up->rowCount() === 1
                    ? ($an ? 'Đã ẩn môn học.' : 'Đã hiện lại môn học.')
                    : 'Môn học đã ở trạng thái này rồi.';
                break;

            case 'xoa_mon':
                // Chỉ xóa được môn chưa có câu hỏi và chưa có trận đấu (A-15); môn đã dùng thì chỉ ẩn
                $del = $pdo->prepare(
                    "DELETE FROM MONHOC WHERE MaMon = ? AND TrangThai IN ('Đang mở','Đã ẩn')
                       AND NOT EXISTS (SELECT 1 FROM CAUHOI c WHERE c.MaMon = MONHOC.MaMon)
                       AND NOT EXISTS (SELECT 1 FROM TRANDAU t WHERE t.MaMon = MONHOC.MaMon)"
                );
                try {
                    $del->execute([$id]);
                    $ok = $del->rowCount() === 1 ? 'Đã xóa môn học.' : null;
                    if ($ok === null) {
                        $loi = 'Môn này đã có câu hỏi hoặc trận đấu nên không xóa được, hãy dùng nút Ẩn.';
                    }
                } catch (PDOException $ex) {
                    $loi = 'Môn này đã có dữ liệu tham chiếu nên không xóa được, hãy dùng nút Ẩn.';
                }
                break;

            case 'xoa_kt':
                $del = $pdo->prepare(
                    'DELETE FROM LOAIKYTHI WHERE MaLoaiKT = ?
                       AND NOT EXISTS (SELECT 1 FROM CAUHOI c WHERE c.MaLoaiKT = LOAIKYTHI.MaLoaiKT)
                       AND NOT EXISTS (SELECT 1 FROM TRANDAU t WHERE t.MaLoaiKT = LOAIKYTHI.MaLoaiKT)'
                );
                try {
                    $del->execute([$id]);
                    if ($del->rowCount() === 1) {
                        $ok = 'Đã xóa loại kỳ thi.';
                    } else {
                        $loi = 'Loại kỳ thi này đã có câu hỏi hoặc trận đấu nên không xóa được.';
                    }
                } catch (PDOException $ex) {
                    $loi = 'Loại kỳ thi này đã có dữ liệu tham chiếu nên không xóa được.';
                }
                break;

            case 'duyet_mon':
                // Điều kiện theo trạng thái cũ: hai quản trị viên cùng bấm thì chỉ một người có tác dụng
                $up = $pdo->prepare("UPDATE MONHOC SET TrangThai = 'Đang mở' WHERE MaMon = ? AND TrangThai = 'Chờ duyệt'");
                $up->execute([$id]);
                $ok = $up->rowCount() === 1 ? 'Đã duyệt, môn học đã hiện cho sinh viên.' : 'Đề xuất này đã được xử lý trước đó.';
                break;

            case 'tu_choi_mon':
                try {
                    $del = $pdo->prepare("DELETE FROM MONHOC WHERE MaMon = ? AND TrangThai = 'Chờ duyệt'");
                    $del->execute([$id]);
                    $ok = $del->rowCount() === 1 ? 'Đã từ chối và xóa đề xuất.' : 'Đề xuất này đã được xử lý trước đó.';
                } catch (PDOException $ex) {
                    $loi = 'Không xóa được đề xuất vì đã có dữ liệu tham chiếu.';
                }
                break;

            case 'them_kt':
            case 'sua_kt':
                $ten  = mh_chuan((string) ($_POST['ten_kt'] ?? ''));
                $moTa = trim((string) ($_POST['mo_ta_kt'] ?? ''));
                if ($ten === '') {
                    $loi = 'Hãy nhập tên loại kỳ thi.';
                } elseif (mb_strlen($ten) > 50) {
                    $loi = 'Tên loại kỳ thi tối đa 50 ký tự.';
                } elseif (mb_strlen($moTa) > 500) {
                    $loi = 'Mô tả tối đa 500 ký tự.';
                } else {
                    $st = $pdo->prepare('SELECT COUNT(*) FROM LOAIKYTHI WHERE TenLoaiKT = ? AND MaLoaiKT <> ?');
                    $st->execute([$ten, $hd === 'sua_kt' ? $id : 0]);
                    if ((int) $st->fetchColumn() > 0) {
                        $loi = 'Đã có loại kỳ thi trùng tên.';
                    } elseif ($hd === 'them_kt') {
                        $pdo->prepare('INSERT INTO LOAIKYTHI (TenLoaiKT, MoTa) VALUES (?, ?)')
                            ->execute([$ten, $moTa === '' ? null : $moTa]);
                        $ok = 'Đã thêm loại kỳ thi.';
                    } else {
                        $co = $pdo->prepare('SELECT COUNT(*) FROM LOAIKYTHI WHERE MaLoaiKT = ?');
                        $co->execute([$id]);
                        if ((int) $co->fetchColumn() === 0) {
                            $loi = 'Không tìm thấy loại kỳ thi này.';
                        } else {
                            $pdo->prepare('UPDATE LOAIKYTHI SET TenLoaiKT = ?, MoTa = ? WHERE MaLoaiKT = ?')
                                ->execute([$ten, $moTa === '' ? null : $moTa, $id]);
                            $ok = 'Đã lưu thay đổi.';
                        }
                    }
                }
                break;

            default:
                $loi = 'Thao tác không hợp lệ.';
        }
    } catch (PDOException $ex) {
        $loi = 'Lỗi cơ sở dữ liệu, chưa lưu được. Hãy thử lại.';
    }

    $_SESSION['mh_flash'] = $loi !== null ? ['loi', $loi] : ['ok', (string) $ok];
    // Luôn quay lại đúng trang đang xem (giữ từ khóa tìm, trang, tab)
    redirect((string) $_SERVER['REQUEST_URI']);
}

$flash = $_SESSION['mh_flash'] ?? null;
unset($_SESSION['mh_flash']);

// ---------------------------------------------------------------------
// Dữ liệu hiển thị
// ---------------------------------------------------------------------

// ---- Môn do sinh viên đề xuất, đang chờ duyệt ----
$tongCho = (int) $pdo->query("SELECT COUNT(*) FROM MONHOC WHERE TrangThai = 'Chờ duyệt'")->fetchColumn();
$pCho    = new Paginator($tongCho, 10, 'pcho');
$dsCho   = $pdo->query(
    "SELECT m.MaMon, m.TenMon, m.MoTa, n.HoTen, n.Email
     FROM MONHOC m LEFT JOIN NGUOIDUNG n ON n.MaND = m.MaNguoiDeXuat
     WHERE m.TrangThai = 'Chờ duyệt'
     ORDER BY m.MaMon
     LIMIT " . $pCho->limit() . ' OFFSET ' . $pCho->offset()
)->fetchAll();

// ---- Danh sách môn (Đang mở / Đã ẩn): tìm theo tên, 6 dòng/trang như ảnh thiết kế ----
$q    = trim((string) ($_GET['q'] ?? ''));
$dk   = ["m.TrangThai IN ('Đang mở','Đã ẩn')"];
$tham = [];
if ($q !== '') {
    $dk[]   = '(m.TenMon LIKE ? OR m.MoTa LIKE ?)';
    $like   = '%' . addcslashes($q, '\\%_') . '%';
    array_push($tham, $like, $like);
}
// Lọc theo tình trạng (bấm ở khung Toàn cảnh): ok | thieu | chua | an
$tt       = (string) ($_GET['tt'] ?? '');
$sqlThieu = "EXISTS (SELECT 1 FROM LOAIKYTHI k WHERE NOT EXISTS (SELECT 1 FROM CAUHOI c2 WHERE c2.MaMon = m.MaMon AND c2.MaLoaiKT = k.MaLoaiKT))";
$sqlCoCau = "EXISTS (SELECT 1 FROM CAUHOI c3 WHERE c3.MaMon = m.MaMon)";
$locTT = [
    'an'    => "m.TrangThai = 'Đã ẩn'",
    'chua'  => "m.TrangThai = 'Đang mở' AND NOT $sqlCoCau",
    'thieu' => "m.TrangThai = 'Đang mở' AND $sqlCoCau AND $sqlThieu",
    'ok'    => "m.TrangThai = 'Đang mở' AND $sqlCoCau AND NOT $sqlThieu",
];
if (!isset($locTT[$tt])) {
    $tt = '';
} else {
    $dk[] = $locTT[$tt];
}
$where = 'WHERE ' . implode(' AND ', $dk);

$st = $pdo->prepare("SELECT COUNT(*) FROM MONHOC m $where");
$st->execute($tham);
$p = new Paginator((int) $st->fetchColumn(), 6, 'pmon');

$st = $pdo->prepare(
    "SELECT m.MaMon, m.TenMon, m.MoTa, m.TrangThai,
            (SELECT COUNT(*) FROM CAUHOI c  WHERE c.MaMon = m.MaMon) AS so_cau,
            (SELECT COUNT(*) FROM TRANDAU t WHERE t.MaMon = m.MaMon) AS so_tran
     FROM MONHOC m $where
     ORDER BY m.MaMon
     LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
);
$st->execute($tham);
$dsMon = $st->fetchAll();

// ---- Bản đồ môn x loại kỳ thi: cột = mọi loại kỳ thi, ô = số câu hỏi của các môn đang hiển thị ----
$cotKT = $pdo->query('SELECT MaLoaiKT, TenLoaiKT FROM LOAIKYTHI ORDER BY MaLoaiKT')->fetchAll();
$soO   = [];   // $soO[MaMon]['chung' | MaLoaiKT] = số câu hỏi (MaLoaiKT NULL = dùng chung cho cả hai kỳ, A-19)
if ($dsMon) {
    $in = implode(',', array_map(static fn ($r) => (int) $r['MaMon'], $dsMon));   // chỉ số nguyên
    foreach ($pdo->query("SELECT MaMon, MaLoaiKT, COUNT(*) AS n FROM CAUHOI WHERE MaMon IN ($in) GROUP BY MaMon, MaLoaiKT") as $r) {
        $soO[(int) $r['MaMon']][$r['MaLoaiKT'] === null ? 'chung' : (int) $r['MaLoaiKT']] = (int) $r['n'];
    }
}
// ---- Toàn cảnh: mỗi ô vuông là một môn (quá nhiều môn thì mỗi ô là một nhóm môn) ----
$tenTT = ['ok' => 'Sẵn sàng', 'thieu' => 'Thiếu câu hỏi', 'chua' => 'Chưa có câu hỏi', 'an' => 'Đã ẩn'];
$tatCa = $pdo->query(
    "SELECT m.MaMon, m.TenMon, m.TrangThai,
            (SELECT COUNT(*) FROM CAUHOI c WHERE c.MaMon = m.MaMon) AS so_cau,
            (SELECT COUNT(*) FROM LOAIKYTHI k WHERE NOT EXISTS
                (SELECT 1 FROM CAUHOI c2 WHERE c2.MaMon = m.MaMon AND c2.MaLoaiKT = k.MaLoaiKT)) AS so_thieu
     FROM MONHOC m WHERE m.TrangThai IN ('Đang mở','Đã ẩn') ORDER BY m.MaMon"
)->fetchAll();
$dem = ['ok' => 0, 'thieu' => 0, 'chua' => 0, 'an' => 0];
foreach ($tatCa as $i => $r) {
    // Đã ẩn > chưa có câu > thiếu câu (chưa có câu cho ít nhất một loại kỳ thi) > sẵn sàng
    $s = $r['TrangThai'] === 'Đã ẩn' ? 'an'
        : ((int) $r['so_cau'] === 0 ? 'chua' : ((int) $r['so_thieu'] > 0 ? 'thieu' : 'ok'));
    $tatCa[$i]['tt'] = $s;
    $dem[$s]++;
}
$tongMon = count($tatCa);
$moiO    = $tongMon > 400 ? (int) ceil($tongMon / 400) : 1;             // trên 400 môn: gộp nhóm
$coO     = $tongMon <= 24 ? 'to' : ($tongMon <= 150 ? 'vua' : 'nho');   // cỡ ô theo số môn
$trongTrang = array_flip(array_map(static fn ($r) => (int) $r['MaMon'], $dsMon));   // các môn đang xem ở bảng
$doNang  = ['an' => 0, 'ok' => 1, 'thieu' => 2, 'chua' => 3];           // nhóm lấy tình trạng nặng nhất
$dsO     = [];
foreach (array_chunk($tatCa, $moiO) as $nhom) {
    $s = 'an'; $moSang = false; $dangXem = false;
    foreach ($nhom as $r) {
        if ($doNang[$r['tt']] > $doNang[$s]) { $s = $r['tt']; }
        $khop = ($q === '' || mb_stripos($r['TenMon'], $q) !== false) && ($tt === '' || $r['tt'] === $tt);
        $moSang = $moSang || $khop;
        $dangXem = $dangXem || isset($trongTrang[(int) $r['MaMon']]);
    }
    $dau = $nhom[0];
    $dsO[] = [
        'tt' => $s, 'mo' => !$moSang, 'xem' => $dangXem, 'mon' => (int) $dau['MaMon'],
        'ten' => $moiO === 1 ? $dau['TenMon'] : ($dau['TenMon'] . ' … ' . end($nhom)['TenMon'] . ' (' . count($nhom) . ' môn)'),
    ];
}
$urlTT = static fn (string $k): string =>
    '?' . http_build_query(array_diff_key($_GET, ['pmon' => 1, 'tt' => 1]) + ($k !== '' && $k !== $tt ? ['tt' => $k] : [])) . '#ds-mon';

// Môn đang mở được chơi nhiều nhưng còn thiếu câu hỏi (theo số trận đấu)
$canBoSung = $pdo->query(
    "SELECT m.MaMon, m.TenMon,
            (SELECT COUNT(*) FROM TRANDAU t WHERE t.MaMon = m.MaMon) AS so_tran,
            (SELECT COUNT(*) FROM LOAIKYTHI k WHERE NOT EXISTS
                (SELECT 1 FROM CAUHOI c2 WHERE c2.MaMon = m.MaMon AND c2.MaLoaiKT = k.MaLoaiKT)) AS so_thieu,
            (SELECT k.TenLoaiKT FROM LOAIKYTHI k WHERE NOT EXISTS
                (SELECT 1 FROM CAUHOI c3 WHERE c3.MaMon = m.MaMon AND c3.MaLoaiKT = k.MaLoaiKT)
             ORDER BY k.MaLoaiKT LIMIT 1) AS kt_thieu
     FROM MONHOC m
     WHERE m.TrangThai = 'Đang mở'
       AND EXISTS (SELECT 1 FROM LOAIKYTHI k WHERE NOT EXISTS
                (SELECT 1 FROM CAUHOI c4 WHERE c4.MaMon = m.MaMon AND c4.MaLoaiKT = k.MaLoaiKT))
     ORDER BY so_tran DESC, m.MaMon LIMIT 4"
)->fetchAll();
$tranMax = 0;
foreach ($canBoSung as $r) { $tranMax = max($tranMax, (int) $r['so_tran']); }

$tuDong    = $dsMon ? $p->offset() + 1 : 0;
$denDong   = $p->offset() + count($dsMon);

// Biểu tượng SVG (kiểu Lucide) dùng trong trang này
$bieuTuongMon = [
    'calc'  => '<rect width="16" height="20" x="4" y="2" rx="2"/><line x1="8" x2="16" y1="6" y2="6"/><line x1="16" x2="16" y1="14" y2="18"/><path d="M16 10h.01M12 10h.01M8 10h.01M12 14h.01M8 14h.01M12 18h.01M8 18h.01"/>',
    'atom'  => '<circle cx="12" cy="12" r="1"/><path d="M20.2 20.2c2.04-2.03.02-7.36-4.5-11.9-4.54-4.52-9.87-6.54-11.9-4.5-2.04 2.03-.02 7.36 4.5 11.9 4.54 4.52 9.87 6.54 11.9 4.5Z"/><path d="M15.7 15.7c4.52-4.54 6.54-9.87 4.5-11.9-2.03-2.04-7.36-.02-11.9 4.5-4.52 4.54-6.54 9.87-4.5 11.9 2.03 2.04 7.36.02 11.9-4.5Z"/>',
    'flask' => '<path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/>',
    'dna'   => '<path d="m10 16 1.5 1.5"/><path d="m14 8-1.5-1.5"/><path d="M15 2c-1.798 1.998-2.518 3.995-2.807 5.993"/><path d="m16.5 10.5 1 1"/><path d="m17 6-2.891-2.891"/><path d="M2 15c6.667-6 13.333 0 20-6"/><path d="m20 9 .891.891"/><path d="M3.109 14.109 4 15"/><path d="m6.5 12.5 1 1"/><path d="m7 18 2.891 2.891"/><path d="M9 22c1.798-1.998 2.518-3.995 2.807-5.993"/>',
    'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    'bank'  => '<line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
    'book'  => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
    'more'  => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    'tim'   => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'mui'   => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'cho'   => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'cup'   => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
    'cong'  => '<path d="M12 5v14M5 12h14"/>',
    'star'   => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
    'medal'  => '<path d="M7.21 15 2.66 7.14a2 2 0 0 1 .13-2.2L4.4 2.8A2 2 0 0 1 6 2h12a2 2 0 0 1 1.6.8l1.6 2.14a2 2 0 0 1 .14 2.2L16.79 15"/><path d="M11 12 5.12 2.2"/><path d="m13 12 5.88-9.8"/><path d="M8 7h8"/><circle cx="12" cy="17" r="5"/>',
    'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
    'cap'    => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
];
$svgMon = static fn (string $k, int $px = 22): string =>
    '<svg width="' . $px . '" height="' . $px . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $bieuTuongMon[$k] . '</svg>';

// ---- Loại kỳ thi: 5 dòng/trang như ảnh thiết kế ----
$qkt     = trim((string) ($_GET['qkt'] ?? ''));
$whereKT = '';
$thamKT  = [];
if ($qkt !== '') {
    $whereKT = 'WHERE (k.TenLoaiKT LIKE ? OR k.MoTa LIKE ?)';
    $likeKT  = '%' . addcslashes($qkt, '\\%_') . '%';
    $thamKT  = [$likeKT, $likeKT];
}
$st = $pdo->prepare("SELECT COUNT(*) FROM LOAIKYTHI k $whereKT");
$st->execute($thamKT);
$pKT = new Paginator((int) $st->fetchColumn(), 5, 'pkt');
$st  = $pdo->prepare(
    "SELECT k.MaLoaiKT, k.TenLoaiKT, k.MoTa,
            (SELECT COUNT(*) FROM CAUHOI c  WHERE c.MaLoaiKT = k.MaLoaiKT) AS so_cau,
            (SELECT COUNT(*) FROM TRANDAU t WHERE t.MaLoaiKT = k.MaLoaiKT) AS so_tran
     FROM LOAIKYTHI k $whereKT ORDER BY k.MaLoaiKT
     LIMIT " . $pKT->limit() . ' OFFSET ' . $pKT->offset()
);
$st->execute($thamKT);
$dsKT = $st->fetchAll();

// Địa chỉ nút xóa tìm kiếm: bỏ từ khóa và số trang của đúng danh sách đó, giữ phần còn lại
$urlXoaTim = static fn (string $tuKhoa, string $trang, string $neo): string =>
    '?' . http_build_query(array_diff_key($_GET, [$tuKhoa => 1, $trang => 1])) . $neo;

// Thanh phân trang kiểu ảnh thiết kế: ‹ 1 2 3 … ›  (giữ nguyên các tham số GET khác)
$mhPhanTrang = static function (Paginator $pg, string $thamSo, string $neo): string {
    if ($pg->soTrang() <= 1) {
        return '';
    }
    $url = static function (int $n) use ($thamSo, $neo): string {
        $q = $_GET;
        $q[$thamSo] = $n;
        return '?' . http_build_query($q) . $neo;
    };
    $t  = $pg->trang();
    $so = $pg->soTrang();
    $h  = '<nav class="mh-pt" aria-label="Phân trang">';
    $h .= $t > 1 ? '<a href="' . e($url($t - 1)) . '" aria-label="Trang trước">&lsaquo;</a>' : '<span class="tat">&lsaquo;</span>';
    $tu  = max(1, min($t - 1, $so - 2));
    $den = min($so, $tu + 2);
    for ($i = $tu; $i <= $den; $i++) {
        $h .= $i === $t ? '<strong>' . $i . '</strong>' : '<a href="' . e($url($i)) . '">' . $i . '</a>';
    }
    if ($den < $so) {
        $h .= '<a href="' . e($url($den + 1)) . '" aria-label="Các trang sau">&hellip;</a>';
    }
    $h .= $t < $so ? '<a href="' . e($url($t + 1)) . '" aria-label="Trang sau">&rsaquo;</a>' : '<span class="tat">&rsaquo;</span>';
    return $h . '</nav>';
};

$tieuDe  = 'Môn học, loại kỳ thi';
$mucChon = 'monhoc';
$cssTrang = 'monhoc';
$jsTrang  = 'monhoc';
// Không bật $giaoDienMoi: dùng cùng khung (nền + sidebar) với trang Tổng quan
require __DIR__ . '/layout/header.php';
?>
<div class="mh-khung">
<section class="mh-hero">
  <img class="mh-hero-hinh" src="<?= e(BASE_URL) ?>/assets/img/hero-sach.svg" alt="" width="150" height="100">
  <div class="mh-hero-chu">
    <h2>Môn học × Loại kỳ thi</h2>
    <p>Nhìn toàn cảnh mọi môn trong một khung, rồi xem chi tiết từng môn ở bảng bên dưới.</p>
  </div>
  <button type="button" class="btn btn-phu mh-nut-them" id="nutThemKt"><?= $svgMon('cong', 18) ?>Thêm loại kỳ thi</button>
  <button type="button" class="btn mh-nut-them" id="nutThemMon" data-them-mon><?= $svgMon('cong', 18) ?>Thêm môn học</button>
</section>

<?php if ($flash): ?>
  <div class="<?= $flash[0] === 'ok' ? 'thanhcong' : 'loi' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

<!-- ===== Toàn cảnh: mỗi ô vuông là một môn ===== -->
<section class="card mh-tc" id="toan-canh">
  <div class="mh-tc-cot">
    <h3>Toàn cảnh <?= $tongMon ?> môn</h3>
    <?php if ($tongMon === 0): ?>
      <p class="nhan-phu">Chưa có môn học nào. Bấm &ldquo;Thêm môn học&rdquo; để bắt đầu.</p>
    <?php else: ?>
      <p class="nhan-phu">Mỗi ô vuông là một môn<?= $moiO > 1 ? ' (mỗi ô gộp ' . $moiO . ' môn, tô theo tình trạng nặng nhất)' : '' ?>. Viền đậm là các môn đang xem ở bảng bên dưới.</p>
      <div class="mh-tuong" data-co="<?= $coO ?>" role="group" aria-label="Toàn cảnh các môn học">
        <?php foreach ($dsO as $o): ?>
          <a class="mh-ov mh-ov-<?= $o['tt'] ?><?= $o['mo'] ? ' mh-ov-mo' : '' ?><?= $o['xem'] ? ' mh-ov-xem' : '' ?>"
             href="<?= e($moiO === 1 ? '?' . http_build_query(['q' => $o['ten']]) . '#ds-mon' : '?' . http_build_query(['pmon' => (int) floor(array_search($o['mon'], array_column($tatCa, 'MaMon')) / 6) + 1]) . '#ds-mon') ?>"
             title="<?= e($o['ten'] . ' – ' . $tenTT[$o['tt']]) ?>"><?= $coO === 'to' ? '<span>' . e($o['ten']) . '</span>' : '' ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="mh-tc-cot mh-tc-tt">
    <h3>Tình trạng</h3>
    <?php foreach ($tenTT as $k => $ten): ?>
      <a class="mh-chip-tt<?= $tt === $k ? ' dang-chon' : '' ?>" href="<?= e($urlTT($k)) ?>"><span class="mh-ov mh-ov-<?= $k ?>"></span><span class="mh-chip-tt-ten"><?= e($ten) ?></span><b><?= $dem[$k] ?></b></a>
    <?php endforeach; ?>
    <p class="nhan-phu"><?= $tt === '' ? 'Bấm một dòng để bảng bên dưới chỉ hiện nhóm môn đó.' : 'Đang lọc theo &ldquo;' . e($tenTT[$tt]) . '&rdquo;. Bấm lại dòng đó để bỏ lọc.' ?></p>
    <p class="nhan-phu">Thiếu câu hỏi: môn đang mở chưa có câu hỏi cho ít nhất một loại kỳ thi.</p>
  </div>
  <div class="mh-tc-cot mh-tc-bs">
    <h3>Nên bổ sung trước</h3>
    <p class="nhan-phu">Môn được chơi nhiều nhưng còn thiếu câu hỏi, xếp theo số trận đấu.</p>
    <?php if (!$canBoSung): ?>
      <p class="nhan-phu">Mọi môn đang mở đều đã có câu hỏi cho từng loại kỳ thi.</p>
    <?php endif; ?>
    <?php foreach ($canBoSung as $r): $nt = (int) $r['so_tran']; ?>
      <div class="mh-bs">
        <div class="mh-bs-dau">
          <a href="<?= e(BASE_URL . '/admin/cauhoi_form.php?mon=' . (int) $r['MaMon']) ?>"><b><?= e($r['TenMon']) ?></b></a>
          <span>chưa có câu <?= e((string) $r['kt_thieu']) ?><?= (int) $r['so_thieu'] > 1 ? ' và ' . ((int) $r['so_thieu'] - 1) . ' loại khác' : '' ?></span>
        </div>
        <div class="mh-bs-thanh"><span class="mh-bs-nen"><span style="width:<?= $tranMax > 0 ? (int) round($nt * 100 / $tranMax) : 0 ?>%"></span></span><small><?= $nt ?> trận</small></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<div class="mh-bd-khung">

  <!-- ===== Bản đồ môn học × loại kỳ thi ===== -->
  <section class="card mh-ds" id="ds-mon" style="scroll-margin-top:16px">
    <form method="get" action="<?= e($sach) ?>#ds-mon" class="mh-loc" role="search">
      <?php if ($qkt !== ''): ?><input type="hidden" name="qkt" value="<?= e($qkt) ?>"><?php endif; ?>
      <?php if ($tt !== ''): ?><input type="hidden" name="tt" value="<?= e($tt) ?>"><?php endif; ?>
      <label class="mh-tim"><?= $svgMon('tim', 18) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm môn học theo tên hoặc mô tả..." class="o-tim" aria-label="Tìm kiếm môn học" autocomplete="off">
        <?php if ($q !== ''): ?><a class="mh-xoa-tim" href="<?= e($urlXoaTim('q', 'pmon', '#ds-mon')) ?>" title="Xóa tìm kiếm" aria-label="Xóa tìm kiếm">&times;</a><?php endif; ?>
      </label>
      <button type="submit" class="btn mh-nut-tim">Tìm</button>
      <?php $conTrong = $dem['thieu'] + $dem['chua']; ?>
      <span class="mh-troi<?= $conTrong === 0 ? ' mh-troi-het' : '' ?>"><?= $conTrong ?> môn còn ô trống</span>
    </form>
    <?php if ($q !== ''): ?><p class="mh-ket-qua">Tìm thấy <b><?= $p->tong() ?></b> môn khớp với &ldquo;<?= e($q) ?>&rdquo;</p><?php endif; ?>

    <?php if (!$dsMon): ?>
      <p class="nhan-phu mh-rong">Không có môn học nào khớp.</p>
    <?php else: ?>
    <div class="mh-cuon">
      <table class="mh-bang mh-bando">
        <thead>
          <tr>
            <th>Môn học</th>
            <?php foreach ($cotKT as $k): ?><th class="giua"><?= e($k['TenLoaiKT']) ?></th><?php endforeach; ?>
            <th class="giua">Dùng chung cả hai</th>
            <th class="giua">Trận đấu</th>
            <th class="giua">Thao tác</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($dsMon as $r):
            $mm    = (int) $r['MaMon'];
            $coThe = (int) $r['so_cau'] === 0 && (int) $r['so_tran'] === 0;   // chưa dùng ở đâu -> xóa được
            $an    = $r['TrangThai'] === 'Đã ẩn';
            $mucO  = static fn (int $n): string => $n >= 80 ? 'mh-o3' : ($n >= 30 ? 'mh-o2' : 'mh-o1'); ?>
          <tr<?= $an ? ' class="mh-da-an"' : '' ?>>
            <td><span class="mh-ten"><span class="mh-chip mh-c<?= $mm % 5 ?>"><?= $svgMon(mh_bieu_tuong($r['TenMon']), 18) ?></span><span class="mh-ten-chu" title="<?= e((string) $r['MoTa']) ?>"><?= e($r['TenMon']) ?></span><?= $an ? '<span class="mh-tt mh-tt-an">Đã ẩn</span>' : '' ?></span></td>
            <?php foreach ($cotKT as $k):
                $n = $soO[$mm][(int) $k['MaLoaiKT']] ?? 0; ?>
              <td class="giua">
                <?php if ($n > 0): ?>
                  <a class="mh-o <?= $mucO($n) ?>" href="<?= e(BASE_URL . '/admin/cauhoi.php?' . http_build_query(['mon' => $mm, 'kt' => (int) $k['MaLoaiKT']])) ?>" title="Xem <?= $n ?> câu hỏi"><?= $n ?></a>
                <?php elseif (!$an): ?>
                  <a class="mh-o mh-o-trong" href="<?= e(BASE_URL . '/admin/cauhoi_form.php?mon=' . $mm) ?>">+ Thêm câu hỏi</a>
                <?php else: ?>
                  <span class="mh-o mh-o0">0</span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
            <td class="giua">
              <?php $nc = $soO[$mm]['chung'] ?? 0; ?>
              <?php if ($nc > 0): ?>
                <a class="mh-o <?= $mucO($nc) ?>" href="<?= e(BASE_URL . '/admin/cauhoi.php?' . http_build_query(['mon' => $mm, 'kt' => 'chung'])) ?>" title="Xem <?= $nc ?> câu hỏi dùng chung"><?= $nc ?></a>
              <?php else: ?>
                <span class="mh-o mh-o0">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="giua"><?= (int) $r['so_tran'] ?></td>
            <td class="giua khong-ngat">
              <span class="mh-thao-tac">
                <button type="button" class="mh-nut mh-nut-sua" data-sua-mon
                        data-id="<?= $mm ?>" data-ten="<?= e($r['TenMon']) ?>" data-mota="<?= e((string) $r['MoTa']) ?>">Sửa</button>
                <form method="post" action="" class="form-dong">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $mm ?>">
                  <?php if ($coThe): ?>
                    <button type="submit" name="hanh_dong" value="xoa_mon" class="mh-nut mh-nut-xoa"
                            data-xac-nhan="Xóa môn &quot;<?= e($r['TenMon']) ?>&quot;? Không thể hoàn tác.">Xóa</button>
                  <?php elseif ($an): ?>
                    <button type="submit" name="hanh_dong" value="hien_mon" class="mh-nut mh-nut-hien" title="Môn đã có câu hỏi hoặc trận đấu nên không xóa được, chỉ hiện lại hoặc ẩn">Hiện</button>
                  <?php else: ?>
                    <button type="submit" name="hanh_dong" value="an_mon" class="mh-nut mh-nut-xoa"
                            title="Môn đã có câu hỏi hoặc trận đấu nên không xóa được, chỉ ẩn (A-15)"
                            data-xac-nhan="Môn này đã có câu hỏi hoặc trận đấu nên không xóa được. Ẩn môn này? Sinh viên sẽ không chọn được để tìm trận nữa.">Ẩn</button>
                  <?php endif; ?>
                </form>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <div class="mh-chan">
      <span class="mh-chan-chu"><?= $p->tong() > 0 ? 'Hiển thị <b>' . $tuDong . '&ndash;' . $denDong . '</b> trên <b>' . $p->tong() . '</b> môn học' : 'Không có môn học nào' ?></span>
      <?= $mhPhanTrang($p, 'pmon', '#ds-mon') ?>
    </div>
    <p class="nhan-phu">Mỗi trang 6 môn. Bấm vào ô để xem câu hỏi của môn và loại kỳ thi đó. Môn chưa có câu hỏi hoặc trận đấu hiện nút Xóa; môn đã có dữ liệu chỉ được Ẩn (rồi Hiện lại).</p>
  </section>

  <!-- ===== Cột bên phải ===== -->
  <aside class="mh-bd-ben">

    <?php if ($tongCho > 0): ?>
    <div class="card mh-ds mh-cho" id="de-xuat" style="scroll-margin-top:80px">
      <div class="mh-ds-dau"><span class="mh-ds-icon mh-icon-cam"><?= $svgMon('cho', 20) ?></span><h3>Chờ duyệt (<?= $tongCho ?>)</h3></div>
      <?php foreach ($dsCho as $r): ?>
        <div class="mh-de-xuat">
          <b><?= e($r['TenMon']) ?></b>
          <?php if ((string) $r['MoTa'] !== ''): ?><span class="nhan-phu"><?= e((string) $r['MoTa']) ?></span><?php endif; ?>
          <span class="nhan-phu">Đề xuất bởi <?= $r['HoTen'] !== null ? e($r['HoTen']) . ' (' . e($r['Email']) . ')' : '-' ?></span>
          <form method="post" action="" class="form-dong">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['MaMon'] ?>">
            <button type="submit" name="hanh_dong" value="duyet_mon" class="btn mh-nut-nho">Duyệt</button>
            <button type="submit" name="hanh_dong" value="tu_choi_mon" class="btn btn-do mh-nut-nho"
                    data-xac-nhan="Từ chối và xóa đề xuất này?">Từ chối</button>
          </form>
        </div>
      <?php endforeach; ?>
      <?= $pCho->links() ?>
    </div>
    <?php endif; ?>

    <!-- Loại kỳ thi: giữ đủ tìm kiếm, phân trang, sửa, xóa như trước -->
    <section class="card mh-ds" id="ds-kt" style="scroll-margin-top:16px">
      <div class="mh-ds-dau"><span class="mh-ds-icon mh-icon-vang"><?= $svgMon('cup', 22) ?></span><h3>Loại kỳ thi</h3></div>
      <form method="get" action="<?= e($sach) ?>#ds-kt" class="mh-loc" role="search">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <label class="mh-tim"><?= $svgMon('tim', 18) ?><input type="search" name="qkt" value="<?= e($qkt) ?>" placeholder="Tìm loại kỳ thi..." class="o-tim" aria-label="Tìm kiếm loại kỳ thi" autocomplete="off">
          <?php if ($qkt !== ''): ?><a class="mh-xoa-tim" href="<?= e($urlXoaTim('qkt', 'pkt', '#ds-kt')) ?>" title="Xóa tìm kiếm" aria-label="Xóa tìm kiếm">&times;</a><?php endif; ?>
        </label>
        <button type="submit" class="btn mh-nut-tim">Tìm</button>
      </form>
      <?php if ($qkt !== ''): ?><p class="mh-ket-qua">Tìm thấy <b><?= $pKT->tong() ?></b> loại kỳ thi khớp với &ldquo;<?= e($qkt) ?>&rdquo;</p><?php endif; ?>
      <?php if (!$dsKT): ?>
        <p class="nhan-phu mh-rong"><?= $qkt !== '' ? 'Không có loại kỳ thi nào khớp.' : 'Chưa có loại kỳ thi nào.' ?></p>
      <?php else: ?>
      <ul class="mh-dong-ds">
        <?php foreach ($dsKT as $k):
            $coThe = (int) $k['so_cau'] === 0 && (int) $k['so_tran'] === 0; ?>
          <li>
            <span class="mh-ten"><span class="mh-ten-chu" title="<?= e((string) $k['MoTa']) ?>"><?= e($k['TenLoaiKT']) ?></span></span>
            <span class="mh-so"><?= (int) $k['so_cau'] ?> câu hỏi</span>
            <span class="mh-thao-tac">
              <button type="button" class="mh-nut mh-nut-sua" data-sua-kt
                      data-id="<?= (int) $k['MaLoaiKT'] ?>" data-ten="<?= e($k['TenLoaiKT']) ?>" data-mota="<?= e((string) $k['MoTa']) ?>">Sửa</button>
              <form method="post" action="" class="form-dong">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $k['MaLoaiKT'] ?>">
                <?php if ($coThe): ?>
                  <button type="submit" name="hanh_dong" value="xoa_kt" class="mh-nut mh-nut-xoa"
                          data-xac-nhan="Xóa loại kỳ thi &quot;<?= e($k['TenLoaiKT']) ?>&quot;? Không thể hoàn tác.">Xóa</button>
                <?php else: ?>
                  <button type="button" class="mh-nut mh-nut-xoa" disabled
                          title="Đã có câu hỏi hoặc trận đấu gắn với loại kỳ thi này nên không xóa được">Xóa</button>
                <?php endif; ?>
              </form>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?= $mhPhanTrang($pKT, 'pkt', '#ds-kt') ?>
      <p class="nhan-phu">Câu hỏi để trống loại kỳ thi dùng được cho cả hai kỳ (A-19).</p>
    </section>


  </aside>
</div>
</div><!-- /.mh-khung -->

<!-- ===== Popup thêm/sửa môn học (JS điền sẵn dữ liệu khi bấm Sửa) ===== -->
<dialog class="mh-hop" id="hopMon" aria-labelledby="hopMonTieuDe">
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="hanh_dong" id="hopMonHd" value="them_mon">
    <input type="hidden" name="id" id="hopMonId" value="0">
    <h3 id="hopMonTieuDe">Thêm môn học</h3>
    <label for="ten_mon">Tên môn (tối đa 150 ký tự)</label>
    <input type="text" id="ten_mon" name="ten_mon" maxlength="150" required>
    <label for="mo_ta">Mô tả (không bắt buộc, tối đa 500 ký tự)</label>
    <textarea id="mo_ta" name="mo_ta" rows="3" maxlength="500"></textarea>
    <p class="mh-nut-form">
      <button type="submit" class="btn" id="hopMonLuu">Thêm môn</button>
      <button type="button" class="btn btn-phu" data-dong-hop>Hủy</button>
    </p>
  </form>
</dialog>

<!-- ===== Popup thêm/sửa loại kỳ thi ===== -->
<dialog class="mh-hop" id="hopKt" aria-labelledby="hopKtTieuDe">
  <form method="post" action="">
    <?= csrf_field() ?>
    <input type="hidden" name="hanh_dong" id="hopKtHd" value="them_kt">
    <input type="hidden" name="id" id="hopKtId" value="0">
    <h3 id="hopKtTieuDe">Thêm loại kỳ thi</h3>
    <label for="ten_kt">Tên loại kỳ thi (tối đa 50 ký tự)</label>
    <input type="text" id="ten_kt" name="ten_kt" maxlength="50" required>
    <label for="mo_ta_kt">Mô tả (không bắt buộc, tối đa 500 ký tự)</label>
    <input type="text" id="mo_ta_kt" name="mo_ta_kt" maxlength="500">
    <p class="mh-nut-form">
      <button type="submit" class="btn" id="hopKtLuu">Thêm loại kỳ thi</button>
      <button type="button" class="btn btn-phu" data-dong-hop>Hủy</button>
    </p>
  </form>
</dialog>
<?php require __DIR__ . '/layout/footer.php'; ?>