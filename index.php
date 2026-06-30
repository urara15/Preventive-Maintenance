<?php
ob_start();
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';

if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    header("Location:" . BASE_URL . "/Dashboard/Authenticate/login.php?gotourl=" . base64_encode(BASE_URL . "/preventive_maintenance/"));
    die;
}

$alert_message = null;
if (isset($_GET['msg'])) {
    $alert_message = htmlspecialchars(urldecode($_GET['msg']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>GRAND TEN - PREVENTIVE MAINTENANCE CHECKLIST</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, user-scalable=no">
    <link rel="shortcut icon" href="assets/images/favicon.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=1">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="assets/css/toastr.min.css">
    <link rel="stylesheet" href="assets/css/select2.min.css">
    <style>
        .dataTables_scrollHead thead th,
        .dataTables_scrollHead thead th.sorting,
        .dataTables_scrollHead thead th.sorting_asc,
        .dataTables_scrollHead thead th.sorting_desc {
            background-color: #1a56a0 !important;
            color: #fff !important;
            font-weight: 600;
            white-space: nowrap;
        }
        .dataTables_scrollHead thead th.sorting::before,
        .dataTables_scrollHead thead th.sorting::after,
        .dataTables_scrollHead thead th.sorting_asc::after,
        .dataTables_scrollHead thead th.sorting_desc::after {
            color: #fff !important;
            opacity: 0.7;
        }

        .badge-status {
            display: inline-block;
            padding: 3px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pending  { background: #ffc107; color: #212529; }
        .badge-verified { background: #28a745; color: #fff; }
        .badge-rejected { background: #dc3545; color: #fff; }

        /* Filter bar */
        .filter-bar {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 16px 20px;
            margin-bottom: 16px;
        }
        .filter-bar .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }
        .filter-bar .filter-group {
            display: flex;
            flex-direction: column;
            min-width: 140px;
            flex: 1 1 140px;
        }
        .filter-bar .filter-group.filter-group-wide {
            min-width: 200px;
            flex: 2 1 200px;
        }
        .filter-bar .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: #555;
            margin-bottom: 4px;
        }
        .filter-bar .filter-group select,
        .filter-bar .filter-group input[type="date"] {
            height: 36px;
            font-size: 13px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 0 8px;
            color: #333;
            background-color: #fff;
            width: 100%;
        }
        .filter-bar .filter-group select:focus,
        .filter-bar .filter-group input[type="date"]:focus {
            outline: none;
            border-color: #80bdff;
            box-shadow: 0 0 0 2px rgba(0,123,255,.15);
        }
        .filter-bar .btn-reset {
            height: 36px;
            min-width: 90px;
            background: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
            color: #495057;
            cursor: pointer;
            align-self: flex-end;
            padding: 0 18px;
            transition: background .15s;
        }
        .filter-bar .btn-reset:hover { background: #d3d6da; }

        /* Make Select2 match other filter inputs */
        .filter-group .select2-container {
            width: 100% !important;
        }
        .filter-group .select2-container .select2-selection--single {
            height: 36px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            display: flex;
            align-items: center;
        }
        .filter-group .select2-container .select2-selection--single .select2-selection__rendered {
            font-size: 13px;
            color: #333;
            line-height: 34px;
            padding-left: 8px;
        }
        .filter-group .select2-container .select2-selection--single .select2-selection__arrow {
            height: 34px;
        }
        .filter-group .select2-container .select2-selection--single .select2-selection__placeholder {
            color: #6c757d;
        }
        .filter-group .select2-container--focus .select2-selection--single,
        .filter-group .select2-container--open .select2-selection--single {
            border-color: #80bdff;
            box-shadow: 0 0 0 2px rgba(0,123,255,.15);
            outline: none;
        }
        .select2-dropdown {
            font-size: 13px;
        }
        .select2-results__option {
            padding: 6px 10px;
        }
        .select2-search--dropdown .select2-search__field {
            font-size: 13px;
            padding: 4px 8px;
        }
    </style>
</head>
<body>
<div class="wrapper">

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
    <br>
    <br>

    <div id="content-container" class="container-fluid mydesignform">
        <div class="card m-a-2">
            <div class="card-block">

                <div class="row mb-2">
                    <div class="col-lg-6 col-md-6 col-sm-9 col-xs-12">
                        <h5>PREVENTIVE MAINTENANCE CHECKLIST</h5>
                    </div>
                    <div class="col-md-6" style="text-align:right;">
                        <!-- <button id="btn_export_excel" class="btn btn-success" style="margin-right:6px;">
                            EXPORT EXCEL
                        </button> -->
                        <a href="add.php" class="btn btn-primary">ADD</a>
                    </div>
                </div>
                <hr>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="filter-row">

                        <div class="filter-group">
                            <label for="filter_month">Month</label>
                            <select id="filter_month">
                                <option value="">-- All Months --</option>
                                <option value="01">January</option>
                                <option value="02">February</option>
                                <option value="03">March</option>
                                <option value="04">April</option>
                                <option value="05">May</option>
                                <option value="06">June</option>
                                <option value="07">July</option>
                                <option value="08">August</option>
                                <option value="09">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>

                        <!-- Week filter — new -->
                        <div class="filter-group">
                            <label for="filter_week">Week</label>
                            <select id="filter_week">
                                <option value="">-- All Weeks --</option>
                                <option value="1">Week 1</option>
                                <option value="2">Week 2</option>
                                <option value="3">Week 3</option>
                                <option value="4">Week 4</option>
                                <option value="5">Week 5</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter_plant">Plant</label>
                            <select id="filter_plant">
                                <option value="">-- All Plants --</option>
                                <option value="GPL">GPL</option>
                                <option value="GP">GP</option>
                                <option value="YTL">YTL</option>
                                <option value="YTA">YTA</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter_status">Status</label>
                            <select id="filter_status">
                                <option value="">-- All Status --</option>
                                <option value="Pending Verification">Pending Verification</option>
                                <option value="Rejected">Rejected</option>
                                <option value="Verified">Verified</option>
                            </select>
                        </div>

                        <!-- Created By — employee search via Select2 AJAX -->
                        <div class="filter-group filter-group-wide">
                            <label for="filter_created_by">Created By</label>
                            <select id="filter_created_by">
                                <!-- Options are loaded dynamically via Select2 AJAX -->
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter_date_from">Created Date From</label>
                            <input type="date" id="filter_date_from">
                        </div>

                        <div class="filter-group">
                            <label for="filter_date_to">Created Date To</label>
                            <input type="date" id="filter_date_to">
                        </div>

                        <button class="btn-reset" id="btn_reset">Reset</button>

                    </div>
                </div>
                <!-- /Filter Bar -->

                <div class="table-responsive" id="content-table" style="overflow-x:auto; width:100%;">
                    <table id="pm_list" class="display nowrap" style="width:100%"></table>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div id="rejectModal" style="
    display:none; position:fixed; top:0; left:0; width:100vw; height:100vh;
    background:rgba(0,0,0,.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:6px; padding:24px 28px; min-width:360px; max-width:480px; width:90%; box-shadow:0 4px 24px rgba(0,0,0,.2);">
        <h6 style="margin:0 0 12px; font-size:15px; font-weight:700; color:#dc3545;">Reject Record</h6>
        <label style="font-size:13px; font-weight:600; color:#555;">Rejection Reason <span style="color:#dc3545;">*</span></label>
        <textarea id="rejection_reason_input" rows="3"
            class="form-control mt-1"
            placeholder="Enter reason for rejection..."
            style="font-size:13px; margin-top:6px;"></textarea>
        <div style="margin-top:14px; display:flex; gap:8px;">
            <button id="rejectConfirmBtn" class="btn btn-danger" style="font-size:13px;">Confirm Reject</button>
            <button id="rejectCancelBtn"  class="btn btn-secondary" style="font-size:13px;">Cancel</button>
        </div>
    </div>
</div>

<script src="assets/js/tether.min.js"></script>
<script src="assets/js/jquery.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/jquery.dataTables.min.js"></script>
<script src="assets/js/toastr.min.js"></script>
<script src="assets/js/select2.min.js"></script>

<script>
$(document).ready(function () {

    // ── Select2: Created By employee search ──────────────────────────────────
    $('#filter_created_by').select2({
        placeholder: '-- All Employees --',
        allowClear: true,
        minimumInputLength: 1,
        ajax: {
            url: 'api/employee_search.php',
            dataType: 'json',
            delay: 300,
            data: function (params) {
                return { q: params.term };
            },
            processResults: function (data) {
                return { results: data.results };
            },
            cache: true
        },
        language: {
            inputTooShort: function () {
                return 'Type at least 1 character to search...';
            },
            searching: function () {
                return 'Searching...';
            },
            noResults: function () {
                return 'No employee found.';
            }
        }
    });

    // ── DataTable ────────────────────────────────────────────────────────────
    var table = $('#pm_list').DataTable({
        processing  : true,
        serverSide  : true,
        searching   : true,
        lengthChange: true,
        paging      : true,
        pageLength  : 10,
        order       : [[0, 'desc']],
        scrollX     : true,
        ajax: {
            url     : 'api/getListData.php',
            type    : 'POST',
            dataType: 'json',
            data: function (d) {
                d.filter_month      = $('#filter_month').val();
                d.filter_week       = $('#filter_week').val();       // new
                d.filter_plant      = $('#filter_plant').val();
                d.filter_status     = $('#filter_status').val();
                d.filter_created_by = $('#filter_created_by').val();
                d.filter_date_from  = $('#filter_date_from').val();
                d.filter_date_to    = $('#filter_date_to').val();
            }
        },
        columns: [
            { title: '#',            data: 'ID',               width: '50px' },
            { title: 'REF NO',       data: 'PM_REF_NO' },
            { title: 'MONTH',        data: 'INSPECTION_MONTH' },
            { title: 'WEEK',         data: 'WEEK_NO' },
            { title: 'WEEK DATE',    data: 'WEEK_DATE' },
            { title: 'LINE',         data: 'LINE' },
            { title: 'MODEL',        data: 'MODEL' },
            { title: 'PLANT',        data: 'PLANT' },
            { title: 'APPROVED BY',  data: 'VERIFIED_BY_NAME' },
            {
                title: 'STATUS',
                data : 'PM_STATUS',
                render: function (val) {
                    var cls   = 'badge-pending';
                    var label = val || 'Pending Verification';
                    if (val === 'Verified') cls = 'badge-verified';
                    if (val === 'Rejected') cls = 'badge-rejected';
                    return '<span class="badge-status ' + cls + '">' + label + '</span>';
                }
            },
            { title: 'CREATED BY',   data: 'CREATED_BY_NAME' },
            { title: 'CREATED DATE', data: 'CREATED_AT' },
            { title: 'UPDATED DATE', data: 'UPDATED_AT' },
            { title: 'ACTION',       data: 'ACTION', orderable: false }
        ],
        language: {
            lengthMenu: 'Show _MENU_ entries',
            emptyTable: 'No Records Found.',
            processing: 'Loading…',
            search: 'Search Model:'
        }
    });

    // Reload on dropdown/filter change
    $('#filter_month, #filter_week, #filter_plant, #filter_status').on('change', function () {
        table.ajax.reload();
    });

    // Reload when Created By selection changes
    $('#filter_created_by').on('change', function () {
        table.ajax.reload();
    });

    // Reload on date change with from/to validation
    $('#filter_date_from, #filter_date_to').on('change', function () {
        var from = $('#filter_date_from').val();
        var to   = $('#filter_date_to').val();
        if (from && to && from > to) {
            alert('Created Date From cannot be later than Created Date To.');
            $(this).val('');
            return;
        }
        table.ajax.reload();
    });

    // Reset all filters
    $('#btn_reset').on('click', function () {
        $('#filter_month, #filter_week, #filter_plant, #filter_status').val('');
        $('#filter_date_from, #filter_date_to').val('');
        // Clear Select2 — must use val(null) + trigger('change') for Select2
        $('#filter_created_by').val(null).trigger('change');
        table.ajax.reload();
    });

    // ── Export Summary Excel ─────────────────────────────────────────────────
    $('#btn_export_excel').on('click', function () {
        var params = {
            filter_month      : $('#filter_month').val(),
            filter_week       : $('#filter_week').val(),
            filter_plant      : $('#filter_plant').val(),
            filter_status     : $('#filter_status').val(),
            filter_created_by : $('#filter_created_by').val(),
            filter_date_from  : $('#filter_date_from').val(),
            filter_date_to    : $('#filter_date_to').val()
        };

        var form = $('<form method="POST" action="api/exportSummaryExcel.php" style="display:none;">');
        $.each(params, function (k, v) {
            form.append($('<input type="hidden">').attr('name', k).val(v));
        });
        $('body').append(form);
        form.submit();
        form.remove();
    });

    // Flash message from URL param
    var alertMessage = "<?= addslashes($alert_message ?? '') ?>";
    if (alertMessage) {
        toastr.error(alertMessage);
        setTimeout(function () {
            window.history.replaceState({}, document.title, window.location.pathname);
        }, 2000);
    }

    // ── Verify / Reject handlers ─────────────────────────────────────────────
    var pendingEncodedId = null;

    $(document).on('click', '.btn-verify', function (e) {
        e.preventDefault();
        var action    = $(this).data('action');
        var encodedId = $(this).data('id');

        if (action === 'verify') {
            if (!confirm('Are you sure you want to verify this record?')) return;
            submitVerifyAction(encodedId, 'verify', '');
        } else {
            pendingEncodedId = encodedId;
            $('#rejection_reason_input').val('');
            $('#rejectModal').css('display', 'flex');
        }
    });

    $('#rejectCancelBtn').on('click', function () {
        $('#rejectModal').hide();
        pendingEncodedId = null;
    });

    $('#rejectConfirmBtn').on('click', function () {
        var reason = $.trim($('#rejection_reason_input').val());
        if (reason === '') {
            alert('Please enter a rejection reason.');
            return;
        }
        $('#rejectModal').hide();
        submitVerifyAction(pendingEncodedId, 'reject', reason);
        pendingEncodedId = null;
    });

    function submitVerifyAction(encodedId, action, reason) {
        $.ajax({
            url     : 'api/save.php',
            type    : 'POST',
            dataType: 'json',
            data    : {
                mode             : action,
                record_id        : atob(encodedId),
                rejection_reason : reason
            },
            success: function (res) {
                if (res.success) {
                    toastr.success(res.message);
                    table.ajax.reload(null, false);
                } else {
                    toastr.error(res.message || 'Action failed.');
                }
            },
            error: function () {
                toastr.error('Server error. Please try again.');
            }
        });
    }
});
</script>
</body>
</html>