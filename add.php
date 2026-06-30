<?php
ob_start();
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';

if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    header("Location:" . BASE_URL . "/Dashboard/Authenticate/login.php?gotourl=" . base64_encode(BASE_URL . "/preventive_maintenance/"));
    die;
}

require_once 'api/common.php';

// Determine mode: add, edit, view, verify
$mode   = isset($_GET['mode']) ? $_GET['mode'] : 'add';
$record = [];
$items  = [];  // flat col => value for the selected week

if (in_array($mode, ['edit', 'view', 'verify']) && isset($_GET['id'])) {
    $id     = intval(base64_decode($_GET['id']));
    $record = Common::view_checklist_by_id($id);

    if (empty($record)) {
        header("Location:" . BASE_URL . "/preventive_maintenance/?msg=" . urlencode("Record not found."));
        die;
    }

    if ($mode == 'edit') {
        $current_user_id = $_SESSION['EMP_ID'] ?? '';
        $created_by      = $record['CREATED_BY'] ?? '';
        if ($current_user_id != $created_by) {
            header("Location:" . BASE_URL . "/preventive_maintenance/?msg=" . urlencode("You do not have permission to edit this record."));
            die;
        }
    }

    if ($mode == 'verify') {
        $current_user_id = $_SESSION['EMP_ID'] ?? '';
        if (!Common::is_verifier($current_user_id)) {
            header("Location:" . BASE_URL . "/preventive_maintenance/?msg=" . urlencode("You are not authorised to verify records."));
            die;
        }
        $current_status = $record['PM_STATUS'] ?? '';
        if (!in_array($current_status, ['Pending Verification'])) {
            header("Location:" . BASE_URL . "/preventive_maintenance/?msg=" . urlencode("This record has already been " . strtolower($current_status) . "."));
            die;
        }
    }

    // WEEK_NO is now a direct column on the master row — no scanning needed.
    $items = $record['week_items'][(int) ($record['WEEK_NO'] ?? 0)] ?? [];
}

// Plant options
$plant_options  = ['GP', 'GPL', 'YTA', 'YTL'];
$selected_plant = $record['PLANT'] ?? '';

$is_readonly = ($mode === 'view' || $mode === 'verify');
$readonly    = $is_readonly ? 'readonly' : '';
$disabled    = $is_readonly ? 'disabled' : '';

$week_no_val   = $record['WEEK_NO']   ?? '';
$week_date_val = $record['WEEK_DATE'] ?? '';
$week_time_val = $record['WEEK_TIME'] ?? '';

// Oven structure
$oven_structure = [
    ['no'=>1,  'oven'=>'Former Oven',       'prefix'=>'FO_',  'components'=>[
        ['name'=>'Former Oven Fan 1',         'items'=>[['FO_F1_CMB','Check Motor Bearing'],['FO_F1_CM','Clean Motor'],['FO_F1_CMV','Check Motor Ventilation']]],
        ['name'=>'Former Oven Fan 2',         'items'=>[['FO_F2_CMB','Check Motor Bearing'],['FO_F2_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 1',              'items'=>[['FO_GB1_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
    ]],
    ['no'=>2,  'oven'=>'Coagulant Oven',    'prefix'=>'CO_',  'components'=>[
        ['name'=>'Coagulant Oven Fan 1',      'items'=>[['CO_F1_CMB','Check Motor Bearing'],['CO_F1_CM','Clean Motor'],['CO_F1_CMV','Check Motor Ventilation']]],
        ['name'=>'Coagulant Oven Fan 2',      'items'=>[['CO_F2_CMB','Check Motor Bearing'],['CO_F2_CM','Clean Motor'],['CO_F2_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 2',              'items'=>[['CO_GB2_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
        ['name'=>'Coagulant Oven Fan 3',      'items'=>[['CO_F3_CMB','Check Motor Bearing'],['CO_F3_CM','Clean Motor'],['CO_F3_CMV','Check Motor Ventilation']]],
        ['name'=>'Coagulant Oven Fan 4',      'items'=>[['CO_F4_CMB','Check Motor Bearing'],['CO_F4_CM','Clean Motor'],['CO_F4_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 3',              'items'=>[['CO_GB3_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
    ]],
    ['no'=>3,  'oven'=>'Latex Oven 1',      'prefix'=>'LO1_', 'components'=>[
        ['name'=>'Latex Oven 1 Fan 1',        'items'=>[['LO1_F1_CMB','Check Motor Bearing'],['LO1_F1_CM','Clean Motor'],['LO1_F1_CMV','Check Motor Ventilation']]],
        ['name'=>'Latex Oven 1 Fan 2',        'items'=>[['LO1_F2_CMB','Check Motor Bearing'],['LO1_F2_CM','Clean Motor'],['LO1_F2_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 4',              'items'=>[['LO1_GB4_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
        ['name'=>'Latex Oven 1 Fan 3',        'items'=>[['LO1_F3_CMB','Check Motor Bearing'],['LO1_F3_CM','Clean Motor'],['LO1_F3_CMV','Check Motor Ventilation']]],
        ['name'=>'Latex Oven 1 Fan 4',        'items'=>[['LO1_F4_CMB','Check Motor Bearing'],['LO1_F4_CM','Clean Motor'],['LO1_F4_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 5',              'items'=>[['LO1_GB5_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
    ]],
    ['no'=>4,  'oven'=>'Latex Oven 2',      'prefix'=>'LO2_', 'components'=>[
        ['name'=>'Latex Oven 2 Fan 1',        'items'=>[['LO2_F1_CMB','Check Motor Bearing'],['LO2_F1_CM','Clean Motor'],['LO2_F1_CMV','Check Motor Ventilation']]],
        ['name'=>'Latex Oven 2 Fan 2',        'items'=>[['LO2_F2_CMB','Check Motor Bearing'],['LO2_F2_CM','Clean Motor'],['LO2_F2_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 6',              'items'=>[['LO2_GB6_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
    ]],
    ['no'=>5,  'oven'=>'Pre Leach Oven',    'prefix'=>'PO_',  'components'=>[
        ['name'=>'Pre Leach Oven Fan 1',      'items'=>[['PO_F1_CMB','Check Motor Bearing'],['PO_F1_CM','Clean Motor'],['PO_F1_CMV','Check Motor Ventilation']]],
        ['name'=>'Pre Leach Oven Fan 2',      'items'=>[['PO_F2_CMB','Check Motor Bearing'],['PO_F2_CM','Clean Motor'],['PO_F2_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 7',              'items'=>[['PO_GB7_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
    ]],
    ['no'=>7,  'oven'=>'Wet Oven',          'prefix'=>'WO_',  'components'=>[
        ['name'=>'Wet Oven Fan 1',            'items'=>[['WO_F1_CMB','Check Motor Bearing'],['WO_F1_CM','Clean Motor'],['WO_F1_CMV','Check Motor Ventilation']]],
        ['name'=>'Wet Oven Fan 2',            'items'=>[['WO_F2_CMB','Check Motor Bearing'],['WO_F2_CM','Clean Motor'],['WO_F2_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 8',              'items'=>[['WO_GB8_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
        ['name'=>'Wet Oven Fan 3',            'items'=>[['WO_F3_CMB','Check Motor Bearing'],['WO_F3_CM','Clean Motor'],['WO_F3_CMV','Check Motor Ventilation']]],
        ['name'=>'Wet Oven Fan 4',            'items'=>[['WO_F4_CMB','Check Motor Bearing'],['WO_F4_CM','Clean Motor'],['WO_F4_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 9',              'items'=>[['WO_GB9_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
        ['name'=>'Wet Oven Fan 5',            'items'=>[['WO_F5_CMB','Check Motor Bearing'],['WO_F5_CM','Clean Motor'],['WO_F5_CMV','Check Motor Ventilation']]],
        ['name'=>'Wet Oven Fan 6',            'items'=>[['WO_F6_CMB','Check Motor Bearing'],['WO_F6_CM','Clean Motor'],['WO_F6_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 10',             'items'=>[['WO_GB10_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
        ['name'=>'Wet Oven Fan 7',            'items'=>[['WO_F7_CMB','Check Motor Bearing'],['WO_F7_CM','Clean Motor'],['WO_F7_CMV','Check Motor Ventilation']]],
        ['name'=>'Wet Oven Fan 8',            'items'=>[['WO_F8_CMB','Check Motor Bearing'],['WO_F8_CM','Clean Motor'],['WO_F8_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 11',             'items'=>[['WO_GB11_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
    ]],
    ['no'=>8,  'oven'=>'Dry Oven',          'prefix'=>'DO_',  'components'=>[
        ['name'=>'Dry Oven Fan 1',            'items'=>[['DO_F1_CMB','Check Motor Bearing'],['DO_F1_CM','Clean Motor'],['DO_F1_CMV','Check Motor Ventilation']]],
        ['name'=>'Dry Oven Fan 2',            'items'=>[['DO_F2_CMB','Check Motor Bearing'],['DO_F2_CM','Clean Motor'],['DO_F2_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 12',             'items'=>[['DO_GB12_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
        ['name'=>'Dry Oven Fan 3',            'items'=>[['DO_F3_CMB','Check Motor Bearing'],['DO_F3_CM','Clean Motor'],['DO_F3_CMV','Check Motor Ventilation']]],
        ['name'=>'Dry Oven Fan 4',            'items'=>[['DO_F4_CMB','Check Motor Bearing'],['DO_F4_CM','Clean Motor'],['DO_F4_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 13',             'items'=>[['DO_GB13_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
        ['name'=>'Dry Oven Fan 5',            'items'=>[['DO_F5_CMB','Check Motor Bearing'],['DO_F5_CM','Clean Motor'],['DO_F5_CMV','Check Motor Ventilation']]],
        ['name'=>'Dry Oven Fan 6',            'items'=>[['DO_F6_CMB','Check Motor Bearing'],['DO_F6_CM','Clean Motor'],['DO_F6_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 14',             'items'=>[['DO_GB14_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
        ['name'=>'Dry Oven Fan 7',            'items'=>[['DO_F7_CMB','Check Motor Bearing'],['DO_F7_CM','Clean Motor'],['DO_F7_CMV','Check Motor Ventilation']]],
        ['name'=>'Dry Oven Fan 8',            'items'=>[['DO_F8_CMB','Check Motor Bearing'],['DO_F8_CM','Clean Motor'],['DO_F8_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 15',             'items'=>[['DO_GB15_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
    ]],
    ['no'=>9,  'oven'=>'Post Leach Oven',   'prefix'=>'PLO_', 'components'=>[
        ['name'=>'Post Leach Oven Fan 1',     'items'=>[['PLO_F1_CMB','Check Motor Bearing'],['PLO_F1_CM','Clean Motor'],['PLO_F1_CMV','Check Motor Ventilation']]],
        ['name'=>'Post Leach Oven Fan 2',     'items'=>[['PLO_F2_CMB','Check Motor Bearing'],['PLO_F2_CM','Clean Motor'],['PLO_F2_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 16',             'items'=>[['PLO_GB16_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
    ]],
    ['no'=>10, 'oven'=>'Final Curing Oven', 'prefix'=>'FCO_', 'components'=>[
        ['name'=>'Final Curing Oven Fan 1',   'items'=>[['FCO_F1_CMB','Check Motor Bearing'],['FCO_F1_CM','Clean Motor'],['FCO_F1_CMV','Check Motor Ventilation']]],
        ['name'=>'Final Curing Oven Fan 2',   'items'=>[['FCO_F2_CMB','Check Motor Bearing'],['FCO_F2_CM','Clean Motor'],['FCO_F2_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 17',             'items'=>[['FCO_GB17_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
        ['name'=>'Final Curing Oven Fan 3',   'items'=>[['FCO_F3_CMB','Check Motor Bearing'],['FCO_F3_CM','Clean Motor'],['FCO_F3_CMV','Check Motor Ventilation']]],
        ['name'=>'Final Curing Oven Fan 4',   'items'=>[['FCO_F4_CMB','Check Motor Bearing'],['FCO_F4_CM','Clean Motor'],['FCO_F4_CMV','Check Motor Ventilation']]],
        ['name'=>'Gas Burner 18',             'items'=>[['FCO_GB18_CCGB','Check &amp; Clean Gas Burner']],'gb'=>true],
    ]],
];

// Pre-count total tasks
$total_all = 0;
foreach ($oven_structure as $ov) {
    foreach ($ov['components'] as $comp) {
        $total_all += count($comp['items']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>GRAND TEN – <?= strtoupper($mode) ?> PREVENTIVE MAINTENANCE</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=1">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/all.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/toastr.min.css">
    <style>
        /* ── Legend ── */
        .legend-wrap { display:flex; gap:12px; align-items:center; margin-bottom:14px; font-size:13px; flex-wrap:wrap; }
        .legend-item { display:flex; align-items:center; gap:6px; }
        .legend-badge { display:inline-block; width:28px; height:28px; line-height:28px;
                        text-align:center; border-radius:4px; font-weight:700; font-size:14px; }
        .badge-ok    { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .badge-fault { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

        /* ── Week indicator pill ── */
        .week-pill {
            display:inline-flex; align-items:center; gap:8px;
            background:#1f3864; color:#fff;
            border-radius:20px; padding:5px 16px;
            font-size:13px; font-weight:600;
            margin-bottom:14px;
        }
        .week-pill .wp-label { opacity:.75; font-weight:400; }

        /* ── Toggle buttons ── */
        .chk-btn {
            width:36px; height:36px; border-radius:4px; border:1px solid #ced4da;
            background:#f8f9fa; font-size:17px; line-height:1; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            transition:background .15s, color .15s; flex-shrink:0;
        }
        .chk-btn.ok    { background:#d4edda; border-color:#c3e6cb; color:#155724; }
        .chk-btn.fault { background:#f8d7da; border-color:#f5c6cb; color:#721c24; }
        .chk-btn:disabled { cursor:default; opacity:.85; }

        /* ── Oven sections ── */
        .pm-oven-sections { display:flex; flex-direction:column; gap:10px; margin-bottom:20px; }

        .pm-section { border:1px solid #dee2e6; border-radius:8px; overflow:hidden; }

        .pm-section-header {
            background:#1f3864; color:#fff;
            padding:10px 16px;
            display:flex; align-items:center; gap:10px;
            cursor:pointer; user-select:none;
        }
        .pm-section-header .ov-no {
            background:rgba(255,255,255,.2); border-radius:50%;
            width:26px; height:26px; flex-shrink:0;
            display:flex; align-items:center; justify-content:center;
            font-size:12px; font-weight:600;
        }
        .pm-section-header .ov-name { font-weight:700; font-size:14px; flex:1; }
        .pm-section-header .ov-badge {
            font-size:11px; background:rgba(255,255,255,.15);
            border-radius:20px; padding:2px 10px; white-space:nowrap;
        }
        .pm-section-header .ov-badge.complete { background:rgba(40,167,69,.5); }
        .pm-section-header .chevron { font-size:11px; transition:transform .2s; flex-shrink:0; }
        .pm-section-header.open .chevron { transform:rotate(180deg); }

        .pm-body { display:none; padding:12px; background:#fff; }
        .pm-body.open { display:block; }

        /* ── Component blocks ── */
        .comp-block { margin-bottom:10px; border:1px solid #dee2e6; border-radius:6px; overflow:hidden; }
        .comp-block:last-child { margin-bottom:0; }

        .comp-name { background:#dce6f1; color:#1f3864; padding:6px 12px; font-size:12px; font-weight:600; }
        .comp-name.gb { background:#e2efda; color:#375623; }

        /* ── Task rows ── */
        .task-row {
            display:flex; align-items:center; justify-content:space-between;
            padding:8px 12px; border-top:1px solid #f0f0f0; background:#fff; gap:10px;
        }
        .task-row.gb { background:#f6fbf3; }
        .task-label { font-size:12px; color:#333; flex:1; }

        /* ── Overall progress bar ── */
        .overall-progress { margin-bottom:16px; }
        .overall-progress .prog-label {
            display:flex; justify-content:space-between;
            font-size:12px; color:#666; margin-bottom:4px;
        }
        .prog-bar { height:6px; border-radius:3px; background:#e9ecef; overflow:hidden; }
        .prog-fill { height:100%; border-radius:3px; background:#28a745; transition:width .3s ease; }

        /* ── Misc ── */
        .error-border { border:1px solid red !important; }
        label.error   { color:red; font-size:12px; margin-top:3px; display:block; }

        #loading-overlay {
            position:fixed; top:0; left:0; width:100vw; height:100vh;
            background:rgba(0,0,0,.5); z-index:9999;
            display:none; justify-content:center; align-items:center;
        }
        .loader {
            border:12px solid #f3f3f3; border-top:12px solid #3498db;
            border-radius:50%; width:80px; height:80px;
            animation:spin 1s linear infinite;
        }
        @keyframes spin { to { transform:rotate(360deg); } }

        @media (max-width:575.98px) { .header-fields .col-6 { margin-bottom:8px; } }
    </style>
</head>
<body>
<div class="wrapper">

    <!-- Header -->
    <header class="main-header-top hidden-print">
        <a href="<?= BASE_URL ?>/preventive_maintenance/" class="logo">
            <img class="img-fluid able-logo" src="assets/images/yty_banner2.svg" alt="logo">
        </a>
        <nav class="navbar navbar-static-top">
            <div class="navbar-custom-menu f-right">
                <ul class="top-nav">
                    <li class="dropdown">
                        <span id="time"></span>
                        <span><b><?= ucwords(strtolower($_SESSION['EMP_NAME'])) ?></b></span>
                        <a href="#!" data-toggle="dropdown" class="dropdown-toggle drop icon-circle drop-image">
                            <span><img id="main_profile" class="img-circle" src="assets/images/profile.svg" alt="User Image"></span>
                        </a>
                        <ul class="dropdown-menu settings-menu">
                            <li class="border-top-menu">
                                <a href="pages/logout.php">
                                    <img src="assets/images/menu_logout.svg" class="side-icon"> Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>
    </header>

    <div id="loading-overlay"><div class="loader"></div></div>

    <br><br>
    <div id="content-container" class="container-fluid content-wrapper">
        <div class="card m-a-2">
            <div class="card-block p-3">

                <!-- Form title -->
                <div class="row mb-2 align-items-center">
                    <div class="col-8" style="padding-left:20px;">
                        <h5 class="mb-0">PREVENTIVE MAINTENANCE – <?= strtoupper($mode) ?></h5>
                        <small class="text-muted">Oven Blower Motor &amp; Gas Burner Checklist</small>
                    </div>
                    <div class="col-4 text-end" style="padding-left:20px;">
                        <img src="assets/images/grandten_logo.png" alt="logo" style="max-height:50px;">
                    </div>
                </div>
                <hr>

                <?php if ($mode === 'verify' && !empty($record['REJECTION_REASON'])): ?>
                <div class="alert alert-warning">
                    <strong>Previous Rejection Reason:</strong> <?= htmlspecialchars($record['REJECTION_REASON']) ?>
                </div>
                <?php endif; ?>

                <form method="post" id="pmForm">
                    <input type="hidden" name="mode"      value="<?= $mode ?>">
                    <input type="hidden" name="record_id" value="<?= $record['ID'] ?? '' ?>">

                    <!-- ── Master header fields ── -->
                    <div class="row mb-3 header-fields">
                        <div class="col-6 col-md-2">
                            <label>Month <span class="text-danger">*</span></label>
                            <input type="month" name="inspection_month" id="inspection_month"
                                   class="form-control"
                                   value="<?= htmlspecialchars($record['INSPECTION_MONTH'] ?? '') ?>"
                                   <?= $readonly ?> required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label>Line <span class="text-danger">*</span></label>
                            <input type="text" name="line" class="form-control"
                                   value="<?= htmlspecialchars($record['LINE'] ?? '') ?>"
                                   <?= $readonly ?> required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label>Model <span class="text-danger">*</span></label>
                            <input type="text" name="model" class="form-control"
                                   value="<?= htmlspecialchars($record['MODEL'] ?? '') ?>"
                                   <?= $readonly ?> required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label>Plant <span class="text-danger">*</span></label>
                            <?php if ($is_readonly): ?>
                                <input type="text" class="form-control" readonly
                                       value="<?= htmlspecialchars($selected_plant) ?>">
                            <?php else: ?>
                                <select name="plant" id="plant" class="form-control" required>
                                    <option value="">— Select Plant —</option>
                                    <?php foreach ($plant_options as $opt): ?>
                                        <option value="<?= $opt ?>"
                                            <?= ($selected_plant === $opt) ? 'selected' : '' ?>>
                                            <?= $opt ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ── Week selector + Date/Time fields ── -->
                    <div class="row mb-3 header-fields">
                        <div class="col-6 col-md-2">
                            <label>Week <span class="text-danger">*</span></label>
                            <?php if ($is_readonly): ?>
                                <!-- View / Verify: plain text, no input -->
                                <input type="text" class="form-control" readonly
                                       value="Week <?= htmlspecialchars((string)$week_no_val) ?>">
                            <?php else: ?>
                                <!-- Add: full dropdown. Edit: locked dropdown + hidden -->
                                <select name="week_no" id="week_no" class="form-control" required
                                        <?= ($mode === 'edit') ? 'disabled' : '' ?>>
                                    <option value="">— Select Week —</option>
                                    <?php for ($w = 1; $w <= 5; $w++): ?>
                                        <option value="<?= $w ?>"
                                            <?= ((string)$week_no_val === (string)$w) ? 'selected' : '' ?>>
                                            Week <?= $w ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <?php if ($mode === 'edit'): ?>
                                    <input type="hidden" name="week_no" value="<?= htmlspecialchars((string)$week_no_val) ?>">
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-6 col-md-3">
                            <label>Date <span class="text-danger">*</span></label>
                            <input type="date" name="week_date" class="form-control"
                                   value="<?= htmlspecialchars($week_date_val) ?>"
                                   <?= $readonly ?> required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label>Time</label>
                            <input type="time" name="week_time" class="form-control"
                                   value="<?= htmlspecialchars($week_time_val) ?>"
                                   <?= $readonly ?>>
                        </div>
                    </div>
                    <br>

                    <!-- ── Week pill indicator (view/edit/verify only) ── -->
                    <?php if ($mode !== 'add' && $week_no_val): ?>
                    <div class="week-pill">
                        <span class="wp-label">Viewing week</span>
                        <span>Week <?= htmlspecialchars((string)$week_no_val) ?></span>
                        <?php if ($week_date_val): ?>
                            <span class="wp-label">·</span>
                            <span><?= htmlspecialchars($week_date_val) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- ── Legend ── -->
                    <div class="legend-wrap">
                        <strong>Legend:</strong>
                        <div class="legend-item">
                            <span class="legend-badge badge-ok">✓</span> All in good condition
                        </div>
                        <div class="legend-item">
                            <span class="legend-badge badge-fault">✗</span> Faulty / Issue found
                        </div>
                        <?php if (!$is_readonly): ?>
                        <div class="legend-item ms-2 text-muted" style="font-size:11px;">
                            Tap/Click: empty ↔ ✓ &nbsp;|&nbsp; <kbd>Shift</kbd>+Click to mark ✗
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── Overall progress ── -->
                    <div class="overall-progress">
                        <div class="prog-label">
                            <span>Overall Progress</span>
                            <span id="overall-count">0 / <?= $total_all ?></span>
                        </div>
                        <div class="prog-bar">
                            <div class="prog-fill" id="overall-fill" style="width:0%"></div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════
                         OVEN SECTIONS
                    ══════════════════════════════════════════════ -->
                    <div class="pm-oven-sections">
                    <?php foreach ($oven_structure as $oven):
                        $oven_id    = 'ov' . $oven['no'];
                        $total_tasks = 0;
                        foreach ($oven['components'] as $comp) {
                            $total_tasks += count($comp['items']);
                        }

                        // Count filled items from the single-week $items array
                        $filled_tasks = 0;
                        foreach ($oven['components'] as $comp) {
                            foreach ($comp['items'] as $item) {
                                $v = $items[$item[0]] ?? null;
                                if ($v !== null && $v !== '') $filled_tasks++;
                            }
                        }
                        $is_complete = ($filled_tasks === $total_tasks && $total_tasks > 0);
                    ?>
                    <div class="pm-section" id="section-<?= $oven_id ?>">
                        <div class="pm-section-header open" data-target="body-<?= $oven_id ?>">
                            <span class="ov-no"><?= $oven['no'] ?></span>
                            <span class="ov-name"><?= htmlspecialchars($oven['oven']) ?></span>
                            <span class="ov-badge<?= $is_complete ? ' complete' : '' ?>"
                                  id="badge-<?= $oven_id ?>"><?= $filled_tasks ?> / <?= $total_tasks ?></span>
                            <span class="chevron">▼</span>
                        </div>

                        <div class="pm-body open" id="body-<?= $oven_id ?>">
                        <?php foreach ($oven['components'] as $comp):
                            $is_gb = !empty($comp['gb']);
                        ?>
                            <div class="comp-block">
                                <div class="comp-name<?= $is_gb ? ' gb' : '' ?>">
                                    <?= htmlspecialchars($comp['name']) ?>
                                </div>
                                <?php foreach ($comp['items'] as $item):
                                    $col = $item[0];
                                    $lbl = $item[1];

                                    // Read from the flat single-week $items array
                                    $val = $items[$col] ?? null;
                                    if ((string)$val === '1')     { $btnClass='ok';    $btnText='✓'; $inpVal='1'; }
                                    elseif ((string)$val === '0') { $btnClass='fault'; $btnText='✗'; $inpVal='0'; }
                                    else                          { $btnClass='';      $btnText=''; $inpVal=''; }
                                ?>
                                <div class="task-row<?= $is_gb ? ' gb' : '' ?>">
                                    <span class="task-label"><?= $lbl ?></span>
                                    <input type="hidden"
                                           name="items[<?= htmlspecialchars($col) ?>]"
                                           id="inp_<?= $col ?>"
                                           value="<?= $inpVal ?>">
                                    <button type="button"
                                            class="chk-btn <?= $btnClass ?>"
                                            data-col="<?= htmlspecialchars($col) ?>"
                                            <?= $is_readonly ? 'disabled' : '' ?>><?= $btnText ?></button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div><!-- /.pm-oven-sections -->

                    <!-- ── Remarks ── -->
                    <?php
                        $remarks_val = $record['REMARKS'] ?? '';
                        if (is_object($remarks_val) && method_exists($remarks_val, 'load')) {
                            $remarks_val = $remarks_val->load();
                        }
                        $remarks_val = (string)($remarks_val ?? '');
                    ?>
                    <div class="mb-3 mt-2">
                        <label>Remarks</label>
                        <textarea name="remarks" class="form-control" rows="3"
                                  <?= $readonly ?>><?= htmlspecialchars($remarks_val) ?></textarea>
                    </div>

                    <?php if ($mode === 'verify'): ?>
                    <button type="button" id="verifyBtn" class="btn btn-success">✓ Verify</button>
                    <button type="button" id="rejectBtn" class="btn btn-danger ms-2">✗ Reject</button>
                    <a href="<?= BASE_URL ?>/preventive_maintenance/" class="btn btn-secondary ms-2">Cancel</a>

                    <div id="rejectPanel" style="display:none; margin-top:16px; max-width:500px;">
                        <label class="fw-bold">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea id="rejection_reason_input" rows="3"
                            class="form-control mt-1"
                            placeholder="Enter reason for rejection..."></textarea>
                        <div class="mt-2">
                            <button type="button" id="rejectConfirmBtn" class="btn btn-danger">Confirm Reject</button>
                            <button type="button" id="rejectCancelBtn" class="btn btn-secondary ms-2">Cancel</button>
                        </div>
                    </div>

                    <?php elseif (!$is_readonly): ?>
                    <button type="button" id="submitBtn" class="btn btn-primary">Submit</button>
                    <a href="<?= BASE_URL ?>/preventive_maintenance/" class="btn btn-secondary ms-2">Cancel</a>
                    <?php else: ?>
                    <a href="<?= BASE_URL ?>/preventive_maintenance/" class="btn btn-secondary">Back</a>
                    <?php endif; ?>

                </form>

            </div><!-- /.card-block -->
        </div><!-- /.card -->
    </div><!-- /.container-fluid -->
</div><!-- /.wrapper -->

<!-- JS -->
<script src="assets/js/jquery.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/jquery-ui.min.js"></script>
<script src="assets/js/jquery.validate.min.js"></script>
<script src="assets/js/toastr.min.js"></script>

<script>
$(function () {

    var TOTAL_ALL = <?= $total_all ?>;

    // Section collapse/expand
    $(document).on('click', '.pm-section-header', function () {
        var target = '#' + $(this).data('target');
        $(this).toggleClass('open');
        $(target).toggleClass('open');
    });

    // Refresh badge + overall progress
    function refreshProgress() {
        var filledAll = 0;

        $('.pm-section').each(function () {
            var $sec    = $(this);
            var $inputs = $sec.find('input[name^="items"]');
            var total   = $inputs.length;
            var filled  = $inputs.filter(function () { return $(this).val() !== ''; }).length;

            filledAll += filled;

            var $badge = $sec.find('.ov-badge');
            $badge.text(filled + ' / ' + total);
            if (filled === total && total > 0) {
                $badge.addClass('complete');
            } else {
                $badge.removeClass('complete');
            }
        });

        var pct = TOTAL_ALL > 0 ? Math.round((filledAll / TOTAL_ALL) * 100) : 0;
        $('#overall-count').text(filledAll + ' / ' + TOTAL_ALL);
        $('#overall-fill').css('width', pct + '%');
    }

    // Init progress on page load
    refreshProgress();

    // Toggle buttons
    $(document).on('click', '.chk-btn:not(:disabled)', function (e) {
        var $btn = $(this);
        var col  = $btn.data('col');
        var $inp = $('#inp_' + col);
        var cur  = $inp.val();

        var newClass, newText, newVal;

        if (e.shiftKey) {
            if (cur === '0') {
                newVal = ''; newClass = ''; newText = '';
            } else {
                newVal = '0'; newClass = 'fault'; newText = '✗';
            }
        } else {
            if (cur === '' || cur === '0') {
                newVal = '1'; newClass = 'ok'; newText = '✓';
            } else {
                newVal = ''; newClass = ''; newText = '';
            }
        }

        $inp.val(newVal);
        $('[data-col="' + col + '"]')
            .removeClass('ok fault')
            .addClass(newClass)
            .text(newText);

        refreshProgress();
    });

    // ── Validation + Submit ──────────────────────────────────────────────────
    <?php if (!$is_readonly): ?>

    $('#submitBtn').on('click', function (e) {
        e.preventDefault();
        var valid = true;

        var requiredSelectors = [
            '#inspection_month',
            '[name="line"]',
            '[name="model"]',
            '[name="plant"]',
            '[name="week_date"]'
        ];

        <?php if ($mode !== 'edit'): ?>
        requiredSelectors.push('#week_no');
        <?php endif; ?>

        requiredSelectors.forEach(function (sel) {
            var $el = $(sel);
            if (!$.trim($el.val())) {
                $el.addClass('error-border');
                valid = false;
            } else {
                $el.removeClass('error-border');
            }
        });

        if (!valid) {
            toastr.error('Please fill in all required fields.');
            return;
        }
        ajaxSubmit();
    });

    $(document).on('input change', '.error-border', function () {
        $(this).removeClass('error-border');
    });

    function ajaxSubmit() {
        $('#loading-overlay').css('display', 'flex');
        var formData = new FormData($('#pmForm')[0]);
        formData.append('action', 'submit');

        $.ajax({
            url: 'api/save.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (res) {
                $('#loading-overlay').hide();
                if (res.success) {
                    toastr.success(res.message || 'Saved successfully.');
                    setTimeout(function () {
                        window.location.href = '<?= BASE_URL ?>/preventive_maintenance/';
                    }, 1500);
                } else {
                    if (res.errors && res.errors.length) {
                        toastr.error(res.errors.join('<br>'));
                    } else {
                        toastr.error(res.message || 'An error occurred.');
                    }
                }
            },
            error: function (xhr) {
                $('#loading-overlay').hide();
                var raw = xhr.responseText || 'No response from server.';
                console.error('save_copy.php raw response:', raw);
                try {
                    var res = JSON.parse(raw.substring(raw.indexOf('{')));
                    toastr.error(res.message || 'Server error.');
                } catch(e) {
                    toastr.error('Server error: ' + raw.substring(0, 300));
                }
            }
        });
    }

    <?php endif; ?>

    // ── Verify / Reject ──────────────────────────────────────────────────────
    <?php if ($mode === 'verify'): ?>

    var recordId = <?= intval($record['ID'] ?? 0) ?>;

    $('#verifyBtn').on('click', function () {
        if (!confirm('Are you sure you want to verify this record?')) return;
        submitVerifyAction('verify', '');
    });

    $('#rejectBtn').on('click', function () {
        $('#rejectPanel').slideDown(150);
        $('#rejection_reason_input').focus();
    });

    $('#rejectCancelBtn').on('click', function () {
        $('#rejectPanel').slideUp(150);
        $('#rejection_reason_input').val('');
    });

    $('#rejectConfirmBtn').on('click', function () {
        var reason = $.trim($('#rejection_reason_input').val());
        if (reason === '') {
            toastr.error('Please enter a rejection reason.');
            $('#rejection_reason_input').focus();
            return;
        }
        submitVerifyAction('reject', reason);
    });

    function submitVerifyAction(action, reason) {
        $('#loading-overlay').css('display', 'flex');
        $.ajax({
            url     : 'api/save.php',
            type    : 'POST',
            dataType: 'json',
            data    : { mode: action, record_id: recordId, rejection_reason: reason },
            success: function (res) {
                $('#loading-overlay').hide();
                if (res.success) {
                    toastr.success(res.message);
                    setTimeout(function () {
                        window.location.href = '<?= BASE_URL ?>/preventive_maintenance/';
                    }, 1500);
                } else {
                    toastr.error(res.message || 'Action failed.');
                }
            },
            error: function () {
                $('#loading-overlay').hide();
                toastr.error('Server error. Please try again.');
            }
        });
    }

    <?php endif; ?>

});
</script>
</body>
</html>