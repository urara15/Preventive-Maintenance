<?php
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';
require_once __DIR__ . '/common.php';

$draw   = isset($_POST['draw'])   ? intval($_POST['draw'])   : 1;
$start  = isset($_POST['start'])  ? intval($_POST['start'])  : 0;
$length = isset($_POST['length']) ? intval($_POST['length']) : 10;

$orderColumnIndex = intval($_POST['order'][0]['column'] ?? 0);
$orderDir = (($_POST['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

// Column index mapping — updated to account for the two new columns (WEEK_NO, WEEK_DATE)
// New column order:
//  0  ID
//  1  PM_REF_NO   (derived, map to ID)
//  2  INSPECTION_MONTH
//  3  WEEK_NO     ← new
//  4  WEEK_DATE   ← new
//  5  LINE
//  6  MODEL
//  7  PLANT
//  8  VERIFIED_BY_NAME
//  9  PM_STATUS
//  10 CREATED_BY_NAME
//  11 CREATED_AT
//  12 UPDATED_AT
//  13 ACTION      (not sortable)
$columnMapping = [
    0  => 'pm.ID',
    1  => 'pm.ID',
    2  => 'pm.INSPECTION_MONTH',
    3  => 'pm.WEEK_NO',
    4  => 'pm.WEEK_DATE',
    5  => 'pm.LINE',
    6  => 'pm.MODEL',
    7  => 'pm.PLANT',
    8  => 'verified_emp.EMPLOYEE_NAME',
    9  => 'pm.PM_STATUS',
    10 => 'created_emp.EMPLOYEE_NAME',
    11 => 'pm.CREATED_AT',
    12 => 'pm.UPDATED_AT',
];

$orderColumn = $columnMapping[$orderColumnIndex] ?? 'pm.ID';
$employee_id = $_SESSION['EMP_ID'] ?? '';
$startRow    = $start;
$endRow      = $start + $length;

// Check once if current user is a configured verifier
$is_verifier = Common::is_verifier($employee_id);

// ── Read filters ─────────────────────────────────────────────────────────────
$filter_month      = trim($_POST['filter_month']      ?? '');
$filter_week       = trim($_POST['filter_week']       ?? '');   // new
$filter_plant      = trim($_POST['filter_plant']      ?? '');
$filter_status     = trim($_POST['filter_status']     ?? '');
$filter_created_by = trim($_POST['filter_created_by'] ?? '');
$filter_date_from  = trim($_POST['filter_date_from']  ?? '');
$filter_date_to    = trim($_POST['filter_date_to']    ?? '');
// DataTables global search — used for model name search
$filter_search     = trim($_POST['search']['value']   ?? '');

// ── Build WHERE clause ───────────────────────────────────────────────────────
$whereParts = [];

if ($filter_month !== '') {
    $m = preg_replace('/[^0-9]/', '', $filter_month);
    if (strlen($m) === 2) {
        $whereParts[] = "SUBSTR(pm.INSPECTION_MONTH, 6, 2) = '$m'";
    }
}

// Week filter — exact match on WEEK_NO column
if ($filter_week !== '' && ctype_digit($filter_week) && (int)$filter_week >= 1 && (int)$filter_week <= 5) {
    $whereParts[] = "pm.WEEK_NO = " . (int)$filter_week;
}

if ($filter_plant !== '') {
    $p = str_replace("'", "''", $filter_plant);
    $whereParts[] = "pm.PLANT = '$p'";
}

if ($filter_status !== '') {
    $s = str_replace("'", "''", $filter_status);
    $whereParts[] = "pm.PM_STATUS = '$s'";
}

// Created By — filter by employee ID (exact match on CREATED_BY column)
if ($filter_created_by !== '') {
    $c = str_replace("'", "''", $filter_created_by);
    $whereParts[] = "pm.CREATED_BY = '$c'";
}

if ($filter_date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
    $whereParts[] = "TRUNC(pm.CREATED_AT) >= TO_DATE('$filter_date_from', 'YYYY-MM-DD')";
}

if ($filter_date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
    $whereParts[] = "TRUNC(pm.CREATED_AT) <= TO_DATE('$filter_date_to', 'YYYY-MM-DD')";
}

// Global search: filter by MODEL name (case-insensitive)
if ($filter_search !== '') {
    $s = str_replace("'", "''", strtoupper($filter_search));
    $whereParts[] = "UPPER(pm.MODEL) LIKE '%$s%'";
}

$whereSQL = count($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// ── Paginated query ──────────────────────────────────────────────────────────
$paginated_query = "
    SELECT *
    FROM (
        SELECT
            pm.ID,
            pm.INSPECTION_MONTH,
            pm.WEEK_NO,
            TO_CHAR(pm.WEEK_DATE, 'DD/MM/YYYY') AS WEEK_DATE,
            pm.LINE,
            pm.MODEL,
            pm.PLANT,
            pm.PM_STATUS,
            pm.VERIFIED_BY,
            pm.CREATED_BY,
            TO_CHAR(pm.CREATED_AT, 'DD/MM/YYYY HH24:MI') AS CREATED_AT,
            TO_CHAR(pm.UPDATED_AT, 'DD/MM/YYYY HH24:MI') AS UPDATED_AT,
            verified_emp.EMPLOYEE_NAME AS VERIFIED_BY_NAME,
            created_emp.EMPLOYEE_NAME  AS CREATED_BY_NAME,
            ROW_NUMBER() OVER (ORDER BY $orderColumn $orderDir) AS RN
        FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER pm
        LEFT JOIN EMPLOYEE_MASTER verified_emp ON verified_emp.EMP_ID = pm.VERIFIED_BY
        LEFT JOIN EMPLOYEE_MASTER created_emp  ON created_emp.EMP_ID  = pm.CREATED_BY
        $whereSQL
    )
    WHERE RN BETWEEN :startrow + 1 AND :endrow
    ORDER BY RN
    ";

$stmt = oci_parse($dbcon, $paginated_query);
oci_bind_by_name($stmt, ':startrow', $startRow);
oci_bind_by_name($stmt, ':endrow',   $endRow);
oci_execute($stmt);

// ── Filtered count ───────────────────────────────────────────────────────────
$count_query = "
    SELECT COUNT(*) AS TOTAL_COUNT
    FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER pm
    LEFT JOIN EMPLOYEE_MASTER verified_emp ON verified_emp.EMP_ID = pm.VERIFIED_BY
    LEFT JOIN EMPLOYEE_MASTER created_emp  ON created_emp.EMP_ID  = pm.CREATED_BY
    $whereSQL
";
$count_stmt = oci_parse($dbcon, $count_query);
oci_execute($count_stmt);
oci_fetch($count_stmt);
$filteredRecords = (int) oci_result($count_stmt, 'TOTAL_COUNT');

// ── Total unfiltered count ───────────────────────────────────────────────────
$total_stmt = oci_parse($dbcon, "SELECT COUNT(*) AS TOTAL_COUNT FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER");
oci_execute($total_stmt);
oci_fetch($total_stmt);
$totalRecords = (int) oci_result($total_stmt, 'TOTAL_COUNT');

// ── Build rows ───────────────────────────────────────────────────────────────
$results = [];

while (($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) !== false) {

    $id        = $row['ID'];
    $createdBy = $row['CREATED_BY'];
    $status    = $row['PM_STATUS'] ?? 'Pending Verification';
    $encodedId = base64_encode($id);
    $locked    = in_array($status, ['Verified', 'Rejected']);

    // View — always visible
    $links = '<a href="add.php?mode=view&id=' . $encodedId . '"
                class="btn btn-info btn-sm"
                style="margin-right:4px;color:white;">VIEW</a>';

    // Edit — only creator, only when not locked
    if ($createdBy == $employee_id && !$locked) {
        $links .= '<a href="add.php?mode=edit&id=' . $encodedId . '"
                    class="btn btn-secondary btn-sm"
                    style="margin-right:4px;">EDIT</a>';
    }

    // PDF & Excel — always visible
    $links .= '<a href="pdfGenerate.php?id=' . $encodedId . '"
                target="_blank"
                class="btn btn-danger btn-sm"
                style="margin-right:4px;">PDF</a>';
    $links .= '<a href="excelGenerate.php?id=' . $encodedId . '"
                class="btn btn-success btn-sm"
                style="margin-right:4px;">EXCEL</a>';

    // Verify — only configured verifiers, only when Pending Verification
    if ($is_verifier && $status === 'Pending Verification') {
        $links .= '<a href="add.php?mode=verify&id=' . $encodedId . '"
                    class="btn btn-primary btn-sm">VERIFY</a>';
    }

    $results[] = [
        'ID'               => $id,
        'PM_REF_NO'        => 'PM-' . str_pad($id, 6, '0', STR_PAD_LEFT),
        'INSPECTION_MONTH' => $row['INSPECTION_MONTH'] ?? '-',
        'WEEK_NO'          => $row['WEEK_NO']          ?? '-',   // new
        'WEEK_DATE'        => $row['WEEK_DATE']        ?? '-',   // new
        'LINE'             => $row['LINE']             ?? '-',
        'MODEL'            => $row['MODEL']            ?? '-',
        'PLANT'            => $row['PLANT']            ?? '-',
        'VERIFIED_BY_NAME' => $row['VERIFIED_BY_NAME'] ?? '-',
        'PM_STATUS'        => $status,
        'CREATED_BY_NAME'  => $row['CREATED_BY_NAME']  ?? '-',
        'CREATED_AT'       => $row['CREATED_AT']       ?? '-',
        'UPDATED_AT'       => $row['UPDATED_AT']       ?? '-',
        'ACTION'           => $links,
    ];
}

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $totalRecords,
    'recordsFiltered' => $filteredRecords,
    'data'            => $results,
]);
exit;