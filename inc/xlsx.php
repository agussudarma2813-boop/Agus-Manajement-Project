<?php
declare(strict_types=1);

/**
 * Penulis file Excel .xlsx sederhana (tanpa library eksternal).
 *
 * Menghasilkan file xlsx ASLI (zip + XML) supaya rapi saat dibuka di
 * Excel / WPS / Google Sheets: ada judul, header berwarna, garis tabel,
 * lebar kolom, format ribuan, baris header dibekukan (freeze), dan
 * satu sheet per project.
 *
 * Pemakaian:
 *   $x = new XlsxExport();
 *   $x->sheet('Nama Project', [
 *       ['cells' => [ ['v' => 'PENGAJUAN', 't' => 's', 's' => 'judul'] ], 'merge' => 'A1:F1'],
 *       ...
 *   ], ['A' => 6, 'B' => 42, 'C' => 12, 'D' => 10, 'E' => 18, 'F' => 18]);
 *   $x->kirim('nama-file');
 */
final class XlsxExport
{
    /** @var array<int,array{nama:string,baris:array,lebar:array,freeze:int,merge:array}> */
    private array $sheets = [];

    public const GAYA = ['normal', 'judul', 'subjudul', 'header', 'teks', 'angka', 'total_teks', 'total_angka'];

    public function tambahSheet(string $nama, array $baris, array $lebar = [], int $freeze = 0): void
    {
        $this->sheets[] = [
            'nama' => $this->namaSheetValid($nama, count($this->sheets)),
            'baris' => $baris,
            'lebar' => $lebar,
            'freeze' => $freeze,
        ];
    }

    public function adaSheet(): bool
    {
        return $this->sheets !== [];
    }

    /** Nama sheet: maks 31 karakter, tanpa karakter terlarang, tidak boleh ganda */
    private function namaSheetValid(string $nama, int $urut): string
    {
        $nama = str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', $nama);
        $nama = trim(preg_replace('/\s+/', ' ', $nama) ?? '');
        if ($nama === '') {
            $nama = 'Project ' . ($urut + 1);
        }
        $nama = mb_substr($nama, 0, 31);
        $dipakai = array_map(fn($s) => mb_strtolower($s['nama']), $this->sheets);
        $kandidat = $nama;
        $n = 1;
        while (in_array(mb_strtolower($kandidat), $dipakai, true)) {
            $kandidat = mb_substr($nama, 0, 28) . ' (' . (++$n) . ')';
        }
        return $kandidat;
    }

    /** Menulis file .xlsx ke sebuah path. false bila zip tidak tersedia/gagal. */
    public function simpanKe(string $path): bool
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        // CREATE wajib: tanpa itu ZipArchive menolak file yang belum ada (ER_NOENT)
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->relsUtama());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relsWorkbook());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('docProps/app.xml', $this->docPropsApp());
        $zip->addFromString('docProps/core.xml', $this->docPropsCore());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->worksheet($sheet));
        }
        $zip->close();
        return true;
    }

    /**
     * Folder sementara yang benar-benar bisa ditulis.
     * (Di sebagian server sys_get_temp_dir() tidak writable — pernah bikin export gagal.)
     */
    private function dirSementara(): ?string
    {
        $kandidat = [
            sys_get_temp_dir(),
            defined('APP_ROOT') ? APP_ROOT . '/data/tmp' : null,
            dirname(__DIR__) . '/data/tmp',
        ];
        foreach ($kandidat as $d) {
            if (!$d) {
                continue;
            }
            if (!is_dir($d)) {
                @mkdir($d, 0775, true);
            }
            if (is_dir($d) && is_writable($d)) {
                return $d;
            }
        }
        return null;
    }

    /** Kirim sebagai unduhan .xlsx. Mengembalikan false bila zip tidak tersedia. */
    public function kirim(string $namaFile): bool
    {
        $dir = $this->dirSementara();
        if ($dir === null) {
            return false;
        }
        $tmp = tempnam($dir, 'xlsx');
        if ($tmp === false || !$this->simpanKe($tmp)) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            return false;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $namaFile . '.xlsx"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        return true;
    }

    private function esc(string $s): string
    {
        // Buang karakter kontrol yang membuat file xlsx rusak
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function kolomNama(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = (int) (($n - $m) / 26);
        }
        return $s;
    }

    /* ---------------- Bagian-bagian file xlsx ---------------- */

    private function contentTypes(): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        foreach ($this->sheets as $i => $s) {
            $out .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return $out . '</Types>';
    }

    private function relsUtama(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($this->sheets as $i => $s) {
            $out .= '<sheet name="' . $this->esc($s['nama']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }
        return $out . '</sheets></workbook>';
    }

    private function relsWorkbook(): string
    {
        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $out .= '<Relationship Id="rId' . ($i + 1) . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $out .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return $out . '</Relationships>';
    }

    private function styles(): string
    {
        // Urutan cellXfs = urutan XlsxExport::GAYA
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="#,##0"/>'
            . '<numFmt numFmtId="165" formatCode="#,##0.###"/>'
            . '</numFmts>'
            . '<fonts count="5">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'                                   // 0 normal
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'                              // 1 bold
            . '<font><b/><sz val="15"/><color rgb="FF1F1B3A"/><name val="Calibri"/></font>'       // 2 judul
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'       // 3 header putih
            . '<font><sz val="11"/><color rgb="FF635F82"/><name val="Calibri"/></font>'           // 4 abu (subjudul)
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF4F46E5"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD5DAE5"/></left><right style="thin"><color rgb="FFD5DAE5"/></right>'
            . '<top style="thin"><color rgb="FFD5DAE5"/></top><bottom style="thin"><color rgb="FFD5DAE5"/></bottom><diagonal/></border>'
            . '<border><left/><right/><top style="thin"><color rgb="FF4F46E5"/></top><bottom/><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="8">'
            // 0 normal
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 judul
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>'
            // 2 subjudul (label abu)
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 3 header
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 4 teks + border
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>'
            // 5 angka ribuan + border
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>'
            // 6 total teks
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="2" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="center"/></xf>'
            // 7 total angka
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function docPropsApp(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>ProyekKita</Application></Properties>';
    }

    private function docPropsCore(): string
    {
        $waktu = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>ProyekKita</dc:creator>'
            . '<cp:lastModifiedBy>ProyekKita</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $waktu . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $waktu . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    /**
     * Satu worksheet.
     * $sheet['baris'] = daftar baris, tiap baris: ['cells' => [ ['v'=>..,'t'=>'s|n','s'=>'nama gaya'], ... ],
     *                                                          'tinggi' => float (opsional),
     *                                                          'merge'  => true (gabung A..sel terakhir baris)]
     */
    private function worksheet(array $sheet): string
    {
        $gayaIdx = array_flip(self::GAYA);
        $maxKolom = 1;
        $jmlBaris = 0;
        foreach ($sheet['baris'] as $r) {
            $maxKolom = max($maxKolom, count($r['cells'] ?? []));
            if (!empty($r['cells']) || !empty($r['tinggi'])) {
                $jmlBaris++;
            }
        }
        $jmlBaris = max(1, $jmlBaris);

        $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // URUTAN ELEMEN CT_Worksheet WAJIB seperti di standar ECMA-376:
        // sheetPr, dimension, sheetViews, sheetFormatPr, cols, sheetData, mergeCells, pageMargins, pageSetup.
        // Excel asli MENOLAK / menampilkan KOSONG bila urutannya salah (openpyxl & LibreOffice lebih pemaaf,
        // jadi "kelihatan benar" di uji tapi kosong di Excel — ini yang dulu terjadi).
        $out .= '<dimension ref="A1:' . $this->kolomNama($maxKolom) . $jmlBaris . '"/>';

        // tampilan + freeze di bawah baris header
        $out .= '<sheetViews><sheetView workbookViewId="0"';
        if (($sheet['freeze'] ?? 0) > 0) {
            $f = (int) $sheet['freeze'];
            $out .= '><pane ySplit="' . $f . '" topLeftCell="A' . ($f + 1) . '" activePane="bottomLeft" state="frozen"/>';
            $out .= '<selection pane="bottomLeft" activeCell="A' . ($f + 1) . '" sqref="A' . ($f + 1) . '"/></sheetView>';
        } else {
            $out .= '><selection activeCell="A1" sqref="A1"/></sheetView>';
        }
        $out .= '</sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>';

        // lebar kolom (setelah sheetFormatPr, sebelum sheetData)
        $lebar = $sheet['lebar'];
        if ($lebar) {
            $out .= '<cols>';
            foreach ($lebar as $kol => $w) {
                $idx = is_int($kol) ? $kol + 1 : (ord(strtoupper((string) $kol)) - 64);
                $out .= '<col min="' . $idx . '" max="' . $idx . '" width="' . $w . '" customWidth="1"/>';
            }
            $out .= '</cols>';
        }

        $out .= '<sheetData>';

        $merges = [];
        $barisNo = 0;
        foreach ($sheet['baris'] as $r) {
            $barisNo++;
            $cells = $r['cells'] ?? [];
            if (!$cells && empty($r['tinggi'])) {
                continue;
            }
            $out .= '<row r="' . $barisNo . '"' . (!empty($r['tinggi']) ? ' ht="' . $r['tinggi'] . '" customHeight="1"' : '') . '>';
            $kolNo = 0;
            foreach ($cells as $cell) {
                $kolNo++;
                $ref = $this->kolomNama($kolNo) . $barisNo;
                $gaya = $cell['s'] ?? 'normal';
                $si = $gayaIdx[$gaya] ?? 0;
                if (($cell['t'] ?? 's') === 'n') {
                    $out .= '<c r="' . $ref . '" s="' . $si . '"><v>' . num_xml((float) ($cell['v'] ?? 0)) . '</v></c>';
                } elseif (($cell['v'] ?? '') === '') {
                    $out .= '<c r="' . $ref . '" s="' . $si . '"/>';
                } else {
                    $out .= '<c r="' . $ref . '" s="' . $si . '" t="inlineStr"><is><t xml:space="preserve">'
                        . $this->esc((string) $cell['v']) . '</t></is></c>';
                }
            }
            // sel sisa (kalau ada yang digabung melebihi kolom terisi)
            $out .= '</row>';
            if (!empty($r['merge']) && $cells) {
                // gabung sampai kolom terakhir yang dipakai sheet (bukan hanya kolom terisi)
                $akhir = $this->kolomNama($maxKolom) . $barisNo;
                $merges[] = 'A' . $barisNo . ':' . $akhir;
            }
        }
        $out .= '</sheetData>';

        if ($merges) {
            $out .= '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $m) {
                $out .= '<mergeCell ref="' . $m . '"/>';
            }
            $out .= '</mergeCells>';
        }

        $out .= '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
        $out .= '<pageSetup orientation="portrait" fitToWidth="1" fitToHeight="0"/>';
        return $out . '</worksheet>';
    }
}
