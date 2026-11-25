<?php
// Đọc chữ trong file Word (.docx) mà không cần thư viện ngoài (chỉ cần extension zip và dom của PHP,
// XAMPP bật sẵn). Dùng cho A-43 (tách câu hỏi từ file Word).
// Nâng cấp:
//  1. Tự động giải mã Auto-Numbering (word/numbering.xml) khi người dùng dùng danh sách tự động 1. 2. 3. hoặc A. B. C. D.
//  2. Tự động nhận diện đáp án được đánh dấu bằng Gạch chân (<u>), In đậm (<b>), Tô màu đỏ/xanh hoặc Tô sáng (Highlight).
//  3. Tự động tách các ô trong Bảng (Table <w:tc>) thành các đoạn rõ ràng để không bị dính chữ khi đề thi kẻ bảng 2x2.
//  4. Cung cấp hàm taiMau() để sinh file Word mẫu (.docx) chuẩn cho Admin tải về sử dụng ngay.
class DocFileDocx
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const TOI_DA_XML = 20 * 1024 * 1024;   // chặn file nén bung quá lớn

    // Trả về danh sách dòng chữ: mỗi phần tử ['so' => số thứ tự đoạn văn, 'text' => chữ, 'marks' => danh sách chữ cái A-D có định dạng đặc biệt].
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
        $numXml = $zip->getFromName('word/numbering.xml');
        $stylesXml = $zip->getFromName('word/styles.xml');
        $coAnh = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $tenMuc = (string) $zip->getNameIndex($i);
            if (str_starts_with($tenMuc, 'word/media/')) {
                $coAnh = true;
                break;
            }
        }
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

        // Giải mã cấu hình đánh số tự động (numbering.xml + styles.xml) nếu có
        $numMap = self::docNumbering(is_string($numXml) ? $numXml : '', is_string($stylesXml) ? $stylesXml : '');
        $boDemNum = []; // [numId][ilvl] => số đếm hiện tại

        $dong = [];
        $so   = 0;
        foreach ($d->getElementsByTagNameNS(self::W, 'p') as $p) {
            // Bỏ qua đoạn nằm lồng bên trong một đoạn khác để tránh lặp
            $cha = $p->parentNode;
            $long = false;
            while ($cha !== null) {
                if ($cha->nodeType === XML_ELEMENT_NODE && $cha->namespaceURI === self::W && $cha->localName === 'p') {
                    $long = true;
                    break;
                }
                $cha = $cha->parentNode;
            }
            if ($long) {
                continue;
            }

            $so++;
            $tienTo = self::layTienToNumbering($p, $numMap, $boDemNum);
            $runs = [];
            self::thuThapRun($p, false, $runs);

            // Ghép các run thành chuỗi và theo dõi định dạng của từng vị trí ký tự
            $chu = '';
            $marks = [];
            foreach ($runs as $r) {
                $chu .= $r['text'];
            }
            $chu = str_replace("\xC2\xA0", ' ', $chu);

            // Nếu đoạn văn có Auto-Numbering (ví dụ "1." hoặc "A.") mà đầu đoạn chưa có sẵn nhãn thì ghép tiền tố vào
            if ($tienTo !== '') {
                $chuTrim = ltrim($chu);
                if ($chuTrim !== '' && !preg_match('/^(?:C[âa]u\s*\d+|\d+\s*[\.\):]|\*?\s*[A-Da-d]\s*[\.\):])/iu', $chuTrim)) {
                    $chu = $tienTo . ' ' . $chuTrim;
                    array_unshift($runs, ['text' => $tienTo . ' ', 'marked' => $runs[0]['marked'] ?? false]);
                }
            }

            // Phát hiện các phương án A, B, C, D được đánh dấu đặc biệt (gạch chân, tô đỏ, highlight, hoặc in đậm riêng biệt)
            $marks = self::timPhuongAnDanhDau($runs);

            $cacManh = explode("\n", $chu);
            foreach ($cacManh as $idxManh => $manh) {
                $manh = trim((string) preg_replace('/[ \t]+/u', ' ', $manh));
                if ($manh !== '') {
                    $dong[] = [
                        'so'    => $so,
                        'text'  => $manh,
                        'marks' => $idxManh === 0 ? $marks : [],
                    ];
                }
            }
        }
        if (!$dong) {
            if ($coAnh) {
                throw new RuntimeException('File Word này chỉ chứa hình ảnh/ảnh chụp (không có văn bản để tách câu hỏi). Hãy dùng file Word dạng chữ hoặc tải file Word mẫu (.docx) ở góc trên.');
            }
            throw new RuntimeException('File Word đang trống, không có nội dung văn bản nào.');
        }
        return $dong;
    }

    // Thu thập từng mảnh văn bản (run) kèm cờ 'marked' (gạch chân / highlight / màu nổi / in đậm)
    private static function thuThapRun(DOMNode $n, bool $chaMarked, array &$runs): void
    {
        foreach ($n->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE || $c->namespaceURI !== self::W) {
                continue;
            }
            switch ($c->localName) {
                case 'r':
                    $m = $chaMarked || self::runCoDanhDau($c);
                    self::thuThapRun($c, $m, $runs);
                    break;
                case 't':
                    $runs[] = ['text' => (string) $c->textContent, 'marked' => $chaMarked];
                    break;
                case 'tab':
                    $runs[] = ['text' => '   ', 'marked' => false];
                    break;
                case 'br':
                case 'cr':
                    $runs[] = ['text' => "\n", 'marked' => false];
                    break;
                case 'pPr':
                case 'rPr':
                case 'p':
                    break;
                default:
                    self::thuThapRun($c, $chaMarked, $runs);
            }
        }
    }

    // Kiểm tra xem <w:r> có định dạng đánh dấu đáp án đúng không (gạch chân, highlight, chữ màu đỏ/xanh/cam, hoặc in đậm)
    private static function runCoDanhDau(DOMNode $r): bool
    {
        foreach ($r->childNodes as $c) {
            if ($c->nodeType !== XML_ELEMENT_NODE || $c->namespaceURI !== self::W || $c->localName !== 'rPr') {
                continue;
            }
            foreach ($c->childNodes as $pr) {
                if ($pr->nodeType !== XML_ELEMENT_NODE || $pr->namespaceURI !== self::W) {
                    continue;
                }
                /** @var DOMElement $pr */
                $ten = $pr->localName;
                $val = strtolower((string) $pr->getAttributeNS(self::W, 'val'));
                if ($val === '') {
                    $val = strtolower((string) $pr->getAttribute('w:val'));
                }
                if ($ten === 'u' && $val !== 'none' && $val !== '0' && $val !== 'false') {
                    return true; // Gạch chân <u>
                }
                if ($ten === 'highlight' && $val !== '' && $val !== 'none') {
                    return true; // Tô nền highlight
                }
                if ($ten === 'b' && $val !== '0' && $val !== 'false') {
                    return true; // In đậm <b>
                }
                if ($ten === 'color' && $val !== '' && !in_array($val, ['auto', '000000', '111111', '222222', '333333', '15204a'], true)) {
                    return true; // Tô màu chữ khác màu đen mặc định (ví dụ đỏ FF0000, xanh...)
                }
            }
        }
        return false;
    }

    // Tìm xem trong đoạn văn có nhãn phương án A/B/C/D nào mà phần nội dung hoặc chữ cái được đánh dấu đặc biệt
    private static function timPhuongAnDanhDau(array $runs): array
    {
        if (!$runs) {
            return [];
        }
        $full = '';
        $map = []; // vị trí byte => marked
        foreach ($runs as $r) {
            $t = str_replace("\xC2\xA0", ' ', $r['text']);
            $len = strlen($t);
            for ($i = 0; $i < $len; $i++) {
                $map[strlen($full) + $i] = (bool) $r['marked'];
            }
            $full .= $t;
        }
        // Chỉ xét nếu đầu dòng hoặc trong dòng có mẫu phương án A. B. C. D.
        if (!preg_match_all('/(?:^|\s)\*?\s*([A-D])\s*[\.\):]\s*(\S[^\n]*?)(?=\s+\*?\s*[A-D]\s*[\.\):]|\s*$)/u', $full, $mm, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $ketQua = [];
        foreach ($mm[1] as $k => $mChu) {
            $chuCai = strtoupper($mChu[0]);
            $posChu = (int) $mChu[1];
            $ndText = (string) $mm[2][$k][0];
            $posNd  = (int) $mm[2][$k][1];
            $lenNd  = strlen($ndText);

            // Một phương án được coi là đánh dấu nếu chữ cái A/B/C/D được gạch chân/in đậm VÀ/HOẶC phần nội dung của nó được đánh dấu
            $markedChu = !empty($map[$posChu]);
            $demNd = 0;
            for ($i = 0; $i < $lenNd; $i++) {
                if (!empty($map[$posNd + $i])) {
                    $demNd++;
                }
            }
            $markedNd = $lenNd > 0 && ($demNd * 2 >= $lenNd);
            // Nếu chỉ in đậm chữ "A." ở cả 4 câu nhưng nội dung không đậm thì không tính;
            // vì vậy lưu lại cả hai mức: 'full' (cả nội dung đậm/gạch chân) và 'letter' (chỉ chữ cái đầu đậm/gạch chân)
            if ($markedNd) {
                $ketQua[$chuCai] = 'full';
            } elseif ($markedChu) {
                $ketQua[$chuCai] = 'letter';
            }
        }
        return $ketQua;
    }

    // Đọc word/numbering.xml và word/styles.xml để ánh xạ numId + ilvl sang kiểu đánh số (decimal, upperLetter, lowerLetter)
    private static function docNumbering(string $numXml, string $stylesXml): array
    {
        $map = ['num' => [], 'style' => []];
        if ($numXml !== '') {
            $d = new DOMDocument();
            $cu = libxml_use_internal_errors(true);
            $ok = $d->loadXML($numXml, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($cu);
            if ($ok) {
                $abs = [];
                foreach ($d->getElementsByTagNameNS(self::W, 'abstractNum') as $a) {
                    /** @var DOMElement $a */
                    $aid = $a->getAttributeNS(self::W, 'abstractNumId') ?: $a->getAttribute('w:abstractNumId');
                    foreach ($a->getElementsByTagNameNS(self::W, 'lvl') as $lvl) {
                        /** @var DOMElement $lvl */
                        $ilvl = (int) ($lvl->getAttributeNS(self::W, 'ilvl') ?: $lvl->getAttribute('w:ilvl') ?: 0);
                        $fmt = 'decimal';
                        $txt = '%1.';
                        $start = 1;
                        foreach ($lvl->childNodes as $c) {
                            if ($c->nodeType !== XML_ELEMENT_NODE || $c->namespaceURI !== self::W) {
                                continue;
                            }
                            /** @var DOMElement $c */
                            $v = $c->getAttributeNS(self::W, 'val') ?: $c->getAttribute('w:val');
                            if ($c->localName === 'numFmt' && $v !== '') {
                                $fmt = $v;
                            } elseif ($c->localName === 'lvlText' && $v !== '') {
                                $txt = $v;
                            } elseif ($c->localName === 'start' && $v !== '') {
                                $start = (int) $v;
                            }
                        }
                        $abs[$aid][$ilvl] = ['fmt' => $fmt, 'txt' => $txt, 'start' => $start];
                    }
                }
                foreach ($d->getElementsByTagNameNS(self::W, 'num') as $num) {
                    /** @var DOMElement $num */
                    $nid = $num->getAttributeNS(self::W, 'numId') ?: $num->getAttribute('w:numId');
                    foreach ($num->getElementsByTagNameNS(self::W, 'abstractNumId') as $an) {
                        /** @var DOMElement $an */
                        $aid = $an->getAttributeNS(self::W, 'val') ?: $an->getAttribute('w:val');
                        if (isset($abs[$aid])) {
                            $map['num'][$nid] = $abs[$aid];
                        }
                    }
                }
            }
        }
        return $map;
    }

    // Lấy nhãn tiền tố (ví dụ "Câu 1." hoặc "A.") nếu đoạn văn <w:p> dùng Auto-Numbering (<w:numPr>)
    private static function layTienToNumbering(DOMNode $p, array $numMap, array &$boDemNum): string
    {
        $numId = '';
        $ilvl = 0;
        foreach ($p->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && $c->namespaceURI === self::W && $c->localName === 'pPr') {
                foreach ($c->getElementsByTagNameNS(self::W, 'numPr') as $np) {
                    foreach ($np->childNodes as $nc) {
                        if ($nc->nodeType !== XML_ELEMENT_NODE || $nc->namespaceURI !== self::W) {
                            continue;
                        }
                        /** @var DOMElement $nc */
                        $v = $nc->getAttributeNS(self::W, 'val') ?: $nc->getAttribute('w:val');
                        if ($nc->localName === 'numId') {
                            $numId = (string) $v;
                        } elseif ($nc->localName === 'ilvl') {
                            $ilvl = (int) $v;
                        }
                    }
                }
            }
        }
        if ($numId === '' || $numId === '0' || !isset($numMap['num'][$numId][$ilvl])) {
            return '';
        }
        $cfg = $numMap['num'][$numId][$ilvl];
        $fmt = $cfg['fmt'];
        if ($fmt === 'bullet' || $fmt === 'none') {
            return '';
        }
        if (!isset($boDemNum[$numId][$ilvl])) {
            $boDemNum[$numId][$ilvl] = max(1, (int) ($cfg['start'] ?? 1));
        } else {
            $boDemNum[$numId][$ilvl]++;
        }
        // Khi cấp ngoài tăng thì reset cấp con
        foreach (array_keys($boDemNum[$numId]) as $lv) {
            if ($lv > $ilvl) {
                unset($boDemNum[$numId][$lv]);
            }
        }
        $n = $boDemNum[$numId][$ilvl];
        if ($fmt === 'upperLetter' || $fmt === 'lowerLetter') {
            $idx = (($n - 1) % 26);
            return chr(65 + $idx) . '.';
        }
        if (stripos((string) $cfg['txt'], 'câu') !== false) {
            return 'Câu ' . $n . '.';
        }
        return $n . '.';
    }

    // Sinh file Word mẫu (.docx) chuẩn cho Admin tải về sử dụng ngay
    public static function taiMau(string $tenFile = 'mau_de_trac_nghiem_word.docx'): never
    {
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            exit('Máy chủ chưa bật extension zip của PHP.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            exit('Không tạo được file Word mẫu.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');

        $dongMau = [
            ['t' => 'MẪU ĐỀ CÂU HỎI TRẮC NGHIỆM (QUIZUP ARENA)', 'b' => true, 'color' => '1F3A8A'],
            ['t' => 'Hướng dẫn: Bạn có thể xóa các câu ví dụ bên dưới và dán đề thi của bạn vào. Hệ thống hỗ trợ cả 3 cách đánh dấu đáp án đúng (dòng Đáp án:, dấu * trước phương án, hoặc Gạch chân/Tô đỏ phương án đúng).', 'b' => false, 'color' => '555555'],
            ['t' => '', 'b' => false],
            ['t' => 'Câu 1 [Dễ]. Trong ngôn ngữ SQL, từ khóa nào dùng để truy vấn dữ liệu từ bảng?', 'b' => true],
            ['t' => 'A. INSERT', 'b' => false],
            ['t' => 'B. SELECT', 'b' => false],
            ['t' => 'C. UPDATE', 'b' => false],
            ['t' => 'D. DELETE', 'b' => false],
            ['t' => 'Đáp án: B', 'b' => true, 'color' => '17804F'],
            ['t' => 'Giải thích: Câu lệnh SELECT được dùng để truy xuất dữ liệu từ một hoặc nhiều bảng trong CSDL.', 'b' => false],
            ['t' => '', 'b' => false],
            ['t' => 'Câu 2 [Trung bình]. Giao thức HTTPS sử dụng cổng (port) mặc định nào sau đây?', 'b' => true],
            ['t' => 'A. 80', 'b' => false],
            ['t' => 'B. 21', 'b' => false],
            ['t' => '*C. 443', 'b' => true, 'color' => '17804F'],
            ['t' => 'D. 3306', 'b' => false],
            ['t' => 'Giải thích: HTTPS chạy mặc định trên cổng 443 (còn HTTP chạy trên cổng 80).', 'b' => false],
            ['t' => '', 'b' => false],
            ['t' => 'Câu 3 [Khó]. Độ phức tạp thời gian trung bình của thuật toán Tìm kiếm nhị phân (Binary Search) là bao nhiêu?', 'b' => true],
            ['t' => 'A. O(n)', 'b' => false],
            ['t' => 'B. O(log n)', 'b' => true, 'u' => true, 'color' => 'D93025'],
            ['t' => 'C. O(n log n)', 'b' => false],
            ['t' => 'D. O(1)', 'b' => false],
            ['t' => 'Giải thích: Mỗi bước Binary Search chia đôi không gian tìm kiếm nên độ phức tạp là O(log n).', 'b' => false],
        ];

        $body = '';
        foreach ($dongMau as $d) {
            if ($d['t'] === '') {
                $body .= '<w:p/>';
                continue;
            }
            $rPr = '';
            if (!empty($d['b'])) {
                $rPr .= '<w:b/>';
            }
            if (!empty($d['u'])) {
                $rPr .= '<w:u w:val="single"/>';
            }
            if (!empty($d['color'])) {
                $rPr .= '<w:color w:val="' . $d['color'] . '"/>';
            }
            $rPrXml = $rPr !== '' ? '<w:rPr>' . $rPr . '</w:rPr>' : '';
            $txt = htmlspecialchars($d['t'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $body .= '<w:p><w:r>' . $rPrXml . '<w:t xml:space="preserve">' . $txt . '</w:t></w:r></w:p>';
        }

        $docXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body . '<w:sectPr/></w:body></w:document>';

        $zip->addFromString('word/document.xml', $docXml);
        $zip->close();

        $tenFile = preg_replace('/[^A-Za-z0-9._-]/', '_', $tenFile);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $tenFile . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}

// Tương thích ngược: Định danh DocxReader trỏ tới DocFileDocx
if (!class_exists('DocxReader', false)) {
    class_alias('DocFileDocx', 'DocxReader');
}
