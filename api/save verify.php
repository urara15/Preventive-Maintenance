<?php
ob_start();
session_start();

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';
require_once __DIR__ . '/common.php';

$response = ['success' => false, 'message' => 'Error occurred'];

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$empId    = trim((string) ($_SESSION['EMP_ID'] ?? ''));
$recordId = intval($_POST['record_id'] ?? 0);
$action   = strtolower(trim((string) ($_POST['action'] ?? '')));
$reason   = trim((string) ($_POST['reject_reason'] ?? ''));

// ── Basic input validation ────────────────────────────────────────────────────
if (!$recordId || !in_array($action, ['verify', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit;
}

if ($action === 'reject' && $reason === '') {
    echo json_encode(['success' => false, 'message' => 'Reject reason is required']);
    exit;
}

// ── Permission check: emp must be listed in CYCLE_TIMER_CONFIG.APPROVED_BY ───
$approvedByCsv = Common::pm_get_verified_by_csv($dbcon);
if (!Common::pm_emp_in_config_csv($empId, $approvedByCsv)) {
    echo json_encode(['success' => false, 'message' => 'You are not authorised to approve this record']);
    exit;
}

// ── Status gate: only Pending Approval records can be acted on ────────────────
$chkSt = oci_parse($dbcon, 'SELECT PM_STATUS FROM PREVENTIVE_MAINTENANCE_MASTER WHERE ID = :id');
oci_bind_by_name($chkSt, ':id', $recordId);
oci_execute($chkSt);
$chkRow       = oci_fetch_assoc($chkSt);
$liveStatus   = strtolower(trim((string) ($chkRow['PM_STATUS'] ?? '')));

if ($liveStatus !== 'pending approval') {
    echo json_encode(['success' => false, 'message' => 'This record is no longer pending approval']);
    exit;
}

// ── Update record ─────────────────────────────────────────────────────────────
$newStatus  = ($action === 'verified') ? 'Verified' : 'Rejected';
$rejectBind = ($action === 'reject')  ? $reason    : null;

$sql = "UPDATE PREVENTIVE_MAINTENANCE_MASTER SET
            PM_STATUS         = :status,
            VERIFIED_BY              = :verified_by,
            CREATED_BY            = :created_by,
            CREATED_AT               = SYSDATE,
            UPDATED_AT               = SYSDATE
        WHERE ID = :id";

$st = oci_parse($dbcon, $sql);
oci_bind_by_name($st, ':status',      $newStatus);
oci_bind_by_name($st, ':verified_by', $empId);
oci_bind_by_name($st, ':reason',      $rejectBind);
oci_bind_by_name($st, ':updated_by',  $empId);
oci_bind_by_name($st, ':id',          $recordId);

if (oci_execute($st) && oci_commit($dbcon)) {
    $response = [
        'success' => true,
        'message' => ($action === 'verify') ? 'Record verified successfully' : 'Record rejected successfully',
    ];
} else {
    $e = oci_error($st);
    $response['message'] = !empty($e['message']) ? $e['message'] : 'Database error occurred';
}

echo json_encode($response);
exit;
