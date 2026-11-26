<?php
// Chi tiết bài đăng, bình luận và kiểm duyệt
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::clientId();   // Khách xem được bài; bình luận, xóa hoặc sửa cần đăng nhập
$laQT = Auth::laQuanTriKiemDuyet();   // Quản trị viên được phép ẩn/hiện bài và bình luận kèm lý do
$pdo  = Database::pdo();
DbSchema::damBaoCot($pdo, 'BINHLUAN', 'AnhDinhKem', 'VARCHAR(255) NULL DEFAULT NULL');
$id   = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$coLoai = CongDongHelper::coCotLoaiBai();

// Kiểm tra bảng LUOTTHICH tồn tại trước khi hiển thị nút thích
$coLike = true;
try {
    $pdo->query('SELECT 1 FROM LUOTTHICH LIMIT 1');
} catch (PDOException $ex) {
    $coLike = false;
}

// Hiển thị bài 'Hiển thị'; chủ bài hoặc quản trị viên vẫn xem được bài 'Bị ẩn'
$st = $pdo->prepare(
    "SELECT b.MaBaiDang, b.MaND, b.MaMon, b.TieuDe, b.NoiDung, b.AnhDinhKem, b.NgayDang, b.TrangThai, u.HoTen, u.AnhDaiDien, m.TenMon,
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
      WHERE b.MaBaiDang = ? AND (b.TrangThai = 'Hiển thị' OR (b.TrangThai = 'Bị ẩn' AND (b.MaND = ? OR ? = 1)))"
);
$st->execute([$id, (int) $maND, (int) $laQT]);
$bai = $st->fetch();
$baiAn = $bai && $bai['TrangThai'] === 'Bị ẩn';   // Chủ bài chỉ được xem và xóa bài đang bị ẩn

$loi = [];
$noiDungBL = '';
$loiSua = [];
$dangSua = (int) ($_GET['sua'] ?? 0);
$noiDungSua = null;

// Hiện bình luận 'Hiển thị' và bình luận 'Bị ẩn' của chính mình hoặc cho quản trị viên
$dkBL = "c.MaBaiDang = :id AND (c.TrangThai = 'Hiển thị' OR (c.TrangThai = 'Bị ẩn' AND (c.MaND = :me OR :qt = 1)))";
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
                'redirect' => BASE_URL . '/client/dangnhap.php'
            ]);
            exit;
        }
        redirect(BASE_URL . '/client/dangnhap.php');
    }
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');

    if ($hanhDong === 'ajax_ket_ban') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            BanBeHelper::xuLyAjax($maND, (string) ($_POST['kieu'] ?? ''), (int) ($_POST['nguoi'] ?? 0)),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    // Bài đang bị ẩn: chỉ cho phép xóa hoặc thao tác quản trị
    if ($baiAn && $hanhDong !== 'xoa' && strpos($hanhDong, 'qt_') !== 0) {
        redirect(BASE_URL . '/client/baidang.php?id=' . $id);
    }

    if ($hanhDong === 'binhluan') {
        $noiDungBL = trim((string) ($_POST['noi_dung'] ?? ''));
        [$loiAnhBL, $duoiAnhBL] = CongDongHelper::kiemTraAnh($_FILES['anh_bl'] ?? null);
        if ($loiAnhBL) {
            $loi = array_merge($loi, array_values($loiAnhBL));
        }

        if ($noiDungBL === '' && $duoiAnhBL === null) {
            $loi[] = 'Vui lòng nhập nội dung bình luận hoặc đính kèm ảnh.';
        } elseif (mb_strlen($noiDungBL) > CongDongHelper::BINH_LUAN_TOI_DA) {
            $loi[] = 'Bình luận tối đa ' . CongDongHelper::BINH_LUAN_TOI_DA . ' ký tự.';
        }

        $anhLuuBL = null;
        if (!$loi && $duoiAnhBL !== null) {
            $anhLuuBL = CongDongHelper::luuAnh($_FILES['anh_bl'], $duoiAnhBL, $cfg['upload_dir']);
            if ($anhLuuBL === null) {
                $loi[] = 'Không lưu được ảnh bình luận lên máy chủ.';
            }
        }

        if (!$loi) {
            $rep_bl_id = (int) ($_POST['rep_bl_id'] ?? 0);
            $maBinhLuanCha = $rep_bl_id > 0 ? $rep_bl_id : null;

            $ins = $pdo->prepare('INSERT INTO BINHLUAN (MaBaiDang, MaND, NoiDung, MaBinhLuanCha, AnhDinhKem) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$id, $maND, $noiDungBL, $maBinhLuanCha, $anhLuuBL]);
            $maBLMoi = (int) $pdo->lastInsertId();

            $linkBL = BASE_URL . '/client/baidang.php?id=' . $id . '#bl-' . $maBLMoi;
            $trichDoan = $noiDungBL !== '' ? mb_substr(strip_tags($noiDungBL), 0, 120) : '[Hình ảnh đính kèm]';

            $stUser = $pdo->prepare('SELECT HoTen FROM NGUOIDUNG WHERE MaND = ?');
            $stUser->execute([$maND]);
            $tenNguoiGui = (string) ($stUser->fetchColumn() ?: 'Một thành viên');

            // Thông báo cho chủ bài viết
            $chuBaiMaND = (int) $bai['MaND'];
            if ($chuBaiMaND > 0 && $chuBaiMaND !== $maND) {
                $tieuDeTB = $tenNguoiGui . ' đã bình luận bài viết của bạn: "' . mb_substr($bai['TieuDe'], 0, 60) . '"';
                ThongBaoHelper::taoThongBao($pdo, $chuBaiMaND, $maND, $id, $maBLMoi, 'binhluan', $tieuDeTB, $trichDoan, $linkBL);
            }

            // Thông báo cho người được trả lời hoặc nhắc tên (@)
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
        // Thả lửa hoặc bỏ thả lửa bài viết
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
        if ($daThich) {
            ThongBaoHelper::thaLuaBai($pdo, $id, $maND);   // Chỉ gửi thông báo khi thả lửa
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
    } elseif (in_array($hanhDong, ['qt_an_bai', 'qt_hien_bai', 'qt_an_bl', 'qt_hien_bl'], true)) {
        // Quản trị viên ẩn/hiện bài hoặc bình luận kèm lý do
        if (!$laQT) {
            redirect(BASE_URL . '/client/baidang.php?id=' . $id);
        }
        $laBL = substr($hanhDong, -3) === '_bl';
        $ma   = $laBL ? (int) ($_POST['ma_bl'] ?? 0) : $id;
        $kq   = CongDongHelper::kiemDuyet($pdo, substr($hanhDong, 3), $ma, (string) ($_POST['ly_do'] ?? ''));
        $an   = strpos($hanhDong, 'qt_an_') === 0;
        $doi  = $laBL ? 'bình luận' : 'bài đăng';
        $msg  = [
            'ok'      => ($an ? 'Đã ẩn ' : 'Đã hiện lại ') . $doi . ' và gửi thông báo kèm lý do cho người đăng.',
            'thieuly' => 'Không thực hiện được: hãy nhập lý do.',
            'khong'   => 'Không thực hiện được: trạng thái đã thay đổi (hoặc người đăng đã tự xóa). Hãy tải lại trang.',
        ][$kq] ?? 'Không thực hiện được.';
        CongDongHelper::flash($msg, $laBL ? 'flash_bl' : 'flash_cd');
        redirect(BASE_URL . '/client/baidang.php?id=' . $id . ($laBL ? '#bl-' . $ma : ''));
    } elseif (in_array($hanhDong, ['like_bl', 'an_bl', 'mo_bl', 'xoa_bl', 'sua_bl'], true)) {
        // Thao tác bình luận: thích, ẩn, mở lại, xóa, sửa
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
            if ($daThichBL) {
                ThongBaoHelper::thaLuaBL($pdo, $maBL, $maND);   // Chỉ gửi thông báo khi thả lửa bình luận
            }

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
            $xoaAnhBL   = !empty($_POST['xoa_anh_bl']);

            $stCurBL = $pdo->prepare('SELECT AnhDinhKem FROM BINHLUAN WHERE MaBinhLuan = ? AND MaND = ?');
            $stCurBL->execute([$maBL, $maND]);
            $anhCuBL = $stCurBL->fetchColumn() ?: null;

            [$loiAnhSua, $duoiAnhSua] = CongDongHelper::kiemTraAnh($_FILES['anh_bl_sua'] ?? null);
            if ($loiAnhSua) {
                $loiSua = array_merge($loiSua, array_values($loiAnhSua));
            }

            $coAnhSauSua = ($duoiAnhSua !== null) || (!$xoaAnhBL && !empty($anhCuBL));
            if ($noiDungSua === '' && !$coAnhSauSua) {
                $loiSua[] = 'Vui lòng nhập nội dung bình luận hoặc giữ lại ảnh đính kèm.';
            } elseif (mb_strlen($noiDungSua) > CongDongHelper::BINH_LUAN_TOI_DA) {
                $loiSua[] = 'Bình luận tối đa ' . CongDongHelper::BINH_LUAN_TOI_DA . ' ký tự.';
            }

            if (!$loiSua) {
                $anhMoiBL = $anhCuBL;
                $capNhatAnh = false;
                if ($duoiAnhSua !== null) {
                    $anhVuaLuu = CongDongHelper::luuAnh($_FILES['anh_bl_sua'], $duoiAnhSua, $cfg['upload_dir']);
                    if ($anhVuaLuu === null) {
                        $loiSua[] = 'Không lưu được ảnh bình luận lên máy chủ.';
                    } else {
                        $anhMoiBL = $anhVuaLuu;
                        $capNhatAnh = true;
                    }
                } elseif ($xoaAnhBL) {
                    $anhMoiBL = null;
                    $capNhatAnh = true;
                }

                if (!$loiSua) {
                    $kq = CongDongHelper::suaBL($pdo, $maBL, $maND, $noiDungSua, $anhMoiBL, $capNhatAnh);
                    if ($kq === 'ok') {
                        if ($capNhatAnh && $anhCuBL && $anhCuBL !== $anhMoiBL) {
                            CongDongHelper::xoaFileAnh((string) $anhCuBL, $cfg['upload_dir']);
                        }
                        CongDongHelper::flash('Đã cập nhật bình luận.', 'flash_bl');
                        $veLai();
                    }
                    $loiSua[] = $kq === 'het_han'
                        ? 'Đã quá ' . CongDongHelper::SUA_TOI_DA_PHUT . ' phút kể từ lúc viết nên không thể sửa bình luận này.'
                        : 'Không sửa được bình luận này.';
                }
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
$mapBanBe = [];
$soBLHienThi = 0;

if ($bai) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM BINHLUAN WHERE MaBaiDang = ? AND TrangThai = 'Hiển thị'");
    $st->execute([$id]);
    $soBLHienThi = (int) $st->fetchColumn();

    // Lấy toàn bộ bình luận để dựng cây phân cấp
    $subLike = $coLikeBL
        ? '(SELECT COUNT(*) FROM LUOTTHICH_BL l WHERE l.MaBinhLuan = c.MaBinhLuan) AS SoThich,
           (SELECT COUNT(*) FROM LUOTTHICH_BL l2 WHERE l2.MaBinhLuan = c.MaBinhLuan AND l2.MaND = :nd) AS DaThich'
        : '0 AS SoThich, 0 AS DaThich';
    $thamBL = [':id' => $id, ':me' => (int) $maND, ':qt' => (int) $laQT];
    if ($coLikeBL) {
        $thamBL[':nd'] = (int) $maND;
    }
    
    $st = $pdo->prepare(
        "SELECT c.MaBinhLuan, c.MaND, c.NoiDung, c.AnhDinhKem, c.NgayBinhLuan, c.TrangThai, u.HoTen, u.AnhDaiDien,
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
    $mapBanBe = $maND ? BanBeHelper::layTrangThaiCacNguoi($pdo, (int) $maND, array_merge([(int) $bai['MaND']], array_column($allBinhLuan, 'MaND'))) : [];

    // Dựng cây bình luận cha - con
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
    unset($bl);
}

// Hiển thị đệ quy cây bình luận phân cấp
function renderBinhLuan($c, $level, $maND, $coAnBL, $coLikeBL, $bai, $eloMap, $coLike, $id, $dangSua, $loiSua, $noiDungSua, &$loiSuaDaHien, array $mapBanBe = []) {
    $laChu   = $maND !== null && (int) $c['MaND'] === $maND;
    $laQT    = Auth::laQuanTriKiemDuyet();
    $dangAn  = $c['TrangThai'] === 'Bị ẩn';
    $conSua  = $laChu && !$dangAn && CongDongHelper::conHanSua((int) $c['GiayTuLuc']);
    $moSua   = $conSua && $dangSua === (int) $c['MaBinhLuan'];
    
    // Giới hạn thụt lề tối đa 3 cấp để tránh tràn giao diện
    $levelClass = '';
    if ($level > 0) {
        $visualLevel = min($level, 3);
        $levelClass = ' bl-reply-level-' . $visualLevel;
    }
    
    $truongBL = '<input type="hidden" name="ma_bl" value="' . (int) $c['MaBinhLuan'] . '">';
    ?>
    <div class="binhluan<?= $levelClass ?>" id="bl-<?= (int) $c['MaBinhLuan'] ?>">
        <div class="meta meta-av">
            <?= CongDongHelper::avatar((string) $c['HoTen'], $c['AnhDaiDien'], 'cd-av cd-av-bl') ?>
            <div class="meta-txt">
                <b><?= e($c['HoTen']) ?></b>
                <?= CongDongHelper::huyHieuElo($eloMap[(int) $c['MaND']] ?? [], $bai['MaMon'] !== null ? (int) $bai['MaMon'] : null) ?>
                <?= $maND ? BanBeHelper::renderNutKetBan($maND, (int) $c['MaND'], $mapBanBe[(int) $c['MaND']]['trangThai'] ?? 'chua_ket_ban', (string) $c['HoTen']) : '' ?>
                · <?= e(CongDongHelper::ngay($c['NgayBinhLuan'])) ?>
                <?php if ($dangAn): ?><span class="nhan-an"><?= $laChu ? 'Đang ẩn (chỉ bạn thấy)' : 'Đang ẩn (bạn thấy vì là quản trị viên)' ?></span><?php endif; ?>
            </div>
        </div>

        <?php if ($moSua): ?>
            <form method="post" action="?id=<?= (int) $id ?>" enctype="multipart/form-data" novalidate class="form-sua-bl">
                <?= csrf_field() ?>
                <input type="hidden" name="hanh_dong" value="sua_bl">
                <?= $truongBL ?>
                <?php if ($loiSua): $loiSuaDaHien = true; ?>
                    <div class="loi"><?php foreach ($loiSua as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div>
                <?php endif; ?>
                <textarea name="noi_dung" maxlength="1000" placeholder="Nhập nội dung bình luận..."><?= e($noiDungSua ?? $c['NoiDung']) ?></textarea>
                <input type="hidden" name="xoa_anh_bl" class="xoa-anh-bl-hidden" value="0">
                <?php $srcAnhBL = !empty($c['AnhDinhKem']) ? BASE_URL . '/' . ltrim((string) $c['AnhDinhKem'], '/') : ''; ?>
                <div class="cd-img-preview-box bl-preview-box"<?= $srcAnhBL !== '' ? '' : ' style="display:none;"' ?>>
                    <div class="cd-img-preview-wrap">
                        <img class="bl-preview-img" src="<?= e($srcAnhBL) ?>" alt="Ảnh đính kèm bình luận">
                        <button type="button" class="cd-img-remove-btn btn-xoa-anh-bl" title="Xóa ảnh đính kèm">&times;</button>
                    </div>
                    <span class="cd-img-preview-name bl-preview-name"><?= $srcAnhBL !== '' ? 'Ảnh hiện tại (bấm × để xóa)' : '' ?></span>
                </div>
                <div class="bl-upload-bar">
                    <label class="btn-chon-anh-bl">
                        <svg viewBox="0 0 20 20" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="3.5" width="15" height="13" rx="2"/><circle cx="7" cy="8" r="1.5"/><path d="M17.5 13l-4-4-6.5 6.5"/></svg>
                        <span>Đổi / Thêm ảnh</span>
                        <input type="file" name="anh_bl_sua" class="input-anh-bl" accept="image/jpeg,image/png,image/webp" data-toi-da="<?= (int) CongDongHelper::gioiHanAnhByte() ?>" data-toi-da-chu="<?= e(CongDongHelper::gioiHanAnhChu()) ?>" hidden>
                    </label>
                </div>
                <p>
                    <button type="submit" class="btn">Lưu</button>
                    <a class="btn btn-phu" data-mem-lien href="?id=<?= (int) $id ?>#bl-<?= (int) $c['MaBinhLuan'] ?>">Hủy</a>
                </p>
            </form>
        <?php else: ?>
            <?php if (trim((string) $c['NoiDung']) !== ''): ?>
                <div><?= nl2br(e($c['NoiDung'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($c['AnhDinhKem'])): ?>
                <?php $srcAnhBL = BASE_URL . '/' . ltrim((string) $c['AnhDinhKem'], '/'); ?>
                <img class="cd-anh-bl-lon cd-zoomable-img" src="<?= e($srcAnhBL) ?>" alt="Ảnh bình luận" title="Bấm để phóng to" loading="lazy">
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$moSua): ?>
        <div class="thao-tac-bl">
            <?php if ($dangAn): ?>
                <span class="cd-chi-so cd-tha-lua-static" title="Lượt thả lửa">
                    <?= CongDongHelper::iconLua(18) ?>
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
                            <?= CongDongHelper::iconLua(18) ?>
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
                    <a class="btn btn-phu btn-nho" data-mem-lien href="?id=<?= (int) $id ?>&amp;sua=<?= (int) $c['MaBinhLuan'] ?>#bl-<?= (int) $c['MaBinhLuan'] ?>">
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
        <?php if ($laQT): ?>
        <div class="qt-khoi">
            <span class="qt-nhan">Quản trị</span>
            <?php if (!$dangAn): ?>
                <?= CongDongHelper::formQuanTri('?id=' . (int) $id, 'an_bl', (int) $c['MaBinhLuan'], 'Ẩn bình luận', true) ?>
            <?php elseif ($c['NguoiAn'] !== 'Chủ bình luận'): ?>
                <?= CongDongHelper::formQuanTri('?id=' . (int) $id, 'hien_bl', (int) $c['MaBinhLuan'], 'Hiện lại bình luận', false) ?>
            <?php else: ?>
                <span class="meta">Chủ bình luận tự ẩn nên quản trị không hiện lại được.</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    if (!empty($c['children'])) {
        foreach ($c['children'] as $child) {
            renderBinhLuan($child, $level + 1, $maND, $coAnBL, $coLikeBL, $bai, $eloMap, $coLike, $id, $dangSua, $loiSua, $noiDungSua, $loiSuaDaHien, $mapBanBe);
        }
    }
}

$tieuDe = $bai ? $bai['TieuDe'] : 'Bài đăng';
$cssTrang = 'baidang';
$jsTrang  = ['capnhat_mem', 'baidang'];
require __DIR__ . '/layout/header.php';
?>
<!-- Gradient dùng chung cho biểu tượng SVG -->
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
/* Phân cấp bình luận tối đa 3 cấp */
.bl-reply-level-1, .bl-reply-level-2, .bl-reply-level-3 {
    margin-left: 35px;
    padding-left: 12px;
    border-left: 2px solid rgba(56, 189, 248, 0.4);
    position: relative;
    margin-top: 12px;
}
.bl-reply-level-1 { margin-left: 40px; }
.bl-reply-level-2 { margin-left: 70px; }
.bl-reply-level-3 { margin-left: 100px; }

@media (max-width: 600px) {
    .bl-reply-level-1 { margin-left: 15px; padding-left: 8px; }
    .bl-reply-level-2 { margin-left: 30px; padding-left: 8px; }
    .bl-reply-level-3 { margin-left: 45px; padding-left: 8px; }
}

/* Ảnh đính kèm bài đăng */
.cd-anh-bai-lon { display: block; max-width: 100%; max-height: 480px; margin: 12px 0; border-radius: 10px; cursor: zoom-in; object-fit: contain; background: rgba(0,0,0,.25); }
.cd-anh-zoom { position: fixed; inset: 0; z-index: 9999; display: none; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.85); cursor: zoom-out; }
.cd-anh-zoom.mo { display: flex; }
.cd-anh-zoom img { max-width: 100%; max-height: 100%; border-radius: 8px; }
</style>

<div class="cd-anh-zoom" id="anhZoom" role="dialog" aria-label="Xem ảnh lớn"><img src="" alt=""></div>
<script>
(function () {
  var z = document.getElementById('anhZoom');
  if (!z) { return; }
  document.addEventListener('click', function (e) {
    var img = e.target.closest ? e.target.closest('#anhBaiDang, .cd-zoomable-img') : null;
    if (img && img.src) {
      z.querySelector('img').src = img.src;
      z.classList.add('mo');
    }
  });
  z.addEventListener('click', function () { z.classList.remove('mo'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { z.classList.remove('mo'); } });
})();
</script>

<div class="container" data-mem-vung="baidang">
  <p><a href="<?= e(BASE_URL) ?>/client/congdong.php">&laquo; Về bảng tin</a></p>

  <?php if (!$bai): ?>
    <div class="card">Bài đăng không tồn tại hoặc không còn hiển thị.</div>
  <?php else: ?>
    <?php if ($thanhCong): ?><div class="thanhcong"><?= e($thanhCong) ?></div><?php endif; ?>

    <?php if ($baiAn): ?>
      <div class="loi">
        <?php if ($laQT && (int) $bai['MaND'] !== (int) $maND): ?>
          Bài này đang bị ẩn, người dùng khác không thấy. Bạn xem được vì là quản trị viên.
        <?php elseif ($bai['NguoiAn'] === 'Chủ bài'): ?>
          Bạn đang ẩn bài này, chỉ mình bạn thấy. Mở lại trong mục <a href="<?= e(BASE_URL) ?>/client/congdong.php?tab=cuatoi">Bài của tôi</a>.
        <?php else: ?>
          Bài này đã bị quản trị viên ẩn, chỉ mình bạn thấy. Lý do nằm trong thông báo của bạn. Khi quản trị viên hiển thị lại, mọi người sẽ thấy bài.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 class="tieu-de-bai"><?= e($bai['TieuDe']) ?></h2>
      <div class="meta meta-av">
        <?= CongDongHelper::avatar((string) $bai['HoTen'], $bai['AnhDaiDien']) ?>
        <div class="meta-txt">
          <b><?= e($bai['HoTen']) ?></b>
          <?= CongDongHelper::huyHieuElo($eloMap[(int) $bai['MaND']] ?? [], $bai['MaMon'] !== null ? (int) $bai['MaMon'] : null) ?>
          <?= $maND ? BanBeHelper::renderNutKetBan($maND, (int) $bai['MaND'], $mapBanBe[(int) $bai['MaND']]['trangThai'] ?? 'chua_ket_ban', (string) $bai['HoTen']) : '' ?>
          · <?= e(CongDongHelper::ngay($bai['NgayDang'])) ?>
          <?php if ($bai['TenMon']): ?> · <?= e($bai['TenMon']) ?><?php endif; ?>
          <?= CongDongHelper::nhanLoai($bai['LoaiBai']) ?>
        </div>
      </div>
      <p><?= nl2br(e($bai['NoiDung'])) ?></p>
      <?php if (!empty($bai['AnhDinhKem'])): ?>
        <?php $srcAnh = BASE_URL . '/' . ltrim((string) $bai['AnhDinhKem'], '/'); ?>
        <img class="cd-anh-bai-lon" id="anhBaiDang" src="<?= e($srcAnh) ?>" alt="Ảnh đính kèm bài viết" title="Bấm để phóng to" loading="lazy">
      <?php endif; ?>

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
            <?php /* Bài đang ẩn: không sửa và không ẩn lại */ ?>
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

      <?php if ($laQT): ?>
        <div class="qt-khoi">
          <span class="qt-nhan">Quản trị</span>
          <?php if ($bai['TrangThai'] === 'Hiển thị'): ?>
            <?= CongDongHelper::formQuanTri('?id=' . (int) $id, 'an_bai', (int) $id, 'Ẩn bài', true) ?>
          <?php elseif ($bai['NguoiAn'] !== 'Chủ bài'): ?>
            <?= CongDongHelper::formQuanTri('?id=' . (int) $id, 'hien_bai', (int) $id, 'Hiện lại bài', false) ?>
          <?php else: ?>
            <span class="meta">Chủ bài tự ẩn nên quản trị không hiện lại được.</span>
          <?php endif; ?>
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
      foreach ($binhLuanTree as $rootBl) {
          renderBinhLuan($rootBl, 0, $maND, $coAnBL, $coLikeBL, $bai, $eloMap, $coLike, $id, $dangSua, $loiSua, $noiDungSua, $loiSuaDaHien, $mapBanBe);
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
          <p class="meta"><a href="<?= e(BASE_URL) ?>/client/dangnhap.php">Đăng nhập</a> để viết bình luận.</p>
        <?php else: ?>
        <form method="post" action="?id=<?= (int) $id ?>" enctype="multipart/form-data" novalidate id="formDangBL">
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
          <textarea id="noi_dung" name="noi_dung" maxlength="1000" placeholder="Chia sẻ lời giải hoặc trả lời thảo luận (có thể đính kèm ảnh)..."><?= e($noiDungBL) ?></textarea>

          <!-- Xem trước ảnh bình luận -->
          <div class="cd-img-preview-box bl-preview-box" id="previewBoxBL" style="display:none;">
            <div class="cd-img-preview-wrap">
              <img class="bl-preview-img" id="previewImgBL" src="" alt="Xem trước ảnh bình luận">
              <button type="button" class="cd-img-remove-btn btn-xoa-anh-bl" id="btnXoaAnhBL" title="Xóa ảnh đã chọn" aria-label="Xóa ảnh đã chọn">&times;</button>
            </div>
            <span class="cd-img-preview-name bl-preview-name" id="previewNameBL"></span>
          </div>

          <div class="bl-form-actions">
            <label class="btn-chon-anh-bl" for="anh_bl">
              <svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="3.5" width="15" height="13" rx="2"/><circle cx="7" cy="8" r="1.5"/><path d="M17.5 13l-4-4-6.5 6.5"/></svg>
              <span>Đính kèm ảnh</span>
              <input type="file" id="anh_bl" name="anh_bl" class="input-anh-bl" accept="image/jpeg,image/png,image/webp" data-toi-da="<?= (int) CongDongHelper::gioiHanAnhByte() ?>" data-toi-da-chu="<?= e(CongDongHelper::gioiHanAnhChu()) ?>" hidden>
            </label>

            <button type="submit" class="btn btn-dang-bai-gradient">
              <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;" aria-hidden="true"><path d="M14 2L7 9M14 2L9.5 14l-2.5-5-5-2.5L14 2z"/></svg>
              Gửi bình luận
            </button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/layout/footer.php'; ?>