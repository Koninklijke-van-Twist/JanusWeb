<?php

/**
 * Minimal .xlsx writer (ZipArchive + SpreadsheetML). No Composer dependency.
 */
class JanusXlsxWriter
{
    /** @var list<string> */
    private array $shared = [];

    /** @var array<string, int> */
    private array $sharedIndex = [];

    public function stringId(string $value): int
    {
        if (isset($this->sharedIndex[$value])) {
            return $this->sharedIndex[$value];
        }
        $id = count($this->shared);
        $this->shared[] = $value;
        $this->sharedIndex[$value] = $id;

        return $id;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * @param list<array{email: string, name: string, officeDays: int, missingDays: list<string>}> $rows
     */
    public function buildOfficeDaysExport(string $startLabel, string $endLabel, array $rows): string
    {
        $headerRow = 2;
        $firstDataRow = 3;
        $lastDataRow = $rows === [] ? $headerRow : ($firstDataRow + count($rows) - 1);
        $tableRef = 'A' . $headerRow . ':E' . $lastDataRow;

        $sheetRows = '';
        $period = 'Van: ' . $startLabel . '    Tot: ' . $endLabel;
        $sheetRows .= '<row r="1">'
            . '<c r="A1" t="s" s="1"><v>' . $this->stringId($period) . '</v></c>'
            . '</row>';

        $headers = ['Check', 'Naam', 'Email', 'Kantoordagen', 'Ontbrekende dagen'];
        $sheetRows .= '<row r="' . $headerRow . '">';
        foreach ($headers as $i => $header) {
            $col = chr(ord('A') + $i);
            $sheetRows .= '<c r="' . $col . $headerRow . '" t="s" s="2"><v>' . $this->stringId($header) . '</v></c>';
        }
        $sheetRows .= '</row>';

        $r = $firstDataRow;
        foreach ($rows as $row) {
            $missing = implode(', ', $row['missingDays']);
            $sheetRows .= '<row r="' . $r . '">'
                . '<c r="A' . $r . '" t="b" s="4"><v>0</v></c>'
                . '<c r="B' . $r . '" t="s" s="3"><v>' . $this->stringId((string) $row['name']) . '</v></c>'
                . '<c r="C' . $r . '" t="s" s="3"><v>' . $this->stringId((string) $row['email']) . '</v></c>'
                . '<c r="D' . $r . '" s="3"><v>' . (int) $row['officeDays'] . '</v></c>'
                . '<c r="E' . $r . '" t="s" s="3"><v>' . $this->stringId($missing) . '</v></c>'
                . '</row>';
            $r++;
        }

        $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . count($this->shared) . '" uniqueCount="' . count($this->shared) . '">';
        foreach ($this->shared as $s) {
            $sharedXml .= '<si><t xml:space="preserve">' . $this->xml($s) . '</t></si>';
        }
        $sharedXml .= '</sst>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr/><dimension ref="A1:E' . $lastDataRow . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>'
            . '<col min="1" max="1" width="10" customWidth="1"/>'
            . '<col min="2" max="2" width="24" customWidth="1"/>'
            . '<col min="3" max="3" width="32" customWidth="1"/>'
            . '<col min="4" max="4" width="14" customWidth="1"/>'
            . '<col min="5" max="5" width="48" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '<mergeCells count="1"><mergeCell ref="A1:E1"/></mergeCells>'
            . '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>'
            . '</worksheet>';

        $tableXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' id="1" name="Kantoordagen" displayName="Kantoordagen" ref="' . $tableRef . '">'
            . '<autoFilter ref="' . $tableRef . '"/>'
            . '<tableColumns count="5">'
            . '<tableColumn id="1" name="Check"/>'
            . '<tableColumn id="2" name="Naam"/>'
            . '<tableColumn id="3" name="Email"/>'
            . '<tableColumn id="4" name="Kantoordagen"/>'
            . '<tableColumn id="5" name="Ontbrekende dagen"/>'
            . '</tableColumns>'
            . '<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>'
            . '</table>';

        $sheetRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>'
            . '</Relationships>';

        $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="12"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">'
            . '<alignment horizontal="center"/>'
            . '<extLst><ext uri="{C7286773-470A-42A8-94C5-96B5CB345126}"'
            . ' xmlns:xfpb="http://schemas.microsoft.com/office/spreadsheetml/2022/featurepropertybag">'
            . '<xfpb:xfComplement i="0"/></ext></extLst>'
            . '</xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        $featureBagXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<FeaturePropertyBags xmlns="http://schemas.microsoft.com/office/spreadsheetml/2022/featurepropertybag">'
            . '<bag type="Checkbox"/>'
            . '<bag type="XFControls"><bagId k="CellControl">0</bagId></bag>'
            . '<bag type="XFComplement"><bagId k="XFControls">1</bagId></bag>'
            . '<bag type="XFComplements" extRef="XFComplementsMapperExtRef">'
            . '<a k="MappedFeaturePropertyBags"><bagId>2</bagId></a>'
            . '</bag>'
            . '</FeaturePropertyBags>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Kantoordagen" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '<Relationship Id="rId4" Type="http://schemas.microsoft.com/office/2022/11/relationships/FeaturePropertyBag" Target="featurePropertyBag/featurePropertyBag.xml"/>'
            . '</Relationships>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>'
            . '<Override PartName="/xl/featurePropertyBag/featurePropertyBag.xml" ContentType="application/vnd.ms-excel.featurepropertybag+xml"/>'
            . '</Types>';

        $tmp = tempnam(sys_get_temp_dir(), 'janusxlsx');
        if ($tmp === false) {
            throw new RuntimeException('Kon tijdelijk bestand niet maken.');
        }
        $zipPath = $tmp . '.xlsx';
        @unlink($tmp);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Kon Excel-bestand niet schrijven.');
        }

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $sheetRels);
        $zip->addFromString('xl/tables/table1.xml', $tableXml);
        $zip->addFromString('xl/styles.xml', $stylesXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
        $zip->addFromString('xl/featurePropertyBag/featurePropertyBag.xml', $featureBagXml);
        $zip->close();

        $binary = file_get_contents($zipPath);
        @unlink($zipPath);
        if ($binary === false) {
            throw new RuntimeException('Kon Excel-bestand niet lezen.');
        }

        return $binary;
    }
}
