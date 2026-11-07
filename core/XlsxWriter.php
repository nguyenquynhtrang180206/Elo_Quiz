<?php
// Ghi file Excel (.xlsx) đơn giản, không cần cài thư viện ngoài (chỉ cần extension zip của PHP,
// XAMPP bật sẵn). Dùng cho các chức năng xuất Excel của Admin (A-45, A-46) và file mẫu import (A-22).
// Cách dùng:
//   XlsxWriter::tai('nguoidung.xlsx', 'Người dùng', ['Họ tên', 'Email'], [['An', 'an@test.com'], ...]);
class XlsxWriter
{
    // Gửi file về trình duyệt rồi dừng chương trình.
    // $dong: mỗi phần tử là một dòng (mảng giá trị). Số nguyên/số thực ghi dạng số, còn lại ghi dạng chữ.
    public static function tai(string $tenFile, string $tenSheet, array $tieuDe, array $dong): never
    {
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            exit('Máy chủ chưa bật extension zip của PHP (bật extension=zip trong php.ini rồi khởi động lại Apache).');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            exit('Không tạo được file Excel tạm.');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        // Tên sheet: tối đa 31 ký tự, không chứa []:*?/\
        $ten = preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $tenSheet);
        $ten = preg_replace('/^(.{31}).+$/su', '$1', $ten);
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($ten) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');

        // Hai kiểu ô: 0 = thường, 1 = chữ đậm (dòng tiêu đề)
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');

        // Độ rộng cột theo nội dung dài nhất (tối thiểu 10, tối đa 50)
        $tatCa = array_merge([$tieuDe], $dong);
        $soCot = 0;
        foreach ($tatCa as $d) {
            $soCot = max($soCot, count($d));
        }
        $rong = array_fill(0, max(1, $soCot), 10);
        foreach ($tatCa as $d) {
            foreach (array_values($d) as $i => $gt) {
                $rong[$i] = max($rong[$i], min(50, mb_strlen((string) $gt, 'UTF-8') + 2));
            }
        }
        $cols = '';
        foreach ($rong as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols><sheetData>';
        $xml .= self::dong(1, $tieuDe, 1);
        foreach ($dong as $n => $d) {
            $xml .= self::dong($n + 2, $d, 0);
        }
        $xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $tenFile = preg_replace('/[^A-Za-z0-9._-]/', '_', $tenFile);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $tenFile . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    // Xuất nhiều sheet trong một file, mỗi sheet có thể kèm một ảnh (biểu đồ) đặt dưới dữ liệu.
    // $sheets: [['ten' => 'Tổng quan',
    //            'dong' => [['kieu' => 2, 'o' => ['Tiêu đề']], ['kieu' => 1, 'o' => ['Cột A', 'Cột B']], ['kieu' => 0, 'o' => ['x', 1]]],
    //            'anh'  => ['jpeg' => '<nhị phân JPEG>', 'rong' => 720, 'cao' => 320]   // không bắt buộc
    //          ], ...]
    // kieu: 0 = thường, 1 = chữ đậm, 2 = tiêu đề lớn. Ảnh JPEG giữ nguyên màu như trên màn hình.
    public static function taiNhieuSheet(string $tenFile, array $sheets): never
    {
        if (!class_exists('ZipArchive')) {
            http_response_code(500);
            exit('Máy chủ chưa bật extension zip của PHP (bật extension=zip trong php.ini rồi khởi động lại Apache).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            http_response_code(500);
            exit('Không tạo được file Excel tạm.');
        }
        $khai = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $rel  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $ct   = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
              . '<Default Extension="xml" ContentType="application/xml"/>'
              . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
              . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
              . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $bangSheet = '';
        $relWb     = '';
        $daDung    = [];
        $soAnh     = 0;

        foreach (array_values($sheets) as $i => $sh) {
            $n = $i + 1;
            $ten = preg_replace('/[\[\]:*?\/\\\\]/u', ' ', (string) $sh['ten']);
            $ten = preg_replace('/^(.{31}).+$/su', '$1', $ten);
            $goc = $ten; $dem = 2;
            while (isset($daDung[mb_strtolower($ten, 'UTF-8')])) {   // tên sheet không được trùng
                $ten = mb_substr($goc, 0, 28, 'UTF-8') . ' ' . $dem++;
            }
            $daDung[mb_strtolower($ten, 'UTF-8')] = true;

            $bangSheet .= '<sheet name="' . self::xml($ten) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $relWb     .= '<Relationship Id="rId' . $n . '" Type="' . $rel . '/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $ct        .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';

            // Độ rộng cột: theo nội dung dài nhất (10 đến 50), ô tiêu đề lớn (kieu 2) không tính
            $rong = [10];
            foreach ($sh['dong'] as $d) {
                if (($d['kieu'] ?? 0) === 2) { continue; }
                foreach (array_values($d['o']) as $c => $gt) {
                    $rong[$c] = max($rong[$c] ?? 10, min(50, mb_strlen((string) $gt, 'UTF-8') + 2));
                }
            }
            $cols = '';
            foreach ($rong as $c => $w) {
                $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml = $khai . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . $rel . '">'
                 . '<cols>' . $cols . '</cols><sheetData>';
            $hang = 0;
            foreach ($sh['dong'] as $d) {
                $hang++;
                $xml .= self::dong($hang, $d['o'], (int) ($d['kieu'] ?? 0));
            }
            $xml .= '</sheetData>';

            if (!empty($sh['anh']['jpeg'])) {
                $soAnh++;
                $xml .= '<drawing r:id="rId1"/>';
                $zip->addFromString('xl/media/anh' . $soAnh . '.jpeg', $sh['anh']['jpeg']);
                $zip->addFromString('xl/worksheets/_rels/sheet' . $n . '.xml.rels',
                    $khai . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="' . $rel . '/drawing" Target="../drawings/drawing' . $n . '.xml"/></Relationships>');
                $zip->addFromString('xl/drawings/_rels/drawing' . $n . '.xml.rels',
                    $khai . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rId1" Type="' . $rel . '/image" Target="../media/anh' . $soAnh . '.jpeg"/></Relationships>');
                $cx = (int) $sh['anh']['rong'] * 9525;   // pixel -> EMU
                $cy = (int) $sh['anh']['cao'] * 9525;
                $zip->addFromString('xl/drawings/drawing' . $n . '.xml',
                    $khai . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" '
                    . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="' . $rel . '">'
                    . '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . ($hang + 1) . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
                    . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/>'
                    . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Bieu do ' . $n . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                    . '<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                    . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
                    . '</xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
                $ct .= '<Override PartName="/xl/drawings/drawing' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
            }
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $xml . '</worksheet>');
        }

        $nStyles = count($sheets) + 1;
        $relWb .= '<Relationship Id="rId' . $nStyles . '" Type="' . $rel . '/styles" Target="styles.xml"/>';
        $zip->addFromString('[Content_Types].xml', $khai . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . $ct . '</Types>');
        $zip->addFromString('_rels/.rels', $khai . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $khai . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="' . $rel . '">'
            . '<sheets>' . $bangSheet . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $khai . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relWb . '</Relationships>');
        // Ba kiểu ô: 0 = thường, 1 = chữ đậm, 2 = tiêu đề lớn
        $zip->addFromString('xl/styles.xml', $khai . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();

        $tenFile = preg_replace('/[^A-Za-z0-9._-]/', '_', $tenFile);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $tenFile . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    private static function dong(int $so, array $gioTri, int $kieu): string
    {
        $h = '<row r="' . $so . '">';
        foreach (array_values($gioTri) as $i => $gt) {
            if ($gt === null || $gt === '') {
                continue;
            }
            $o = self::cot($i) . $so;
            if (is_int($gt) || is_float($gt)) {
                $h .= '<c r="' . $o . '" s="' . $kieu . '"><v>' . $gt . '</v></c>';
            } else {
                // inlineStr: luôn là chữ, Excel không hiểu nhầm thành công thức
                $h .= '<c r="' . $o . '" s="' . $kieu . '" t="inlineStr"><is><t xml:space="preserve">'
                    . self::xml((string) $gt) . '</t></is></c>';
            }
        }
        return $h . '</row>';
    }

    // 0 => A, 25 => Z, 26 => AA ...
    private static function cot(int $chiSo): string
    {
        $s = '';
        for ($n = $chiSo + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    // Bỏ ký tự điều khiển không hợp lệ trong XML rồi thoát ký tự đặc biệt
    private static function xml(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}