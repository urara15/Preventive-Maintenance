<?php
ob_start();
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    http_response_code(403);
    exit('Unauthorized');
}

// ── Read filters (same as getListData.php) ───────────────────────────────────
$filter_month     = trim($_POST['filter_month']     ?? '');
$filter_plant     = trim($_POST['filter_plant']     ?? '');
$filter_status    = trim($_POST['filter_status']    ?? '');
$filter_date_from = trim($_POST['filter_date_from'] ?? '');
$filter_date_to   = trim($_POST['filter_date_to']   ?? '');

// ── Build WHERE clause ───────────────────────────────────────────────────────
$whereParts = [];

if ($filter_month !== '') {
    $m = preg_replace('/[^0-9]/', '', $filter_month);
    if (strlen($m) === 2) {
        $whereParts[] = "SUBSTR(pm.INSPECTION_MONTH, 6, 2) = '$m'";
    }
}
if ($filter_plant !== '') {
    $p = str_replace("'", "''", $filter_plant);
    $whereParts[] = "pm.PLANT = '$p'";
}
if ($filter_status !== '') {
    $s = str_replace("'", "''", $filter_status);
    $whereParts[] = "pm.PM_STATUS = '$s'";
}
if ($filter_date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
    $whereParts[] = "TRUNC(pm.CREATED_AT) >= TO_DATE('$filter_date_from', 'YYYY-MM-DD')";
}
if ($filter_date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
    $whereParts[] = "TRUNC(pm.CREATED_AT) <= TO_DATE('$filter_date_to', 'YYYY-MM-DD')";
}

$whereSQL = count($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// ── Fetch all matching records ───────────────────────────────────────────────
$sql = "
    SELECT
        pm.ID,
        'PM-' || LPAD(pm.ID, 6, '0')                        AS PM_REF_NO,
        pm.INSPECTION_MONTH,
        pm.LINE,
        pm.MODEL,
        pm.PLANT,
        pm.PM_STATUS,
        verified_emp.EMPLOYEE_NAME                           AS VERIFIED_BY_NAME,
        created_emp.EMPLOYEE_NAME                            AS CREATED_BY_NAME,
        TO_CHAR(pm.CREATED_AT, 'DD/MM/YYYY HH24:MI')         AS CREATED_AT,
        TO_CHAR(pm.UPDATED_AT, 'DD/MM/YYYY HH24:MI')         AS UPDATED_AT
    FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER pm
    LEFT JOIN EMPLOYEE_MASTER verified_emp ON verified_emp.EMP_ID = pm.VERIFIED_BY
    LEFT JOIN EMPLOYEE_MASTER created_emp  ON created_emp.EMP_ID  = pm.CREATED_BY
    $whereSQL
    ORDER BY pm.ID DESC
";

$stmt = oci_parse($dbcon, $sql);
oci_execute($stmt);

$rows = [];
while (($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) !== false) {
    $rows[] = $row;
}
oci_free_statement($stmt);

// ── Helper: XML-escape ───────────────────────────────────────────────────────
$x = function(string $s): string {
    return htmlspecialchars($s, ENT_XML1, 'UTF-8');
};

// ── Shared strings ───────────────────────────────────────────────────────────
$sharedStrings = [];
$siIndex       = [];
$addSS = function(string $val) use (&$sharedStrings, &$siIndex): int {
    if (!isset($siIndex[$val])) {
        $siIndex[$val] = count($sharedStrings);
        $sharedStrings[] = $val;
    }
    return $siIndex[$val];
};

// ── Build filter description line ────────────────────────────────────────────
$filterParts = [];
if ($filter_month     !== '') $filterParts[] = 'Month: ' . $filter_month;
if ($filter_plant     !== '') $filterParts[] = 'Plant: ' . $filter_plant;
if ($filter_status    !== '') $filterParts[] = 'Status: ' . $filter_status;
if ($filter_date_from !== '') $filterParts[] = 'From: ' . $filter_date_from;
if ($filter_date_to   !== '') $filterParts[] = 'To: ' . $filter_date_to;
$filterDesc = empty($filterParts) ? 'All Records' : implode('   |   ', $filterParts);

// ── styles.xml ───────────────────────────────────────────────────────────────
// Style indices:
// 0 = default
// 1 = title (dark blue bg, white bold, large)
// 2 = filter info (light blue bg, italic)
// 3 = column header (blue bg, white bold, center)
// 4 = data cell (with border)
// 5 = data cell center (status, with border)
// 6 = status: Verified (green bg)
// 7 = status: Rejected (red bg)
// 8 = status: Pending (amber bg, dark text)
$stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="5">
    <font><sz val="11"/><name val="Calibri"/></font>
    <font><sz val="14"/><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><sz val="10"/><i/><color rgb="FF1F3864"/><name val="Calibri"/></font>
    <font><sz val="11"/><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><sz val="11"/><name val="Calibri"/></font>
  </fonts>
  <fills count="8">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1A56A0"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F1"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF2E75B6"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE2EFDA"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFCE4D6"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFB8CCE4"/></left>
      <right style="thin"><color rgb="FFB8CCE4"/></right>
      <top style="thin"><color rgb="FFB8CCE4"/></top>
      <bottom style="thin"><color rgb="FFB8CCE4"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="9">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="6" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="7" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>
  </cellXfs>
</styleSheet>
XML;

// ── Build sheet rows ─────────────────────────────────────────────────────────
$rowXml  = '';
$merges  = [];
$ri      = 1;   // current row index

// Row 1: Title
$si = $addSS('PREVENTIVE MAINTENANCE CHECKLIST – SUMMARY');
$rowXml .= '<row r="'.$ri.'" ht="24" customHeight="1">'
         . '<c r="A'.$ri.'" t="s" s="1"><v>'.$si.'</v></c>'
         . '</row>';
$merges[] = 'A'.$ri.':K'.$ri;
$ri++;

// Row 2: Filter info
$si = $addSS('Filters: ' . $filterDesc);
$rowXml .= '<row r="'.$ri.'" ht="16" customHeight="1">'
         . '<c r="A'.$ri.'" t="s" s="2"><v>'.$si.'</v></c>'
         . '</row>';
$merges[] = 'A'.$ri.':K'.$ri;
$ri++;

// Row 3: Generated date
$si = $addSS('Generated: ' . date('d/m/Y H:i'));
$rowXml .= '<row r="'.$ri.'" ht="14" customHeight="1">'
         . '<c r="A'.$ri.'" t="s" s="2"><v>'.$si.'</v></c>'
         . '</row>';
$merges[] = 'A'.$ri.':K'.$ri;
$ri++;

// Row 4: blank spacer
$rowXml .= '<row r="'.$ri.'"></row>';
$ri++;

// Row 5: Column headers
$headers = ['#', 'REF NO', 'MONTH', 'LINE', 'MODEL', 'PLANT', 'APPROVED BY', 'STATUS', 'CREATED BY', 'CREATED DATE', 'UPDATED DATE'];
$cols    = ['A','B','C','D','E','F','G','H','I','J','K'];
$rowXml .= '<row r="'.$ri.'" ht="30" customHeight="1">';
foreach ($headers as $hi => $h) {
    $si = $addSS($h);
    $rowXml .= '<c r="'.$cols[$hi].$ri.'" t="s" s="3"><v>'.$si.'</v></c>';
}
$rowXml .= '</row>';
$ri++;

// Rows 6+: Data
foreach ($rows as $row) {
    $status = $row['PM_STATUS'] ?? 'Pending Verification';

    // Pick status style
    if ($status === 'Verified') {
        $statusStyle = 6;
    } elseif ($status === 'Rejected') {
        $statusStyle = 7;
    } else {
        $statusStyle = 8; // Pending — amber
    }

    $cells = [
        ['v' => $row['ID']               ?? '', 's' => 5],
        ['v' => $row['PM_REF_NO']        ?? '', 's' => 5],
        ['v' => $row['INSPECTION_MONTH'] ?? '', 's' => 5],
        ['v' => $row['LINE']             ?? '', 's' => 4],
        ['v' => $row['MODEL']            ?? '', 's' => 4],
        ['v' => $row['PLANT']            ?? '', 's' => 5],
        ['v' => $row['VERIFIED_BY_NAME'] ?? '', 's' => 4],
        ['v' => $status,                        's' => $statusStyle],
        ['v' => $row['CREATED_BY_NAME']  ?? '', 's' => 4],
        ['v' => $row['CREATED_AT']       ?? '', 's' => 5],
        ['v' => $row['UPDATED_AT']       ?? '', 's' => 5],
    ];

    $rowXml .= '<row r="'.$ri.'" ht="15" customHeight="1">';
    foreach ($cells as $ci => $cell) {
        $si = $addSS((string)($cell['v'] ?? ''));
        $rowXml .= '<c r="'.$cols[$ci].$ri.'" t="s" s="'.$cell['s'].'"><v>'.$si.'</v></c>';
    }
    $rowXml .= '</row>';
    $ri++;
}

// ── sharedStrings.xml ────────────────────────────────────────────────────────
$ssXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' count="'.count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">';
foreach ($sharedStrings as $s) {
    $ssXml .= '<si><t xml:space="preserve">'.$x($s).'</t></si>';
}
$ssXml .= '</sst>';

// ── sheet1.xml ───────────────────────────────────────────────────────────────
$mergeXml = '';
if (!empty($merges)) {
    $mergeXml = '<mergeCells count="'.count($merges).'">';
    foreach ($merges as $m) { $mergeXml .= '<mergeCell ref="'.$m.'"/>'; }
    $mergeXml .= '</mergeCells>';
}

$colDefsXml = '<cols>'
    . '<col min="1"  max="1"  width="6"  customWidth="1"/>'  // #
    . '<col min="2"  max="2"  width="14" customWidth="1"/>'  // REF NO
    . '<col min="3"  max="3"  width="14" customWidth="1"/>'  // MONTH
    . '<col min="4"  max="4"  width="10" customWidth="1"/>'  // LINE
    . '<col min="5"  max="5"  width="20" customWidth="1"/>'  // MODEL
    . '<col min="6"  max="6"  width="10" customWidth="1"/>'  // PLANT
    . '<col min="7"  max="7"  width="24" customWidth="1"/>'  // APPROVED BY
    . '<col min="8"  max="8"  width="22" customWidth="1"/>'  // STATUS
    . '<col min="9"  max="9"  width="24" customWidth="1"/>'  // CREATED BY
    . '<col min="10" max="10" width="18" customWidth="1"/>'  // CREATED DATE
    . '<col min="11" max="11" width="18" customWidth="1"/>'  // UPDATED DATE
    . '</cols>';

$sheetXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
$sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
$sheetXml .= '<sheetViews><sheetView workbookViewId="0"><selection activeCell="A1"/></sheetView></sheetViews>';
$sheetXml .= $colDefsXml;
$sheetXml .= '<sheetData>'.$rowXml.'</sheetData>';
$sheetXml .= $mergeXml;
$sheetXml .= '</worksheet>';

// ── workbook.xml ─────────────────────────────────────────────────────────────
$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="PM Summary" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>';

// ── [Content_Types].xml ───────────────────────────────────────────────────────
$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml"  ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>';

// ── _rels/.rels ───────────────────────────────────────────────────────────────
$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

// ── xl/_rels/workbook.xml.rels ────────────────────────────────────────────────
$wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

// ── Assemble .xlsx via ZipArchive ─────────────────────────────────────────────
$tmpFile = tempnam(sys_get_temp_dir(), 'pm_summary_') . '.xlsx';
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo 'Failed to create Excel file.';
    exit;
}

$zip->addFromString('[Content_Types].xml',         $contentTypes);
$zip->addFromString('_rels/.rels',                 $rootRels);
$zip->addFromString('xl/workbook.xml',             $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels',  $wbRels);
$zip->addFromString('xl/styles.xml',               $stylesXml);
$zip->addFromString('xl/sharedStrings.xml',        $ssXml);
$zip->addFromString('xl/worksheets/sheet1.xml',    $sheetXml);
$zip->close();

// ── Stream to browser ─────────────────────────────────────────────────────────
$fileName = 'PM_Summary_' . date('Ymd_His') . '.xlsx';

ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: max-age=0');

readfile($tmpFile);
unlink($tmpFile);
exit;