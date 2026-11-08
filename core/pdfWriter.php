<?php
// Ghi file PDF khổ A4, không cần thư viện ngoài. Chữ tiếng Việt dùng font TrueType nhúng thẳng
// vào file (assets/fonts/DejaVuSans*.ttf); ảnh biểu đồ là JPEG nên giữ nguyên màu như trên màn hình.
// Mặc định A4 dọc. Gọi khoTrang(true) để các trang từ đó trở đi là A4 ngang, khoTrang(false) để quay lại dọc;
// mỗi trang giữ đúng khổ của nhóm nội dung đó (một file có thể có cả trang ngang và trang dọc).
// Nội dung chảy theo một luồng: hết trang thì tự sang trang mới, không cắt cột.
//
//   $pdf = new PdfWriter(__DIR__ . '/../assets/fonts');
//   $pdf->tieuDe('Báo cáo'); $pdf->doan('Nội dung...'); $pdf->anh($jpeg);
//   $pdf->bang(['Cột 1', 'Cột 2'], [['a', 'b']], [2, 1]);
//   $pdf->tai('baocao.pdf');

// Đọc một font TrueType: bảng cmap (mã Unicode -> glyph) và độ rộng ký tự.
class PdfFontTtf
{
    public string $du;                // toàn bộ nội dung file .ttf
    public int $donVi = 1000;         // unitsPerEm
    public array $hop = [0, 0, 0, 0]; // FontBBox (đơn vị font)
    public int $len = 0;
    public int $xuong = 0;
    private array $bang = [];         // tên bảng => [offset, length]
    private int $soHM = 0;
    private int $cmapOff = 0;
    private int $soDoan = 0;
    private array $maGlyph = [];      // cache: mã Unicode => glyph
    public array $dung = [];          // glyph đã dùng => mã Unicode (cho ToUnicode)

    public function __construct(string $duongDan)
    {
        $d = @file_get_contents($duongDan);
        if ($d === false || strlen($d) < 12) {
            throw new RuntimeException('Không đọc được font: ' . $duongDan);
        }
        $this->du = $d;
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + 16 * $i;
            $this->bang[substr($d, $o, 4)] = [$this->u32($o + 8), $this->u32($o + 12)];
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $t) {
            if (!isset($this->bang[$t])) {
                throw new RuntimeException('Font thiếu bảng ' . $t);
            }
        }
        $h = $this->bang['head'][0];
        $this->donVi = max(1, $this->u16($h + 18));
        $this->hop   = [$this->i16($h + 36), $this->i16($h + 38), $this->i16($h + 40), $this->i16($h + 42)];
        $a = $this->bang['hhea'][0];
        $this->len   = $this->i16($a + 4);
        $this->xuong = $this->i16($a + 6);
        $this->soHM  = max(1, $this->u16($a + 34));

        // Tìm bảng con cmap định dạng 4 (Unicode BMP, bao gồm toàn bộ chữ tiếng Việt)
        $c = $this->bang['cmap'][0];
        $soBang = $this->u16($c + 2);
        for ($i = 0; $i < $soBang; $i++) {
            $pf = $this->u16($c + 4 + 8 * $i);
            $en = $this->u16($c + 6 + 8 * $i);
            $of = $c + $this->u32($c + 8 + 8 * $i);
            if (($pf === 3 && $en === 1 || $pf === 0) && $this->u16($of) === 4) {
                $this->cmapOff = $of;
                $this->soDoan  = intdiv($this->u16($of + 6), 2);
                if ($pf === 3) { break; }
            }
        }
        if ($this->cmapOff === 0) {
            throw new RuntimeException('Font không có bảng cmap Unicode định dạng 4');
        }
    }

    private function u16(int $o): int { return (ord($this->du[$o]) << 8) | ord($this->du[$o + 1]); }
    private function i16(int $o): int { $v = $this->u16($o); return $v >= 32768 ? $v - 65536 : $v; }
    private function u32(int $o): int { return ($this->u16($o) << 16) | $this->u16($o + 2); }

    // Mã Unicode -> glyph (0 nếu font không có ký tự đó)
    public function glyph(int $ma): int
    {
        if (isset($this->maGlyph[$ma])) { return $this->maGlyph[$ma]; }
        $g = 0;
        if ($ma <= 0xFFFF) {
            $o = $this->cmapOff;
            $n = $this->soDoan;
            $cuoi = $o + 14; $dau = $o + 16 + 2 * $n; $delta = $o + 16 + 4 * $n; $rg = $o + 16 + 6 * $n;
            for ($i = 0; $i < $n; $i++) {
                if ($this->u16($cuoi + 2 * $i) >= $ma) {
                    $s = $this->u16($dau + 2 * $i);
                    if ($s <= $ma) {
                        $d  = $this->u16($delta + 2 * $i);
                        $ro = $this->u16($rg + 2 * $i);
                        if ($ro === 0) {
                            $g = ($ma + $d) & 0xFFFF;
                        } else {
                            $g = $this->u16($rg + 2 * $i + $ro + 2 * ($ma - $s));
                            if ($g !== 0) { $g = ($g + $d) & 0xFFFF; }
                        }
                    }
                    break;
                }
            }
        }
        $this->maGlyph[$ma] = $g;
        if ($g !== 0) { $this->dung[$g] = $ma; }
        return $g;
    }

    // Độ rộng glyph theo thang 1000 đơn vị
    public function rong(int $g): int
    {
        $i  = min($g, $this->soHM - 1);
        $aw = $this->u16($this->bang['hmtx'][0] + 4 * $i);
        return (int) round($aw * 1000 / $this->donVi);
    }
}

class PdfWriter
{
    public const RONG = 595.28;
    public const CAO  = 841.89;
    private const LE  = 42.0;

    private array $font = [];     // 'r' / 'b' => PdfFontTtf
    private array $doi = [];      // các đối tượng PDF, chỉ số = mã đối tượng (từ 1)
    private array $trang = [];    // từng trang: ['nd' => content stream, 'w' => rộng, 'h' => cao]
    private string $nd = '';
    private float $w = self::RONG;   // khổ của trang đang viết
    private float $h = self::CAO;
    private float $y;
    private array $anh = [];      // tên ảnh => mã đối tượng
    private int $resId;

    public function __construct(string $thuMucFont)
    {
        $this->font['r'] = new PdfFontTtf(rtrim($thuMucFont, '/\\') . '/DejaVuSans.ttf');
        $this->font['b'] = new PdfFontTtf(rtrim($thuMucFont, '/\\') . '/DejaVuSans-Bold.ttf');
        $this->doi[1] = null;   // Catalog
        $this->doi[2] = null;   // Pages
        $this->resId  = $this->giu();
        $this->y = $this->h - self::LE;
    }

    private function giu(): int { $this->doi[] = null; return array_key_last($this->doi); }
    private function them(string $thanVan): int { $id = $this->giu(); $this->doi[$id] = $thanVan; return $id; }
    private function luong(string $tuDien, string $du, bool $nen = true): int
    {
        if ($nen) { $du = gzcompress($du, 6); $tuDien .= ' /Filter /FlateDecode'; }
        return $this->them('<< ' . $tuDien . ' /Length ' . strlen($du) . " >>\nstream\n" . $du . "\nendstream");
    }

    private static function chuan(string $s): string
    {
        $s = preg_replace('/[\r\n\t]+/u', ' ', $s) ?? '';
        $s = preg_replace('/[\x{10000}-\x{10FFFF}\x{FE0F}\x{200D}]/u', '', $s) ?? '';   // bỏ emoji (font không có)
        if (class_exists('Normalizer')) {
            $n = Normalizer::normalize($s, Normalizer::FORM_C);
            if ($n !== false) { $s = $n; }
        }
        return $s;
    }

    private function doRong(string $s, float $co, bool $dam): float
    {
        $f = $this->font[$dam ? 'b' : 'r'];
        $t = 0;
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $t += $f->rong($f->glyph(mb_ord($ch, 'UTF-8')));
        }
        return $t * $co / 1000;
    }

    // Ngắt dòng theo từ cho vừa bề rộng $rong
    private function ngat(string $s, float $co, bool $dam, float $rong): array
    {
        $dong = [];
        $cur = '';
        foreach (preg_split('/ +/u', trim(self::chuan($s))) ?: [] as $tu) {
            $thu = $cur === '' ? $tu : $cur . ' ' . $tu;
            if ($this->doRong($thu, $co, $dam) <= $rong) { $cur = $thu; continue; }
            if ($cur !== '') { $dong[] = $cur; $cur = ''; }
            // từ dài hơn cả dòng: cắt theo ký tự
            while ($this->doRong($tu, $co, $dam) > $rong && mb_strlen($tu, 'UTF-8') > 1) {
                $k = mb_strlen($tu, 'UTF-8');
                while ($k > 1 && $this->doRong(mb_substr($tu, 0, $k, 'UTF-8'), $co, $dam) > $rong) { $k--; }
                $dong[] = mb_substr($tu, 0, $k, 'UTF-8');
                $tu = mb_substr($tu, $k, null, 'UTF-8');
            }
            $cur = $tu;
        }
        if ($cur !== '' || !$dong) { $dong[] = $cur; }
        return $dong;
    }

    private static function mau(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    private function ghiChu(float $x, float $y, string $s, float $co, bool $dam, string $mau): void
    {
        $f = $this->font[$dam ? 'b' : 'r'];
        $hex = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $hex .= sprintf('%04X', $f->glyph(mb_ord($ch, 'UTF-8')));
        }
        $this->nd .= sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td <%s> Tj ET\n", $dam ? 'F2' : 'F1', $co, self::mau($mau), $x, $y, $hex);
    }

    private function hinhChuNhat(float $x, float $y, float $w, float $h, string $mau): void
    {
        $this->nd .= sprintf("%s rg %.2F %.2F %.2F %.2F re f\n", self::mau($mau), $x, $y, $w, $h);
    }

    private function duong(float $x1, float $y1, float $x2, float $y2, string $mau): void
    {
        $this->nd .= sprintf("%s RG 0.5 w %.2F %.2F m %.2F %.2F l S\n", self::mau($mau), $x1, $y1, $x2, $y2);
    }

    private function canCho(float $h): void
    {
        if ($this->y - $h < self::LE + 14) { $this->trangMoi(); }
    }

    // Sang trang mới, giữ nguyên khổ hiện tại
    public function trangMoi(): void
    {
        if ($this->nd !== '') { $this->trang[] = ['nd' => $this->nd, 'w' => $this->w, 'h' => $this->h]; }
        $this->nd = '';
        $this->y = $this->h - self::LE;
    }

    // Bắt đầu một trang mới với khổ ngang (true) hoặc dọc (false); các trang sau giữ khổ này cho đến lần gọi tiếp theo
    public function khoTrang(bool $ngang): void
    {
        $this->trangMoi();
        $this->w = $ngang ? self::CAO : self::RONG;
        $this->h = $ngang ? self::RONG : self::CAO;
        $this->y = $this->h - self::LE;
    }

    public function khoang(float $pt): void { $this->y -= $pt; }

    public function tieuDe(string $s, float $co = 18, string $mau = '#14213d'): void
    {
        foreach ($this->ngat($s, $co, true, $this->w - 2 * self::LE) as $d) {
            $this->canCho($co * 1.4);
            $this->y -= $co * 1.2;
            $this->ghiChu(self::LE, $this->y, $d, $co, true, $mau);
            $this->y -= $co * 0.2;
        }
    }

    public function doan(string $s, float $co = 10, bool $dam = false, string $mau = '#24324f'): void
    {
        foreach ($this->ngat($s, $co, $dam, $this->w - 2 * self::LE) as $d) {
            $this->canCho($co * 1.5);
            $this->y -= $co * 1.3;
            $this->ghiChu(self::LE, $this->y, $d, $co, $dam, $mau);
            $this->y -= $co * 0.15;
        }
    }

    // Nhúng một ảnh JPEG vào file, trả về [tên tài nguyên, rộng px, cao px] (null nếu không phải JPEG)
    private function nhungAnh(string $jpeg): ?array
    {
        $kt = @getimagesizefromstring($jpeg);
        if (!$kt || $kt[2] !== IMAGETYPE_JPEG) { return null; }
        [$wPx, $hPx] = $kt;
        $kenh = $kt['channels'] ?? 3;
        $cs = $kenh === 1 ? '/DeviceGray' : ($kenh === 4 ? '/DeviceCMYK' : '/DeviceRGB');
        $ten = 'Im' . (count($this->anh) + 1);
        $this->anh[$ten] = $this->luong('/Type /XObject /Subtype /Image /Width ' . $wPx . ' /Height ' . $hPx
            . ' /ColorSpace ' . $cs . ' /BitsPerComponent 8 /Filter /DCTDecode' . ($kenh === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : ''), $jpeg, false);
        return [$ten, $wPx, $hPx];
    }

    // Ảnh JPEG (đã có nền trắng, giữ nguyên màu) rộng hết khổ chữ, tự co nếu quá cao
    public function anh(string $jpeg): void
    {
        $a = $this->nhungAnh($jpeg);
        if (!$a) { return; }
        [$ten, $wPx, $hPx] = $a;
        $w = $this->w - 2 * self::LE;
        $h = $w * $hPx / $wPx;
        $toiDa = $this->h - 2 * self::LE - 20;
        if ($h > $toiDa) { $w *= $toiDa / $h; $h = $toiDa; }
        $this->canCho($h + 6);
        $this->y -= $h;
        $this->nd .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, self::LE, $this->y, $ten);
        $this->y -= 8;
    }

    // Nhiều ảnh JPEG đặt NGANG CẠNH NHAU trên một hàng, cùng chiều cao (giữ tỷ lệ từng ảnh), tổng vừa khít bề ngang trang;
    // chiều cao không vượt $caoToiDa. Dùng cho trang tóm tắt khổ ngang (biểu đồ cột/đường + biểu đồ tròn).
    public function anhHang(array $dsJpeg, float $caoToiDa = 230, float $khe = 14): void
    {
        $cac = [];
        foreach ($dsJpeg as $j) {
            $a = $this->nhungAnh((string) $j);
            if ($a) { $cac[] = $a; }
        }
        if (!$cac) { return; }
        $tiLe = 0.0;
        foreach ($cac as $a) { $tiLe += $a[1] / $a[2]; }
        $kho = $this->w - 2 * self::LE - $khe * (count($cac) - 1);
        $h = min($caoToiDa, $kho / $tiLe);
        $this->canCho($h + 6);
        $this->y -= $h;
        $x = self::LE;
        foreach ($cac as $a) {
            $w = $h * $a[1] / $a[2];
            $this->nd .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $this->y, $a[0]);
            $x += $w + $khe;
        }
        $this->y -= 8;
    }

    // Hàng ô số liệu chính. $cac: [[nhãn, giá trị, so với kỳ trước], ...] chia đều bề ngang trang
    public function oSoLieu(array $cac, float $khe = 10): void
    {
        $n = count($cac);
        if ($n === 0) { return; }
        $cao = 54.0;
        $rong = ($this->w - 2 * self::LE - $khe * ($n - 1)) / $n;
        $this->canCho($cao + 6);
        $top = $this->y;
        foreach (array_values($cac) as $i => $c) {
            $x = self::LE + $i * ($rong + $khe);
            $this->hinhChuNhat($x, $top - $cao, $rong, $cao, '#f3f6fd');
            $this->ghiChu($x + 10, $top - 15, (string) $c[0], 8.5, false, '#5b6b8c');
            $this->ghiChu($x + 10, $top - 36, (string) $c[1], 17, true, '#14213d');
            if (($c[2] ?? '') !== '') {
                $this->ghiChu($x + 10, $top - 49, 'So với kỳ trước: ' . $c[2], 8, false, '#5b6b8c');
            }
        }
        $this->y = $top - $cao - 8;
    }

    // Chú thích biểu đồ tròn: mỗi mục một chấm màu + "tên: số trận (tỷ lệ)", xếp liên tiếp trên hàng, hết chỗ thì xuống dòng.
    // $ds: [['ten' => ..., 'so' => ..., 'phantram' => ..., 'mau' => '#rrggbb'], ...]
    public function chuThich(array $ds): void
    {
        $co = 8.5; $cao = $co * 1.6; $khe = 16.0;
        $toiDa = $this->w - 2 * self::LE;
        $this->canCho($cao + 4);
        $this->y -= $cao;
        $x = 0.0;
        foreach ($ds as $m) {
            $chu = self::chuan($m['ten'] . ': ' . $m['so'] . ' trận (' . $m['phantram'] . '%)');
            $rong = 12 + $this->doRong($chu, $co, false);
            if ($x > 0 && $x + $rong > $toiDa) {
                $x = 0.0;
                $this->canCho($cao + 2);
                $this->y -= $cao;
            }
            $this->hinhChuNhat(self::LE + $x, $this->y + 0.5, 7, 7, $m['mau']);
            $this->ghiChu(self::LE + $x + 12, $this->y, $chu, $co, false, '#24324f');
            $x += $rong + $khe;
        }
        $this->y -= 8;
    }

    // Bảng nằm gọn trong chiều ngang trang: chữ tự xuống dòng trong ô, không cắt cột.
    // $tyLe: tỷ lệ rộng các cột. $mauHang[$i]: màu (#rrggbb) vẽ chấm ở đầu ô đầu của dòng $i (dùng cho chú thích biểu đồ).
    // $mauO[$i][$j]: màu nền (#rrggbb) tô cả ô ở dòng $i, cột $j (dùng cho bản đồ phủ). $canGiua: danh sách chỉ số cột canh giữa chữ.
    public function bang(array $tieuDe, array $dong, array $tyLe = [], array $mauHang = [], array $mauO = [], array $canGiua = []): void
    {
        $soCot = count($tieuDe);
        $tyLe = $tyLe ?: array_fill(0, $soCot, 1);
        $tong = array_sum($tyLe);
        $kho  = $this->w - 2 * self::LE;
        $rong = array_map(static fn ($t) => $kho * $t / $tong, $tyLe);
        $co = 9.0; $dem = 4.0; $caoDong = $co * 1.3;

        $veDong = function (array $o, bool $dau, ?string $chamMau, array $nenO = []) use ($co, $dem, $caoDong, $rong, $kho, $canGiua): void {
            $cac = []; $nhieu = 1;
            foreach ($o as $i => $gt) {
                $le = ($i === 0 && $chamMau !== null) ? 14.0 : 0.0;
                $cac[$i] = $this->ngat((string) $gt, $co, $dau, $rong[$i] - 2 * $dem - $le);
                $nhieu = max($nhieu, count($cac[$i]));
            }
            $h = $nhieu * $caoDong + 2 * $dem;
            $this->canCho($h);
            $top = $this->y;
            if ($dau) { $this->hinhChuNhat(self::LE, $top - $h, $kho, $h, '#2f6bff'); }
            $x = self::LE;
            foreach ($cac as $i => $lines) {
                $le = ($i === 0 && $chamMau !== null) ? 14.0 : 0.0;
                if (isset($nenO[$i])) { $this->hinhChuNhat($x, $top - $h, $rong[$i], $h, $nenO[$i]); }   // tô nền cả ô
                if ($le > 0) { $this->hinhChuNhat($x + $dem, $top - $dem - $caoDong * 0.85, 8, 8, $chamMau); }
                foreach ($lines as $k => $d) {
                    $lech = 0.0;
                    if (in_array($i, $canGiua, true)) { $lech = max(0.0, ($rong[$i] - 2 * $dem - $le - $this->doRong($d, $co, $dau)) / 2); }
                    $this->ghiChu($x + $dem + $le + $lech, $top - $dem - $co - $k * $caoDong, $d, $co, $dau, $dau ? '#ffffff' : '#24324f');
                }
                $x += $rong[$i];
            }
            $this->duong(self::LE, $top - $h, self::LE + $kho, $top - $h, '#d5dbea');
            $this->y = $top - $h;
        };

        $veDong($tieuDe, true, null);
        foreach ($dong as $i => $d) {
            if ($this->y - $caoDong * 3 < self::LE + 14) {   // sang trang mới thì lặp lại dòng tiêu đề
                $this->trangMoi();
                $veDong($tieuDe, true, null);
            }
            $veDong($d, false, $mauHang[$i] ?? null, $mauO[$i] ?? []);
        }
        $this->y -= 8;
    }

    // Biểu đồ cột đơn giản vẽ bằng hình chữ nhật (không cần ảnh). $nhan: nhãn dưới mỗi cột, $so: giá trị, $mau: màu #rrggbb từng cột.
    public function bieuDoCot(array $nhan, array $so, array $mau, float $cao = 130): void
    {
        $n = count($so);
        if ($n === 0) { return; }
        $kho   = $this->w - 2 * self::LE;
        $duoi  = 16.0;   // chỗ cho nhãn dưới cột
        $tren  = 12.0;   // chỗ cho số trên đỉnh cột
        $this->canCho($cao + $duoi + $tren + 8);
        $top   = $this->y;
        $day   = $top - $tren - $cao;            // đường đáy của các cột
        $max   = max(1, (int) max($so));
        $o     = $kho / $n;
        $rongC = min(34.0, $o * 0.62);
        $this->duong(self::LE, $day, self::LE + $kho, $day, '#c9d2ee');
        foreach (array_values($so) as $i => $v) {
            $x = self::LE + $i * $o + ($o - $rongC) / 2;
            $h = $v > 0 ? max(2.0, $cao * $v / $max) : 0.0;
            if ($h > 0) {
                $this->hinhChuNhat($x, $day, $rongC, $h, $mau[$i] ?? '#2f6bff');
                $chu = (string) $v;
                $this->ghiChu($x + ($rongC - $this->doRong($chu, 8, true)) / 2, $day + $h + 3, $chu, 8, true, '#24324f');
            }
            $nh = (string) ($nhan[$i] ?? '');
            $this->ghiChu(self::LE + $i * $o + ($o - $this->doRong($nh, 7.5, false)) / 2, $day - 11, $nh, 7.5, false, '#5b6b8c');
        }
        $this->y = $day - $duoi - 4;
    }

    private function nhungFont(PdfFontTtf $f, string $ten): int
    {
        $file = $this->luong('/Length1 ' . strlen($f->du), $f->du);
        $mo = $this->them(sprintf('<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 /Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $ten, $f->hop[0] * 1000 / $f->donVi, $f->hop[1] * 1000 / $f->donVi, $f->hop[2] * 1000 / $f->donVi, $f->hop[3] * 1000 / $f->donVi,
            $f->len * 1000 / $f->donVi, $f->xuong * 1000 / $f->donVi, $f->len * 1000 / $f->donVi, $file));
        $w = '';
        $g = array_keys($f->dung);
        sort($g);
        foreach ($g as $id) { $w .= $id . ' [' . $f->rong($id) . '] '; }
        $cid = $this->them('<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $ten . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> '
            . '/FontDescriptor ' . $mo . ' 0 R /CIDToGIDMap /Identity /DW 600 /W [' . $w . '] >>');
        $cm = "/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n/CMapName /Adobe-Identity-UCS def /CMapType 2 def\n1 begincodespacerange <0000> <FFFF> endcodespacerange\n";
        foreach (array_chunk($f->dung, 100, true) as $nhom) {
            $cm .= count($nhom) . " beginbfchar\n";
            foreach ($nhom as $gid => $uni) { $cm .= sprintf("<%04X> <%04X>\n", $gid, $uni); }
            $cm .= "endbfchar\n";
        }
        $cm .= "endcmap CMapName currentdict /CMap defineresource pop end end";
        $tu = $this->luong('', $cm);
        return $this->them('<< /Type /Font /Subtype /Type0 /BaseFont /' . $ten . ' /Encoding /Identity-H /DescendantFonts [' . $cid . ' 0 R] /ToUnicode ' . $tu . ' 0 R >>');
    }

    // Hoàn tất file và trả về nội dung PDF
    public function noiDung(): string
    {
        $this->trangMoi();
        if (!$this->trang) { $this->trang[] = ['nd' => '', 'w' => self::RONG, 'h' => self::CAO]; }
        $tong = count($this->trang);

        // Số trang ở chân mỗi trang (căn giữa theo bề ngang của chính trang đó)
        foreach ($this->trang as $i => $p) {
            $this->nd = $p['nd'];
            $s = 'Trang ' . ($i + 1) . '/' . $tong;
            $this->ghiChu(($p['w'] - $this->doRong($s, 8, false)) / 2, self::LE - 14, $s, 8, false, '#7a88a8');
            $this->trang[$i]['nd'] = $this->nd;
        }
        $this->nd = '';

        $f1 = $this->nhungFont($this->font['r'], 'DejaVuSans');
        $f2 = $this->nhungFont($this->font['b'], 'DejaVuSans-Bold');
        $xo = '';
        foreach ($this->anh as $ten => $id) { $xo .= '/' . $ten . ' ' . $id . ' 0 R '; }
        $this->doi[$this->resId] = '<< /Font << /F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R >> /XObject << ' . $xo . '>> /ProcSet [/PDF /Text /ImageC] >>';

        $kids = '';
        foreach ($this->trang as $tr) {
            $c = $this->luong('', $tr['nd']);
            $p = $this->them(sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %d 0 R /Contents %d 0 R >>', $tr['w'], $tr['h'], $this->resId, $c));
            $kids .= $p . ' 0 R ';
        }
        $this->doi[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $this->doi[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . $tong . ' >>';

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        ksort($this->doi);
        foreach ($this->doi as $id => $body) {
            $off[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($out);
        $n = count($this->doi) + 1;
        $out .= "xref\n0 $n\n0000000000 65535 f \n";
        foreach ($off as $o) { $out .= sprintf("%010d 00000 n \n", $o); }
        $out .= "trailer\n<< /Size $n /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }

    public function tai(string $tenFile): never
    {
        $pdf = $this->noiDung();
        $tenFile = preg_replace('/[^A-Za-z0-9._-]/', '_', $tenFile);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $tenFile . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store');
        echo $pdf;
        exit;
    }
}