<?php
// Bạn bè: tìm sinh viên, gửi / chấp nhận / từ chối / hủy lời mời, hủy kết bạn (C-41). Đặt tại: elo_quiz/client/banbe.php
require_once __DIR__ . '/../core/bootstrap.php';

$maND = Auth::requireClient(BASE_URL . '/client/dangnhap.php');
if (Auth::laAdminSinhVien()) {   // tài khoản quản trị không thi đấu, không có lịch sử trận và bạn bè
    redirect(BASE_URL . '/client/hoso.php');
}
$pdo  = Database::pdo();

$q    = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$trang = (int) ($_GET['page'] ?? 1);

// ---- Xử lý thao tác (POST), xong thì quay lại đúng trang tìm kiếm ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hanhDong = (string) ($_POST['hanh_dong'] ?? '');
    $nguoi    = (int) ($_POST['nguoi'] ?? 0);
    $qGui     = mb_substr(trim((string) ($_POST['q'] ?? '')), 0, 100);
    $trangGui = max(1, (int) ($_POST['page'] ?? 1));

    if ($hanhDong === 'gui') {
        $kq = BanBeHelper::guiLoiMoi($maND, $nguoi);
        BanBeHelper::flash($kq === BanBeHelper::OK ? 'ok' : 'loi', BanBeHelper::THONG_BAO[$kq]);
    } elseif ($hanhDong === 'huy') {
        $ok = BanBeHelper::huyLoiMoi($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã hủy lời mời.' : 'Lời mời không còn hiệu lực.');
    } elseif ($hanhDong === 'chapnhan') {
        $ok = BanBeHelper::chapNhan($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã chấp nhận lời mời.' : 'Lời mời không còn hiệu lực.');
    } elseif ($hanhDong === 'tuchoi') {
        $ok = BanBeHelper::tuChoi($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã từ chối lời mời.' : 'Lời mời không còn hiệu lực.');
    } elseif ($hanhDong === 'huyketban') {
        $ok = BanBeHelper::huyKetBan($maND, $nguoi);
        BanBeHelper::flash($ok ? 'ok' : 'loi', $ok ? 'Đã hủy kết bạn.' : 'Hai bạn không còn là bạn bè.');
    }

    $url = BASE_URL . '/client/banbe.php';
    if ($qGui !== '') {
        $url .= '?' . http_build_query(['q' => $qGui, 'page' => $trangGui]);
    }
    redirect($url);
}

$thongBao = BanBeHelper::flash();

// Nút bấm nhỏ gửi một thao tác (kèm CSRF, giữ nguyên từ khóa tìm kiếm và trang).
// $xacNhan khác rỗng thì hiện modal hỏi lại trước khi gửi (dùng cho thao tác khó hoàn tác như hủy kết bạn).
function nutBanBe(string $hanhDong, int $nguoi, string $nhan, string $q, int $trang, string $lop = 'btn', string $xacNhan = ''): string
{
    return '<form method="post" action="" class="form-dong"' . ($xacNhan !== '' ? ' data-xacnhan="' . e($xacNhan) . '"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="hanh_dong" value="' . e($hanhDong) . '">'
        . '<input type="hidden" name="nguoi" value="' . $nguoi . '">'
        . '<input type="hidden" name="q" value="' . e($q) . '">'
        . '<input type="hidden" name="page" value="' . $trang . '">'
        . '<button type="submit" class="' . e($lop) . '">' . e($nhan) . '</button></form> ';
}

// ---- Tìm sinh viên theo họ tên (không tìm theo email để khỏi lộ email nào đã đăng ký) ----
$ketQua = [];
$p = null;
if ($q !== '') {
    $mau = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $st = $pdo->prepare("SELECT COUNT(*) FROM NGUOIDUNG WHERE MaND <> ? AND TrangThai = 'Hoạt động' AND HoTen LIKE ?
                          AND MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)");
    $st->execute([$maND, $mau]);
    $p = new Paginator((int) $st->fetchColumn(), 10);

    // Quan hệ giữa mình và từng người tìm được: một dòng BANBE duy nhất cho mỗi cặp
    $st = $pdo->prepare(
        "SELECT u.MaND, u.HoTen, u.AnhDaiDien, b.MaNguoiGui, b.TrangThai AS TrangThaiBan
           FROM NGUOIDUNG u
           LEFT JOIN BANBE b ON b.CapNho = LEAST(?, u.MaND) AND b.CapLon = GREATEST(?, u.MaND)
          WHERE u.MaND <> ? AND u.TrangThai = 'Hoạt động' AND u.HoTen LIKE ?
            AND u.MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)
          ORDER BY u.HoTen
          LIMIT " . $p->limit() . ' OFFSET ' . $p->offset()
    );
    $st->execute([$maND, $maND, $maND, $mau]);
    $ketQua = $st->fetchAll();
}
$trangDangXem = $p ? $p->trang() : 1;

// ---- Lời mời đã nhận, đã gửi, danh sách bạn bè (chỉ hiện tài khoản còn hoạt động) ----
$st = $pdo->prepare(
    "SELECT u.MaND, u.HoTen, u.AnhDaiDien, b.NgayGui
       FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = b.MaNguoiGui
      WHERE b.MaNguoiNhan = ? AND b.TrangThai = 'Chờ' AND u.TrangThai = 'Hoạt động'
      ORDER BY b.NgayGui DESC"
);
$st->execute([$maND]);
$daNhan = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT u.MaND, u.HoTen, u.AnhDaiDien, b.NgayGui
       FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = b.MaNguoiNhan
      WHERE b.MaNguoiGui = ? AND b.TrangThai = 'Chờ' AND u.TrangThai = 'Hoạt động'
      ORDER BY b.NgayGui DESC"
);
$st->execute([$maND]);
$daGui = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT u.MaND, u.HoTen, u.AnhDaiDien,
            (SELECT MAX(e.DiemElo) FROM ELOMONHOC e WHERE e.MaND = u.MaND) AS EloCao
       FROM BANBE b JOIN NGUOIDUNG u ON u.MaND = IF(b.MaNguoiGui = ?, b.MaNguoiNhan, b.MaNguoiGui)
      WHERE (b.MaNguoiGui = ? OR b.MaNguoiNhan = ?) AND b.TrangThai = 'Đã chấp nhận' AND u.TrangThai = 'Hoạt động'
      ORDER BY u.HoTen"
);
$st->execute([$maND, $maND, $maND]);
$banBe = $st->fetchAll();


// Ảnh đại diện (chưa có ảnh thì hiện chữ cái đầu tên)
function bbAva(array $r): string
{
    if (!empty($r['AnhDaiDien'])) {
        return '<img class="bb-ava" src="' . e(BASE_URL . '/' . ltrim((string) $r['AnhDaiDien'], '/')) . '" alt="">';
    }
    return '<span class="bb-ava bb-ava-chu" aria-hidden="true">'
        . e(mb_strtoupper(mb_substr((string) $r['HoTen'], 0, 1))) . '</span>';
}

// Khối "ảnh + tên + dòng phụ" dùng chung cho mọi danh sách
function bbNguoi(array $r, string $phu = ''): string
{
    if ($phu === '' && isset($r['EloCao'])) {
        $phu = '<span class="bb-elo" title="Elo cao nhất ở một môn"><svg viewBox="0 0 20 20" width="13" height="13" aria-hidden="true"><path d="M2.5 15.5l-1.2-8.5 5 4.2L10 3.5l3.7 7.7 5-4.2-1.2 8.5H2.5z" fill="currentColor"/></svg>'
             . (int) $r['EloCao'] . ' Elo</span>';
    }
    return '<div class="bb-nguoi">' . bbAva($r) . '<div class="bb-ten"><b>' . e($r['HoTen']) . '</b>'
        . ($phu !== '' ? '<small>' . $phu . '</small>' : '') . '</div></div>';
}

function bbNgay(?string $t): string
{
    return $t ? 'Gửi ngày ' . date('d/m/Y', strtotime($t)) : '';
}

$soBan = count($banBe);
$soNhan = count($daNhan);
$soGui = count($daGui);

$tieuDe = 'Bạn bè';
$cssTrang = 'banbe';
require __DIR__ . '/layout/header.php';
?>
<div class="container bb-trang">

  <!-- ===== Banner đầu trang + số liệu nhanh ===== -->
  <header class="bb-hero">
    <div class="bb-hero-chu">
      <span class="bb-tag"><i aria-hidden="true"></i> KẾT NỐI ĐỒNG ĐỘI</span>
      <h2>Bạn bè</h2>
      <p>Tìm đối thủ quen mặt, thách đấu 1 vs 1 và cùng nhau leo bảng xếp hạng.</p>
    </div>
    <div class="bb-so">
      <a class="bb-so-o" href="#danhsach"><b><?= $soBan ?></b><span>Bạn bè</span></a>
      <a class="bb-so-o<?= $soNhan ? ' co-moi' : '' ?>" href="#loimoi"><b><?= $soNhan ?></b><span>Lời mời nhận</span></a>
      <a class="bb-so-o" href="#loimoigui"><b><?= $soGui ?></b><span>Đã gửi</span></a>
    </div>
  </header>

  <?php if ($thongBao): ?>
    <div class="<?= $thongBao['loai'] === 'ok' ? 'thanhcong' : 'loi' ?> bb-tb"><?= e($thongBao['nd']) ?></div>
  <?php endif; ?>

  <div class="bb-luoi<?= $soNhan ? ' co-loi-moi' : '' ?>">

    <!-- ===== Cột chính: tìm kiếm + danh sách bạn bè ===== -->
    <div class="bb-chinh">

      <section class="bb-hop" id="timban">
        <h3 class="bb-tieude">Tìm sinh viên</h3>
        <form method="get" action="" class="bb-tim">
          <svg class="bb-tim-ico" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="9" r="5.5"/><path d="M13.2 13.2L17 17"/></svg>
          <input type="text" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="Nhập họ tên cần tìm..." aria-label="Họ tên sinh viên cần tìm" autocomplete="off">
          <button type="submit" class="bb-nut bb-nut-chinh">Tìm</button>
        </form>

        <?php if ($q !== ''): ?>
          <div class="bb-ketqua">
            <?php if (!$ketQua): ?>
              <p class="bb-rong bb-rong-nho">Không tìm thấy sinh viên nào khớp “<?= e($q) ?>”.</p>
            <?php endif; ?>
            <?php foreach ($ketQua as $r):
                $tt  = $r['TrangThaiBan'];
                $gui = (int) ($r['MaNguoiGui'] ?? 0);
                $id  = (int) $r['MaND'];
            ?>
              <div class="bb-dong">
                <?= bbNguoi($r) ?>
                <div class="bb-nut-nhom">
                  <?php if ($tt === 'Đã chấp nhận'): ?>
                    <span class="bb-nhan bb-nhan-ok">✓ Đã là bạn bè</span>
                  <?php elseif ($tt === 'Chờ' && $gui === $maND): ?>
                    <span class="bb-nhan">Đã gửi lời mời</span>
                    <?= nutBanBe('huy', $id, 'Hủy lời mời', $q, $trangDangXem, 'bb-nut bb-nut-phu') ?>
                  <?php elseif ($tt === 'Chờ'): ?>
                    <span class="bb-nhan bb-nhan-tim">Đã gửi lời mời cho bạn</span>
                    <?= nutBanBe('chapnhan', $id, 'Chấp nhận', $q, $trangDangXem, 'bb-nut bb-nut-chinh') ?>
                    <?= nutBanBe('tuchoi', $id, 'Từ chối', $q, $trangDangXem, 'bb-nut bb-nut-phu') ?>
                  <?php else: /* chưa có dòng, hoặc đã "Từ chối" thì được gửi lại */ ?>
                    <?= nutBanBe('gui', $id, '+ Kết bạn', $q, $trangDangXem, 'bb-nut bb-nut-chinh') ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
            <?= $p ? $p->links() : '' ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="bb-hop" id="danhsach">
        <h3 class="bb-tieude">Bạn bè của tôi <span class="bb-dem"><?= $soBan ?></span></h3>
        <?php if (!$banBe): ?>
          <div class="bb-rong">
            <svg viewBox="0 0 48 48" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="18" cy="17" r="6"/><path d="M6 38c1.3-7 6-10 12-10s10.7 3 12 10"/><circle cx="33" cy="19" r="4.5"/><path d="M34 29c5 .4 8 3 9 8"/></svg>
            <p>Bạn chưa có bạn bè nào.<br>Hãy tìm tên một người bạn ở khung bên trên và gửi lời mời.</p>
          </div>
        <?php else: ?>
          <div class="bb-luoi-the">
            <?php foreach ($banBe as $r): ?>
              <article class="bb-the">
                <?= bbAva($r) ?>
                <b class="bb-the-ten"><?= e($r['HoTen']) ?></b>
                <?php if (isset($r['EloCao'])): ?>
                  <span class="bb-elo" title="Elo cao nhất ở một môn"><svg viewBox="0 0 20 20" width="13" height="13" aria-hidden="true"><path d="M2.5 15.5l-1.2-8.5 5 4.2L10 3.5l3.7 7.7 5-4.2-1.2 8.5H2.5z" fill="currentColor"/></svg><?= (int) $r['EloCao'] ?> Elo</span>
                <?php else: ?>
                  <span class="bb-elo bb-elo-moi">Chưa có Elo</span>
                <?php endif; ?>
                <div class="bb-the-nut">
                  <a href="<?= e(BASE_URL) ?>/client/timtran.php?che_do=pin&amp;moi_ban=<?= (int) $r['MaND'] ?>" class="bb-nut bb-nut-xanh" title="Thách đấu" aria-label="Thách đấu <?= e($r['HoTen']) ?>">⚔️</a>
                  <?= nutBanBe('huyketban', (int) $r['MaND'], 'Hủy kết bạn', $q, $trangDangXem, 'bb-nut bb-nut-nguy',
                               'Hủy kết bạn với ' . $r['HoTen'] . '? Hai bạn sẽ không còn thấy nhau trong bảng xếp hạng bạn bè.') ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <p class="bb-lienket"><a href="<?= e(BASE_URL) ?>/client/bxh.php?pv=banbe&amp;mon=0">Xem bảng xếp hạng bạn bè →</a></p>
        <?php endif; ?>
      </section>
    </div>

    <!-- ===== Cột phụ: lời mời ===== -->
    <aside class="bb-phu">
      <section class="bb-hop<?= $soNhan ? ' bb-hop-moi' : '' ?>" id="loimoi">
        <h3 class="bb-tieude">Lời mời đã nhận <span class="bb-dem"><?= $soNhan ?></span></h3>
        <?php if (!$daNhan): ?>
          <p class="bb-rong bb-rong-nho">Không có lời mời nào đang chờ.</p>
        <?php endif; ?>
        <?php foreach ($daNhan as $r): ?>
          <div class="bb-loimoi">
            <?= bbNguoi($r, e(bbNgay($r['NgayGui'] ?? null))) ?>
            <div class="bb-nut-nhom">
              <?= nutBanBe('chapnhan', (int) $r['MaND'], 'Chấp nhận', $q, $trangDangXem, 'bb-nut bb-nut-chinh') ?>
              <?= nutBanBe('tuchoi', (int) $r['MaND'], 'Từ chối', $q, $trangDangXem, 'bb-nut bb-nut-phu') ?>
            </div>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="bb-hop" id="loimoigui">
        <h3 class="bb-tieude">Lời mời đã gửi <span class="bb-dem"><?= $soGui ?></span></h3>
        <?php if (!$daGui): ?>
          <p class="bb-rong bb-rong-nho">Bạn chưa gửi lời mời nào đang chờ.</p>
        <?php endif; ?>
        <?php foreach ($daGui as $r): ?>
          <div class="bb-loimoi">
            <?= bbNguoi($r, e(bbNgay($r['NgayGui'] ?? null))) ?>
            <div class="bb-nut-nhom">
              <?= nutBanBe('huy', (int) $r['MaND'], 'Hủy lời mời', $q, $trangDangXem, 'bb-nut bb-nut-phu') ?>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
    </aside>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>