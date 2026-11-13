<?php
// Thống kê khảo sát định hướng (UC10, luồng thay thế: A-35, A-36). Đặt tại: elo_quiz/admin/khaosat.php
// Bước 3: truy vấn theo khoảng ngày. Bước 4-6: khung giao diện mới, 3 biểu đồ Chart.js, bảng chi tiết câu hỏi.
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../core/KhaoSatThongKe.php';
Auth::requireAdmin(BASE_URL . '/admin/dangnhap.php');   // A-04

$pdo = Database::pdo();

// ---- Danh sách mã => nhãn (trùng với client/khaosat.php) ----
$mucTieu = [
    'kiem_tra_kien_thuc' => 'Kiểm tra đánh giá kiến thức',
    'on_luyen_nhanh'     => 'Ôn luyện nhanh chóng',
    'ghi_nho_lau'        => 'Ghi nhớ lâu',
    'ap_luc_thoi_gian'   => 'Rèn luyện áp lực thời gian',
    'so_hoa_du_lieu'     => 'Số hóa dữ liệu học tập',
    'xoa_so_thi'         => 'Xóa bỏ tâm lý sợ thi cử',
];
$linhVuc = [
    'toan_hoc'  => 'Toán học',
    'tin_hoc'   => 'Tin học',
    'khoa_hoc'  => 'Khoa học',
    'tieng_anh' => 'Tiếng Anh',
    'lich_su'   => 'Lịch sử',
];
$trinhDo = ['Mới bắt đầu', 'Trung bình', 'Khá', 'Nâng cao'];

// ---- Khoảng ngày lọc: ?tu=YYYY-MM-DD&den=YYYY-MM-DD, mặc định là tháng hiện tại ----
function doc_ngay(?string $s): ?DateTimeImmutable
{
    if ($s === null || $s === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    $loi = DateTimeImmutable::getLastErrors();
    if (!$d || ($loi && ($loi['warning_count'] || $loi['error_count']))) {
        return null;
    }
    return $d;
}
$homNay = new DateTimeImmutable('today');
$tu  = doc_ngay($_GET['tu'] ?? null)  ?? $homNay->modify('first day of this month');
$den = doc_ngay($_GET['den'] ?? null) ?? $homNay->modify('last day of this month');
if ($tu > $den) {                       // người dùng chọn ngược thì đổi chỗ
    [$tu, $den] = [$den, $tu];
}
$soNgay = (int) $tu->diff($den)->days + 1;

// Khoảng liền trước, cùng độ dài, để tính % so với kỳ trước
$truocDen = $tu->modify('-1 day');
$truocTu  = $tu->modify('-' . $soNgay . ' days');

// Đếm theo trạng thái trong [tu, den] (den tính hết ngày: NgayKhaoSat < den + 1 ngày)
function dem_theo_trang_thai(PDO $pdo, DateTimeImmutable $tu, DateTimeImmutable $den): array
{
    $kq = ['Hoàn tất' => 0, 'Bỏ qua' => 0];
    $st = $pdo->prepare(
        "SELECT TrangThaiKhaoSat, COUNT(*) FROM NGUOIDUNG
         WHERE TrangThaiKhaoSat <> 'Chưa làm' AND NgayKhaoSat >= ? AND NgayKhaoSat < ?
         GROUP BY TrangThaiKhaoSat"
    );
    $st->execute([$tu->format('Y-m-d'), $den->modify('+1 day')->format('Y-m-d')]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$tt, $so]) {
        $kq[$tt] = (int) $so;
    }
    return $kq;
}

// ---- 4 thẻ số liệu ----
$hienTai = dem_theo_trang_thai($pdo, $tu, $den);
$hoanTat     = $hienTai['Hoàn tất'];
$chuaHoanTat = $hienTai['Bỏ qua'];            // "Chưa hoàn thành" = Bỏ qua (người chưa làm không có ngày nên không tính)
$tongLuot    = $hoanTat + $chuaHoanTat;
$tyLeHoanTat = $tongLuot > 0 ? round($hoanTat * 100 / $tongLuot, 1) : 0;
$tyLeChua    = $tongLuot > 0 ? round($chuaHoanTat * 100 / $tongLuot, 1) : 0;

$truoc      = dem_theo_trang_thai($pdo, $truocTu, $truocDen);
$tongTruoc  = $truoc['Hoàn tất'] + $truoc['Bỏ qua'];
$soVoiKyTruoc = $tongTruoc > 0 ? round(($tongLuot - $tongTruoc) * 100 / $tongTruoc, 1) : null;   // null = kỳ trước không có dữ liệu

$st = $pdo->prepare(
    "SELECT MAX(NgayKhaoSat) FROM NGUOIDUNG
     WHERE TrangThaiKhaoSat <> 'Chưa làm' AND NgayKhaoSat >= ? AND NgayKhaoSat < ?"
);
$st->execute([$tu->format('Y-m-d'), $den->modify('+1 day')->format('Y-m-d')]);
$gannhat = $st->fetchColumn() ?: null;

// ---- Câu trả lời của SV hoàn tất trong khoảng ngày ----
$st = $pdo->prepare(
    "SELECT MucTieuKhaoSat, LinhVucYeuThich, TrinhDoTuDanhGia FROM NGUOIDUNG
     WHERE TrangThaiKhaoSat = 'Hoàn tất' AND NgayKhaoSat >= ? AND NgayKhaoSat < ?"
);
$st->execute([$tu->format('Y-m-d'), $den->modify('+1 day')->format('Y-m-d')]);
$dong = $st->fetchAll(PDO::FETCH_ASSOC);

// Đếm lượt chọn cho cột lưu nhiều mã phân cách bằng dấu phẩy
function dem_nhieu_ma(array $dong, string $cot, array $danhSach): array
{
    $dem = array_fill_keys(array_keys($danhSach), 0);
    foreach ($dong as $r) {
        foreach (array_unique(array_map('trim', explode(',', (string) $r[$cot]))) as $ma) {
            if (isset($dem[$ma])) {
                $dem[$ma]++;
            }
        }
    }
    return $dem;
}
$luotMucTieu = dem_nhieu_ma($dong, 'MucTieuKhaoSat', $mucTieu);
$luotLinhVuc = dem_nhieu_ma($dong, 'LinhVucYeuThich', $linhVuc);
$luotTrinhDo = array_fill_keys($trinhDo, 0);
foreach ($dong as $r) {
    if (isset($luotTrinhDo[$r['TrinhDoTuDanhGia']])) {
        $luotTrinhDo[$r['TrinhDoTuDanhGia']]++;
    }
}

// % của từng mục trên tổng lượt chọn (dùng cho donut và cột, cộng lại = 100%)
function ty_le_tren_tong(array $dem): array
{
    $tong = array_sum($dem);
    return array_map(static fn ($v) => $tong > 0 ? round($v * 100 / $tong, 1) : 0, $dem);
}
$tyLeMucTieu = ty_le_tren_tong($luotMucTieu);
$tyLeLinhVuc = ty_le_tren_tong($luotLinhVuc);
$tyLeTrinhDo = ty_le_tren_tong($luotTrinhDo);

// ---- Bảng "Chi tiết câu hỏi": đáp án được chọn nhiều nhất, tỷ lệ trên số SV hoàn tất ----
function dap_an_nhieu_nhat(array $dem, array $nhan): array
{
    if (!$dem || max($dem) === 0) {
        return ['dapAn' => '—', 'so' => 0];
    }
    $ma = array_search(max($dem), $dem, true);
    return ['dapAn' => $nhan[$ma] ?? $ma, 'so' => $dem[$ma]];
}
$nhanTrinhDo = array_combine($trinhDo, $trinhDo);
$chiTiet = [
    ['cau' => 'Bạn tham gia QUIZUP ARENA với mục đích gì?']           + dap_an_nhieu_nhat($luotMucTieu, $mucTieu),
    ['cau' => 'Bạn yêu thích lĩnh vực nào nhất?']                      + dap_an_nhieu_nhat($luotLinhVuc, $linhVuc),
    ['cau' => 'Bạn tự đánh giá trình độ hiện tại của mình như thế nào?'] + dap_an_nhieu_nhat($luotTrinhDo, $nhanTrinhDo),
];
foreach ($chiTiet as &$c) {
    $c['tyLe'] = $hoanTat > 0 ? round($c['so'] * 100 / $hoanTat, 1) : 0;
}
unset($c);

// ---- Dữ liệu cho Chart.js (bước 5 sẽ đọc JSON này) ----
$duLieu = [
    'tongLuot'  => $tongLuot,
    'tongChon'  => array_sum($luotMucTieu),   // số ở giữa donut: tổng lượt chọn mục tiêu
    'mucTieu'   => ['nhan' => array_values($mucTieu), 'luot' => array_values($luotMucTieu), 'tyLe' => array_values($tyLeMucTieu)],
    'linhVuc'   => ['nhan' => array_values($linhVuc), 'luot' => array_values($luotLinhVuc), 'tyLe' => array_values($tyLeLinhVuc)],
    'trinhDo'   => ['nhan' => $trinhDo,               'luot' => array_values($luotTrinhDo), 'tyLe' => array_values($tyLeTrinhDo)],
];

// ---- Chi tiết từng sinh viên đã khảo sát trong khoảng ngày (mới nhất trước) ----
$dieuKienSV = "TrangThaiKhaoSat <> 'Chưa làm' AND NgayKhaoSat >= ? AND NgayKhaoSat < ?
       AND MaND NOT IN (SELECT MaND FROM ADMIN WHERE MaND IS NOT NULL)";
$thamSoSV = [$tu->format('Y-m-d'), $den->modify('+1 day')->format('Y-m-d')];
$st = $pdo->prepare("SELECT COUNT(*) FROM NGUOIDUNG WHERE $dieuKienSV");
$st->execute($thamSoSV);
$phanTrang = new Paginator((int) $st->fetchColumn(), 5);   // 5 sinh viên mỗi trang; ?page=N, giữ nguyên tu/den
$st = $pdo->prepare(
    "SELECT MaND, HoTen, TrangThaiKhaoSat, NgayKhaoSat, MucTieuKhaoSat, LinhVucYeuThich, TrinhDoTuDanhGia
     FROM NGUOIDUNG WHERE $dieuKienSV
     ORDER BY NgayKhaoSat DESC, MaND DESC
     LIMIT " . $phanTrang->limit() . ' OFFSET ' . $phanTrang->offset()
);
$st->execute($thamSoSV);
$dsSinhVien = $st->fetchAll(PDO::FETCH_ASSOC);

// Đổi chuỗi mã "a,b,c" thành nhãn "A, B, C"
function ma_ra_nhan(?string $chuoi, array $nhan): string
{
    $kq = [];
    foreach (array_unique(array_map('trim', explode(',', (string) $chuoi))) as $ma) {
        if ($ma !== '' && isset($nhan[$ma])) {
            $kq[] = $nhan[$ma];
        }
    }
    return $kq ? implode(', ', $kq) : '—';
}

// Định dạng số kiểu Việt: 1.248 và 97,4
function so_vn(int|float $v, int $le = 0): string
{
    return number_format($v, $le, ',', '.');
}

$tieuDe  = 'Thống kê khảo sát';
$mucChon = 'khaosat';
// Không bật $giaoDienMoi: dùng cùng khung (nền tối + sidebar) với trang Tổng quan
$cssTrang = ['thongke_xuat', 'khaosat'];   // khaosat sau để ghi đè kích thước hộp thoại
$jsTrang  = ['khaosat', 'khaosat_xuat'];
require __DIR__ . '/layout/header.php';

$tuHienThi  = $tu->format('d/m/Y');
$denHienThi = $den->format('d/m/Y');
?>
<div class="ks">
  <div class="ks-tieude">
    <span class="ks-icon" aria-hidden="true">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><rect x="4" y="11" width="4" height="9" rx="1"/><rect x="10" y="4" width="4" height="16" rx="1"/><rect x="16" y="8" width="4" height="12" rx="1"/></svg>
    </span>
    <div>
      <h2>Thống kê khảo sát</h2>
      <p class="nhan-phu">Tổng hợp và phân tích kết quả khảo sát định hướng người dùng.</p>
    </div>
  </div>

  <!-- 4 thẻ số liệu -->
  <div class="ks-the">
    <div class="ks-so ks-so-tong">
      <div class="ks-so-nhan">Tổng lượt khảo sát</div>
      <div class="ks-so-lon"><?= so_vn($tongLuot) ?></div>
      <?php if ($soVoiKyTruoc === null): ?>
        <div class="ks-so-phu">Kỳ trước chưa có dữ liệu</div>
      <?php else: ?>
        <div class="ks-so-phu <?= $soVoiKyTruoc >= 0 ? 'tang' : 'giam' ?>">
          <?= $soVoiKyTruoc >= 0 ? '↑' : '↓' ?> <?= so_vn(abs($soVoiKyTruoc), 1) ?>% so với kỳ trước
        </div>
      <?php endif; ?>
    </div>
    <div class="ks-so ks-so-xong">
      <div class="ks-so-nhan">Đã hoàn thành</div>
      <div class="ks-so-lon"><?= so_vn($hoanTat) ?></div>
      <div class="ks-tiendo"><span style="width:<?= e((string) $tyLeHoanTat) ?>%"></span></div>
      <div class="ks-so-phu"><?= so_vn($tyLeHoanTat, 1) ?>%</div>
    </div>
    <div class="ks-so ks-so-chua">
      <div class="ks-so-nhan">Chưa hoàn thành</div>
      <div class="ks-so-lon"><?= so_vn($chuaHoanTat) ?></div>
      <div class="ks-tiendo"><span style="width:<?= e((string) $tyLeChua) ?>%"></span></div>
      <div class="ks-so-phu"><?= so_vn($tyLeChua, 1) ?>%</div>
    </div>
    <div class="ks-so ks-so-gan">
      <div class="ks-so-nhan">Thời gian khảo sát gần nhất</div>
      <?php if ($gannhat): $t = strtotime($gannhat); ?>
        <div class="ks-so-lon ks-so-ngay"><?= e(date('d/m/Y', $t)) ?></div>
        <div class="ks-so-phu"><?= e(date('H:i:s', $t)) ?></div>
      <?php else: ?>
        <div class="ks-so-lon ks-so-ngay">Chưa có</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Bộ lọc ngày -->
  <form method="get" class="ks-loc">
    <div class="ks-loc-ngay">
      <input type="date" name="tu" value="<?= e($tu->format('Y-m-d')) ?>" aria-label="Từ ngày">
      <span>–</span>
      <input type="date" name="den" value="<?= e($den->format('Y-m-d')) ?>" aria-label="Đến ngày">
      <button type="submit" class="btn">Áp dụng</button>
    </div>
    <div class="ks-loc-phai">
      <span class="nhan-phu">Đang xem: <?= e($tuHienThi) ?> – <?= e($denHienThi) ?> (<?= $soNgay ?> ngày)</span>
      <button type="button" class="btn" id="nutXuat" title="Xuất thống kê khảo sát (Excel/PDF)">⬇ Xuất</button>
    </div>
  </form>

  <!-- 3 biểu đồ -->
  <div class="ks-bieudo">
    <section class="ks-khung">
      <h3>Mục tiêu tham gia Elo_Quiz</h3>
      <div class="ks-donut">
        <div class="ks-donut-hinh">
          <canvas id="bdMucTieu" aria-label="Biểu đồ tròn mục tiêu tham gia"></canvas>
          <div class="ks-donut-giua"><b id="bdMucTieuTong">0</b><span>lượt chọn</span></div>
        </div>
        <ul class="ks-chuthich" id="bdMucTieuChuThich"></ul>
      </div>
    </section>
    <section class="ks-khung">
      <h3>Lĩnh vực yêu thích</h3>
      <div class="ks-hinh"><canvas id="bdLinhVuc" aria-label="Biểu đồ cột ngang lĩnh vực yêu thích"></canvas></div>
    </section>
    <section class="ks-khung">
      <h3>Trình độ tự đánh giá</h3>
      <div class="ks-hinh"><canvas id="bdTrinhDo" aria-label="Biểu đồ cột trình độ tự đánh giá"></canvas></div>
    </section>
  </div>

  <!-- Bảng chi tiết câu hỏi -->
  <section class="ks-khung ks-bang">
    <h3>Chi tiết câu hỏi khảo sát</h3>
    <table>
      <tr><th>Câu hỏi</th><th>Đáp án được chọn nhiều nhất</th><th>Số lượng</th><th>Tỷ lệ</th></tr>
      <?php foreach ($chiTiet as $c): ?>
        <tr>
          <td><?= e($c['cau']) ?></td>
          <td><?= e($c['dapAn']) ?></td>
          <td><?= so_vn($c['so']) ?></td>
          <td><?= so_vn($c['tyLe'], 1) ?>%</td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="nhan-phu ks-chuthich-duoi">Hiển thị 1–<?= count($chiTiet) ?> trong tổng số <?= count($chiTiet) ?> câu hỏi.
      Tỷ lệ tính trên số sinh viên hoàn tất (<?= so_vn($hoanTat) ?>); câu 1 và câu 2 cho chọn nhiều mục nên tổng có thể lớn hơn 100%.</p>
  </section>

  <!-- Chi tiết từng sinh viên -->
  <section class="ks-khung ks-bang ks-sv" id="ks-sv">
    <h3>Chi tiết từng sinh viên trả lời</h3>
    <table>
      <tr><th>Sinh viên</th><th>Trạng thái</th><th>Thời gian</th><th>Mục tiêu tham gia</th><th>Lĩnh vực yêu thích</th><th>Trình độ</th></tr>
      <?php foreach ($dsSinhVien as $r): $xong = $r['TrangThaiKhaoSat'] === 'Hoàn tất'; ?>
        <tr>
          <td><a href="<?= e(BASE_URL) ?>/admin/nguoidung_chitiet.php?id=<?= (int) $r['MaND'] ?>"><?= e($r['HoTen']) ?></a></td>
          <td><span class="ks-tt <?= $xong ? 'xong' : 'bo' ?>"><?= $xong ? 'Hoàn tất' : 'Chưa hoàn thành' ?></span></td>
          <td class="khong-ngat"><?= e(date('d/m/Y H:i', strtotime($r['NgayKhaoSat']))) ?></td>
          <td><?= $xong ? e(ma_ra_nhan($r['MucTieuKhaoSat'], $mucTieu)) : '—' ?></td>
          <td><?= $xong ? e(ma_ra_nhan($r['LinhVucYeuThich'], $linhVuc)) : '—' ?></td>
          <td><?= $xong ? e($r['TrinhDoTuDanhGia'] ?: '—') : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$dsSinhVien): ?>
        <tr><td colspan="6" class="nhan-phu">Chưa có sinh viên nào làm khảo sát trong khoảng ngày này.</td></tr>
      <?php endif; ?>
    </table>
    <div class="ks-chantrang"><?= preg_replace('/href="([^"]*)"/', 'href="$1#ks-sv"', $phanTrang->links()) ?></div>
    <p class="nhan-phu ks-chuthich-duoi">Mỗi trang 5 sinh viên, mới nhất trước. Sinh viên bỏ qua khảo sát không có câu trả lời.</p>
  </section>
</div>

<!-- Hộp thoại Xuất: cột trái chọn các trường cần xuất, cột phải xem trước toàn bộ -->
<div class="modal-nen" id="hopXuat" hidden>
  <form class="modal xuat-hop" id="formXuat" method="post" action="<?= e(BASE_URL) ?>/admin/khaosat_xuat.php" role="dialog" aria-modal="true" aria-labelledby="xuatTieuDe"
        data-api="<?= e(BASE_URL) ?>/admin/khaosat_du_lieu.php">
    <h3 id="xuatTieuDe">Xuất thống kê khảo sát</h3>
    <?= csrf_field() ?>
    <input type="hidden" name="tu" value="<?= e($tu->format('Y-m-d')) ?>">
    <input type="hidden" name="den" value="<?= e($den->format('Y-m-d')) ?>">
    <p class="xuat-ghichu">Khoảng ngày: <?= e($tuHienThi) ?> – <?= e($denHienThi) ?> (đổi ở bộ lọc ngày bên ngoài rồi mở lại hộp thoại này).</p>
    <div class="xuat-luoi">
      <div class="xuat-chonCot">
        <fieldset>
          <legend>Nội dung cần xuất</legend>
          <?php foreach (KhaoSatThongKe::TRUONG as $ma => $nhan): ?>
            <label class="xuat-chon"><input type="checkbox" name="truong[]" value="<?= e($ma) ?>" checked> <?= e($nhan) ?></label>
            <?php if ($ma === 'sinhVien'): ?>
              <!-- Các cột của danh sách sinh viên: hiện ngay dưới khi tick "Danh sách từng sinh viên", mặc định chọn hết -->
              <div class="xuat-con" id="xuatCotSV">
                <?php foreach (KhaoSatThongKe::COT_SV as $maCot => $ten): ?>
                  <label class="xuat-chon"><input type="checkbox" name="cot[]" value="<?= e($maCot) ?>" data-ten="<?= e($ten) ?>" checked> <?= e($ten) ?></label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
          <label class="xuat-chon"><input type="checkbox" name="kemBieuDo" value="1" checked> Kèm hình biểu đồ</label>
          <button type="button" class="btn btn-phu xuat-tatca" id="xuatChonHet">Chọn / bỏ chọn tất cả</button>
        </fieldset>
        <fieldset>
          <legend>Định dạng</legend>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="excel" checked> Excel (.xlsx)</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="pdf"> PDF</label>
          <label class="xuat-chon"><input type="radio" name="dinhdang" value="ca"> Cả hai (tải liên tiếp 2 file)</label>
        </fieldset>
      </div>
      <div class="xuat-xemCot">
        <div class="xuat-xemDau">Xem trước</div>
        <div class="xuat-xem" id="xuatXem"><p class="xuat-ghichu">Đang tải...</p></div>
      </div>
    </div>
    <p class="xuat-loi" id="xuatLoi" hidden></p>
    <div class="hanh-dong">
      <button type="button" class="btn btn-phu" id="xuatHuy">Hủy</button>
      <button type="submit" class="btn" id="xuatOk">Xuất</button>
    </div>
  </form>
</div>

<script src="<?= e(BASE_URL) ?>/assets/vendor/chart.umd.js"></script>
<script id="du-lieu-khaosat" type="application/json"><?= json_encode($duLieu, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php require __DIR__ . '/layout/footer.php'; ?>