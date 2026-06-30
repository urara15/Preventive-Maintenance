<?php
ob_start();
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

$response = [
    'success' => false,
    'message' => 'An unknown error occurred.',
    'errors'  => []
];

// ── Auth check ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    $response['message'] = 'User not authenticated.';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method.';
    echo json_encode($response);
    exit;
}

// ── Valid checklist column keys ──────────────────────────────────────────────
$VALID_COLS = [
    'FO_F1_CMB','FO_F1_CM','FO_F1_CMV',
    'FO_F2_CMB','FO_F2_CMV',
    'FO_GB1_CCGB',
    'CO_F1_CMB','CO_F1_CM','CO_F1_CMV',
    'CO_F2_CMB','CO_F2_CM','CO_F2_CMV',
    'CO_GB2_CCGB',
    'CO_F3_CMB','CO_F3_CM','CO_F3_CMV',
    'CO_F4_CMB','CO_F4_CM','CO_F4_CMV',
    'CO_GB3_CCGB',
    'LO1_F1_CMB','LO1_F1_CM','LO1_F1_CMV',
    'LO1_F2_CMB','LO1_F2_CM','LO1_F2_CMV',
    'LO1_GB4_CCGB',
    'LO1_F3_CMB','LO1_F3_CM','LO1_F3_CMV',
    'LO1_F4_CMB','LO1_F4_CM','LO1_F4_CMV',
    'LO1_GB5_CCGB',
    'LO2_F1_CMB','LO2_F1_CM','LO2_F1_CMV',
    'LO2_F2_CMB','LO2_F2_CM','LO2_F2_CMV',
    'LO2_GB6_CCGB',
    'PO_F1_CMB','PO_F1_CM','PO_F1_CMV',
    'PO_F2_CMB','PO_F2_CM','PO_F2_CMV',
    'PO_GB7_CCGB',
    'WO_F1_CMB','WO_F1_CM','WO_F1_CMV',
    'WO_F2_CMB','WO_F2_CM','WO_F2_CMV',
    'WO_GB8_CCGB',
    'WO_F3_CMB','WO_F3_CM','WO_F3_CMV',
    'WO_F4_CMB','WO_F4_CM','WO_F4_CMV',
    'WO_GB9_CCGB',
    'WO_F5_CMB','WO_F5_CM','WO_F5_CMV',
    'WO_F6_CMB','WO_F6_CM','WO_F6_CMV',
    'WO_GB10_CCGB',
    'WO_F7_CMB','WO_F7_CM','WO_F7_CMV',
    'WO_F8_CMB','WO_F8_CM','WO_F8_CMV',
    'WO_GB11_CCGB',
    'DO_F1_CMB','DO_F1_CM','DO_F1_CMV',
    'DO_F2_CMB','DO_F2_CM','DO_F2_CMV',
    'DO_GB12_CCGB',
    'DO_F3_CMB','DO_F3_CM','DO_F3_CMV',
    'DO_F4_CMB','DO_F4_CM','DO_F4_CMV',
    'DO_GB13_CCGB',
    'DO_F5_CMB','DO_F5_CM','DO_F5_CMV',
    'DO_F6_CMB','DO_F6_CM','DO_F6_CMV',
    'DO_GB14_CCGB',
    'DO_F7_CMB','DO_F7_CM','DO_F7_CMV',
    'DO_F8_CMB','DO_F8_CM','DO_F8_CMV',
    'DO_GB15_CCGB',
    'PLO_F1_CMB','PLO_F1_CM','PLO_F1_CMV',
    'PLO_F2_CMB','PLO_F2_CM','PLO_F2_CMV',
    'PLO_GB16_CCGB',
    'FCO_F1_CMB','FCO_F1_CM','FCO_F1_CMV',
    'FCO_F2_CMB','FCO_F2_CM','FCO_F2_CMV',
    'FCO_GB17_CCGB',
    'FCO_F3_CMB','FCO_F3_CM','FCO_F3_CMV',
    'FCO_F4_CMB','FCO_F4_CM','FCO_F4_CMV',
    'FCO_GB18_CCGB',
];

// ── Sanitize date → 'YYYY-MM-DD' or null ─────────────────────────────────────
function sanitizeDate(?string $raw): ?string {
    if ($raw === null || trim($raw) === '') return null;
    $raw = trim($raw);
    $dt  = DateTime::createFromFormat('Y-m-d', $raw)
        ?: DateTime::createFromFormat('d/m/Y', $raw)
        ?: DateTime::createFromFormat('m/d/Y', $raw)
        ?: null;
    return $dt ? $dt->format('Y-m-d') : null;
}

// ── Sanitize time → 'HH:MM' 24-hour or null ──────────────────────────────────
function sanitizeTime(?string $raw): ?string {
    if ($raw === null || trim($raw) === '') return null;
    $raw = trim($raw);
    $dt  = DateTime::createFromFormat('H:i:s', $raw)
        ?: DateTime::createFromFormat('H:i',   $raw)
        ?: DateTime::createFromFormat('h:i A', strtoupper($raw))
        ?: DateTime::createFromFormat('h:i a', $raw)
        ?: null;
    if ($dt) return $dt->format('H:i');
    if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m)) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }
    return null;
}

// ── Build SQL fragment for date / time bind placeholders ─────────────────────
function dateSql(?string $val, string $ph): string {
    return ($val !== null) ? "TO_DATE({$ph}, 'YYYY-MM-DD')" : 'NULL';
}
function timeSql(?string $val, string $ph): string {
    return ($val !== null) ? "TO_TIMESTAMP({$ph}, 'HH24:MI')" : 'NULL';
}

require_once __DIR__ . '/common.php';

try {
    $mode      = $_POST['mode']      ?? 'add';
    $record_id = intval($_POST['record_id'] ?? 0);
    $emp_id    = $_SESSION['EMP_ID'] ?? '';

    // =========================================
    // ===== VERIFY / REJECT MODE ==============
    // =========================================
    if ($mode === 'verify' || $mode === 'reject') {
        if ($record_id <= 0) throw new Exception('Invalid record ID.');

        if (!Common::is_verifier($emp_id)) {
            $response['message'] = 'You are not authorised to verify or reject this record.';
            echo json_encode($response);
            exit;
        }

        $chk_stmt = oci_parse($dbcon, 'SELECT PM_STATUS FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER WHERE ID = :id');
        if (!$chk_stmt) { $err = oci_error($dbcon); throw new Exception('Parse failed: ' . $err['message']); }
        oci_bind_by_name($chk_stmt, ':id', $record_id);
        oci_execute($chk_stmt, OCI_NO_AUTO_COMMIT);
        $chk_row = oci_fetch_array($chk_stmt, OCI_ASSOC);
        oci_free_statement($chk_stmt);

        if (!$chk_row) {
            $response['message'] = 'Record not found.';
            echo json_encode($response);
            exit;
        }

        $locked = ['Verified', 'Rejected'];
        if (in_array($chk_row['PM_STATUS'], $locked)) {
            $response['message'] = 'This record has already been ' . strtolower($chk_row['PM_STATUS']) . ' and cannot be changed.';
            echo json_encode($response);
            exit;
        }

        if ($mode === 'verify') {
            $new_status = 'Verified';
            $upd_sql    = "UPDATE PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER SET
                                PM_STATUS   = :status,
                                VERIFIED_BY = :verified_by,
                                UPDATED_AT  = SYSDATE
                           WHERE ID = :id";

            $upd_stmt = oci_parse($dbcon, $upd_sql);
            if (!$upd_stmt) { $err = oci_error($dbcon); throw new Exception('Parse failed: ' . $err['message']); }
            oci_bind_by_name($upd_stmt, ':status',      $new_status);
            oci_bind_by_name($upd_stmt, ':verified_by', $emp_id);
            oci_bind_by_name($upd_stmt, ':id',          $record_id);

        } else {
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            if ($rejection_reason === '') {
                $response['message'] = 'Rejection reason is required.';
                echo json_encode($response);
                exit;
            }

            $new_status = 'Rejected';
            $upd_sql    = "UPDATE PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER SET
                                PM_STATUS        = :status,
                                VERIFIED_BY      = :verified_by,
                                REJECTION_REASON = :rejection_reason,
                                UPDATED_AT       = SYSDATE
                           WHERE ID = :id";

            $upd_stmt = oci_parse($dbcon, $upd_sql);
            if (!$upd_stmt) { $err = oci_error($dbcon); throw new Exception('Parse failed: ' . $err['message']); }
            oci_bind_by_name($upd_stmt, ':status',           $new_status);
            oci_bind_by_name($upd_stmt, ':verified_by',      $emp_id);
            oci_bind_by_name($upd_stmt, ':rejection_reason', $rejection_reason);
            oci_bind_by_name($upd_stmt, ':id',               $record_id);
        }

        if (!oci_execute($upd_stmt, OCI_COMMIT_ON_SUCCESS)) {
            $err = oci_error($upd_stmt);
            throw new Exception('Status update failed: ' . $err['message']);
        }
        oci_free_statement($upd_stmt);

        $response['success'] = true;
        $response['message'] = ($mode === 'verify') ? 'Record verified successfully.' : 'Record rejected.';
        echo json_encode($response);
        exit;
    }

    // ── Master fields ────────────────────────────────────────────────────────
    $inspection_month = trim($_POST['inspection_month'] ?? '');
    $line             = trim($_POST['line']             ?? '');
    $model_val        = trim($_POST['model']            ?? '');
    $plant            = trim($_POST['plant']            ?? '');
    $remarks          = trim($_POST['remarks']          ?? '');
    $remarks          = ($remarks === '') ? null : $remarks;
    $week_no          = intval($_POST['week_no'] ?? 0);

    // ── Single week date & time ───────────────────────────────────────────────
    $w_date = sanitizeDate($_POST['week_date'] ?? '');
    $w_time = sanitizeTime($_POST['week_time'] ?? '');

    // ── Checklist items (posted as items[COL_NAME]) ───────────────────────────
    $week_item_data = [];
    foreach ($VALID_COLS as $col) {
        $week_item_data[$col] = null;
    }
    $raw_items = is_array($_POST['items'] ?? null) ? $_POST['items'] : [];
    foreach ($VALID_COLS as $col) {
        if (isset($raw_items[$col]) && $raw_items[$col] === '1') {
            $week_item_data[$col] = 1;
        } elseif (isset($raw_items[$col]) && $raw_items[$col] === '0') {
            $week_item_data[$col] = 0;
        } else {
            $week_item_data[$col] = null;
        }
    }

    // ── Validation ───────────────────────────────────────────────────────────
    if ($inspection_month === '') $response['errors'][] = 'Inspection Month is required.';
    if ($line             === '') $response['errors'][] = 'Line is required.';
    if ($model_val        === '') $response['errors'][] = 'Model is required.';
    if ($plant            === '') $response['errors'][] = 'Plant is required.';
    if ($week_no <= 0)            $response['errors'][] = 'Week is required.';

    // ── Edit ownership check ─────────────────────────────────────────────────
    if ($mode === 'edit' && $record_id > 0) {
        $perm_stmt = oci_parse($dbcon, 'SELECT CREATED_BY FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER WHERE ID = :id');
        oci_bind_by_name($perm_stmt, ':id', $record_id);
        oci_execute($perm_stmt, OCI_NO_AUTO_COMMIT);
        $perm_row = oci_fetch_array($perm_stmt, OCI_ASSOC);
        oci_free_statement($perm_stmt);

        if (!$perm_row) {
            $response['message'] = 'Record not found.';
            echo json_encode($response);
            exit;
        }
        if ((string)$emp_id !== (string)($perm_row['CREATED_BY'] ?? '')) {
            $response['message'] = 'You do not have permission to edit this record.';
            echo json_encode($response);
            exit;
        }
    }

    if (!empty($response['errors'])) {
        $response['message'] = 'Validation failed.';
        echo json_encode($response);
        exit;
    }

    // ── Pre-build SQL fragments for the single week date/time ────────────────
    $d_sql = dateSql($w_date, ':wd');
    $t_sql = timeSql($w_time, ':wt');

    // ════════════════════════════════════════════════════════════════════════
    // INSERT — add mode
    // ════════════════════════════════════════════════════════════════════════
    if ($mode === 'add') {

        // ── Duplicate check ───────────────────────────────────────────────────
        // A conflict exists when another non-Rejected master record for the same
        // month/line/model/plant already has data for THIS week_no — either a
        // WEEK_DATE stored in the master row OR item rows in the items table.
        // ─────────────────────────────────────────────────────────────────────
        $null_checks = implode(' IS NOT NULL OR ', array_map(fn($c) => 'i.' . $c, $VALID_COLS)) . ' IS NOT NULL';

        $dupSql = "
            SELECT COUNT(1) AS CNT
            FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER m
            WHERE UPPER(TRIM(m.INSPECTION_MONTH)) = UPPER(TRIM(:inspection_month))
              AND UPPER(TRIM(m.LINE))             = UPPER(TRIM(:line))
              AND UPPER(TRIM(m.MODEL))            = UPPER(TRIM(:model_val))
              AND UPPER(TRIM(m.PLANT))            = UPPER(TRIM(:plant))
              AND m.WEEK_NO                       = :target_week
              AND NVL(m.PM_STATUS, 'Pending Verification') <> 'Rejected'
              AND (
                  m.WEEK_DATE IS NOT NULL
                  OR EXISTS (
                      SELECT 1
                      FROM PRODUCTION.PREVENTIVE_MAINTENANCE_ITEMS i
                      WHERE i.MASTER_ID = m.ID
                        AND i.WEEK_NO   = :target_week_items
                        AND ({$null_checks})
                  )
              )
        ";

        $dupSt = oci_parse($dbcon, $dupSql);
        if (!$dupSt) { $err = oci_error($dbcon); throw new Exception('Dup check parse failed: ' . $err['message']); }

        oci_bind_by_name($dupSt, ':inspection_month',  $inspection_month);
        oci_bind_by_name($dupSt, ':line',              $line);
        oci_bind_by_name($dupSt, ':model_val',         $model_val);
        oci_bind_by_name($dupSt, ':plant',             $plant);
        oci_bind_by_name($dupSt, ':target_week',       $week_no, -1, SQLT_INT);
        oci_bind_by_name($dupSt, ':target_week_items', $week_no, -1, SQLT_INT);

        oci_execute($dupSt, OCI_NO_AUTO_COMMIT);
        $dupRow = oci_fetch_array($dupSt, OCI_ASSOC);
        oci_free_statement($dupSt);

        if ($dupRow && (int)$dupRow['CNT'] > 0) {
            $response['success'] = false;
            $response['message'] = "Submission Denied: A Preventive Maintenance log already contains data for Week {$week_no} under the selected Line / Model / Plant / Month combination. Please switch to Edit mode to modify that record.";
            echo json_encode($response);
            exit;
        }

        // ── Insert master row ─────────────────────────────────────────────────
        $ins_master = "
            INSERT INTO PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER
                (INSPECTION_MONTH, LINE, MODEL, PLANT,
                 WEEK_NO, WEEK_DATE, WEEK_TIME,
                 REMARKS, PM_STATUS,
                 CREATED_BY, CREATED_AT, UPDATED_AT)
            VALUES
                (:inspection_month, :line, :model_val, :plant,
                 :week_no_val, {$d_sql}, {$t_sql},
                 :remarks, 'Pending Verification',
                 :created_by, SYSDATE, SYSDATE)
            RETURNING ID INTO :new_id
        ";

        $stmt = oci_parse($dbcon, $ins_master);
        if (!$stmt) { $err = oci_error($dbcon); throw new Exception('Master parse failed: ' . $err['message']); }

        oci_bind_by_name($stmt, ':inspection_month', $inspection_month);
        oci_bind_by_name($stmt, ':line',             $line);
        oci_bind_by_name($stmt, ':model_val',        $model_val);
        oci_bind_by_name($stmt, ':plant',            $plant);
        oci_bind_by_name($stmt, ':week_no_val',      $week_no, -1, SQLT_INT);
        oci_bind_by_name($stmt, ':remarks',          $remarks, -1, SQLT_CHR);
        oci_bind_by_name($stmt, ':created_by',       $emp_id);
        oci_bind_by_name($stmt, ':new_id',           $master_id, -1, SQLT_INT);

        if ($w_date !== null) oci_bind_by_name($stmt, ':wd', $w_date);
        if ($w_time !== null) oci_bind_by_name($stmt, ':wt', $w_time);

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmt);
            throw new Exception('Master insert failed: ' . $err['message']);
        }
        oci_free_statement($stmt);

    // ════════════════════════════════════════════════════════════════════════
    // UPDATE — edit mode
    // ════════════════════════════════════════════════════════════════════════
    } else {
        $master_id = $record_id;
        if ($master_id <= 0) throw new Exception('Invalid master ID for update.');

        $upd_master = "
            UPDATE PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER SET
                INSPECTION_MONTH = :inspection_month,
                LINE             = :line,
                MODEL            = :model_val,
                PLANT            = :plant,
                WEEK_DATE        = {$d_sql},
                WEEK_TIME        = {$t_sql},
                REMARKS          = :remarks,
                UPDATED_AT       = SYSDATE
            WHERE ID = :master_id
        ";

        $stmt = oci_parse($dbcon, $upd_master);
        if (!$stmt) { $err = oci_error($dbcon); throw new Exception('Master update parse failed: ' . $err['message']); }

        oci_bind_by_name($stmt, ':inspection_month', $inspection_month);
        oci_bind_by_name($stmt, ':line',             $line);
        oci_bind_by_name($stmt, ':model_val',        $model_val);
        oci_bind_by_name($stmt, ':plant',            $plant);
        oci_bind_by_name($stmt, ':remarks',          $remarks, -1, SQLT_CHR);
        oci_bind_by_name($stmt, ':master_id',        $master_id);

        if ($w_date !== null) oci_bind_by_name($stmt, ':wd', $w_date);
        if ($w_time !== null) oci_bind_by_name($stmt, ':wt', $w_time);

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmt);
            throw new Exception('Master update failed: ' . $err['message']);
        }
        oci_free_statement($stmt);

        // Clear existing item rows for this week only, then rewrite them
        $del_stmt = oci_parse($dbcon,
            'DELETE FROM PRODUCTION.PREVENTIVE_MAINTENANCE_ITEMS WHERE MASTER_ID = :master_id AND WEEK_NO = :week_no'
        );
        if (!$del_stmt) { $err = oci_error($dbcon); throw new Exception('Items delete parse failed: ' . $err['message']); }
        oci_bind_by_name($del_stmt, ':master_id', $master_id);
        oci_bind_by_name($del_stmt, ':week_no',   $week_no, -1, SQLT_INT);
        if (!oci_execute($del_stmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($del_stmt);
            throw new Exception('Items delete failed: ' . $err['message']);
        }
        oci_free_statement($del_stmt);
    }

    // ════════════════════════════════════════════════════════════════════════
    // INSERT checklist item row for the selected week
    // ════════════════════════════════════════════════════════════════════════
    $col_names_sql = 'WEEK_NO, ' . implode(', ', $VALID_COLS);
    $col_binds_sql = ':b_week_no, ' . implode(', ', array_map(fn($c) => ':b_' . strtolower($c), $VALID_COLS));

    $ins_item_sql = "
        INSERT INTO PRODUCTION.PREVENTIVE_MAINTENANCE_ITEMS
            (MASTER_ID, {$col_names_sql})
        VALUES
            (:b_master_id, {$col_binds_sql})
    ";

    $stmt = oci_parse($dbcon, $ins_item_sql);
    if (!$stmt) {
        $err = oci_error($dbcon);
        throw new Exception("Week {$week_no} items parse failed: " . $err['message']);
    }

    $b_master_id = (int)$master_id;
    $b_week_no   = (int)$week_no;

    oci_bind_by_name($stmt, ':b_master_id', $b_master_id, -1, SQLT_INT);
    oci_bind_by_name($stmt, ':b_week_no',   $b_week_no,   -1, SQLT_INT);

    $bind_vars = [];
    foreach ($VALID_COLS as $col) {
        $key = strtolower($col);
        $val = $week_item_data[$col] ?? null;

        if ($val === 1) {
            $bind_vars[$key] = 1;
            oci_bind_by_name($stmt, ':b_' . $key, $bind_vars[$key], -1, SQLT_INT);
        } elseif ($val === 0) {
            $bind_vars[$key] = 0;
            oci_bind_by_name($stmt, ':b_' . $key, $bind_vars[$key], -1, SQLT_INT);
        } else {
            $bind_vars[$key] = null;
            oci_bind_by_name($stmt, ':b_' . $key, $bind_vars[$key]);
        }
    }

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmt);
        throw new Exception("Week {$week_no} items insert failed: " . $err['message']);
    }
    oci_free_statement($stmt);

    // ── Commit transaction ────────────────────────────────────────────────────
    oci_commit($dbcon);

    $response['success']   = true;
    $response['master_id'] = $master_id;
    $response['message']   = ($mode === 'add')
        ? 'Preventive Maintenance record added successfully.'
        : 'Preventive Maintenance record updated successfully.';

    echo json_encode($response);
    exit;

} catch (Exception $e) {
    if (isset($dbcon)) { oci_rollback($dbcon); }
    ob_clean();
    $response['message'] = $e->getMessage();
    echo json_encode($response);
    exit;
}