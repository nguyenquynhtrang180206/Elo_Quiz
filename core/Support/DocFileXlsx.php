<?php
// Đọc sheet đầu tiên của file Excel (.xlsx) mà không cần thư viện ngoài (chỉ cần extension zip và dom).
// Dùng cho A-22 (import câu hỏi).
class DocFileXlsx
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const TOI_DA_XML = 10 * 1024 * 1024;

    // Trả về các dòng có dữ liệu: ['so' => số dòng trong Excel, 'o' => [chỉ số cột (từ 0) => chữ]].
    // Vượt quá $toiDaDong dòng có dữ liệu thì báo lỗi (tính cả dòng tiêu đề).
    public static function doc(string $duongDan, int $toiDaDong): array
    {
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            throw new RuntimeException('Máy chủ chưa bật extension zip hoặc dom của PHP (kiểm tra php.ini rồi khởi động lại Apache).');
        }
        $zip = new ZipArchive();
        if ($zip->open($duongDan) !== true) {
            throw new RuntimeException('Không mở được file Excel. Hãy kiểm tra đó là file .xlsx hợp lệ.');
        }

        $tenSheet = 'xl/worksheets/sheet1.xml';
        if ($zip->statName($tenSheet) === false) {
            $tenSheet = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $ten = (string) $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $ten)) {
                    $tenSheet = $ten;
                    break;
                }
            }
        }
        if ($tenSheet === null) {
            $zip->close();
            throw new RuntimeException('File Excel không có trang tính nào.');
        }
        foreach ([$tenSheet, 'xl/sharedStrings.xml'] as $phan) {
            $st = $zip->statName($phan);
            if ($st !== false && $st['size'] > self::TOI_DA_XML) {
                $zip->close();
                throw new RuntimeException('File Excel quá lớn.');
            }
        }

        $chuoi = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && $ss !== '') {
            $xp = new DOMXPath(self::nap($ss));
            $xp->registerNamespace('m', self::NS);
            foreach ($xp->query('//m:si') as $si) {
                $s = '';
                foreach ($xp->query('.//m:t[not(ancestor::m:rPh)]', $si) as $t) {
                    $s .= $t->textContent;
                }
                $chuoi[] = $s;
            }
        }

        $xmlSheet = $zip->getFromName($tenSheet);
        $zip->close();
        $xp = new DOMXPath(self::nap((string) $xmlSheet));
        $xp->registerNamespace('m', self::NS);

        $rows = [];
        $truoc = 0;
        foreach ($xp->query('//m:sheetData/m:row') as $hang) {
            /** @var DOMElement $hang */
            $so = (int) $hang->getAttribute('r');
            if ($so === 0) {
                $so = $truoc + 1;
            }
            $truoc = $so;

            $o = [];
            $tiep = 0;
            foreach ($xp->query('m:c', $hang) as $c) {
                /** @var DOMElement $c */
                $ref = $c->getAttribute('r');
                $cot = $ref !== '' ? self::chiSoCot($ref) : $tiep;
                $tiep = $cot + 1;
                $loai = $c->getAttribute('t');
                $gt = '';
                if ($loai === 'inlineStr') {
                    foreach ($xp->query('.//m:t[not(ancestor::m:rPh)]', $c) as $t) {
                        $gt .= $t->textContent;
                    }
                } else {
                    $vn = $xp->query('m:v', $c)->item(0);
                    $v  = $vn ? $vn->textContent : '';
                    $gt = $loai === 's' ? ($chuoi[(int) $v] ?? '') : $v;
                }
                $gt = trim($gt);
                if ($gt !== '') {
                    $o[$cot] = $gt;
                }
            }
            if ($o) {
                $rows[] = ['so' => $so, 'o' => $o];
                if (count($rows) > $toiDaDong) {
                    throw new RuntimeException('File có quá nhiều dòng (tối đa ' . ($toiDaDong - 1) . ' dòng dữ liệu mỗi lần). Hãy chia nhỏ file.');
                }
            }
        }
        return $rows;
    }

    private static function nap(string $xml): DOMDocument
    {
        $d  = new DOMDocument();
        $cu = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $d->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($cu);
        if (!$ok) {
            throw new RuntimeException('File Excel bị hỏng hoặc không đọc được.');
        }
        return $d;
    }

    // "A" => 0, "B" => 1, "AA" => 26
    private static function chiSoCot(string $ref): int
    {
        if (!preg_match('/^([A-Za-z]+)/', $ref, $m)) {
            return 0;
        }
        $n = 0;
        foreach (str_split(strtoupper($m[1])) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}

// Tương thích ngược: Định danh XlsxReader trỏ tới DocFileXlsx
if (!class_exists('XlsxReader', false)) {
    class_alias('DocFileXlsx', 'XlsxReader');
}
