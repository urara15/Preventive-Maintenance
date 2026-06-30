<?php
ob_start();
session_start();

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';
require_once 'common.php';

$response = ['success' => false, 'message' => 'Error occurred'];

if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    $response['message'] = 'User not authenticated';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit;
}

$record_id  = intval($_POST['record_id'] ?? 0);
$action     = trim($_POST['action']      ?? '');   // 'verify', 'verify_reject', 'approve', 'reject'
$remarks    = trim($_POST['remarks']     ?? '');
$emp_id     = $_SESSION['EMP_ID'] ?? '';

if (!$record_id || !$action) {
    $response['message'] = 'Missing required parameters';
    echo json_encode($response);
    exit;
}

// ── Fetch current record status ───────────────────────────────────────────────
$sql_check = "SELECT EQUIPMENT_STATUS FROM CYCLE_TIMER_CALIBRATION WHERE ID = :id";
$stmt_check = oci_parse($dbcon, $sql_check);
oci_bind_by_name($stmt_check, ":id", $record_id);
oci_execute($stmt_check);
$row_check = oci_fetch_array($stmt_check, OCI_ASSOC + OCI_RETURN_NULLS);
oci_free_statement($stmt_check);

if (!$row_check) {
    $response['message'] = 'Record not found';
    echo json_encode($response);
    exit;
}

$current_status = $row_check['EQUIPMENT_STATUS'] ?? '';

// ── Route: VERIFY actions ────────────────────────────────────────────────────
if (in_array($action, ['verify', 'verify_reject'])) {

    // Must be a configured verifier
    if (!Common::is_verifier($emp_id)) {
        $response['message'] = 'You are not authorised to verify records.';
        echo json_encode($response);
        exit;
    }

    // Must be in Pending Verification state
    if ($current_status !== 'Pending Verification') {
        $response['message'] = 'This record is not pending verification. Current status: ' . $current_status;
        echo json_encode($response);
        exit;
    }

    // Reject requires remarks
    if ($action === 'verify_reject' && $remarks === '') {
        $response['message'] = 'Please enter remarks before rejecting.';
        echo json_encode($response);
        exit;
    }

    $new_status = ($action === 'verify') ? 'Pending Approval' : 'Rejected';

    $sql_update = "
        UPDATE CYCLE_TIMER_CALIBRATION SET
            EQUIPMENT_STATUS          = :new_status,
            VERIFICATION_ACTION_BY    = :action_by,
            VERIFICATION_ACTION_AT    = SYSDATE,
            VERIFICATION_REMARKS      = :remarks
        WHERE ID = :id
          AND EQUIPMENT_STATUS = 'Pending Verification'
    ";

    $stmt = oci_parse($dbcon, $sql_update);
    oci_bind_by_name($stmt, ":new_status", $new_status);
    oci_bind_by_name($stmt, ":action_by",  $emp_id);
    oci_bind_by_name($stmt, ":remarks",    $remarks);
    oci_bind_by_name($stmt, ":id",         $record_id);

    $ok = oci_execute($stmt);
    oci_free_statement($stmt);

    if ($ok) {
        $msg = ($action === 'verify')
            ? 'Record verified successfully. Now pending approval.'
            : 'Record rejected at verification stage.';
        $response['success'] = true;
        $response['message'] = $msg;
    } else {
        $err = oci_error($stmt);
        $response['message'] = 'Database error: ' . ($err['message'] ?? 'Unknown error');
    }

    echo json_encode($response);
    exit;
}

// ── Route: APPROVE actions ───────────────────────────────────────────────────
if (in_array($action, ['approve', 'reject'])) {

    // Must be a configured approver
    if (!Common::is_approver($emp_id)) {
        $response['message'] = 'You are not authorised to approve records.';
        echo json_encode($response);
        exit;
    }

    // Must be in Pending Approval state
    if ($current_status !== 'Pending Approval') {
        $response['message'] = 'This record is not pending approval. Current status: ' . $current_status;
        echo json_encode($response);
        exit;
    }

    // Reject requires remarks
    if ($action === 'reject' && $remarks === '') {
        $response['message'] = 'Please enter remarks before rejecting.';
        echo json_encode($response);
        exit;
    }

    $new_status = ($action === 'approve') ? 'Approved' : 'Rejected';

    $sql_update = "
        UPDATE CYCLE_TIMER_CALIBRATION SET
            EQUIPMENT_STATUS      = :new_status,
            APPROVAL_ACTION_BY    = :action_by,
            APPROVAL_ACTION_AT    = SYSDATE,
            APPROVAL_REMARKS      = :remarks
        WHERE ID = :id
          AND EQUIPMENT_STATUS = 'Pending Approval'
    ";

    $stmt = oci_parse($dbcon, $sql_update);
    oci_bind_by_name($stmt, ":new_status", $new_status);
    oci_bind_by_name($stmt, ":action_by",  $emp_id);
    oci_bind_by_name($stmt, ":remarks",    $remarks);
    oci_bind_by_name($stmt, ":id",         $record_id);

    $ok = oci_execute($stmt);
    oci_free_statement($stmt);

    if ($ok) {
        $msg = ($action === 'approve')
            ? 'Record approved successfully.'
            : 'Record rejected.';
        $response['success'] = true;
        $response['message'] = $msg;
    } else {
        $err = oci_error($stmt);
        $response['message'] = 'Database error: ' . ($err['message'] ?? 'Unknown error');
    }

    echo json_encode($response);
    exit;
}

// Unknown action
$response['message'] = 'Unknown action: ' . htmlspecialchars($action);
echo json_encode($response);
exit;