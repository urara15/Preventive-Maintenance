<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
require $_SERVER['DOCUMENT_ROOT'] . '/common/EmailHelper.php';

use Common\Email\EmailHelper;

class Common
{
    private static $dbcon = null;

    private static function getDb()
    {
        if (self::$dbcon === null) {
            include $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';
            self::$dbcon = $dbcon;
        }
        return self::$dbcon;
    }


    public static function is_verifier(string $emp_id): bool
    {
        if (empty($emp_id)) return false;

        include $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

        $sql  = "SELECT COUNT(*) AS CNT
                 FROM PRODUCTION.PREVENTIVE_MAINTENANCE_CONFIG
                 WHERE POSITION_TYPE = 'preventive_maintenance'
                 AND VERIFIED_BY     = :emp_id";

        $stmt = oci_parse($dbcon, $sql);
        oci_bind_by_name($stmt, ':emp_id', $emp_id);
        oci_execute($stmt);

        $row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS);
        oci_free_statement($stmt);

        return (int)($row['CNT'] ?? 0) > 0;
    }


    public static function view_checklist_by_month_group($record_id = ''): array
    {
        if (!$record_id) {
            return [];
        }

        include $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

        $key_sql = "
            SELECT INSPECTION_MONTH, LINE, MODEL, PLANT
            FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER
            WHERE ID = :ID
        ";
        $key_stmt = oci_parse($dbcon, $key_sql);
        oci_bind_by_name($key_stmt, ':ID', $record_id);
        oci_execute($key_stmt);
        $key_row = oci_fetch_array($key_stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS);
        oci_free_statement($key_stmt);

        if (empty($key_row)) {
            return [];
        }

        $inspection_month = $key_row['INSPECTION_MONTH'] ?? '';
        $line             = $key_row['LINE']             ?? '';
        $model_val        = $key_row['MODEL']            ?? '';
        $plant            = $key_row['PLANT']             ?? '';

        $group_sql = "
            SELECT
                pm.ID,
                pm.INSPECTION_MONTH,
                pm.LINE,
                pm.MODEL,
                pm.PLANT,
                pm.PM_STATUS,
                pm.VERIFIED_BY,
                verified_emp.EMPLOYEE_NAME                          AS VERIFIED_BY_NAME,
                pm.REMARKS,
                pm.CREATED_BY,
                creator.EMPLOYEE_NAME                               AS CREATED_BY_NAME,
                TO_CHAR(pm.CREATED_AT, 'DD/MM/YYYY HH24:MI')        AS CREATED_AT,
                TO_CHAR(pm.UPDATED_AT, 'DD/MM/YYYY HH24:MI')        AS UPDATED_AT,
                pm.WEEK_NO                                          AS WEEK_NO,
                TO_CHAR(pm.WEEK_DATE, 'YYYY-MM-DD')                 AS WEEK_DATE,
                TO_CHAR(pm.WEEK_TIME, 'HH24:MI')                    AS WEEK_TIME
            FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER pm
            LEFT JOIN EMPLOYEE_MASTER verified_emp ON verified_emp.EMP_ID = pm.VERIFIED_BY
            LEFT JOIN EMPLOYEE_MASTER creator      ON creator.EMP_ID      = pm.CREATED_BY
            WHERE UPPER(TRIM(pm.INSPECTION_MONTH)) = UPPER(TRIM(:inspection_month))
            AND UPPER(TRIM(pm.LINE))             = UPPER(TRIM(:line))
            AND UPPER(TRIM(pm.MODEL))            = UPPER(TRIM(:model_val))
            AND UPPER(TRIM(pm.PLANT))            = UPPER(TRIM(:plant))
            AND UPPER(TRIM(pm.PM_STATUS))         = 'VERIFIED'
            ORDER BY pm.UPDATED_AT DESC, pm.CREATED_AT DESC, pm.ID DESC
        ";

        $group_stmt = oci_parse($dbcon, $group_sql);
        oci_bind_by_name($group_stmt, ':inspection_month', $inspection_month);
        oci_bind_by_name($group_stmt, ':line',             $line);
        oci_bind_by_name($group_stmt, ':model_val',        $model_val);
        oci_bind_by_name($group_stmt, ':plant',            $plant);
        oci_execute($group_stmt);

        $group_rows = [];
        while (($row = oci_fetch_array($group_stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) !== false) {
            $group_rows[] = array_change_key_case($row, CASE_UPPER);
        }
        oci_free_statement($group_stmt);

        if (empty($group_rows)) {
            return [];
        }

        $latest = $group_rows[0];

        $merged = [
            'ID'               => $latest['ID'],
            'INSPECTION_MONTH' => $latest['INSPECTION_MONTH'],
            'LINE'             => $latest['LINE'],
            'MODEL'            => $latest['MODEL'],
            'PLANT'            => $latest['PLANT'],
            'PM_STATUS'        => $latest['PM_STATUS'],
            'VERIFIED_BY'      => $latest['VERIFIED_BY'],
            'VERIFIED_BY_NAME' => $latest['VERIFIED_BY_NAME'],
            'REMARKS'          => $latest['REMARKS'],
            'CREATED_BY'       => $latest['CREATED_BY'],
            'CREATED_BY_NAME'  => $latest['CREATED_BY_NAME'],
            'CREATED_AT'       => $latest['CREATED_AT'],
            'UPDATED_AT'       => $latest['UPDATED_AT'],
        ];

        for ($w = 1; $w <= 5; $w++) {
            $merged['WEEK' . $w . '_DATE'] = null;
            $merged['WEEK' . $w . '_TIME'] = null;
        }

        $week_no_to_master_id = [];

        foreach ($group_rows as $row) {
            $w = (int) ($row['WEEK_NO'] ?? 0);
            if ($w < 1 || $w > 5) continue;
            if (isset($week_no_to_master_id[$w])) continue;

            if (!empty($row['WEEK_DATE'])) {
                $merged['WEEK' . $w . '_DATE'] = $row['WEEK_DATE'];
                $merged['WEEK' . $w . '_TIME'] = $row['WEEK_TIME'];
                $week_no_to_master_id[$w]      = (int) $row['ID'];
            }
        }

        $master_ids = array_map(fn($r) => (int) $r['ID'], $group_rows);
        $id_placeholders = [];
        foreach ($master_ids as $i => $mid) {
            $id_placeholders[] = ':mid' . $i;
        }
        $in_clause = implode(',', $id_placeholders);

        $items_sql = "
            SELECT *
            FROM PRODUCTION.PREVENTIVE_MAINTENANCE_ITEMS
            WHERE MASTER_ID IN ({$in_clause})
            ORDER BY WEEK_NO ASC
        ";
        $items_stmt = oci_parse($dbcon, $items_sql);
        foreach ($master_ids as $i => $mid) {
            oci_bind_by_name($items_stmt, ':mid' . $i, $master_ids[$i]);
        }
        oci_execute($items_stmt);

        $week_items = [];
        while (($row = oci_fetch_array($items_stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) !== false) {
            $row     = array_change_key_case($row, CASE_UPPER);
            $week_no = (int) $row['WEEK_NO'];
            $row_master_id = (int) $row['MASTER_ID'];

            if (isset($week_no_to_master_id[$week_no])) {
                if ($week_no_to_master_id[$week_no] === $row_master_id) {
                    $week_items[$week_no] = $row;
                }
            } elseif (!isset($week_items[$week_no])) {
                $week_items[$week_no] = $row;
            }
        }
        oci_free_statement($items_stmt);

        $merged['week_items'] = $week_items;

        return $merged;
    }


    public static function view_checklist_by_id($record_id = ''): array
    {
        if (!$record_id) {
            return [];
        }

        include $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

        $sql_master = "
            SELECT
                pm.ID,
                pm.INSPECTION_MONTH,
                pm.LINE,
                pm.MODEL,
                pm.PLANT,
                pm.PM_STATUS,
                pm.VERIFIED_BY,
                verified_emp.EMPLOYEE_NAME                          AS VERIFIED_BY_NAME,
                pm.REMARKS,
                pm.CREATED_BY,
                creator.EMPLOYEE_NAME                               AS CREATED_BY_NAME,
                TO_CHAR(pm.CREATED_AT, 'DD/MM/YYYY HH24:MI')        AS CREATED_AT,
                TO_CHAR(pm.UPDATED_AT, 'DD/MM/YYYY HH24:MI')        AS UPDATED_AT,
                pm.WEEK_NO                                          AS WEEK_NO,
                TO_CHAR(pm.WEEK_DATE, 'YYYY-MM-DD')                 AS WEEK_DATE,
                TO_CHAR(pm.WEEK_TIME, 'HH24:MI')                    AS WEEK_TIME
            FROM PRODUCTION.PREVENTIVE_MAINTENANCE_MASTER pm
            LEFT JOIN EMPLOYEE_MASTER verified_emp ON verified_emp.EMP_ID = pm.VERIFIED_BY
            LEFT JOIN EMPLOYEE_MASTER creator      ON creator.EMP_ID      = pm.CREATED_BY
            WHERE pm.ID = :ID
        ";

        $stmt_master = oci_parse($dbcon, $sql_master);
        oci_bind_by_name($stmt_master, ':ID', $record_id);
        oci_execute($stmt_master);
        $master_row = oci_fetch_array($stmt_master, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS);

        if (empty($master_row)) {
            return [];
        }

        $master_row = array_change_key_case($master_row, CASE_UPPER);

        $sql_items = "
            SELECT *
            FROM PRODUCTION.PREVENTIVE_MAINTENANCE_ITEMS
            WHERE MASTER_ID = :MASTER_ID
            ORDER BY WEEK_NO ASC
        ";

        $stmt_items = oci_parse($dbcon, $sql_items);
        oci_bind_by_name($stmt_items, ':MASTER_ID', $record_id);
        oci_execute($stmt_items);

        $week_items = [];
        while (($row = oci_fetch_array($stmt_items, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) !== false) {
            $week_no = (int) $row['WEEK_NO'];
            $week_items[$week_no] = array_change_key_case($row, CASE_UPPER);
        }

        $master_row['week_items'] = $week_items;

        return $master_row;
    }


    private static function format_status($val) {
        if ($val === null || $val === '') return '';
        return ((int)$val === 1) ? '√' : 'X';
    }


    public static function employee_list(string $term = ''): array
    {
        $db  = self::getDb();
        $out = [];

        if (trim($term) === '') return $out;

        $search = '%' . strtoupper(trim($term)) . '%';

        $sql = "
            SELECT EMP_ID, EMPLOYEE_NAME
            FROM EMPLOYEE_MASTER
            WHERE UPPER(EMP_ID) LIKE :search
               OR UPPER(EMPLOYEE_NAME) LIKE :search2
            ORDER BY EMPLOYEE_NAME
            FETCH FIRST 30 ROWS ONLY
        ";

        $stmt = oci_parse($db, $sql);
        if (!$stmt) {
            $err = oci_error($db);
            throw new Exception('employee_list parse failed: ' . $err['message']);
        }

        oci_bind_by_name($stmt, ':search',  $search);
        oci_bind_by_name($stmt, ':search2', $search);

        if (!oci_execute($stmt)) {
            $err = oci_error($stmt);
            throw new Exception('employee_list execute failed: ' . $err['message']);
        }

        while (($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) !== false) {
            $out[] = array_change_key_case($row, CASE_UPPER);
        }

        oci_free_statement($stmt);
        return $out;
    }


    public static function pdf_generate_pm($record_id = '', $type = 'I')
    {
        ini_set('pcre.backtrack_limit', 100000000000);
        require_once $_SERVER['DOCUMENT_ROOT'] . '/common/vendor/autoload.php';

        $record = Common::view_checklist_by_month_group($record_id);

        if (empty($record)) {
            echo 'No verified checklist found for this month yet. The PDF can only be generated once at least one week has been Verified.';
            exit;
        }

        $week_items = $record['week_items'] ?? [];

        $http              = $_SERVER['REQUEST_SCHEME'];
        $serverHostAddress = $_SERVER['SERVER_NAME'];
        $logo = $http . '://' . $serverHostAddress . '/preventive_maintenance/assets/images/grandten_logo.png';

        $checklist = [
            ['FO_F1_CMB',    'Former Oven Fan 1 – Check Motor Bearing'],
            ['FO_F1_CM',     'Former Oven Fan 1 – Clean Motor'],
            ['FO_F1_CMV',    'Former Oven Fan 1 – Check Motor Ventilation'],
            ['FO_F2_CMB',    'Former Oven Fan 2 – Check Motor Bearing'],
            ['FO_F2_CMV',    'Former Oven Fan 2 – Check Motor Ventilation'],
            ['FO_GB1_CCGB',  'Former Oven Gas Burner 1 – Check & Clean Gas Burner'],
            ['CO_F1_CMB',    'Coagulant Oven Fan 1 – Check Motor Bearing'],
            ['CO_F1_CM',     'Coagulant Oven Fan 1 – Clean Motor'],
            ['CO_F1_CMV',    'Coagulant Oven Fan 1 – Check Motor Ventilation'],
            ['CO_F2_CMB',    'Coagulant Oven Fan 2 – Check Motor Bearing'],
            ['CO_F2_CM',     'Coagulant Oven Fan 2 – Clean Motor'],
            ['CO_F2_CMV',    'Coagulant Oven Fan 2 – Check Motor Ventilation'],
            ['CO_GB2_CCGB',  'Coagulant Oven Gas Burner 2 – Check & Clean Gas Burner'],
            ['CO_F3_CMB',    'Coagulant Oven Fan 3 – Check Motor Bearing'],
            ['CO_F3_CM',     'Coagulant Oven Fan 3 – Clean Motor'],
            ['CO_F3_CMV',    'Coagulant Oven Fan 3 – Check Motor Ventilation'],
            ['CO_F4_CMB',    'Coagulant Oven Fan 4 – Check Motor Bearing'],
            ['CO_F4_CM',     'Coagulant Oven Fan 4 – Clean Motor'],
            ['CO_F4_CMV',    'Coagulant Oven Fan 4 – Check Motor Ventilation'],
            ['CO_GB3_CCGB',  'Coagulant Oven Gas Burner 3 – Check & Clean Gas Burner'],
            ['LO1_F1_CMB',   'Latex Oven 1 Fan 1 – Check Motor Bearing'],
            ['LO1_F1_CM',    'Latex Oven 1 Fan 1 – Clean Motor'],
            ['LO1_F1_CMV',   'Latex Oven 1 Fan 1 – Check Motor Ventilation'],
            ['LO1_F2_CMB',   'Latex Oven 1 Fan 2 – Check Motor Bearing'],
            ['LO1_F2_CM',    'Latex Oven 1 Fan 2 – Clean Motor'],
            ['LO1_F2_CMV',   'Latex Oven 1 Fan 2 – Check Motor Ventilation'],
            ['LO1_GB4_CCGB', 'Latex Oven 1 Gas Burner 4 – Check & Clean Gas Burner'],
            ['LO1_F3_CMB',   'Latex Oven 1 Fan 3 – Check Motor Bearing'],
            ['LO1_F3_CM',    'Latex Oven 1 Fan 3 – Clean Motor'],
            ['LO1_F3_CMV',   'Latex Oven 1 Fan 3 – Check Motor Ventilation'],
            ['LO1_F4_CMB',   'Latex Oven 1 Fan 4 – Check Motor Bearing'],
            ['LO1_F4_CM',    'Latex Oven 1 Fan 4 – Clean Motor'],
            ['LO1_F4_CMV',   'Latex Oven 1 Fan 4 – Check Motor Ventilation'],
            ['LO1_GB5_CCGB', 'Latex Oven 1 Gas Burner 5 – Check & Clean Gas Burner'],
            ['LO2_F1_CMB',   'Latex Oven 2 Fan 1 – Check Motor Bearing'],
            ['LO2_F1_CM',    'Latex Oven 2 Fan 1 – Clean Motor'],
            ['LO2_F1_CMV',   'Latex Oven 2 Fan 1 – Check Motor Ventilation'],
            ['LO2_F2_CMB',   'Latex Oven 2 Fan 2 – Check Motor Bearing'],
            ['LO2_F2_CM',    'Latex Oven 2 Fan 2 – Clean Motor'],
            ['LO2_F2_CMV',   'Latex Oven 2 Fan 2 – Check Motor Ventilation'],
            ['LO2_GB6_CCGB', 'Latex Oven 2 Gas Burner 6 – Check & Clean Gas Burner'],
            ['PO_F1_CMB',    'Pre Leach Oven Fan 1 – Check Motor Bearing'],
            ['PO_F1_CM',     'Pre Leach Oven Fan 1 – Clean Motor'],
            ['PO_F1_CMV',    'Pre Leach Oven Fan 1 – Check Motor Ventilation'],
            ['PO_F2_CMB',    'Pre Leach Oven Fan 2 – Check Motor Bearing'],
            ['PO_F2_CM',     'Pre Leach Oven Fan 2 – Clean Motor'],
            ['PO_F2_CMV',    'Pre Leach Oven Fan 2 – Check Motor Ventilation'],
            ['PO_GB7_CCGB',  'Pre Leach Oven Gas Burner 7 – Check & Clean Gas Burner'],
            ['WO_F1_CMB',    'Wet Oven Fan 1 – Check Motor Bearing'],
            ['WO_F1_CM',     'Wet Oven Fan 1 – Clean Motor'],
            ['WO_F1_CMV',    'Wet Oven Fan 1 – Check Motor Ventilation'],
            ['WO_F2_CMB',    'Wet Oven Fan 2 – Check Motor Bearing'],
            ['WO_F2_CM',     'Wet Oven Fan 2 – Clean Motor'],
            ['WO_F2_CMV',    'Wet Oven Fan 2 – Check Motor Ventilation'],
            ['WO_GB8_CCGB',  'Wet Oven Gas Burner 8 – Check & Clean Gas Burner'],
            ['WO_F3_CMB',    'Wet Oven Fan 3 – Check Motor Bearing'],
            ['WO_F3_CM',     'Wet Oven Fan 3 – Clean Motor'],
            ['WO_F3_CMV',    'Wet Oven Fan 3 – Check Motor Ventilation'],
            ['WO_F4_CMB',    'Wet Oven Fan 4 – Check Motor Bearing'],
            ['WO_F4_CM',     'Wet Oven Fan 4 – Clean Motor'],
            ['WO_F4_CMV',    'Wet Oven Fan 4 – Check Motor Ventilation'],
            ['WO_GB9_CCGB',  'Wet Oven Gas Burner 9 – Check & Clean Gas Burner'],
            ['WO_F5_CMB',    'Wet Oven Fan 5 – Check Motor Bearing'],
            ['WO_F5_CM',     'Wet Oven Fan 5 – Clean Motor'],
            ['WO_F5_CMV',    'Wet Oven Fan 5 – Check Motor Ventilation'],
            ['WO_F6_CMB',    'Wet Oven Fan 6 – Check Motor Bearing'],
            ['WO_F6_CM',     'Wet Oven Fan 6 – Clean Motor'],
            ['WO_F6_CMV',    'Wet Oven Fan 6 – Check Motor Ventilation'],
            ['WO_GB10_CCGB', 'Wet Oven Gas Burner 10 – Check & Clean Gas Burner'],
            ['WO_F7_CMB',    'Wet Oven Fan 7 – Check Motor Bearing'],
            ['WO_F7_CM',     'Wet Oven Fan 7 – Clean Motor'],
            ['WO_F7_CMV',    'Wet Oven Fan 7 – Check Motor Ventilation'],
            ['WO_F8_CMB',    'Wet Oven Fan 8 – Check Motor Bearing'],
            ['WO_F8_CM',     'Wet Oven Fan 8 – Clean Motor'],
            ['WO_F8_CMV',    'Wet Oven Fan 8 – Check Motor Ventilation'],
            ['WO_GB11_CCGB', 'Wet Oven Gas Burner 11 – Check & Clean Gas Burner'],
            ['DO_F1_CMB',    'Dry Oven Fan 1 – Check Motor Bearing'],
            ['DO_F1_CM',     'Dry Oven Fan 1 – Clean Motor'],
            ['DO_F1_CMV',    'Dry Oven Fan 1 – Check Motor Ventilation'],
            ['DO_F2_CMB',    'Dry Oven Fan 2 – Check Motor Bearing'],
            ['DO_F2_CM',     'Dry Oven Fan 2 – Clean Motor'],
            ['DO_F2_CMV',    'Dry Oven Fan 2 – Check Motor Ventilation'],
            ['DO_GB12_CCGB', 'Dry Oven Gas Burner 12 – Check & Clean Gas Burner'],
            ['DO_F3_CMB',    'Dry Oven Fan 3 – Check Motor Bearing'],
            ['DO_F3_CM',     'Dry Oven Fan 3 – Clean Motor'],
            ['DO_F3_CMV',    'Dry Oven Fan 3 – Check Motor Ventilation'],
            ['DO_F4_CMB',    'Dry Oven Fan 4 – Check Motor Bearing'],
            ['DO_F4_CM',     'Dry Oven Fan 4 – Clean Motor'],
            ['DO_F4_CMV',    'Dry Oven Fan 4 – Check Motor Ventilation'],
            ['DO_GB13_CCGB', 'Dry Oven Gas Burner 13 – Check & Clean Gas Burner'],
            ['DO_F5_CMB',    'Dry Oven Fan 5 – Check Motor Bearing'],
            ['DO_F5_CM',     'Dry Oven Fan 5 – Clean Motor'],
            ['DO_F5_CMV',    'Dry Oven Fan 5 – Check Motor Ventilation'],
            ['DO_F6_CMB',    'Dry Oven Fan 6 – Check Motor Bearing'],
            ['DO_F6_CM',     'Dry Oven Fan 6 – Clean Motor'],
            ['DO_F6_CMV',    'Dry Oven Fan 6 – Check Motor Ventilation'],
            ['DO_GB14_CCGB', 'Dry Oven Gas Burner 14 – Check & Clean Gas Burner'],
            ['DO_F7_CMB',    'Dry Oven Fan 7 – Check Motor Bearing'],
            ['DO_F7_CM',     'Dry Oven Fan 7 – Clean Motor'],
            ['DO_F7_CMV',    'Dry Oven Fan 7 – Check Motor Ventilation'],
            ['DO_F8_CMB',    'Dry Oven Fan 8 – Check Motor Bearing'],
            ['DO_F8_CM',     'Dry Oven Fan 8 – Clean Motor'],
            ['DO_F8_CMV',    'Dry Oven Fan 8 – Check Motor Ventilation'],
            ['DO_GB15_CCGB', 'Dry Oven Gas Burner 15 – Check & Clean Gas Burner'],
            ['PLO_F1_CMB',   'Post Leach Oven Fan 1 – Check Motor Bearing'],
            ['PLO_F1_CM',    'Post Leach Oven Fan 1 – Clean Motor'],
            ['PLO_F1_CMV',   'Post Leach Oven Fan 1 – Check Motor Ventilation'],
            ['PLO_F2_CMB',   'Post Leach Oven Fan 2 – Check Motor Bearing'],
            ['PLO_F2_CM',    'Post Leach Oven Fan 2 – Clean Motor'],
            ['PLO_F2_CMV',   'Post Leach Oven Fan 2 – Check Motor Ventilation'],
            ['PLO_GB16_CCGB','Post Leach Oven Gas Burner 16 – Check & Clean Gas Burner'],
            ['FCO_F1_CMB',   'Final Curing Oven Fan 1 – Check Motor Bearing'],
            ['FCO_F1_CM',    'Final Curing Oven Fan 1 – Clean Motor'],
            ['FCO_F1_CMV',   'Final Curing Oven Fan 1 – Check Motor Ventilation'],
            ['FCO_F2_CMB',   'Final Curing Oven Fan 2 – Check Motor Bearing'],
            ['FCO_F2_CM',    'Final Curing Oven Fan 2 – Clean Motor'],
            ['FCO_F2_CMV',   'Final Curing Oven Fan 2 – Check Motor Ventilation'],
            ['FCO_GB17_CCGB','Final Curing Oven Gas Burner 17 – Check & Clean Gas Burner'],
            ['FCO_F3_CMB',   'Final Curing Oven Fan 3 – Check Motor Bearing'],
            ['FCO_F3_CM',    'Final Curing Oven Fan 3 – Clean Motor'],
            ['FCO_F3_CMV',   'Final Curing Oven Fan 3 – Check Motor Ventilation'],
            ['FCO_F4_CMB',   'Final Curing Oven Fan 4 – Check Motor Bearing'],
            ['FCO_F4_CM',    'Final Curing Oven Fan 4 – Clean Motor'],
            ['FCO_F4_CMV',   'Final Curing Oven Fan 4 – Check Motor Ventilation'],
            ['FCO_GB18_CCGB','Final Curing Oven Gas Burner 18 – Check & Clean Gas Burner'],
        ];

        $oven_sections = [
            ['label' => '1 – Former Oven',        'prefix' => 'FO_'],
            ['label' => '2 – Coagulant Oven',     'prefix' => 'CO_'],
            ['label' => '3 – Latex Oven 1',       'prefix' => 'LO1_'],
            ['label' => '4 – Latex Oven 2',       'prefix' => 'LO2_'],
            ['label' => '5 – Pre Leach Oven',     'prefix' => 'PO_'],
            ['label' => '7 – Wet Oven',           'prefix' => 'WO_'],
            ['label' => '8 – Dry Oven',           'prefix' => 'DO_'],
            ['label' => '9 – Post Leach Oven',    'prefix' => 'PLO_'],
            ['label' => '10 – Final Curing Oven', 'prefix' => 'FCO_'],
        ];

        $cell = function($v): string {
            if ((string)$v === '1') {
                return '<td style="text-align:center;width:62px;font-weight:bold;'
                     . 'font-size:12pt;color:#155724;">&#10003;</td>';
            }
            if ((string)$v === '0') {
                return '<td style="text-align:center;width:62px;font-weight:bold;'
                     . 'font-size:12pt;color:#721c24;">&#10007;</td>';
            }
            return '<td style="text-align:center;width:62px;">&nbsp;</td>';
        };

        $week_header_cells = '';
        for ($w = 1; $w <= 5; $w++) {
            $date = htmlspecialchars($record['WEEK' . $w . '_DATE'] ?? '');
            $time = htmlspecialchars($record['WEEK' . $w . '_TIME'] ?? '');
            $sub  = '';
            if ($date !== '') {
                $sub .= '<br><span style="font-size:7pt;font-weight:normal;">' . $date . '</span>';
            }
            if ($time !== '') {
                $sub .= '<br><span style="font-size:7pt;font-weight:normal;">' . $time . '</span>';
            }
            $week_header_cells .= '<th style="text-align:center;width:62px;white-space:nowrap;">Week&nbsp;' . $w . $sub . '</th>';
        }

        $tbody      = '';
        $row_num    = 1;
        $cur_prefix = '';

        foreach ($checklist as $item) {
            $col = $item[0];
            $lbl = $item[1];

            foreach ($oven_sections as $sec) {
                if (strpos($col, $sec['prefix']) === 0 && $cur_prefix !== $sec['prefix']) {
                    $cur_prefix = $sec['prefix'];
                    $tbody .= '<tr>'
                            . '<td colspan="7" style="background:#000;color:#fff;'
                            . 'font-weight:bold;font-size:9pt;padding:4px 8px;">'
                            . 'Oven ' . htmlspecialchars($sec['label'])
                            . '</td></tr>';
                    break;
                }
            }

            $is_gb  = (strpos($col, '_CCGB') !== false);
            $row_bg = $is_gb ? 'background:#ebebeb;' : '';

            $tbody .= '<tr style="' . $row_bg . '">'
                    . '<td style="text-align:center;width:28px;font-size:8pt;">' . $row_num++ . '</td>'
                    . '<td style="font-size:8.5pt;padding:3px 6px;">' . htmlspecialchars($lbl) . '</td>';

            for ($w = 1; $w <= 5; $w++) {
                $v = $week_items[$w][$col] ?? null;
                $tbody .= $cell($v);
            }

            $tbody .= '</tr>';
        }

        $html = '<!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <title>Preventive Maintenance Checklist</title>
            <style>
                body  { font-family: Arial, sans-serif; font-size: 9pt; margin: 0; color: #000; }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #000; padding: 3px 5px; }
                .nb th, .nb td { border: none; padding: 2px 4px; }
                thead th { background: #000; color: #fff; font-weight: bold; }
            </style>
            </head>
            <body>

            <table class="nb" style="margin-bottom:6px;">
            <tr>
                <td style="vertical-align:middle;">
                    <div style="font-size:14pt;font-weight:bold;">PREVENTIVE MAINTENANCE CHECKLIST</div>
                    <div style="font-size:8pt;margin-top:2px;">Oven Blower Motor &amp; Gas Burner Checklist</div>
                    <div style="font-size:7.5pt;margin-top:1px;">
                        Document No.: WIPUR-03-PM &nbsp;|&nbsp; Revision No.: 1
                    </div>
                </td>
                <td style="text-align:right;vertical-align:middle;width:160px;">
                    <img src="' . $logo . '" alt="Logo" height="50">
                </td>
            </tr>
            </table>

            <hr style="border:1px solid #000;margin:4px 0 6px 0;">

            <table class="nb" style="margin-bottom:8px;border:1px solid #000;">
            <tr>
                <td style="width:115px;font-weight:bold;font-size:8.5pt;padding:3px 6px;">Inspection Month</td>
                <td style="font-size:8.5pt;padding:3px 6px;">
                    : ' . htmlspecialchars($record['INSPECTION_MONTH'] ?? '') . '
                </td>
                <td style="width:85px;font-weight:bold;font-size:8.5pt;padding:3px 6px;">Status</td>
                <td style="font-size:8.5pt;padding:3px 6px;">
                    : ' . htmlspecialchars($record['PM_STATUS'] ?? '') . '
                </td>
            </tr>
            <tr>
                <td style="font-weight:bold;font-size:8.5pt;padding:3px 6px;">Line</td>
                <td style="font-size:8.5pt;padding:3px 6px;">
                    : ' . htmlspecialchars($record['LINE'] ?? '') . '
                </td>
                <td style="font-weight:bold;font-size:8.5pt;padding:3px 6px;">Verified By</td>
                <td style="font-size:8.5pt;padding:3px 6px;">
                    : ' . htmlspecialchars($record['VERIFIED_BY_NAME'] ?? $record['VERIFIED_BY'] ?? '') . '
                </td>
            </tr>
            <tr>
                <td style="font-weight:bold;font-size:8.5pt;padding:3px 6px;">Model</td>
                <td style="font-size:8.5pt;padding:3px 6px;">
                    : ' . htmlspecialchars($record['MODEL'] ?? '') . '
                </td>
                <td style="font-weight:bold;font-size:8.5pt;padding:3px 6px;">Created By</td>
                <td style="font-size:8.5pt;padding:3px 6px;">
                    : ' . htmlspecialchars($record['CREATED_BY_NAME'] ?? '') . '
                </td>
            </tr>
            <tr>
                <td style="font-weight:bold;font-size:8.5pt;padding:3px 6px;">Plant</td>
                <td style="font-size:8.5pt;padding:3px 6px;">
                    : ' . htmlspecialchars($record['PLANT'] ?? '') . '
                </td>
                <td style="font-weight:bold;font-size:8.5pt;padding:3px 6px;">Created At</td>
                <td style="font-size:8.5pt;padding:3px 6px;">
                    : ' . htmlspecialchars($record['CREATED_AT'] ?? '') . '
                </td>
            </tr>
            <tr>
                <td style="font-weight:bold;font-size:8.5pt;padding:3px 6px;">Remarks</td>
                <td colspan="3" style="font-size:8.5pt;padding:3px 6px;">
                    : ' . nl2br(htmlspecialchars($record['REMARKS'] ?? '')) . '
                </td>
            </tr>
            </table>

            <table class="nb" style="margin-bottom:5px;">
            <tr>
                <td style="font-size:7.5pt;padding:2px 0;">
                    <b>Legend:</b>&nbsp;
                    <b style="color:#155724;">&#10003;</b> = All in good condition &nbsp;&nbsp;
                    <b style="color:#721c24;">&#10007;</b> = Faulty / Issue found &nbsp;&nbsp;
                    <span style="background:#ebebeb;padding:1px 4px;border:1px solid #000;">shaded</span>
                    = Gas Burner row &nbsp;&nbsp;
                    blank = Not yet inspected
                </td>
            </tr>
            </table>

            <table>
            <thead>
            <tr>
                <th style="text-align:center;width:28px;">#</th>
                <th style="text-align:left;">Particulars</th>
                ' . $week_header_cells . '
            </tr>
            </thead>
            <tbody>
            ' . $tbody . '
            </tbody>
            </table>

            <br>

            <table class="nb" style="margin-top:10px;">
            <tr>
                <td style="width:50%;vertical-align:top;font-size:8.5pt;padding:4px;">
                    <b>Verified By:</b><br><br>
                    ___________________________________<br>
                    ' . htmlspecialchars($record['VERIFIED_BY_NAME'] ?? '') . '<br>
                    Date:&nbsp;______________________
                </td>
                <td style="width:50%;vertical-align:top;font-size:8.5pt;padding:4px;">
                    <b>Prepared By:</b><br><br>
                    ___________________________________<br>
                    ' . htmlspecialchars($record['CREATED_BY_NAME'] ?? '') . '<br>
                    Date:&nbsp;______________________
                </td>
            </tr>
            </table>

            <hr style="margin-top:10px;border:1px solid #000;">
            <p style="text-align:center;font-size:7.5pt;margin:3px 0;">
                <em>*This is a computer-generated document. No signature is required.</em>
            </p>

            </body>
            </html>';

        $tmp_dir = $_SERVER['DOCUMENT_ROOT'] . '/webdocs/preventive_maintenance/tmp/mpdf_custom/';
        if (!file_exists($tmp_dir)) {
            mkdir($tmp_dir, 0777, true);
        }

        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode'              => 'UTF-8',
                'format'            => 'A4',
                'margin_left'       => 8,
                'margin_right'      => 8,
                'margin_top'        => 8,
                'margin_bottom'     => 8,
                'default_font_size' => 8,
                'autoLangToFont'    => true,
                'tempDir'           => $tmp_dir,
                'curlAllowUnsafeSslRequests' => true,
            ]);

            $mpdf->SetDisplayMode('fullpage');
            $mpdf->SetTitle('Preventive Maintenance Checklist');
            $mpdf->SetAuthor('GRAND TEN HOLDINGS SDN. BHD.');
            $mpdf->WriteHTML($html);

            $fileName = time() . '_preventive_maintenance_' . $record_id;
            $type     = $_GET['type'] ?? 'I';
            $mpdf->Output($fileName . '.pdf', $type);

        } catch (\Mpdf\MpdfException $e) {
            echo 'Error generating PDF: ' . $e->getMessage();
            exit;
        }
    }


    // ── Drawing XML generator ─────────────────────────────────────────────────
    // Produces a text box in the top-right corner (cols N–O, rows 1–4) that
    // matches the original template:  Doc.No / Rev / Eff. Date
    // sheetId is used to give each shape a unique id (1-based).
    private static function make_drawing_xml(int $sheetId = 1): string
    {
        // Reusable run-property block: Arial 10 pt, black
        $rpr = '<a:rPr lang="en-MY" sz="1000" b="0" i="0" u="none" strike="noStrike" baseline="0">'
             . '<a:solidFill><a:srgbClr val="000000"/></a:solidFill>'
             . '<a:latin typeface="Arial" panose="020B0604020202020204"/>'
             . '<a:cs    typeface="Arial" panose="020B0604020202020204"/>'
             . '</a:rPr>';

        // Helper: one paragraph with a single text run
        $para = function(string $text) use ($rpr): string {
            return '<a:p>'
                 . '<a:pPr algn="l" rtl="0"><a:defRPr sz="1000"/></a:pPr>'
                 . '<a:r>' . $rpr
                 . '<a:t>' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</a:t>'
                 . '</a:r>'
                 . '</a:p>';
        };

        // Minimal lstStyle required by the schema
        $lstStyle = '<a:lstStyle>'
                  . '<a:defPPr><a:defRPr lang="en-US"/></a:defPPr>'
                  . '<a:lvl1pPr marL="0" algn="l" defTabSz="914400" rtl="0"'
                  . ' eaLnBrk="1" latinLnBrk="0" hangingPunct="1">'
                  . '<a:defRPr sz="1100">'
                  . '<a:latin typeface="+mn-lt"/>'
                  . '<a:ea typeface="+mn-ea"/>'
                  . '<a:cs typeface="+mn-cs"/>'
                  . '</a:defRPr>'
                  . '</a:lvl1pPr>'
                  . '</a:lstStyle>';

        // Shape id is unique per drawing; using sheetId keeps them distinct
        $spId = $sheetId * 10 + 1;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<xdr:wsDr'
             . ' xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"'
             . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'

             // ── Text box: top-right corner, cols N(13)–O(14), rows 0–3 ────────
             . '<xdr:twoCellAnchor>'
               . '<xdr:from>'
                . '<xdr:col>14</xdr:col><xdr:colOff>0</xdr:colOff>'
                . '<xdr:row>0</xdr:row><xdr:rowOff>9525</xdr:rowOff>'
                . '</xdr:from>'
                . '<xdr:to>'
                . '<xdr:col>14</xdr:col><xdr:colOff>1642110</xdr:colOff>'
                . '<xdr:row>3</xdr:row><xdr:rowOff>25400</xdr:rowOff>'
                . '</xdr:to>'

               . '<xdr:sp macro="" textlink="">'
                 . '<xdr:nvSpPr>'
                   . '<xdr:cNvPr id="' . $spId . '" name="Text Box ' . $sheetId . '"/>'
                   . '<xdr:cNvSpPr txBox="1">'
                     . '<a:spLocks noChangeArrowheads="1"/>'
                   . '</xdr:cNvSpPr>'
                 . '</xdr:nvSpPr>'

                 . '<xdr:spPr>'
                   . '<a:xfrm>'
                    . '<a:off x="13430000" y="9525"/>'
                    . '<a:ext cx="950000" cy="768350"/>'
                    . '</a:xfrm>'
                   . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
                   . '<a:noFill/>'
                    . '<a:ln><a:noFill/></a:ln>'
                 . '</xdr:spPr>'

                 . '<xdr:txBody>'
                   . '<a:bodyPr vertOverflow="clip" wrap="square"'
                   . ' lIns="0" tIns="22860" rIns="27432" bIns="0"'
                   . ' anchor="t" upright="1"/>'
                   . $lstStyle
                   . $para('Doc.No: YTA 0901/04')
                   . $para('Rev : 1')
                   . $para('Eff. Date: 01/12/2019')
                 . '</xdr:txBody>'
               . '</xdr:sp>'
               . '<xdr:clientData/>'
             . '</xdr:twoCellAnchor>'

             . '</xdr:wsDr>';
    }


    public static function excel_generate_pm($record_id = '')
    {
        $record = Common::view_checklist_by_month_group($record_id);
        if (empty($record)) {
            echo 'No verified checklist found for this month yet. The Excel can only be generated once at least one week has been Verified.';
            exit;
        }

        $week_items = $record['week_items'] ?? [];

        $remarksVal = $record['REMARKS'] ?? '';
        if (is_object($remarksVal) && method_exists($remarksVal, 'load')) {
            $remarksVal = $remarksVal->load();
        }
        $remarksVal = (string)($remarksVal ?? '');

        // ── Checklist layout ──────────────────────────────────────────────────
        $layout = [
            // 1 – Former Oven
            ['1','Former Oven',       'Former Oven Fan 1',       'Check Motor Bearing',       'FO_F1_CMB'],
            ['1','Former Oven',       'Former Oven Fan 1',       'Clean Motor',               'FO_F1_CM'],
            ['1','Former Oven',       'Former Oven Fan 1',       'Check Motor Ventilation',   'FO_F1_CMV'],
            ['1','Former Oven',       'Former Oven Fan 2',       'Check Motor Bearing',       'FO_F2_CMB'],
            ['1','Former Oven',       'Former Oven Fan 2',       'Clean Motor',               'FO_F2_CM'],
            ['1','Former Oven',       'Former Oven Fan 2',       'Check Motor Ventilation',   'FO_F2_CMV'],
            ['1','Former Oven',       'Gas burner 1',            'Check and Clean Gas Burner','FO_GB1_CCGB'],

            // 2 – Coagulant Oven
            ['2','Coagulant Oven',    'Coagulant Oven Fan 1',   'Check Motor Bearing',       'CO_F1_CMB'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 1',   'Clean Motor',               'CO_F1_CM'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 1',   'Check Motor Ventilation',   'CO_F1_CMV'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 2',   'Check Motor Bearing',       'CO_F2_CMB'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 2',   'Clean Motor',               'CO_F2_CM'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 2',   'Check Motor Ventilation',   'CO_F2_CMV'],
            ['2','Coagulant Oven',    'Gas burner 2',            'Check and Clean Gas Burner','CO_GB2_CCGB'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 3',   'Check Motor Bearing',       'CO_F3_CMB'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 3',   'Clean Motor',               'CO_F3_CM'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 3',   'Check Motor Ventilation',   'CO_F3_CMV'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 4',   'Check Motor Bearing',       'CO_F4_CMB'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 4',   'Clean Motor',               'CO_F4_CM'],
            ['2','Coagulant Oven',    'Coagulant Oven Fan 4',   'Check Motor Ventilation',   'CO_F4_CMV'],
            ['2','Coagulant Oven',    'Gas burner 3',            'Check and Clean Gas Burner','CO_GB3_CCGB'],

            // 3 – Latex Oven 1
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 1',     'Check Motor Bearing',       'LO1_F1_CMB'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 1',     'Clean Motor',               'LO1_F1_CM'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 1',     'Check Motor Ventilation',   'LO1_F1_CMV'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 2',     'Check Motor Bearing',       'LO1_F2_CMB'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 2',     'Clean Motor',               'LO1_F2_CM'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 2',     'Check Motor Ventilation',   'LO1_F2_CMV'],
            ['3','Latex Oven 1',      'Gas burner 4',            'Check and Clean Gas Burner','LO1_GB4_CCGB'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 3',     'Check Motor Bearing',       'LO1_F3_CMB'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 3',     'Clean Motor',               'LO1_F3_CM'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 3',     'Check Motor Ventilation',   'LO1_F3_CMV'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 4',     'Check Motor Bearing',       'LO1_F4_CMB'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 4',     'Clean Motor',               'LO1_F4_CM'],
            ['3','Latex Oven 1',      'Latex Oven 1 Fan 4',     'Check Motor Ventilation',   'LO1_F4_CMV'],
            ['3','Latex Oven 1',      'Gas burner 5',            'Check and Clean Gas Burner','LO1_GB5_CCGB'],

            // 4 – Latex Oven 2
            ['4','Latex Oven 2',      'Latex Oven 2 Fan 1',     'Check Motor Bearing',       'LO2_F1_CMB'],
            ['4','Latex Oven 2',      'Latex Oven 2 Fan 1',     'Clean Motor',               'LO2_F1_CM'],
            ['4','Latex Oven 2',      'Latex Oven 2 Fan 1',     'Check Motor Ventilation',   'LO2_F1_CMV'],
            ['4','Latex Oven 2',      'Latex Oven 2 Fan 2',     'Check Motor Bearing',       'LO2_F2_CMB'],
            ['4','Latex Oven 2',      'Latex Oven 2 Fan 2',     'Clean Motor',               'LO2_F2_CM'],
            ['4','Latex Oven 2',      'Latex Oven 2 Fan 2',     'Check Motor Ventilation',   'LO2_F2_CMV'],
            ['4','Latex Oven 2',      'Gas burner 6',            'Check and Clean Gas Burner','LO2_GB6_CCGB'],

            // 5 – Pre Leach Oven
            ['5','Pre Leach oven',    'Pre Leach Oven Fan 1',   'Check Motor Bearing',       'PO_F1_CMB'],
            ['5','Pre Leach oven',    'Pre Leach Oven Fan 1',   'Clean Motor',               'PO_F1_CM'],
            ['5','Pre Leach oven',    'Pre Leach Oven Fan 1',   'Check Motor Ventilation',   'PO_F1_CMV'],
            ['5','Pre Leach oven',    'Pre Leach Oven Fan 2',   'Check Motor Bearing',       'PO_F2_CMB'],
            ['5','Pre Leach oven',    'Pre Leach Oven Fan 2',   'Clean Motor',               'PO_F2_CM'],
            ['5','Pre Leach oven',    'Pre Leach Oven Fan 2',   'Check Motor Ventilation',   'PO_F2_CMV'],
            ['5','Pre Leach oven',    'Gas burner 7',            'Check and Clean Gas Burner','PO_GB7_CCGB'],

            // 7 – Wet Oven
            ['7','Wet Oven',          'Wet Oven Fan 1',          'Check Motor Bearing',       'WO_F1_CMB'],
            ['7','Wet Oven',          'Wet Oven Fan 1',          'Clean Motor',               'WO_F1_CM'],
            ['7','Wet Oven',          'Wet Oven Fan 1',          'Check Motor Ventilation',   'WO_F1_CMV'],
            ['7','Wet Oven',          'Wet Oven Fan 2',          'Check Motor Bearing',       'WO_F2_CMB'],
            ['7','Wet Oven',          'Wet Oven Fan 2',          'Clean Motor',               'WO_F2_CM'],
            ['7','Wet Oven',          'Wet Oven Fan 2',          'Check Motor Ventilation',   'WO_F2_CMV'],
            ['7','Wet Oven',          'Gas burner 8',            'Check and Clean Gas Burner','WO_GB8_CCGB'],
            ['7','Wet Oven',          'Wet Oven Fan 3',          'Check Motor Bearing',       'WO_F3_CMB'],
            ['7','Wet Oven',          'Wet Oven Fan 3',          'Clean Motor',               'WO_F3_CM'],
            ['7','Wet Oven',          'Wet Oven Fan 3',          'Check Motor Ventilation',   'WO_F3_CMV'],
            ['7','Wet Oven',          'Wet Oven Fan 4',          'Check Motor Bearing',       'WO_F4_CMB'],
            ['7','Wet Oven',          'Wet Oven Fan 4',          'Clean Motor',               'WO_F4_CM'],
            ['7','Wet Oven',          'Wet Oven Fan 4',          'Check Motor Ventilation',   'WO_F4_CMV'],
            ['7','Wet Oven',          'Gas burner 9',            'Check and Clean Gas Burner','WO_GB9_CCGB'],
            ['7','Wet Oven',          'Wet Oven Fan 5',          'Check Motor Bearing',       'WO_F5_CMB'],
            ['7','Wet Oven',          'Wet Oven Fan 5',          'Clean Motor',               'WO_F5_CM'],
            ['7','Wet Oven',          'Wet Oven Fan 5',          'Check Motor Ventilation',   'WO_F5_CMV'],
            ['7','Wet Oven',          'Wet Oven Fan 6',          'Check Motor Bearing',       'WO_F6_CMB'],
            ['7','Wet Oven',          'Wet Oven Fan 6',          'Clean Motor',               'WO_F6_CM'],
            ['7','Wet Oven',          'Wet Oven Fan 6',          'Check Motor Ventilation',   'WO_F6_CMV'],
            ['7','Wet Oven',          'Gas burner 10',           'Check and Clean Gas Burner','WO_GB10_CCGB'],
            ['7','Wet Oven',          'Wet Oven Fan 7',          'Check Motor Bearing',       'WO_F7_CMB'],
            ['7','Wet Oven',          'Wet Oven Fan 7',          'Clean Motor',               'WO_F7_CM'],
            ['7','Wet Oven',          'Wet Oven Fan 7',          'Check Motor Ventilation',   'WO_F7_CMV'],
            ['7','Wet Oven',          'Wet Oven Fan 8',          'Check Motor Bearing',       'WO_F8_CMB'],
            ['7','Wet Oven',          'Wet Oven Fan 8',          'Clean Motor',               'WO_F8_CM'],
            ['7','Wet Oven',          'Wet Oven Fan 8',          'Check Motor Ventilation',   'WO_F8_CMV'],
            ['7','Wet Oven',          'Gas burner 11',           'Check and Clean Gas Burner','WO_GB11_CCGB'],

            // 8 – Dry Oven
            ['8','Dry Oven',          'Dry Oven Fan 1',          'Check Motor Bearing',       'DO_F1_CMB'],
            ['8','Dry Oven',          'Dry Oven Fan 1',          'Clean Motor',               'DO_F1_CM'],
            ['8','Dry Oven',          'Dry Oven Fan 1',          'Check Motor Ventilation',   'DO_F1_CMV'],
            ['8','Dry Oven',          'Dry Oven Fan 2',          'Check Motor Bearing',       'DO_F2_CMB'],
            ['8','Dry Oven',          'Dry Oven Fan 2',          'Clean Motor',               'DO_F2_CM'],
            ['8','Dry Oven',          'Dry Oven Fan 2',          'Check Motor Ventilation',   'DO_F2_CMV'],
            ['8','Dry Oven',          'Gas burner 12',           'Check and Clean Gas Burner','DO_GB12_CCGB'],
            ['8','Dry Oven',          'Dry Oven Fan 3',          'Check Motor Bearing',       'DO_F3_CMB'],
            ['8','Dry Oven',          'Dry Oven Fan 3',          'Clean Motor',               'DO_F3_CM'],
            ['8','Dry Oven',          'Dry Oven Fan 3',          'Check Motor Ventilation',   'DO_F3_CMV'],
            ['8','Dry Oven',          'Dry Oven Fan 4',          'Check Motor Bearing',       'DO_F4_CMB'],
            ['8','Dry Oven',          'Dry Oven Fan 4',          'Clean Motor',               'DO_F4_CM'],
            ['8','Dry Oven',          'Dry Oven Fan 4',          'Check Motor Ventilation',   'DO_F4_CMV'],
            ['8','Dry Oven',          'Gas burner 13',           'Check and Clean Gas Burner','DO_GB13_CCGB'],
            ['8','Dry Oven',          'Dry Oven Fan 5',          'Check Motor Bearing',       'DO_F5_CMB'],
            ['8','Dry Oven',          'Dry Oven Fan 5',          'Clean Motor',               'DO_F5_CM'],
            ['8','Dry Oven',          'Dry Oven Fan 5',          'Check Motor Ventilation',   'DO_F5_CMV'],
            ['8','Dry Oven',          'Dry Oven Fan 6',          'Check Motor Bearing',       'DO_F6_CMB'],
            ['8','Dry Oven',          'Dry Oven Fan 6',          'Clean Motor',               'DO_F6_CM'],
            ['8','Dry Oven',          'Dry Oven Fan 6',          'Check Motor Ventilation',   'DO_F6_CMV'],
            ['8','Dry Oven',          'Gas burner 14',           'Check and Clean Gas Burner','DO_GB14_CCGB'],
            ['8','Dry Oven',          'Dry Oven Fan 7',          'Check Motor Bearing',       'DO_F7_CMB'],
            ['8','Dry Oven',          'Dry Oven Fan 7',          'Clean Motor',               'DO_F7_CM'],
            ['8','Dry Oven',          'Dry Oven Fan 7',          'Check Motor Ventilation',   'DO_F7_CMV'],
            ['8','Dry Oven',          'Dry Oven Fan 8',          'Check Motor Bearing',       'DO_F8_CMB'],
            ['8','Dry Oven',          'Dry Oven Fan 8',          'Clean Motor',               'DO_F8_CM'],
            ['8','Dry Oven',          'Dry Oven Fan 8',          'Check Motor Ventilation',   'DO_F8_CMV'],
            ['8','Dry Oven',          'Gas burner 15',           'Check and Clean Gas Burner','DO_GB15_CCGB'],

            // 9 – Post Leach Oven
            ['9','Post Leach Oven',   'Post Leach Oven Fan 1',  'Check Motor Bearing',       'PLO_F1_CMB'],
            ['9','Post Leach Oven',   'Post Leach Oven Fan 1',  'Clean Motor',               'PLO_F1_CM'],
            ['9','Post Leach Oven',   'Post Leach Oven Fan 1',  'Check Motor Ventilation',   'PLO_F1_CMV'],
            ['9','Post Leach Oven',   'Post Leach Oven Fan 2',  'Check Motor Bearing',       'PLO_F2_CMB'],
            ['9','Post Leach Oven',   'Post Leach Oven Fan 2',  'Clean Motor',               'PLO_F2_CM'],
            ['9','Post Leach Oven',   'Post Leach Oven Fan 2',  'Check Motor Ventilation',   'PLO_F2_CMV'],
            ['9','Post Leach Oven',   'Gas burner 16',           'Check and Clean Gas Burner','PLO_GB16_CCGB'],

            // 10 – Final Curing Oven
            ['10','Final Curing Oven','Final Oven Fan 1',        'Check Motor Bearing',       'FCO_F1_CMB'],
            ['10','Final Curing Oven','Final Oven Fan 1',        'Clean Motor',               'FCO_F1_CM'],
            ['10','Final Curing Oven','Final Oven Fan 1',        'Check Motor Ventilation',   'FCO_F1_CMV'],
            ['10','Final Curing Oven','Final Oven Fan 2',        'Check Motor Bearing',       'FCO_F2_CMB'],
            ['10','Final Curing Oven','Final Oven Fan 2',        'Clean Motor',               'FCO_F2_CM'],
            ['10','Final Curing Oven','Final Oven Fan 2',        'Check Motor Ventilation',   'FCO_F2_CMV'],
            ['10','Final Curing Oven','Gas burner 17',           'Check and Clean Gas Burner','FCO_GB17_CCGB'],
            ['10','Final Curing Oven','Final Oven Fan 3',        'Check Motor Bearing',       'FCO_F3_CMB'],
            ['10','Final Curing Oven','Final Oven Fan 3',        'Clean Motor',               'FCO_F3_CM'],
            ['10','Final Curing Oven','Final Oven Fan 3',        'Check Motor Ventilation',   'FCO_F3_CMV'],
            ['10','Final Curing Oven','Final Oven Fan 4',        'Check Motor Bearing',       'FCO_F4_CMB'],
            ['10','Final Curing Oven','Final Oven Fan 4',        'Clean Motor',               'FCO_F4_CM'],
            ['10','Final Curing Oven','Final Oven Fan 4',        'Check Motor Ventilation',   'FCO_F4_CMV'],
            ['10','Final Curing Oven','Gas burner 18',           'Check and Clean Gas Burner','FCO_GB18_CCGB'],
        ];

        $page1_rows = array_filter($layout, fn($r) => in_array($r[0], ['1','2','3','4','5','7']));
        $page2_rows = array_filter($layout, fn($r) => in_array($r[0], ['8','9','10']));

        // ── XML helpers ───────────────────────────────────────────────────────
        $x = fn(string $s): string => htmlspecialchars($s, ENT_XML1, 'UTF-8');

        // ── Shared strings ────────────────────────────────────────────────────
        $sharedStrings = [];
        $siIndex = [];
        $addSS = function(string $val) use (&$sharedStrings, &$siIndex): int {
            if (!isset($siIndex[$val])) {
                $siIndex[$val] = count($sharedStrings);
                $sharedStrings[] = $val;
            }
            return $siIndex[$val];
        };

        // ── Styles XML ────────────────────────────────────────────────────────
        $stylesXml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
        <fonts count="6">
        <font><sz val="11"/><name val="Calibri"/></font>
        <font><sz val="18"/><b/><name val="Arial Narrow"/></font>
        <font><sz val="14"/><u/><name val="Arial Narrow"/></font>
        <font><sz val="10"/><name val="Arial Narrow"/></font>
        <font><sz val="11"/><b/><name val="Arial Narrow"/></font>
        <font><sz val="11"/><b/><name val="Arial Narrow"/></font>
        <font><sz val="11"/><b/><name val="Calibri"/></font>
        </fonts>
        <fills count="2">
        <fill><patternFill patternType="none"/></fill>
        <fill><patternFill patternType="gray125"/></fill>
        </fills>
        <borders count="3">
        <border><left/><right/><top/><bottom/><diagonal/></border>
        <border>
            <left style="thin"><color rgb="FF000000"/></left>
            <right style="thin"><color rgb="FF000000"/></right>
            <top style="thin"><color rgb="FF000000"/></top>
            <bottom style="thin"><color rgb="FF000000"/></bottom>
            <diagonal/>
        </border>
        <border>
            <left style="thin"><color rgb="FF000000"/></left>
            <right style="thin"><color rgb="FF000000"/></right>
            <top style="thin"><color rgb="FF000000"/></top>
            <bottom style="thin"><color rgb="FF000000"/></bottom>
        </border>
        </borders>
        <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
        <cellXfs count="20">
        <!-- 0: normal bordered center -->
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 1: company name -->
        <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 2: title size 14 centered -->
        <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 3: subtitle size 14 centered -->
        <xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 4: legend size 10 left -->
        <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment horizontal="left" vertical="center"/></xf>
        <!-- 5: month/info line size 10 left -->
        <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment horizontal="left" vertical="center"/></xf>
        <!-- 6: Weekly header bold bordered center -->
        <xf numFmtId="0" fontId="0" fillId="0" borderId="2" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 7: Date/Time labels bold center bordered -->
        <xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 8: column header row bold center bordered -->
        <xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <!-- 9: No cell bold center bordered -->
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 10: Oven/Section bold center bordered wrap -->
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <!-- 11: Component bold center bordered wrap -->
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <!-- 12: Particular bold center bordered -->
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <!-- 13: Gas-burner particular bold italic center bordered -->
        <xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
        <!-- 14: tick cell bold center bordered -->
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 15: cross cell bold center bordered -->
        <xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 16: empty week cell bordered center -->
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 17: Remarks header bordered bold -->
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        <!-- 18: footer label bold thin-border left -->
        <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment horizontal="left" vertical="center"/></xf>
        <!-- 19: page label size 10 no border center -->
        <xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
        </cellXfs>
        </styleSheet>
        XML;

        // ── Column definitions (shared by both sheets) ─────────────────────────
        $colDefsXml = '<cols>'
            . '<col min="1"  max="1"  width="3.375"  customWidth="1"/>'
            . '<col min="2"  max="2"  width="3.75"   customWidth="1"/>'
            . '<col min="3"  max="3"  width="9.125"  customWidth="1"/>'
            . '<col min="4"  max="4"  width="6.875"  customWidth="1"/>'
            . '<col min="5"  max="5"  width="5.125"  customWidth="1"/>'
            . '<col min="6"  max="6"  width="7.625"  customWidth="1"/>'
            . '<col min="7"  max="7"  width="8.125"  customWidth="1"/>'
            . '<col min="8"  max="8"  width="6.25"   customWidth="1"/>'
            . '<col min="9"  max="9"  width="18.875" customWidth="1"/>'
            . '<col min="10" max="14" width="15.75"  customWidth="1"/>'
            . '<col min="15" max="15" width="25.75"  customWidth="1"/>'
            . '</cols>';

        // ── Sheet builder closure ─────────────────────────────────────────────
        $buildSheet = function(
            array    $rows,
            int      $pageNum,
            array    $record,
            array    $week_items,
            callable $addSS,
            string   $colDefsXml,
            callable $x
        ): array {
            $rowXml = '';
            $merges = [];
            $r      = 1;

            $inspMonth = $record['INSPECTION_MONTH'] ?? '';
            $line      = $record['LINE']             ?? '';
            $model     = $record['MODEL']            ?? '';
            $plant     = $record['PLANT']            ?? '';

            // Row 1: Company name
            $si = $addSS('YTY ACHEH');
            $rowXml .= '<row r="'.$r.'" ht="23.25" customHeight="1">'
                    . '<c r="A'.$r.'" t="s" s="1"><v>'.$si.'</v></c>'
                    . '</row>';
            $merges[] = 'A'.$r.':O'.$r;
            $r++;

            // Row 2: Title
            $si = $addSS('PREVENTIVE MAINTENANCE CHECKLIST');
            $rowXml .= '<row r="'.$r.'" ht="18" customHeight="1">'
                    . '<c r="A'.$r.'" t="s" s="2"><v>'.$si.'</v></c>'
                    . '</row>';
            $merges[] = 'A'.$r.':O'.$r;
            $r++;

            // Row 3: Subtitle
            $si = $addSS('FOR OVEN BLOWER MOTOR AND GAS BURNER');
            $rowXml .= '<row r="'.$r.'" ht="18" customHeight="1">'
                    . '<c r="A'.$r.'" t="s" s="3"><v>'.$si.'</v></c>'
                    . '</row>';
            $merges[] = 'A'.$r.':O'.$r;
            $r++;

            // Row 4: blank
            $rowXml .= '<row r="'.$r.'"></row>';
            $r++;

            // Row 5: Legend line 1
            $si = $addSS("\u{2713}   All in good condition");
            $rowXml .= '<row r="'.$r.'" ht="16.5" customHeight="1">'
                    . '<c r="B'.$r.'" t="s" s="4"><v>'.$si.'</v></c>'
                    . '</row>';
            $merges[] = 'B'.$r.':H'.$r;
            $r++;

            // Row 6: Legend line 2 + month/line/model/plant
            $si  = $addSS("\u{2717}   Not in order - need service or replacement");
            $inf = 'Month: '.$inspMonth.'         Line: '.$line.'     Model: '.$model.'     Plant: '.$plant;
            $si2 = $addSS($inf);
            $rowXml .= '<row r="'.$r.'" ht="16.5" customHeight="1">'
                    . '<c r="B'.$r.'" t="s" s="4"><v>'.$si.'</v></c>'
                    . '<c r="I'.$r.'" t="s" s="5"><v>'.$si2.'</v></c>'
                    . '</row>';
            $merges[] = 'B'.$r.':H'.$r;
            $merges[] = 'I'.$r.':O'.$r;
            $r++;

            // Row 7: blank
            $rowXml .= '<row r="'.$r.'"></row>';
            $r++;

            // Row 8: "Weekly" spanning J-N
            $si = $addSS('Weekly');
            $rowXml .= '<row r="'.$r.'" ht="15" customHeight="1">'
                    . '<c r="J'.$r.'" t="s" s="6"><v>'.$si.'</v></c>';
            foreach (['K','L','M','N'] as $midCol) {
                $rowXml .= '<c r="'.$midCol.$r.'" s="6"/>';
            }
            $rowXml .= '</row>';
            $merges[] = 'J'.$r.':N'.$r;
            $r++;

            // Row 9: Date row
            $siDate = $addSS('Date');
            $siRem  = $addSS('Remarks');
            $rowXml .= '<row r="'.$r.'" ht="15" customHeight="1">'
                    . '<c r="A'.$r.'" t="s" s="7"><v>'.$siDate.'</v></c>';
            foreach (['B','C','D','E','F','G','H','I'] as $midCol) {
                $rowXml .= '<c r="'.$midCol.$r.'" s="7"/>';
            }
            for ($w = 1; $w <= 5; $w++) {
                $col  = ['J','K','L','M','N'][$w - 1];
                $dVal = $record['WEEK'.$w.'_DATE'] ?? '';
                $si_d = $addSS($dVal);
                $rowXml .= '<c r="'.$col.$r.'" t="s" s="16"><v>'.$si_d.'</v></c>';
            }
            $rowXml .= '<c r="O'.$r.'" t="s" s="17"><v>'.$siRem.'</v></c>'
                    . '</row>';
            $merges[] = 'A'.$r.':I'.$r;
            $r++;

            // Row 10: Time row
            $siTime = $addSS('Time');
            $rowXml .= '<row r="'.$r.'" ht="15" customHeight="1">'
                    . '<c r="A'.$r.'" t="s" s="7"><v>'.$siTime.'</v></c>';
            foreach (['B','C','D','E','F','G','H','I'] as $midCol) {
                $rowXml .= '<c r="'.$midCol.$r.'" s="7"/>';
            }
            for ($w = 1; $w <= 5; $w++) {
                $col  = ['J','K','L','M','N'][$w - 1];
                $tVal = $record['WEEK'.$w.'_TIME'] ?? '';
                $si_t = $addSS($tVal);
                $rowXml .= '<c r="'.$col.$r.'" t="s" s="16"><v>'.$si_t.'</v></c>';
            }
            $rowXml .= '</row>';
            $merges[] = 'A'.$r.':I'.$r;
            $r++;

            // Row 11: Column headers
            $siNo   = $addSS('No ');
            $siOven = $addSS('Oven');
            $siPart = $addSS('Particulars');
            $rowXml .= '<row r="'.$r.'" ht="15" customHeight="1">'
                    . '<c r="A'.$r.'" t="s" s="8"><v>'.$siNo.'</v></c>'
                    . '<c r="B'.$r.'" t="s" s="8"><v>'.$siOven.'</v></c>'
                    . '<c r="C'.$r.'" s="8"/>'
                    . '<c r="D'.$r.'" t="s" s="8"><v>'.$siPart.'</v></c>';
            foreach (['E','F','G','H','I'] as $midCol) {
                $rowXml .= '<c r="'.$midCol.$r.'" s="8"/>';
            }
            foreach (['J','K','L','M','N','O'] as $weekHdrCol) {
                $rowXml .= '<c r="'.$weekHdrCol.$r.'" s="8"/>';
            }
            $rowXml .= '</row>';
            $merges[] = 'B'.$r.':C'.$r;
            $merges[] = 'D'.$r.':I'.$r;
            $r++;

            // ── Data rows ──────────────────────────────────────────────────────
            $rows  = array_values($rows);
            $total = count($rows);

            $noSpan   = array_fill(0, $total, 0);
            $secSpan  = array_fill(0, $total, 0);
            $compSpan = array_fill(0, $total, 0);

            $i = 0;
            while ($i < $total) {
                $noVal = $rows[$i][0];
                $cnt = 0;
                for ($j = $i; $j < $total && $rows[$j][0] === $noVal; $j++) $cnt++;
                $noSpan[$i] = $cnt;
                $secSpan[$i] = $cnt;
                $i += $cnt;
            }
            $i = 0;
            while ($i < $total) {
                $compVal = $rows[$i][2];
                $secVal  = $rows[$i][1];
                $cCnt = 0;
                for ($j = $i; $j < $total && $rows[$j][2] === $compVal && $rows[$j][1] === $secVal; $j++) $cCnt++;
                $compSpan[$i] = $cCnt;
                $i += $cCnt;
            }

            foreach ($rows as $idx => $item) {
                [$noVal, $section, $component, $particular, $dbCol] = $item;
                $isGb    = (strpos($dbCol, '_CCGB') !== false);
                $partSty = $isGb ? 13 : 12;

                $rowXml .= '<row r="'.$r.'" ht="15" customHeight="1">';

                if ($noSpan[$idx] > 0) {
                    $si = $addSS($noVal);
                    $rowXml .= '<c r="A'.$r.'" t="s" s="9"><v>'.$si.'</v></c>';
                    if ($noSpan[$idx] > 1) {
                        $merges[] = 'A'.$r.':A'.($r + $noSpan[$idx] - 1);
                    }
                } else {
                    $rowXml .= '<c r="A'.$r.'" s="9"/>';
                }

                if ($secSpan[$idx] > 0) {
                    $si = $addSS($section);
                    $rowXml .= '<c r="B'.$r.'" t="s" s="10"><v>'.$si.'</v></c>'
                            . '<c r="C'.$r.'" s="10"/>';
                    if ($secSpan[$idx] > 1) {
                        $merges[] = 'B'.$r.':C'.($r + $secSpan[$idx] - 1);
                    } else {
                        $merges[] = 'B'.$r.':C'.$r;
                    }
                } else {
                    $rowXml .= '<c r="B'.$r.'" s="10"/><c r="C'.$r.'" s="10"/>';
                }

                if ($compSpan[$idx] > 0) {
                    $si = $addSS($component);
                    $rowXml .= '<c r="D'.$r.'" t="s" s="11"><v>'.$si.'</v></c>'
                            . '<c r="E'.$r.'" s="11"/><c r="F'.$r.'" s="11"/>';
                    if ($compSpan[$idx] > 1) {
                        $merges[] = 'D'.$r.':F'.($r + $compSpan[$idx] - 1);
                    } else {
                        $merges[] = 'D'.$r.':F'.$r;
                    }
                } else {
                    $rowXml .= '<c r="D'.$r.'" s="11"/><c r="E'.$r.'" s="11"/><c r="F'.$r.'" s="11"/>';
                }

                $si = $addSS($particular);
                $rowXml .= '<c r="G'.$r.'" t="s" s="'.$partSty.'"><v>'.$si.'</v></c>'
                        . '<c r="H'.$r.'" s="'.$partSty.'"/>'
                        . '<c r="I'.$r.'" s="'.$partSty.'"/>';
                $merges[] = 'G'.$r.':I'.$r;

                $weekCols = ['J','K','L','M','N'];
                for ($w = 1; $w <= 5; $w++) {
                    $col = $weekCols[$w - 1];
                    $v   = (string)($week_items[$w][$dbCol] ?? '');
                    if ($v === '1') {
                        $si = $addSS("\u{2713}");
                        $rowXml .= '<c r="'.$col.$r.'" t="s" s="14"><v>'.$si.'</v></c>';
                    } elseif ($v === '0') {
                        $si = $addSS("\u{2717}");
                        $rowXml .= '<c r="'.$col.$r.'" t="s" s="15"><v>'.$si.'</v></c>';
                    } else {
                        $rowXml .= '<c r="'.$col.$r.'" s="16"/>';
                    }
                }

                $rowXml .= '<c r="O'.$r.'" s="16"/>';
                $rowXml .= '</row>';
                $r++;
            }

            // ── Footer rows ────────────────────────────────────────────────────
            $footerCols = ['B','C','D','E','F','G','H','I','J','K','L','M','N','O'];

            $siCB = $addSS('CHECKED BY :');
            $rowXml .= '<row r="'.$r.'" ht="11" customHeight="1">'
                    . '<c r="A'.$r.'" t="s" s="18"><v>'.$siCB.'</v></c>';
            foreach ($footerCols as $fc) { $rowXml .= '<c r="'.$fc.$r.'" s="18"/>'; }
            $rowXml .= '</row>';
            $merges[] = 'A'.$r.':O'.$r;
            $r++;

            $verifiedByName = $record['VERIFIED_BY_NAME'] ?? ($record['VERIFIED_BY'] ?? '');
            $siVB = $addSS('VERIFIED BY : ' . $verifiedByName);
            $rowXml .= '<row r="'.$r.'" ht="11" customHeight="1">'
                    . '<c r="A'.$r.'" t="s" s="18"><v>'.$siVB.'</v></c>';
            foreach ($footerCols as $fc) { $rowXml .= '<c r="'.$fc.$r.'" s="18"/>'; }
            $rowXml .= '</row>';
            $merges[] = 'A'.$r.':O'.$r;
            $r++;

            $siRM = $addSS('REMARKS : ');
            $rowXml .= '<row r="'.$r.'" ht="11" customHeight="1">'
                    . '<c r="A'.$r.'" t="s" s="18"><v>'.$siRM.'</v></c>';
            foreach ($footerCols as $fc) { $rowXml .= '<c r="'.$fc.$r.'" s="18"/>'; }
            $rowXml .= '</row>';
            $merges[] = 'A'.$r.':O'.$r;
            $r++;

            $rowXml .= '<row r="'.$r.'" ht="13" customHeight="1">'
                    . '<c r="A'.$r.'" t="inlineStr" s="19"><is><t>Page ' . $pageNum . ' of 2</t></is></c>';
            foreach ($footerCols as $fc) { $rowXml .= '<c r="'.$fc.$r.'" s="19"/>'; }
            $rowXml .= '</row>';
            $merges[] = 'A'.$r.':O'.$r;

            return ['rowXml' => $rowXml, 'merges' => $merges, 'lastRow' => $r];
        };

        // ── Build both pages ──────────────────────────────────────────────────
        $p1 = $buildSheet(array_values($page1_rows), 1, $record, $week_items, $addSS, $colDefsXml, $x);
        $p2 = $buildSheet(array_values($page2_rows), 2, $record, $week_items, $addSS, $colDefsXml, $x);

        // ── sharedStrings.xml ─────────────────────────────────────────────────
        $ssXml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ssXml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                . ' count="'.count($sharedStrings).'" uniqueCount="'.count($sharedStrings).'">';
        foreach ($sharedStrings as $s) {
            $ssXml .= '<si><t xml:space="preserve">'.$x($s).'</t></si>';
        }
        $ssXml .= '</sst>';

        // ── Sheet XML builder: now includes <drawing r:id="rId1"/> ────────────
        // The r: namespace is declared on the <worksheet> element so that the
        // <drawing> reference is valid per the OOXML schema.
        $buildSheetXml = function(array $data) use ($colDefsXml): string {
            $mergeXml = '';
            if (!empty($data['merges'])) {
                $mergeXml = '<mergeCells count="'.count($data['merges']).'">';
                foreach ($data['merges'] as $m) {
                    $mergeXml .= '<mergeCell ref="'.$m.'"/>';
                }
                $mergeXml .= '</mergeCells>';
            }

            $sheetPrXml   = '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
            $sheetViewXml = '<sheetViews>'
                          . '<sheetView view="pageBreakPreview" workbookViewId="0">'
                          . '<selection activeCell="A1" sqref="A1"/>'
                          . '</sheetView>'
                          . '</sheetViews>';

            // NOTE: xmlns:r is required so <drawing r:id="rId1"/> is valid XML
            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet'
                . ' xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . $sheetPrXml
                . $sheetViewXml
                . $colDefsXml
                . '<sheetData>'.$data['rowXml'].'</sheetData>'
                . $mergeXml
                . '<pageMargins left="0.3" right="0.3" top="0.3" bottom="0.3" header="0.2" footer="0.2"/>'
                . '<pageSetup paperSize="9" scale="100" fitToWidth="1" fitToHeight="0" orientation="portrait"/>'
                // ── This element links the sheet to its drawing (text box) ──────
                . '<drawing r:id="rId1"/>'
                . '</worksheet>';
        };

        $sheet1Xml = $buildSheetXml($p1);
        $sheet2Xml = $buildSheetXml($p2);

        // ── workbook.xml ──────────────────────────────────────────────────────
        $definedNamesXml = '<definedNames>'
            . '<definedName name="_xlnm.Print_Area" localSheetId="0">&apos;page 1&apos;!$A$1:$O$' . $p1['lastRow'] . '</definedName>'
            . '<definedName name="_xlnm.Print_Area" localSheetId="1">&apos;page 2&apos;!$A$1:$O$' . $p2['lastRow'] . '</definedName>'
            . '</definedNames>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView/></bookViews>'
            . '<sheets>'
            . '<sheet name="page 1" sheetId="1" r:id="rId1"/>'
            . '<sheet name="page 2" sheetId="2" r:id="rId2"/>'
            . '</sheets>'
            . $definedNamesXml
            . '</workbook>';

        // ── [Content_Types].xml — drawing content type added ──────────────────
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml"  ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            // ── Drawing content types (one per sheet) ─────────────────────────
            . '<Override PartName="/xl/drawings/drawing1.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
            . '<Override PartName="/xl/drawings/drawing2.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>'
            . '</Types>';

        // ── _rels/.rels ───────────────────────────────────────────────────────
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';

        // ── xl/_rels/workbook.xml.rels ────────────────────────────────────────
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings"'
            . ' Target="sharedStrings.xml"/>'
            . '<Relationship Id="rId4"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>'
            . '</Relationships>';

        // ── Sheet .rels: each sheet gets its OWN drawing file ─────────────────
        // sheet1 → drawing1.xml,  sheet2 → drawing2.xml
        // Using separate drawing files avoids any shape-id collision between sheets.
        $sheet1Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing"'
            . ' Target="../drawings/drawing1.xml"/>'
            . '</Relationships>';

        $sheet2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing"'
            . ' Target="../drawings/drawing2.xml"/>'
            . '</Relationships>';

        // ── Generate drawing XML for each sheet (unique shape IDs) ────────────
        $drawing1Xml = self::make_drawing_xml(1);  // shape id 11
        $drawing2Xml = self::make_drawing_xml(2);  // shape id 21

        // ── Build .xlsx via ZipArchive ────────────────────────────────────────
        $tmpFile = tempnam(sys_get_temp_dir(), 'pm_excel_') . '.xlsx';

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            echo 'Failed to create Excel file.';
            exit;
        }

        $zip->addFromString('[Content_Types].xml',                $contentTypes);
        $zip->addFromString('_rels/.rels',                        $rootRels);
        $zip->addFromString('xl/workbook.xml',                    $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels',         $wbRels);
        $zip->addFromString('xl/styles.xml',                      $stylesXml);
        $zip->addFromString('xl/sharedStrings.xml',               $ssXml);
        $zip->addFromString('xl/worksheets/sheet1.xml',           $sheet1Xml);
        $zip->addFromString('xl/worksheets/sheet2.xml',           $sheet2Xml);
        // ── Drawing files (text box on each sheet) ────────────────────────────
        $zip->addFromString('xl/drawings/drawing1.xml',           $drawing1Xml);
        $zip->addFromString('xl/drawings/drawing2.xml',           $drawing2Xml);
        // ── Sheet relationship files (link each sheet to its drawing) ─────────
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $sheet1Rels);
        $zip->addFromString('xl/worksheets/_rels/sheet2.xml.rels', $sheet2Rels);
        $zip->close();

        // ── Stream to browser ─────────────────────────────────────────────────
        $model_safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $record['MODEL']            ?? 'PM');
        $month_safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $record['INSPECTION_MONTH'] ?? date('Y-m'));
        $fileName   = 'PREVENTIVE_MAINTENANCE_CHECKLIST_FOR_OVEN_BLOWER_MOTOR_AND_GAS_BURNER_REV_'
                    . $model_safe . '_' . $month_safe . '.xlsx';

        ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: max-age=0');

        readfile($tmpFile);
        unlink($tmpFile);
        exit;
    }


    private static function count_rows_combination($arr, $key, $val) {
        $c = 0; foreach ($arr as $i) { if ($i[$key] === $val) $c++; } return $c;
    }

    private static function count_rows_combination_sub($arr, $sec, $comp) {
        $c = 0; foreach ($arr as $i) { if ($i['section'] === $sec && $i['component'] === $comp) $c++; } return $c;
    }

    private static function get_checklist_layout_structure() {
        return [
            ['no' => '1', 'section' => 'Former Oven', 'component' => 'Former Oven Fan 1', 'particular' => 'Check Motor Bearing',    'db_column' => 'FO_F1_CMB'],
            ['no' => '1', 'section' => 'Former Oven', 'component' => 'Former Oven Fan 1', 'particular' => 'Clean Motor',             'db_column' => 'FO_F1_CM'],
            ['no' => '1', 'section' => 'Former Oven', 'component' => 'Former Oven Fan 1', 'particular' => 'Check Motor Ventilation', 'db_column' => 'FO_F1_CMV'],
            ['no' => '1', 'section' => 'Former Oven', 'component' => 'Former Oven Fan 2', 'particular' => 'Check Motor Bearing',    'db_column' => 'FO_F2_CMB'],
            ['no' => '1', 'section' => 'Former Oven', 'component' => 'Former Oven Fan 2', 'particular' => 'Clean Motor',             'db_column' => 'FO_F1_CM'],
            ['no' => '1', 'section' => 'Former Oven', 'component' => 'Former Oven Fan 2', 'particular' => 'Check Motor Ventilation', 'db_column' => 'FO_F2_CMV'],
            ['no' => '1', 'section' => 'Former Oven', 'component' => 'Gas burner 1',      'particular' => 'Check and Clean Gas Burner', 'db_column' => 'FO_GB1_CCGB'],
        ];
    }
}