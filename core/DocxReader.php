<?php
// Đọc chữ trong file Word (.docx) mà không cần thư viện ngoài (chỉ cần extension zip và dom của PHP,
// XAMPP bật sẵn). Dùng cho A-43 (tách câu hỏi từ file Word). Đặt tại: elo_quiz/core/DocxReader.php
class DocxReader
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const TOI_DA_XML = 20 * 1024 * 1024;   // chặn file nén bung quá lớn

    // Trả về danh sách dòng chữ: mỗi phần tử ['so' => số thứ tự đoạn văn (tính từ 1, kể cả đoạn trống), 'text' => chữ].
    // Một đoạn có ngắt dòng (Shift+Enter) được tách thành nhiều dòng cùng số đoạn. Dòng trống bị bỏ.
    public static function doc(string $duongDan): array
    {
        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            throw new RuntimeException('Máy chủ chưa bật extension zip hoặc dom của PHP (kiểm tra php.ini rồi khởi động lại Apache).');
        }
        $zip = new ZipArchive();
        if ($zip->open($duongDan) !== true) {
            throw new RuntimeException('Không mở được file Word. Hãy kiểm tra đó là file .docx hợp lệ.');
        }
        $st = $zip->statName('word/document.xml');
        if ($st === false) {
            $zip->close();
            throw new RuntimeException('File không phải .docx hợp lệ (thiếu nội dung văn bản).');
        }
        if ($st['size'] > self::TOI_DA_XML) {
            $zip->close();
            throw new RuntimeException('Nội dung file Word quá lớn.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            throw new RuntimeException('Không đọc được nội dung file Word.');
        }

        $d = new DOMDocument();
        $cu = libxml_use_internal_errors(true);
        $ok = $d->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($cu);
        if (!$ok) {
            throw new RuntimeException('File Word bị hỏng hoặc không đọc được.');
        }

        $dong = [];
        $so   = 0;
        foreach ($d->getElementsByTagNameNS(self::W, 'p') as $p) {   // theo thứ tự xuất hiện, gồm cả đoạn trong bảng
            $so++;
            $chu = str_replace("\xC2\xA0", ' ', self::chu($p));
            foreach (explode("\n", $chu) as $manh) {
                $manh = trim((string) preg_replace('/[ \t]+/u', ' ', $manh));
                if ($manh !== '') {
                    $dong[] = ['so' => $so, 'text' => $manh];
                }
            }
        }
        return $dong;
    }

    private static function chu(DOMNode $n): string
    {
        $s = '';
        foreach ($n->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE || $c->namespaceURI !== self::W) {
                continue;
            }
            switch ($c->localName) {
                case 't':
                    $s .= $c->textContent;
                    break;
                case 'tab':
                    $s .= ' ';
                    break;
                case 'br':
                case 'cr':
                    $s .= "\n";
                    break;
                case 'pPr':
                case 'rPr':
                case 'p':      // đoạn lồng (hộp văn bản) được duyệt riêng ở vòng ngoài
                    break;
                default:       // r, hyperlink, ins, smartTag, sdt, sdtContent...
                    $s .= self::chu($c);
            }
        }
        return $s;
    }
}