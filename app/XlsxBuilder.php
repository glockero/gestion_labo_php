<?php
// app/XlsxBuilder.php

class SimpleZipArchiveBuilder {
    private $files = [];
    private $centralDirectory = [];
    private $offset = 0;

    public function addFile($name, $contents) {
        $name = str_replace('\\', '/', $name);
        $timestamp = $this->getDosTimestamp();
        $crc = $this->unsignedCrc32($contents);
        $size = strlen($contents);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $timestamp['time'],
            $timestamp['date'],
            $crc,
            $size,
            $size,
            strlen($name),
            0
        );

        $this->files[] = $localHeader . $name . $contents;

        $centralHeader = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $timestamp['time'],
            $timestamp['date'],
            $crc,
            $size,
            $size,
            strlen($name),
            0,
            0,
            0,
            0,
            32,
            $this->offset
        );

        $this->centralDirectory[] = $centralHeader . $name;
        $this->offset += strlen($localHeader) + strlen($name) + $size;
    }

    public function build() {
        $fileData = implode('', $this->files);
        $centralData = implode('', $this->centralDirectory);
        $centralSize = strlen($centralData);
        $centralOffset = strlen($fileData);

        $endOfCentralDirectory = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($this->centralDirectory),
            count($this->centralDirectory),
            $centralSize,
            $centralOffset,
            0
        );

        return $fileData . $centralData . $endOfCentralDirectory;
    }

    private function getDosTimestamp() {
        $now = getdate();
        $year = max(1980, (int)$now['year']);
        return [
            'time' => ($now['hours'] << 11) | ($now['minutes'] << 5) | (int) floor($now['seconds'] / 2),
            'date' => (($year - 1980) << 9) | ($now['mon'] << 5) | $now['mday'],
        ];
    }

    private function unsignedCrc32($contents) {
        return (int) sprintf('%u', crc32($contents));
    }
}

class XlsxBuilder {
    public static function output($filename, $sheetName, array $headers, array $rows) {
        $zip = new SimpleZipArchiveBuilder();
        $zip->addFile('[Content_Types].xml', self::contentTypesXml());
        $zip->addFile('_rels/.rels', self::rootRelsXml());
        $zip->addFile('xl/workbook.xml', self::workbookXml($sheetName));
        $zip->addFile('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFile('xl/styles.xml', self::stylesXml());
        $zip->addFile('xl/worksheets/sheet1.xml', self::sheetXml($headers, $rows));

        $binary = $zip->build();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($binary));
        echo $binary;
        exit;
    }

    private static function contentTypesXml() {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rootRelsXml() {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml($sheetName) {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml() {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function stylesXml() {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function sheetXml(array $headers, array $rows) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';

        $xml .= self::rowXml(1, $headers, true);
        $rowNumber = 2;
        foreach ($rows as $row) {
            $xml .= self::rowXml($rowNumber, $row, false);
            $rowNumber++;
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private static function rowXml($rowNumber, array $values, $isHeader) {
        $xml = '<row r="' . $rowNumber . '">';
        foreach (array_values($values) as $index => $value) {
            $cellRef = self::columnName($index + 1) . $rowNumber;
            $style = $isHeader ? ' s="1"' : '';
            $text = self::xml((string) ($value ?? ''));
            $xml .= '<c r="' . $cellRef . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $text . '</t></is></c>';
        }
        $xml .= '</row>';
        return $xml;
    }

    private static function columnName($index) {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)) . $name;
            $index = intdiv($index, 26);
        }
        return $name;
    }

    private static function xml($value) {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
