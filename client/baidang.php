<?php
// Chi tiết bài đăng, bình luận, xóa bài của mình (C-43, C-44). Đặt tại: elo_quiz/client/baidang.php
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/CongDongHelper.php';

$maND = Auth::clientId();   // khách chưa đăng nhập vẫn xem được bài; bình luận/xóa/sửa cần đăng nhập
$pdo  = Database::pdo();
$id   = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$coLoai = CongDongHelper::coCotLoaiBai();

// Bảng LUOTTHICH có thể chưa được tạo (chạy migration_luotthich.sql); chưa có thì ẩn nút thích
$coLike = true;
try {
    $pdo->query('SELECT 1 FROM LUOTTHICH LIMIT 1');
} catch (PDOException $ex) {
    $coLike = false;
}

// C-44: mọi người chỉ thấy bài "Hiển thị"; riêng chủ bài vẫn xem được bài của mình khi đang "Bị ẩn" (chỉ đọc, để xem thông báo và lý do)
$st = $pdo->prepare(
    "SELECT b.MaBaiDang, b.MaND, b.MaMon, b.TieuDe, b.NoiDung, b.NgayDang, b.TrangThai, u.HoTen, m.TenMon,
            " . (CongDongHelper::coCotNguoiAn() ? 'b.NguoiAn' : 'NULL AS NguoiAn') . ",
            " . ($coLoai ? 'b.LoaiBai' : "'" . CongDongHelper::LOAI_MAC_DINH . "' AS LoaiBai") . ",
            TIMESTAMPDIFF(SECOND, b.NgayDang, NOW()) AS GiayTuDang,
            " . ($coLike
                ? '(SELECT COUNT(*) FROM LUOTTHICH l WHERE l.MaBaiDang = b.MaBaiDang) AS SoThich,
                   (SELECT COUNT(*) FROM LUOTTHICH l2 WHERE l2.MaBaiDang = b.MaBaiDang AND l2.MaND = ' . (int) $maND . ') AS DaThich'
                : '0 AS SoThich, 0 AS DaThich') . "
       FROM BAIDANG b
       JOIN NGUOIDUNG u ON u.MaND = b.MaND
       LEFT JOIN MONHOC m ON m.MaMon = b.MaMon
      WHERE b.MaBaiDang = ? AND (b.TrangThai = 'Hiển thị' OR (b.TrangThai = 'Bị ẩn' AND b.MaND = ?))"
);
$st->execute([$id, (int) $maND]);
$bai = $st->fetch();
$baiAn = $bai && $bai['TrangThai'] === 'Bị ẩn';   // chủ bài đang xem bài của mình bị ẩn: chỉ đọc

$loi = [];
$noiDungBL = '';
$loiSua = [];          // lỗi khi sửa bình luận
$dangSua = (int) ($_GET['sua'] ?? 0);   // bình luận đang mở khung sửa
$noiDungSua = null;

// Bình luận hiện trong danh sách: bình luận "Hiển thị" của mọi người, cộng bình luận đang ẩn của chính mình
$dkBL = "c.MaBaiDang = :id AND (c.TrangThai = 'Hiển thị' OR (c.TrangThai = 'Bị ẩn' AND c.MaND = :me))";
$coLikeBL = CongDongHelper::coLikeBL();
$coAnBL   = CongDongHelper::coCotNguoiAnBL();

if ($bai && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || (($_POST['ajax'] ?? '') === '1');

    if ($maND === null) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'yeuCauLogin' => true,
                'redirect' => BASE_URL . '/client/login.php'
            ]);
            exit;
        }
        redirect(BASE_URL . '/client/login.php');
    }
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    // Bài đang bị ẩn: không bình luận, không thả lửa; chủ bài chỉ còn xóa được
    if ($baiAn && $hanhDong !== 'xoa') {
        redirect(BASE_URL . '/client/baidang.php?id=' . $id);
    }

    if ($hanhDong === 'binhluan') {
        $noiDungBL = trim((string) ($_POST['noi_dung'] ?? ''));
        if ($noiDungBL === '') {
            $loi[] = 'Vui lòng nhập nội dung bình luận.';
        } elseif (mb_strlen($noiDungBL) > CongDongHelper::BINH_LUAN_TOI_DA) {
            $loi[] = 'Bình luận tối đa ' . CongDongHelper::BINH_LUAN_TOI_DA . ' ký tự.';
        } else {
            // Lấy ID bình luận cha nếu có (Trả lời bình luận)
            $rep_bl_id = (int) ($_POST['rep_bl_id'] ?? 0);
            $maBinhLuanCha = $rep_bl_id > 0 ? $rep_bl_id : null;

            $ins = $pdo->prepare('INSERT INTO BINHLUAN (MaBaiDang, MaND, NoiDung, MaBinhLuanCha) VALUES (?, ?, ?, ?)');
            $ins->execute([$id, $maND, $noiDungBL, $maBinhLuanCha]);
            $maBLMoi = (int) $pdo->lastInsertId();

            $linkBL = BASE_URL . '/client/baidang.php?id=' . $id . '#bl-' . $maBLMoi;
            $trichDoan = mb_substr(strip_tags($noiDungBL), 0, 120);

            // Tên người gửi bình luận
            $stUser = $pdo->prepare('SELECT HoTen FROM NGUOIDUNG WHERE MaND = ?');
            $stUser->execute([$maND]);
            $tenNguoiGui = (string) ($stUser->fetchColumn() ?: 'Một thành viên');

            // 1. Thông báo cho chủ bài viết
            $chuBaiMaND = (int) $bai['MaND'];
            if ($chuBaiMaND > 0 && $chuBaiMaND !== $maND) {
                $tieuDeTB = $tenNguoiGui . ' đã bình luận bài viết của bạn: "' . mb_substr($bai['TieuDe'], 0, 60) . '"';
                ThongBaoHelper::taoThongBao($pdo, $chuBaiMaND, $maND, $id, $maBLMoi, 'binhluan', $tieuDeTB, $trichDoan, $linkBL);
            }

            // 2. Thông báo cho người được trả lời
            $repMaND = (int) ($_POST['rep_mand'] ?? 0);
            if ($repMaND <= 0 && preg_match('/@([^\s,:;!?\n\r]+)/u', $noiDungBL, $khop)) {
                $tenTag = trim($khop[1]);
                if ($tenTag !== '') {
                    $stND = $pdo->prepare("SELECT MaND FROM NGUOIDUNG WHERE HoTen LIKE ? AND MaND <> ? LIMIT 1");
                    $stND->execute(['%' . $tenTag . '%', $maND]);
                    $repMaND = (int) $stND->fetchColumn();
                }
            }

            if ($repMaND > 0 && $repMaND !== $maND && $repMaND !== $chuBaiMaND) {
                $tieuDeTB = $tenNguoiGui . ' đã trả lời bình luận của bạn trong: "' . mb_substr($bai['TieuDe'], 0, 60) . '"';
                ThongBaoHelper::taoThongBao($pdo, $repMaND, $maND, $id, $maBLMoi, 'rep_bl', $tieuDeTB, $trichDoan, $linkBL);
            }

            redirect($linkBL);
        }
    } elseif ($hanhDong === 'thich') {
        // ---- Thả lửa / dập lửa bài đăng này ----
        $daThich = false;
        if ($coLike) {
            $st = $pdo->prepare('INSERT IGNORE INTO LUOTTHICH (MaBaiDang, MaND) VALUES (?, ?)');
            $st->execute([$id, $maND]);
            if ($st->rowCount() === 0) {
                $st = $pdo->prepare('DELETE FROM LUOTTHICH WHERE MaBaiDang = ? AND MaND = ?');
                $st->execute([$id, $maND]);
                $daThich = false;
            } else {
                $daThich = true;
            }
        }
        $stCount = $pdo->prepare('SELECT COUNT(*) FROM LUOTTHICH WHERE MaBaiDang = ?');
        $stCount->execute([$id]);
        $soThichMoi = (int) $stCount->fetchColumn();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'daThich' => $daThich,
                'soThich' => $soThichMoi,
                'thongBao' => $daThich ? '🔥 Đã tiếp lửa bài viết!' : 'Đã bỏ thả lửa.'
            ]);
            exit;
        }
        redirect(BASE_URL . '/client/baidang.php?id=' . $id);
    } elseif (in_array($hanhDong, ['like_bl', 'an_bl', 'mo_bl', 'xoa_bl', 'sua_bl'], true)) {
        // ---- Bình luận: thích, ẩn, mở lại, xóa, sửa ----
        $maBL     = (int) ($_POST['ma_bl'] ?? 0);
        $veLai = static fn () => redirect(BASE_URL . '/client/baidang.php?id=' . $id . '#bl-' . $maBL);

        if ($hanhDong === 'like_bl') {
            CongDongHelper::thichBL($pdo, $maBL, $maND);
            $stCount = $pdo->prepare('SELECT COUNT(*) FROM LUOTTHICH_BL WHERE MaBinhLuan = ?');
            $stCount->execute([$maBL]);
            $soThichMoi = (int) $stCount->fetchColumn();
            $stDaThich = $pdo->prepare('SELECT COUNT(*) FROM LUOTTHICH_BL WHERE MaBinhLuan = ? AND MaND = ?');
            $stDaThich->execute([$maBL, $maND]);
            $daThichBL = ((int) $stDaThich->fetchColumn()) > 0;

            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'daThich' => $daThichBL,
                    'soThich' => $soThichMoi,
                    'thongBao' => $daThichBL ? '🔥 Đã tiếp lửa bình luận!' : 'Đã bỏ thả lửa.'
                ]);
                exit;
            }
            $veLai();
        } elseif ($hanhDong === 'an_bl') {
            $ok = CongDongHelper::anBL($pdo, $maBL, $maND);
            CongDongHelper::flash($ok ? 'Đã ẩn bình luận. Chỉ bạn còn thấy bình luận này và có thể mở lại.' : 'Không ẩn được bình luận này.', 'flash_bl');
            $veLai();
        } elseif ($hanhDong === 'mo_bl') {
            $ok = CongDongHelper::moLaiBL($pdo, $maBL, $maND);
            CongDongHelper::flash($ok ? 'Đã mở lại bình luận.' : 'Không mở lại được: bình luận này do quản trị viên ẩn hoặc đã thay đổi trạng thái.', 'flash_bl');
            $veLai();
        } elseif ($hanhDong === 'xoa_bl') {
            $ok = CongDongHelper::xoaBL($pdo, $maBL, $maND);
            CongDongHelper::flash($ok ? 'Đã xóa bình luận.' : 'Không xóa được bình luận này.', 'flash_bl');
            redirect(BASE_URL . '/client/baidang.php?id=' . $id . '#binh-luan');
        } else {
            $dangSua    = $maBL;
            $noiDungSua = trim((string) ($_POST['noi_dung'] ?? ''));
            if ($noiDungSua === '') {
                $loiSua[] = 'Vui lòng nhập nội dung bình luận.';
            } elseif (mb_strlen($noiDungSua) > CongDongHelper::BINH_LUAN_TOI_DA) {
                $loiSua[] = 'Bình luận tối đa ' . CongDongHelper::BINH_LUAN_TOI_DA . ' ký tự.';
            } else {
                $kq = CongDongHelper::suaBL($pdo, $maBL, $maND, $noiDungSua);
                if ($kq === 'ok') {
                    CongDongHelper::flash('Đã cập nhật bình luận.', 'flash_bl');
                    $veLai();
                }
                $loiSua[] = $kq === 'het_han'
                    ? 'Đã quá ' . CongDongHelper::SUA_TOI_DA_PHUT . ' phút kể từ lúc viết nên không thể sửa bình luận này.'
                    : 'Không sửa được bình luận này.';
            }
        }
    } elseif ($hanhDong === 'xoa' && (int) $bai['MaND'] === $maND) {
        CongDongHelper::xoaBai($pdo, $id, $maND);
        CongDongHelper::flash('Đã xóa bài đăng.');
        redirect(BASE_URL . '/client/congdong.php');
    } elseif ($hanhDong === 'an' && (int) $bai['MaND'] === $maND) {
        $ok = CongDongHelper::anBai($pdo, $id, $maND);
        CongDongHelper::flash($ok ? 'Đã ẩn bài đăng. Bạn có thể mở lại trong mục "Bài của tôi".' : 'Không ẩn được bài này.');
        redirect(BASE_URL . '/client/congdong.php?tab=cuatoi');
    }
}

$thanhCong   = CongDongHelper::flash();
$thanhCongBL = CongDongHelper::flash(null, 'flash_bl');
$binhLuanTree = [];
$eloMap = [];
$soBLHienThi = 0;

if ($bai) {
    // Số bình luận ghi trên tiêu đề
    $st = $pdo->prepare("SELECT COUNT(*) FROM BINHLUAN WHERE MaBaiDang = ? AND TrangThai = 'Hiển thị'");
    $st->execute([$id]);
    $soBLHienThi = (int) $st->fetchColumn();

    // Lấy TOÀN BỘ bình luận (Bỏ phân trang để xây dựng cây)
    $subLike = $coLikeBL
        ? '(SELECT COUNT(*) FROM LUOTTHICH_BL l WHERE l.MaBinhLuan = c.MaBinhLuan) AS SoThich,
           (SELECT COUNT(*) FROM LUOTTHICH_BL l2 WHERE l2.MaBinhLuan = c.MaBinhLuan AND l2.MaND = :nd) AS DaThich'
        : '0 AS SoThich, 0 AS DaThich';
    $thamBL = [':id' => $id, ':me' => (int) $maND];
    if ($coLikeBL) {
        $thamBL[':nd'] = (int) $maND;
    }
    
    $st = $pdo->prepare(
        "SELECT c.MaBinhLuan, c.MaND, c.NoiDung, c.NgayBinhLuan, c.TrangThai, u.HoTen, 
                c.MaBinhLuanCha,
                " . ($coAnBL ? 'c.NguoiAn' : 'NULL AS NguoiAn') . ",
                TIMESTAMPDIFF(SECOND, c.NgayBinhLuan, NOW()) AS GiayTuLuc,
                $subLike
           FROM BINHLUAN c JOIN NGUOIDUNG u ON u.MaND = c.MaND
          WHERE $dkBL
          ORDER BY c.NgayBinhLuan ASC, c.MaBinhLuan ASC"
    );
    $st->execute($thamBL);
    $allBinhLuan = $st->fetchAll();
    $eloMap = CongDongHelper::layElo($pdo, array_merge([(int) $bai['MaND']], array_column($allBinhLuan, 'MaND')));

    // Xây dựng cấu trúc cây (Tree) cho bình luận
    $binhLuanMap = [];
    foreach ($allBinhLuan as $bl) {
        $bl['children'] = [];
        $binhLuanMap[$bl['MaBinhLuan']] = $bl;
    }
    foreach ($binhLuanMap as $blId => &$bl) {
        if (!empty($bl['MaBinhLuanCha']) && isset($binhLuanMap[$bl['MaBinhLuanCha']])) {
            $binhLuanMap[$bl['MaBinhLuanCha']]['children'][] = &$bl;
        } else {
            $binhLuanTree[] = &$bl;
        }
    }
    unset($bl); // Giải phóng tham chiếu
}

/**
 * Hàm đệ quy hiển thị bình luận phân cấp
 */
function renderBinhLuan($c, $level, $maND, $coAnBL, $coLikeBL, $bai, $eloMap, $coLike, $id, $dangSua, $loiSua, $noiDungSua, &$loiSuaDaHien) {
    $laChu   = $maND !== null && (int) $c['MaND'] === $maND;
    $dangAn  = $c['TrangThai'] === 'Bị ẩn';
    $conSua  = $laChu && !$dangAn && CongDongHelper::conHanSua((int) $c['GiayTuLuc']);
    $moSua   = $conSua && $dangSua === (int) $c['MaBinhLuan'];
    
    // Thiết lập class thụt lề (giới hạn tối đa 3 cấp giống FB để không bị tràn viền)
    $levelClass = '';
    if ($level > 0) {
        $visualLevel = min($level, 3);
        $levelClass = ' bl-reply-level-' . $visualLevel;
    }
    
    $truongBL = '<input type="hidden" name="ma_bl" value="' . (int) $c['MaBinhLuan'] . '">';
    ?>
    <div class="binhluan<?= $levelClass ?>" id="bl-<?= (int) $c['MaBinhLuan'] ?>">
        <div class="meta">
            <b><?= e($c['HoTen']) ?></b>
            <?= CongDongHelper::huyHieuElo($eloMap[(int) $c['MaND']] ?? [], $bai['MaMon'] !== null ? (int) $bai['MaMon'] : null) ?>
            · <?= e(CongDongHelper::ngay($c['NgayBinhLuan'])) ?>
            <?php if ($dangAn): ?><span class="nhan-an">Đang ẩn (chỉ bạn thấy)</span><?php endif; ?>
        </div>

        <?php if ($moSua): ?>
            <form method="post" action="?id=<?= (int) $id ?>" novalidate class="form-sua-bl">
                <?= csrf_field() ?>
                <input type="hidden" name="hanh_dong" value="sua_bl">
                <?= $truongBL ?>
                <?php if ($loiSua): $loiSuaDaHien = true; ?>
                    <div class="loi"><?php foreach ($loiSua as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
                <?php endif; ?>
                <textarea name="noi_dung" maxlength="1000" required><?= e($noiDungSua ?? $c['NoiDung']) ?></textarea>
                <p>
                    <button type="submit" class="btn">Lưu</button>
                    <a class="btn btn-phu" href="?id=<?= (int) $id ?>#bl-<?= (int) $c['MaBinhLuan'] ?>">Hủy</a>
                </p>
            </form>
        <?php else: ?>
            <div><?= nl2br(e($c['NoiDung'])) ?></div>
        <?php endif; ?>

        <?php if (!$moSua): ?>
        <div class="thao-tac-bl">
            <?php if ($dangAn): ?>
                <span class="cd-chi-so cd-tha-lua-static" title="Lượt thả lửa">
                    <?= CongDongHelper::iconLua(16) ?>
                    <span class="flame-count"><?= (int) $c['SoThich'] ?></span>
                </span>
            <?php elseif ($coLikeBL): ?>
                <form method="post" action="?id=<?= (int) $id ?>" class="form-dong cd-like-bl-form" data-bl-id="<?= (int) $c['MaBinhLuan'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <input type="hidden" name="hanh_dong" value="like_bl">
                    <?= $truongBL ?>
                    <button type="submit" class="cd-chi-so cd-tha-lua<?= (int) $c['DaThich'] > 0 ? ' da-tha-lua' : '' ?>"
                            title="<?= (int) $c['DaThich'] > 0 ? 'Dập lửa (Bỏ thả lửa)' : 'Thả lửa bình luận này' ?>">
                        <span class="flame-icon">
                            <?= CongDongHelper::iconLua(16) ?>
                        </span>
                        <span class="flame-count"><?= (int) $c['SoThich'] ?></span>
                    </button>
                </form>
            <?php endif; ?>

            <?php if (!$dangAn && $maND !== null): ?>
                <button type="button" class="cd-chi-so btn-rep-bl"
                        data-ten="<?= e($c['HoTen']) ?>"
                        data-mand="<?= (int) $c['MaND'] ?>"
                        data-bl-id="<?= (int) $c['MaBinhLuan'] ?>"
                        title="Trả lời bình luận của <?= e($c['HoTen']) ?>">
                    <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M14 7.5c0 3-2.5 5.5-5.5 5.5-1 0-2-.3-2.8-.7L2 13.5l1.2-3.1C2.5 9.6 2 8.6 2 7.5 2 4.5 4.5 2 7.5 2S14 4.5 14 7.5z"/></svg>
                    <span>Trả lời</span>
                </button>
            <?php endif; ?>

            <?php if ($laChu): ?>
                <?php if ($conSua): ?>
                    <a class="btn btn-phu btn-nho" href="?id=<?= (int) $id ?>&amp;sua=<?= (int) $c['MaBinhLuan'] ?>#bl-<?= (int) $c['MaBinhLuan'] ?>">
                        <svg viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" style="vertical-align: -1px; margin-right: 2px;" aria-hidden="true"><path d="M11 2.5l2.5 2.5-8 8H3v-2.5l8-8z"/></svg>
                        Sửa
                    </a>
                <?php endif; ?>

                <?php if ($coAnBL): ?>
                    <?php if (!$dangAn): ?>
                        <form method="post" action="?id=<?= (int) $id ?>" class="form-dong" data-xac-nhan="Ẩn bình luận này? Bạn vẫn thấy và có thể mở lại.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="hanh_dong" value="an_bl">
                            <?= $truongBL ?>
                            <button type="submit" class="btn btn-phu btn-nho">
                                <svg viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align: -1px; margin-right: 2px;" aria-hidden="true"><path d="M2 8s2.5-4.5 6-4.5S14 8 14 8s-2.5 4.5-6 4.5S2 8 2 8z"/><circle cx="8" cy="8" r="2"/></svg>
                                Ẩn
                            </button>
                        </form>
                    <?php elseif ($c['NguoiAn'] === 'Chủ bình luận'): ?>
                        <form method="post" action="?id=<?= (int) $id ?>" class="form-dong">
                            <?= csrf_field() ?>
                            <input type="hidden" name="hanh_dong" value="mo_bl">
                            <?= $truongBL ?>
                            <button type="submit" class="btn btn-phu btn-nho">
                                <svg viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align: -1px; margin-right: 2px;" aria-hidden="true"><rect x="3" y="7" width="10" height="7" rx="1.5"/><path d="M5.5 7V4.5a2.5 2.5 0 015 0V7"/></svg>
                                Mở lại
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="meta">Bình luận này do quản trị viên ẩn, bạn không thể mở lại.</span>
                    <?php endif; ?>

                    <form method="post" action="?id=<?= (int) $id ?>" class="form-dong" data-xac-nhan="Xóa bình luận này?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="hanh_dong" value="xoa_bl">
                        <?= $truongBL ?>
                        <button type="submit" class="btn btn-phu btn-nho">
                            <svg viewBox="0 0 16 16" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" style="vertical-align: -1px; margin-right: 2px;" aria-hidden="true"><path d="M3 4.5h10M6 4.5V3a1 1 0 011-1h2a1 1 0 011 1v1.5M4.5 4.5v8.5a1.5 1.5 0 001.5 1.5h4a1.5 1.5 0 001.5-1.5V4.5"/></svg>
                            Xóa
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    // Đệ quy hiển thị bình luận con
    if (!empty($c['children'])) {
        foreach ($c['children'] as $child) {
            renderBinhLuan($child, $level + 1, $maND, $coAnBL, $coLikeBL, $bai, $eloMap, $coLike, $id, $dangSua, $loiSua, $noiDungSua, $loiSuaDaHien);
        }
    }
}

$tieuDe = $bai ? $bai['TieuDe'] : 'Bài đăng';
$cssTrang = 'baidang';
$jsTrang  = 'baidang';
require __DIR__ . '/layout/header.php';
?>
<!-- Reusable Cyber Gradients for Community SVG Icons -->
<svg width="0" height="0" style="position: absolute; width: 0; height: 0; overflow: hidden; pointer-events: none;" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="cdGradGold" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#FFFFFF"/>
      <stop offset="30%" stop-color="#FFF0A3"/>
      <stop offset="70%" stop-color="#F59E0B"/>
      <stop offset="100%" stop-color="#9A5B02"/>
    </linearGradient>
    <linearGradient id="cdGradCyan" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#E0F2FE"/>
      <stop offset="50%" stop-color="#38BDF8"/>
      <stop offset="100%" stop-color="#0284C7"/>
    </linearGradient>
    <linearGradient id="cdGradFlame" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#FEF08A"/>
      <stop offset="45%" stop-color="#F97316"/>
      <stop offset="100%" stop-color="#EF4444"/>
    </linearGradient>
  </defs>
</svg>

<style>
/* CSS cho Bình luận phân cấp (Nested Comments) */
.bl-reply-level-1, .bl-reply-level-2, .bl-reply-level-3 {
    margin-left: 35px;
    padding-left: 12px;
    border-left: 2px solid rgba(56, 189, 248, 0.4); /* Đường kẻ xanh giống FB */
    position: relative;
    margin-top: 12px;
}
.bl-reply-level-1 { margin-left: 40px; }
.bl-reply-level-2 { margin-left: 70px; }
.bl-reply-level-3 { margin-left: 100px; }

/* Responsive cho điện thoại để không bị tràn màn hình */
@media (max-width: 600px) {
    .bl-reply-level-1 { margin-left: 15px; padding-left: 8px; }
    .bl-reply-level-2 { margin-left: 30px; padding-left: 8px; }
    .bl-reply-level-3 { margin-left: 45px; padding-left: 8px; }
}
</style>

<div class="container">
  <p><a href="<?= e(BASE_URL) ?>/client/congdong.php">&laquo; Về bảng tin</a></p>

  <?php if (!$bai): ?>
    <div class="card">Bài đăng không tồn tại hoặc không còn hiển thị.</div>
  <?php else: ?>
    <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>

    <?php if ($baiAn): ?>
      <div class="loi">
        <?php if ($bai['NguoiAn'] === 'Chủ bài'): ?>
          Bạn đang ẩn bài này, chỉ mình bạn thấy. Mở lại trong mục <a href="<?= e(BASE_URL) ?>/client/congdong.php?tab=cuatoi">Bài của tôi</a>.
        <?php else: ?>
          Bài này đã bị quản trị viên ẩn, chỉ mình bạn thấy. Lý do nằm trong thông báo của bạn. Khi quản trị viên hiển thị lại, mọi người sẽ thấy bài.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 class="tieu-de-bai"><?= e($bai['TieuDe']) ?></h2>
      <div class="meta">
        <?= e($bai['HoTen']) ?>
        <?= CongDongHelper::huyHieuElo($eloMap[(int) $bai['MaND']] ?? [], $bai['MaMon'] !== null ? (int) $bai['MaMon'] : null) ?>
        · <?= e(CongDongHelper::ngay($bai['NgayDang'])) ?>
        <?php if ($bai['TenMon']): ?> · <?= e($bai['TenMon']) ?><?php endif; ?>
        <?= CongDongHelper::nhanLoai($bai['LoaiBai']) ?>
      </div>
      <p><?= nl2br(e($bai['NoiDung'])) ?></p>

      <?php if ($coLike && !$baiAn): ?>
        <form method="post" action="?id=<?= (int) $id ?>" class="form-dong cd-like-form" data-bai-id="<?= (int) $id ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <input type="hidden" name="hanh_dong" value="thich">
          <button type="submit" class="cd-chi-so cd-tha-lua<?= (int) $bai['DaThich'] > 0 ? ' da-tha-lua' : '' ?>"
                  title="<?= (int) $bai['DaThich'] > 0 ? 'Dập lửa (Bỏ thả lửa)' : 'Thả lửa bài viết này' ?>">
            <span class="flame-icon">
              <?= CongDongHelper::iconLua(18) ?>
            </span>
            <span class="flame-count"><?= (int) $bai['SoThich'] ?></span>
          </button>
        </form>
      <?php endif; ?>

      <?php if ((int) $bai['MaND'] === $maND): ?>
        <div class="thao-tac-chu-bai">
          <?php if ($baiAn): ?>
            <?php /* bài đang ẩn: không sửa, không ẩn lại */ ?>
          <?php elseif (CongDongHelper::conHanSua((int) $bai['GiayTuDang'])): ?>
            <a class="btn btn-phu" href="<?= e(BASE_URL) ?>/client/suabai.php?id=<?= (int) $id ?>">
              <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M11 2.5l2.5 2.5-8 8H3v-2.5l8-8z"/></svg>
              Sửa bài
            </a>
          <?php else: ?>
            <span class="meta">Đã quá <?= CongDongHelper::SUA_TOI_DA_PHUT ?> phút kể từ lúc đăng nên không thể sửa bài.</span>
          <?php endif; ?>
          <?php if (!$baiAn && CongDongHelper::coCotNguoiAn()): ?>
            <form method="post" action="?id=<?= (int) $id ?>" class="form-dong"
                  data-xac-nhan="Ẩn bài đăng này? Bạn có thể mở lại trong mục Bài của tôi.">
              <?= csrf_field() ?>
              <input type="hidden" name="hanh_dong" value="an">
              <button type="submit" class="btn btn-phu">
                <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M2 8s2.5-4.5 6-4.5S14 8 14 8s-2.5 4.5-6 4.5S2 8 2 8z"/><circle cx="8" cy="8" r="2"/></svg>
                Ẩn bài
              </button>
            </form>
          <?php endif; ?>
          <form method="post" action="?id=<?= (int) $id ?>" class="form-dong"
                data-xac-nhan="Xóa bài đăng này?">
            <?= csrf_field() ?>
            <input type="hidden" name="hanh_dong" value="xoa">
            <button type="submit" class="btn btn-phu">
              <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" style="vertical-align: -1.5px; margin-right: 3px;" aria-hidden="true"><path d="M3 4.5h10M6 4.5V3a1 1 0 011-1h2a1 1 0 011 1v1.5M4.5 4.5v8.5a1.5 1.5 0 001.5 1.5h4a1.5 1.5 0 001.5-1.5V4.5"/></svg>
              Xóa bài
            </button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" id="binh-luan">
      <h3 class="tieu-de-binhluan">Bình luận (<?= (int) $soBLHienThi ?>)</h3>
      <?php if ($thanhCongBL): ?><div class="thanhcong"><?= e($thanhCongBL) ?></div><?php endif; ?>

      <?php if (!$binhLuanTree): ?>
        <p class="meta">Chưa có bình luận nào.</p>
      <?php endif; ?>
      
      <?php 
      $loiSuaDaHien = false;
      // Bắt đầu vẽ cây bình luận đệ quy
      foreach ($binhLuanTree as $rootBl) {
          renderBinhLuan($rootBl, 0, $maND, $coAnBL, $coLikeBL, $bai, $eloMap, $coLike, $id, $dangSua, $loiSua, $noiDungSua, $loiSuaDaHien);
      }
      ?>
      
      <?php if ($loiSua && !$loiSuaDaHien): ?>
        <div class="loi"><?php foreach ($loiSua as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
      <?php endif; ?>

      <div id="form-bl">
        <?php if ($loi): ?>
          <div class="loi"><?php foreach ($loi as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($baiAn): ?>
          <p class="meta">Bài đang bị ẩn nên chưa thể bình luận.</p>
        <?php elseif ($maND === null): ?>
          <p class="meta"><a href="<?= e(BASE_URL) ?>/client/login.php">Đăng nhập</a> để viết bình luận.</p>
        <?php else: ?>
        <form method="post" action="?id=<?= (int) $id ?>" novalidate id="formDangBL">
          <?= csrf_field() ?>
          <input type="hidden" name="hanh_dong" value="binhluan">
          <input type="hidden" name="rep_mand" id="rep_mand" value="">
          <input type="hidden" name="rep_bl_id" id="rep_bl_id" value="">

          <div class="cd-rep-indicator" id="repIndicator" style="display:none;">
            <svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="#38BDF8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 4px;" aria-hidden="true"><path d="M14 7.5c0 3-2.5 5.5-5.5 5.5-1 0-2-.3-2.8-.7L2 13.5l1.2-3.1C2.5 9.6 2 8.6 2 7.5 2 4.5 4.5 2 7.5 2S14 4.5 14 7.5z"/></svg>
            <span>Đang trả lời <strong id="repTenDisplay">@Tên</strong></span>
            <button type="button" class="btn-cancel-rep" id="btnCancelRep" title="Hủy trả lời">&times;</button>
          </div>

          <label for="noi_dung">Viết bình luận</label>
          <textarea id="noi_dung" name="noi_dung" maxlength="1000" placeholder="Chia sẻ lời giải hoặc trả lời thảo luận..." required><?= e($noiDungBL) ?></textarea>
          <p>
            <button type="submit" class="btn btn-dang-bai-gradient">
              <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;" aria-hidden="true"><path d="M14 2L7 9M14 2L9.5 14l-2.5-5-5-2.5L14 2z"/></svg>
              Gửi bình luận
            </button>
          </p>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var textarea = document.getElementById('noi_dung');
  var repMandInput = document.getElementById('rep_mand');
  var repBlIdInput = document.getElementById('rep_bl_id');
  var repIndicator = document.getElementById('repIndicator');
  var repTenDisplay = document.getElementById('repTenDisplay');
  var btnCancelRep = document.getElementById('btnCancelRep');

  // Sử dụng Event Delegation để bắt sự kiện click cho tất cả các nút trả lời (kể cả bình luận con)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.btn-rep-bl');
    if (!btn) return;
    
    e.preventDefault();
    var ten = btn.getAttribute('data-ten') || '';
    var mand = btn.getAttribute('data-mand') || '';
    var blId = btn.getAttribute('data-bl-id') || '';

    if (repMandInput) repMandInput.value = mand;
    if (repBlIdInput) repBlIdInput.value = blId;
    if (repTenDisplay) repTenDisplay.textContent = '@' + ten;
    if (repIndicator) repIndicator.style.display = 'inline-flex';

    if (textarea) {
      var prefix = '@' + ten + ' ';
      // Nếu textarea đang trống hoặc chưa có tag của người này thì thêm vào
      if (!textarea.value.startsWith(prefix)) {
        textarea.value = prefix + textarea.value;
      }
      var formBL = document.getElementById('form-bl');
      if (formBL) {
        formBL.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      textarea.focus();
    }
  });

  // Nút hủy trả lời
  if (btnCancelRep) {
    btnCancelRep.addEventListener('click', function () {
      if (repMandInput) repMandInput.value = '';
      if (repBlIdInput) repBlIdInput.value = '';
      if (repIndicator) repIndicator.style.display = 'none';
      if (textarea && textarea.value.startsWith('@')) {
        textarea.value = textarea.value.replace(/^@[^\s]+\s*/, '');
      }
    });
  }
});
</script>
<?php require __DIR__ . '/layout/footer.php'; ?>