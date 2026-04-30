<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

function json_response($payload, $statusCode = 200) {
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function html_response($html, $statusCode = 200) {
    http_response_code($statusCode);
    header("Content-Type: text/html; charset=UTF-8");
    echo $html;
    exit;
}

function get_request_payload() {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];
    return array_merge($_GET ?? [], $_POST ?? [], $json);
}

$request = get_request_payload();
$action = $request['action'] ?? ($_GET['action'] ?? '');

function normalize_permissions($value) {
    if (is_array($value)) return $value;
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function encode_permissions($value) {
    return json_encode(normalize_permissions($value), JSON_UNESCAPED_UNICODE);
}

function to_int($value, $default = 0) {
    return isset($value) && $value !== '' ? (int)$value : $default;
}

function to_float($value, $default = 0) {
    return isset($value) && $value !== '' ? (float)$value : $default;
}

function log_action($pdo, $user_id, $branch_id, $action_type, $description) {
    try {
        $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, branch_id, action_type, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id ?: null, $branch_id ?: null, $action_type, $description]);
    } catch (Throwable $e) {
    }
}

function fetch_user_basic($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT u.*, b.branch_name
        FROM users u
        LEFT JOIN branches b ON b.id = u.branch_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['feature_permissions'] = normalize_permissions($row['feature_permissions'] ?? null);
    }
    return $row ?: null;
}

function fetch_user_contract($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM employee_contracts
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'user_id' => $user_id,
            'monthly_fixed_salary' => 0,
            'monthly_paid_leaves' => 0,
            'max_advance_percentage' => 30,
            'standard_shift_hours' => 9,
            'week_off_day' => 'Sunday',
            'max_paid_leaves_cap' => 4,
            'pre_advance_balance' => 0,
            'final_advance_balance' => 0,
        ];
    }
    return $row;
}

function build_api_url($query = []) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
    return $scheme . '://' . $host . $script . '?' . http_build_query($query);
}

function calculate_paid_holidays($pdo, $branch_id, $total_duty, $cap) {
    $stmt = $pdo->prepare("
        SELECT earned_paid_leaves
        FROM branch_leave_rules
        WHERE (branch_id = ? OR branch_id IS NULL)
          AND max_leaves_cap = ?
          AND min_working_days <= ?
          AND (max_days_exclusive IS NULL OR ? < max_days_exclusive)
        ORDER BY 
          CASE WHEN branch_id = ? THEN 0 ELSE 1 END,
          min_working_days DESC
        LIMIT 1
    ");
    $stmt->execute([$branch_id, $cap, $total_duty, $total_duty, $branch_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return (int)$row['earned_paid_leaves'];

    if ((int)$cap === 2) {
        if ($total_duty >= 24) return 2;
        if ($total_duty >= 14) return 1;
        return 0;
    }

    if ($total_duty >= 24) return 4;
    if ($total_duty >= 20) return 3;
    if ($total_duty >= 14) return 2;
    if ($total_duty >= 10) return 1;
    return 0;
}

function get_daily_punch_map($pdo, $user_id, $month, $year) {
    $stmt = $pdo->prepare("
        SELECT 
            DATE(punch_time) AS p_date,
            GROUP_CONCAT(DATE_FORMAT(punch_time, '%Y-%m-%d %H:%i:%s') ORDER BY punch_time ASC SEPARATOR '||') AS punches
        FROM attendance_punches
        WHERE user_id = ? AND MONTH(punch_time) = ? AND YEAR(punch_time) = ?
        GROUP BY DATE(punch_time)
    ");
    $stmt->execute([$user_id, $month, $year]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[$row['p_date']] = explode('||', $row['punches']);
    }
    return $map;
}

function get_override_map($pdo, $user_id, $month, $year) {
    $stmt = $pdo->prepare("
        SELECT date, override_status
        FROM attendance_overrides
        WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?
    ");
    $stmt->execute([$user_id, $month, $year]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[$row['date']] = $row['override_status'];
    }
    return $map;
}

function calculate_work_minutes_from_punches($punches) {
    $total = 0;
    $count = count($punches);
    for ($i = 0; $i + 1 < $count; $i += 2) {
        $in = strtotime($punches[$i]);
        $out = strtotime($punches[$i + 1]);
        if ($out > $in) {
            $total += ($out - $in) / 60;
        }
    }
    return round($total);
}

function get_day_status_from_punches($punches, $targetHours) {
    $count = count($punches);
    if ($count === 0) return ['status' => 'A', 'hours' => 0, 'minutes' => 0];
    if ($count < 2) return ['status' => 'M', 'hours' => 0, 'minutes' => 0];

    $minutes = calculate_work_minutes_from_punches($punches);
    $hours = $minutes / 60;

    if ($hours >= ($targetHours - 0.5)) return ['status' => 'F', 'hours' => round($hours, 2), 'minutes' => $minutes];
    if ($hours >= ($targetHours / 2)) return ['status' => 'H', 'hours' => round($hours, 2), 'minutes' => $minutes];
    return ['status' => 'A', 'hours' => round($hours, 2), 'minutes' => $minutes];
}

function calculate_employee_payroll($pdo, $userRow, $month, $year) {
    $branch_id = $userRow['branch_id'] ?? null;
    $user_id = $userRow['id'];
    $contract = fetch_user_contract($pdo, $user_id);

    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $salary = to_float($contract['monthly_fixed_salary'] ?? 0);
    $targetHours = to_float($contract['standard_shift_hours'] ?? 9, 9);
    $weekOffDay = strtolower(trim($contract['week_off_day'] ?? ''));
    $cap = to_int($contract['max_paid_leaves_cap'] ?? 4, 4);

    $punchMap = get_daily_punch_map($pdo, $user_id, $month, $year);
    $overrideMap = get_override_map($pdo, $user_id, $month, $year);

    $totals = ['F' => 0, 'H' => 0, 'A' => 0, 'WO' => 0, 'M' => 0, 'PH' => 0];
    $days = [];

    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $dayName = strtolower(date('l', strtotime($date)));

        if (isset($overrideMap[$date])) {
            $status = $overrideMap[$date];
            if (!isset($totals[$status])) $totals[$status] = 0;
            $totals[$status]++;
            $days[$date] = [
                'status' => $status,
                'hours' => 0,
                'punches' => $punchMap[$date] ?? [],
                'override' => true,
            ];
            continue;
        }

        if ($weekOffDay && $dayName === strtolower($weekOffDay)) {
            $totals['WO']++;
            $days[$date] = ['status' => 'WO', 'hours' => 0, 'punches' => []];
            continue;
        }

        $punches = $punchMap[$date] ?? [];
        $calc = get_day_status_from_punches($punches, $targetHours);
        $totals[$calc['status']] = ($totals[$calc['status']] ?? 0) + 1;

        $days[$date] = [
            'status' => $calc['status'],
            'hours' => $calc['hours'],
            'minutes' => $calc['minutes'],
            'first_in' => $punches[0] ?? null,
            'last_out' => count($punches) >= 2 ? end($punches) : null,
            'punches' => $punches,
        ];
    }

    $totalDuty = $totals['F'] + ($totals['H'] * 0.5);
    $manualPH = $totals['PH'] ?? 0;
    $earnedPaidLeaves = calculate_paid_holidays($pdo, $branch_id, $totalDuty, $cap);
    $paidLeaves = $earnedPaidLeaves + $manualPH;
    $paidDuty = min($daysInMonth, $totalDuty + $paidLeaves);
    $dailyRate = $daysInMonth > 0 ? ($salary / $daysInMonth) : 0;
    $grossSalary = round($dailyRate * $paidDuty, 2);

    $advStmt = $pdo->prepare("
        SELECT type, COALESCE(SUM(amount), 0) AS total
        FROM advance_ledger
        WHERE user_id = ? AND month = ? AND year = ?
        GROUP BY type
    ");
    $advStmt->execute([$user_id, $month, $year]);
    $advanceMap = [
        'pre_advance' => 0,
        'final_advance' => 0,
        'shop_advance' => 0,
        'shop_bill' => 0,
        'fine' => 0,
        'repayment' => 0,
        'other' => 0,
    ];
    foreach ($advStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $row['type'];
        if (array_key_exists($type, $advanceMap)) {
            $advanceMap[$type] = (float)$row['total'];
        }
    }

    $totalAdvance = round(
        $advanceMap['pre_advance'] +
        $advanceMap['final_advance'] +
        $advanceMap['shop_advance'] +
        $advanceMap['shop_bill'],
        2
    );

    $deduction = round($advanceMap['fine'] + $advanceMap['other'], 2);
    $salaryToPay = round($grossSalary - $totalAdvance - $deduction + $advanceMap['repayment'], 2);
    $maxAdvanceAllowed = round($salary * (to_float($contract['max_advance_percentage'] ?? 30) / 100), 2);

    $ledgerStmt = $pdo->prepare("
        SELECT paid_amount, advance_due, remarks, status
        FROM payroll_ledgers
        WHERE user_id = ? AND payroll_month = ? AND payroll_year = ?
        LIMIT 1
    ");
    $ledgerStmt->execute([$user_id, $month, $year]);
    $ledger = $ledgerStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'id' => (int)$user_id,
        'user_id' => (int)$user_id,
        'branch_id' => $branch_id ? (int)$branch_id : null,
        'branch_name' => $userRow['branch_name'] ?? null,
        'name' => $userRow['name'] ?? '',
        'role' => $userRow['role'] ?? '',
        'department' => $userRow['department'] ?? '',
        'salary' => $salary,
        'base_salary' => $salary,
        'monthly_fixed_salary' => $salary,
        'days_in_month' => $daysInMonth,
        'present' => (int)$totals['F'],
        'night' => 0,
        'half_day' => (int)$totals['H'],
        'absent' => (int)$totals['A'],
        'missed' => (int)$totals['M'],
        'week_off' => (int)$totals['WO'],
        'total_duty' => $totalDuty,
        'paid_leaves' => $paidLeaves,
        'paid_holidays' => $paidLeaves,
        'paid_duty' => $paidDuty,
        'pre_advance' => round($advanceMap['pre_advance'], 2),
        'final_advance' => round($advanceMap['final_advance'], 2),
        'shop_advance' => round($advanceMap['shop_advance'], 2),
        'shop_bill' => round($advanceMap['shop_bill'], 2),
        'total_advance' => $totalAdvance,
        'deduction' => $deduction,
        'salary_to_pay' => $salaryToPay,
        'paid' => round((float)($ledger['paid_amount'] ?? 0), 2),
        'paid_amount' => round((float)($ledger['paid_amount'] ?? 0), 2),
        'advance_due' => round((float)($ledger['advance_due'] ?? 0), 2),
        'remarks' => $ledger['remarks'] ?? '',
        'ledger_status' => $ledger['status'] ?? 'draft',
        'max_advance_allowed' => $maxAdvanceAllowed,
        'max_paid_leaves_cap' => $cap,
        'week_off_day' => $contract['week_off_day'] ?? 'Sunday',
        'standard_shift_hours' => to_float($contract['standard_shift_hours'] ?? 9, 9),
        'days' => $days,
        'totals' => $totals,
    ];
}

function fetch_payroll_rows($pdo, $month, $year, $branch_id = null) {
    if ($branch_id) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.branch_id, u.name, u.role, u.department, b.branch_name
            FROM users u
            LEFT JOIN branches b ON b.id = u.branch_id
            WHERE u.branch_id = ? AND u.status = 'active' AND u.role != 'admin'
            ORDER BY FIELD(u.role,'manager','staff'), u.name ASC
        ");
        $stmt->execute([$branch_id]);
    } else {
        $stmt = $pdo->query("
            SELECT u.id, u.branch_id, u.name, u.role, u.department, b.branch_name
            FROM users u
            LEFT JOIN branches b ON b.id = u.branch_id
            WHERE u.status = 'active' AND u.role != 'admin'
            ORDER BY b.branch_name ASC, FIELD(u.role,'manager','staff'), u.name ASC
        ");
    }

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
        $rows[] = calculate_employee_payroll($pdo, $user, $month, $year);
    }
    return $rows;
}

function render_salary_slip_html($pdo, $user_id, $month, $year) {
    $user = fetch_user_basic($pdo, $user_id);
    if (!$user) {
        html_response("<h1>User not found</h1>", 404);
    }

    $payroll = calculate_employee_payroll($pdo, $user, $month, $year);

    $advStmt = $pdo->prepare("
        SELECT al.*, lu.name AS logged_by_name
        FROM advance_ledger al
        LEFT JOIN users lu ON lu.id = al.logged_by
        WHERE al.user_id = ? AND al.month = ? AND al.year = ?
        ORDER BY al.created_at DESC
    ");
    $advStmt->execute([$user_id, $month, $year]);
    $advances = $advStmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Salary Slip - <?= htmlspecialchars($payroll['name']) ?></title>
        <style>
            body{font-family:Arial,sans-serif;background:#f7f7f7;padding:24px;color:#111}
            .wrap{max-width:900px;margin:0 auto;background:#fff;padding:24px;border-radius:16px;box-shadow:0 6px 24px rgba(0,0,0,.08)}
            h1,h2,h3{margin:0 0 12px}
            .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:18px 0}
            .card{border:1px solid #e5e7eb;border-radius:12px;padding:14px}
            table{width:100%;border-collapse:collapse;margin-top:16px}
            th,td{border:1px solid #e5e7eb;padding:10px;text-align:left;font-size:14px}
            th{background:#f3f4f6}
            .num{text-align:right}
            .muted{color:#666;font-size:13px}
            @media print{body{background:#fff;padding:0}.wrap{box-shadow:none;border-radius:0}}
        </style>
    </head>
    <body>
        <div class="wrap">
            <h1>Caketown Cafe Salary Slip</h1>
            <p class="muted">Month: <?= (int)$month ?>/<?= (int)$year ?></p>

            <div class="grid">
                <div class="card">
                    <h3>Employee</h3>
                    <p><strong>Name:</strong> <?= htmlspecialchars($payroll['name']) ?></p>
                    <p><strong>Role:</strong> <?= htmlspecialchars($payroll['role']) ?></p>
                    <p><strong>Department:</strong> <?= htmlspecialchars($payroll['department']) ?></p>
                    <p><strong>Branch:</strong> <?= htmlspecialchars($payroll['branch_name'] ?? '-') ?></p>
                </div>
                <div class="card">
                    <h3>Attendance</h3>
                    <p><strong>Total Duty:</strong> <?= htmlspecialchars($payroll['total_duty']) ?></p>
                    <p><strong>Paid Leaves:</strong> <?= htmlspecialchars($payroll['paid_leaves']) ?></p>
                    <p><strong>Paid Duty:</strong> <?= htmlspecialchars($payroll['paid_duty']) ?></p>
                    <p><strong>Week Off:</strong> <?= htmlspecialchars($payroll['week_off']) ?></p>
                </div>
            </div>

            <table>
                <tr><th>Base Salary</th><td class="num">₹<?= number_format($payroll['base_salary'], 2) ?></td></tr>
                <tr><th>Pre Advance</th><td class="num">₹<?= number_format($payroll['pre_advance'], 2) ?></td></tr>
                <tr><th>Final Advance</th><td class="num">₹<?= number_format($payroll['final_advance'], 2) ?></td></tr>
                <tr><th>Shop Advance</th><td class="num">₹<?= number_format($payroll['shop_advance'], 2) ?></td></tr>
                <tr><th>Shop Bill</th><td class="num">₹<?= number_format($payroll['shop_bill'], 2) ?></td></tr>
                <tr><th>Total Advance</th><td class="num">₹<?= number_format($payroll['total_advance'], 2) ?></td></tr>
                <tr><th>Deduction</th><td class="num">₹<?= number_format($payroll['deduction'], 2) ?></td></tr>
                <tr><th>Salary To Pay</th><td class="num"><strong>₹<?= number_format($payroll['salary_to_pay'], 2) ?></strong></td></tr>
                <tr><th>Paid</th><td class="num">₹<?= number_format($payroll['paid'], 2) ?></td></tr>
                <tr><th>Advance / Due</th><td class="num">₹<?= number_format($payroll['advance_due'], 2) ?></td></tr>
                <tr><th>Remarks</th><td><?= htmlspecialchars($payroll['remarks'] ?: '-') ?></td></tr>
            </table>

            <h3 style="margin-top:24px;">Advance History</h3>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="num">Amount</th>
                        <th>Logged By</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$advances): ?>
                        <tr><td colspan="5">No advance entries found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($advances as $a): ?>
                            <tr>
                                <td><?= htmlspecialchars($a['created_at']) ?></td>
                                <td><?= htmlspecialchars($a['type']) ?></td>
                                <td class="num">₹<?= number_format((float)$a['amount'], 2) ?></td>
                                <td><?= htmlspecialchars($a['logged_by_name'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($a['remarks'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <script>window.onload=()=>setTimeout(()=>window.print(),300);</script>
    </body>
    </html>
    <?php
    html_response(ob_get_clean());
}

if (($_GET['render'] ?? '') === 'salary_slip') {
    $user_id = to_int($_GET['user_id'] ?? 0);
    $month = to_int($_GET['month'] ?? date('m'));
    $year = to_int($_GET['year'] ?? date('Y'));
    if (!$user_id) {
        html_response("<h1>Missing user_id</h1>", 400);
    }
    render_salary_slip_html($pdo, $user_id, $month, $year);
}

if (!$action) {
    json_response(['status' => 'error', 'message' => 'Action is required.'], 400);
}

switch ($action) {
    case 'ping':
        json_response([
            'status' => 'success',
            'message' => 'Caketown ERP API is live.',
            'time' => date('Y-m-d H:i:s')
        ]);
        break;

    case 'login':
        $mobile = trim($request['mobile_number'] ?? '');
        $password = $request['password'] ?? '';

        if ($mobile === '' || $password === '') {
            json_response(['status' => 'error', 'message' => 'Mobile number and password are required.'], 400);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT 
                    u.id, u.branch_id, u.role, u.department, u.name, u.mobile_number,
                    u.password, u.status, u.feature_permissions, b.branch_name,
                    c.monthly_fixed_salary, c.monthly_paid_leaves, c.max_paid_leaves_cap,
                    c.standard_shift_hours, c.week_off_day, c.max_advance_percentage
                FROM users u
                LEFT JOIN branches b ON b.id = u.branch_id
                LEFT JOIN employee_contracts c ON c.user_id = u.id
                WHERE u.mobile_number = ?
                LIMIT 1
            ");
            $stmt->execute([$mobile]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($password, $user['password'])) {
                json_response(['status' => 'error', 'message' => 'Invalid mobile number or password.']);
            }

            if (($user['status'] ?? 'inactive') !== 'active') {
                json_response(['status' => 'error', 'message' => 'Your account is inactive. Contact admin.']);
            }

            unset($user['password']);
            $user['feature_permissions'] = normalize_permissions($user['feature_permissions'] ?? null);

            log_action($pdo, $user['id'], $user['branch_id'] ?? null, 'AUTH_LOGIN', "{$user['name']} logged in.");
            json_response(['status' => 'success', 'user' => $user]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Login failed: ' . $e->getMessage()]);
        }
        break;

        case 'get_admin_dashboard':
            try {
                $month = (int)($request['month'] ?? date('m'));
                $year  = (int)($request['year'] ?? date('Y'));
        
                $total_branches = $pdo->query(
                    "SELECT COUNT(*) FROM branches WHERE status = 'active'"
                )->fetchColumn();
        
                $total_employees = $pdo->query(
                    "SELECT COUNT(*) FROM users WHERE role != 'admin' AND status = 'active'"
                )->fetchColumn();
        
                $salary_stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(salary_to_pay), 0) as total
                     FROM payroll_ledgers
                     WHERE payroll_month = ? AND payroll_year = ?"
                );
                $salary_stmt->execute([$month, $year]);
                $salary_expenditure = (float)$salary_stmt->fetchColumn();
        
                $branch_grid_stmt = $pdo->query(
                    "SELECT
                        b.id,
                        b.branch_name,
                        b.address,
                        b.status,
                        COUNT(DISTINCT u.id) as staff_count,
                        COUNT(DISTINCT CASE WHEN DATE(ap.punch_time) = CURDATE() THEN ap.user_id END) as present_today
                     FROM branches b
                     LEFT JOIN users u
                        ON b.id = u.branch_id
                       AND u.status = 'active'
                       AND u.role != 'admin'
                     LEFT JOIN attendance_punches ap
                        ON ap.user_id = u.id
                     GROUP BY b.id
                     ORDER BY b.created_at DESC"
                );
                $branch_grid = $branch_grid_stmt->fetchAll(PDO::FETCH_ASSOC);
        
                $today_punches_stmt = $pdo->query(
                    "SELECT
                        p.id,
                        p.user_id,
                        p.punch_time,
                        u.name as user_name,
                        u.role as user_role,
                        u.branch_id,
                        b.branch_name
                     FROM attendance_punches p
                     JOIN users u ON p.user_id = u.id
                     LEFT JOIN branches b ON u.branch_id = b.id
                     WHERE DATE(p.punch_time) = CURDATE()
                     ORDER BY p.punch_time DESC
                     LIMIT 100"
                );
                $today_punches = $today_punches_stmt->fetchAll(PDO::FETCH_ASSOC);
        
                $logs = $pdo->query(
                    "SELECT
                        l.id,
                        l.action_type,
                        l.description,
                        l.created_at,
                        u.name as actor_name,
                        u.role as actor_role,
                        b.branch_name
                     FROM system_logs l
                     LEFT JOIN users u ON l.user_id = u.id
                     LEFT JOIN branches b ON l.branch_id = b.id
                     ORDER BY l.id DESC
                     LIMIT 50"
                )->fetchAll(PDO::FETCH_ASSOC);
        
                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'total_branches' => (int)$total_branches,
                        'total_employees' => (int)$total_employees,
                        'salary_expenditure' => $salary_expenditure,
                        'month' => $month,
                        'year' => $year,
                        'branch_grid' => $branch_grid,
                        'today_punches' => $today_punches,
                        'system_history' => $logs,
                    ]
                ]);
            } catch (PDOException $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Dashboard load failed: ' . $e->getMessage()
                ]);
            }
            break;

    case 'get_system_logs':
    case 'get_system_history':
        $page = max(1, to_int($request['page'] ?? 1, 1));
        $per_page = min(100, max(10, to_int($request['per_page'] ?? 25, 25)));
        $offset = ($page - 1) * $per_page;
        $branch_id = trim((string)($request['branch_id'] ?? ''));

        try {
            $params = [];
            $where = '';
            if ($branch_id !== '') {
                $where = 'WHERE l.branch_id = ?';
                $params[] = $branch_id;
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_logs l $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "
                SELECT 
                    l.id, l.action_type, l.description, l.created_at,
                    u.name AS actor_name, u.role AS actor_role,
                    b.branch_name
                FROM system_logs l
                LEFT JOIN users u ON u.id = l.user_id
                LEFT JOIN branches b ON b.id = l.branch_id
                $where
                ORDER BY l.id DESC
                LIMIT $per_page OFFSET $offset
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            json_response([
                'status' => 'success',
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page
            ]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'System log fetch failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_branches':
        try {
            $rows = $pdo->query("
                SELECT 
                    b.*,
                    COUNT(CASE WHEN u.status='active' AND u.role!='admin' THEN 1 END) AS staff_count
                FROM branches b
                LEFT JOIN users u ON u.branch_id = b.id
                GROUP BY b.id
                ORDER BY b.id DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            json_response(['status' => 'success', 'data' => $rows]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Branch fetch failed: ' . $e->getMessage()]);
        }
        break;

    case 'create_branch':
        $branch_name = trim($request['branch_name'] ?? '');
        $address = trim($request['address'] ?? '');
        $admin_id = $request['admin_id'] ?? null;

        if ($branch_name === '') {
            json_response(['status' => 'error', 'message' => 'Branch name is required.'], 400);
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO branches (branch_name, address, status) VALUES (?, ?, 'active')");
            $stmt->execute([$branch_name, $address]);
            $branch_id = (int)$pdo->lastInsertId();

            log_action($pdo, $admin_id, $branch_id, 'BRANCH_CREATED', "Branch '$branch_name' created.");
            json_response(['status' => 'success', 'message' => 'Branch created.', 'branch_id' => $branch_id]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Branch creation failed: ' . $e->getMessage()]);
        }
        break;

    case 'update_branch':
        $id = to_int($request['id'] ?? 0);
        $branch_name = trim($request['branch_name'] ?? '');
        $address = trim($request['address'] ?? '');
        $status = trim($request['status'] ?? 'active');
        $admin_id = $request['admin_id'] ?? null;

        if (!$id) json_response(['status' => 'error', 'message' => 'Branch ID required.'], 400);

        try {
            $stmt = $pdo->prepare("UPDATE branches SET branch_name = ?, address = ?, status = ? WHERE id = ?");
            $stmt->execute([$branch_name, $address, $status, $id]);

            log_action($pdo, $admin_id, $id, 'BRANCH_UPDATED', "Branch ID $id updated.");
            json_response(['status' => 'success', 'message' => 'Branch updated.']);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Branch update failed: ' . $e->getMessage()]);
        }
        break;

    case 'delete_branch':
        $id = to_int($request['id'] ?? $request['branch_id'] ?? 0);
        $admin_id = $request['admin_id'] ?? null;
        if (!$id) json_response(['status' => 'error', 'message' => 'Branch ID required.'], 400);

        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE branch_id = ? AND status='active'");
            $countStmt->execute([$id]);
            $activeUsers = (int)$countStmt->fetchColumn();

            if ($activeUsers > 0) {
                json_response(['status' => 'error', 'message' => 'Cannot delete branch with active staff. Move/deactivate staff first.']);
            }

            $stmt = $pdo->prepare("UPDATE branches SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);

            log_action($pdo, $admin_id, $id, 'BRANCH_DELETED', "Branch ID $id marked inactive.");
            json_response(['status' => 'success', 'message' => 'Branch deleted successfully.']);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Branch delete failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_users':
        try {
            $stmt = $pdo->query("
                SELECT
                    u.id,
                    u.branch_id,
                    b.branch_name,
                    u.role,
                    u.department,
                    u.name,
                    u.mobile_number,
                    u.status,
                    u.feature_permissions,
                    u.created_at,
                    u.updated_at,
                    (u.face_descriptor IS NOT NULL) AS face_registered,
                    c.monthly_fixed_salary,
                    c.monthly_paid_leaves,
                    c.max_paid_leaves_cap,
                    c.standard_shift_hours,
                    c.week_off_day,
                    c.max_advance_percentage
                FROM users u
                LEFT JOIN branches b ON b.id = u.branch_id
                LEFT JOIN employee_contracts c ON c.user_id = u.id
                ORDER BY FIELD(u.role,'admin','manager','staff'), u.name ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['feature_permissions'] = normalize_permissions($row['feature_permissions'] ?? null);
            }
            unset($row);

            json_response(['status' => 'success', 'data' => $rows]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'User fetch failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_branch_staff':
        $branch_id = to_int($request['branch_id'] ?? 0);
        if (!$branch_id) json_response(['status' => 'error', 'message' => 'Branch ID required.'], 400);

        try {
            $stmt = $pdo->prepare("
                SELECT
                    u.id, u.branch_id, u.name, u.role, u.department, u.mobile_number, u.status,
                    u.feature_permissions,
                    (u.face_descriptor IS NOT NULL) AS face_registered,
                    c.monthly_fixed_salary, c.monthly_paid_leaves, c.max_paid_leaves_cap,
                    c.standard_shift_hours, c.week_off_day, c.max_advance_percentage
                FROM users u
                LEFT JOIN employee_contracts c ON c.user_id = u.id
                WHERE u.branch_id = ?
                ORDER BY FIELD(u.role,'manager','staff','admin'), u.name ASC
            ");
            $stmt->execute([$branch_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $row['feature_permissions'] = normalize_permissions($row['feature_permissions'] ?? null);
            }
            unset($row);

            json_response(['status' => 'success', 'data' => $rows]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Branch staff fetch failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_branch_master':
        $branch_id = to_int($request['branch_id'] ?? 0);
        if (!$branch_id) json_response(['status' => 'error', 'message' => 'Branch ID required.'], 400);

        try {
            $branchStmt = $pdo->prepare("SELECT * FROM branches WHERE id = ? LIMIT 1");
            $branchStmt->execute([$branch_id]);
            $branch = $branchStmt->fetch(PDO::FETCH_ASSOC);

            $staffStmt = $pdo->prepare("
                SELECT
                    u.id, u.branch_id, u.name, u.role, u.department, u.mobile_number, u.status,
                    u.feature_permissions, u.created_at,
                    (u.face_descriptor IS NOT NULL) AS face_registered,
                    c.monthly_fixed_salary, c.monthly_paid_leaves, c.max_paid_leaves_cap,
                    c.standard_shift_hours, c.week_off_day, c.max_advance_percentage,
                    c.pre_advance_balance, c.final_advance_balance
                FROM users u
                LEFT JOIN employee_contracts c ON c.user_id = u.id
                WHERE u.branch_id = ?
                ORDER BY FIELD(u.role,'manager','staff','admin'), u.name ASC
            ");
            $staffStmt->execute([$branch_id]);
            $staff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($staff as &$s) {
                $s['feature_permissions'] = normalize_permissions($s['feature_permissions'] ?? null);
            }
            unset($s);

            $punchStmt = $pdo->prepare("
                SELECT u.id AS user_id, u.name, p.punch_time
                FROM attendance_punches p
                INNER JOIN users u ON u.id = p.user_id
                WHERE u.branch_id = ? AND DATE(p.punch_time) = CURDATE()
                ORDER BY p.punch_time DESC
                LIMIT 50
            ");
            $punchStmt->execute([$branch_id]);
            $recent = $punchStmt->fetchAll(PDO::FETCH_ASSOC);

            json_response([
                'status' => 'success',
                'data' => [
                    'branch' => $branch,
                    'staff' => $staff,
                    'recent_punches' => $recent
                ]
            ]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Branch master load failed: ' . $e->getMessage()]);
        }
        break;

    case 'create_user':
        $role = trim($request['role'] ?? 'staff');
        $branch_id = ($role === 'admin') ? null : to_int($request['branch_id'] ?? 0);
        $name = trim($request['name'] ?? '');
        $mobile = trim($request['mobile_number'] ?? '');
        $password = trim($request['password'] ?? '');
        $department = trim($request['department'] ?? '');
        $salary = to_float($request['salary'] ?? 0);
        $paid_leaves = to_int($request['paid_leaves'] ?? 0);
        $max_cap = to_int($request['max_paid_leaves'] ?? $request['max_leaves_cap'] ?? 4, 4);
        if (!in_array($max_cap, [2, 4], true)) $max_cap = 4;
        $shift_hours = to_float($request['shift_hours'] ?? 9, 9);
        $week_off_day = trim($request['weekly_off_day'] ?? $request['week_off_day'] ?? 'Sunday');
        $permissions = encode_permissions($request['permissions'] ?? []);
        $admin_id = $request['admin_id'] ?? null;

        if ($name === '' || $mobile === '' || $password === '') {
            json_response(['status' => 'error', 'message' => 'Name, mobile number, and password are required.'], 400);
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO users (branch_id, role, department, name, mobile_number, password, status, feature_permissions)
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?)
            ");
            $stmt->execute([
                $branch_id ?: null,
                $role,
                $department ?: null,
                $name,
                $mobile,
                password_hash($password, PASSWORD_DEFAULT),
                $permissions
            ]);

            $user_id = (int)$pdo->lastInsertId();

            $contractStmt = $pdo->prepare("
                INSERT INTO employee_contracts
                (user_id, monthly_fixed_salary, monthly_paid_leaves, max_advance_percentage, standard_shift_hours, week_off_day, max_paid_leaves_cap, pre_advance_balance, final_advance_balance)
                VALUES (?, ?, ?, 30.00, ?, ?, ?, 0, 0)
            ");
            $contractStmt->execute([$user_id, $salary, $paid_leaves, $shift_hours, $week_off_day, $max_cap]);

            $pdo->commit();

            log_action($pdo, $admin_id, $branch_id, 'USER_CREATED', "New $role '$name' (ID:$user_id) created.");
            json_response(['status' => 'success', 'message' => 'Employee created successfully.', 'user_id' => $user_id]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = ((int)$e->getCode() === 23000) ? 'This mobile number is already registered.' : ('Employee creation failed: ' . $e->getMessage());
            json_response(['status' => 'error', 'message' => $message]);
        }
        break;

    case 'update_user':
        $user_id = to_int($request['user_id'] ?? 0);
        if (!$user_id) json_response(['status' => 'error', 'message' => 'User ID required.'], 400);

        try {
            $existing = fetch_user_basic($pdo, $user_id);
            if (!$existing) json_response(['status' => 'error', 'message' => 'User not found.'], 404);

            $contract = fetch_user_contract($pdo, $user_id);

            $name = trim($request['name'] ?? $existing['name']);
            $mobile = trim($request['mobile_number'] ?? $existing['mobile_number']);
            $department = trim($request['department'] ?? ($existing['department'] ?? ''));
            $role = trim($request['role'] ?? $existing['role']);
            $status = trim($request['status'] ?? $existing['status']);
            $branch_id = array_key_exists('branch_id', $request) ? to_int($request['branch_id'] ?? 0) : (int)$existing['branch_id'];
            if ($role === 'admin') $branch_id = null;

            $permissions = array_key_exists('permissions', $request)
                ? encode_permissions($request['permissions'])
                : json_encode($existing['feature_permissions'] ?? [], JSON_UNESCAPED_UNICODE);

            $salary = array_key_exists('salary', $request) ? to_float($request['salary']) : to_float($contract['monthly_fixed_salary'] ?? 0);
            $paid_leaves = array_key_exists('paid_leaves', $request) ? to_int($request['paid_leaves']) : to_int($contract['monthly_paid_leaves'] ?? 0);
            $max_cap = array_key_exists('max_paid_leaves', $request)
                ? to_int($request['max_paid_leaves'])
                : (array_key_exists('max_leaves_cap', $request) ? to_int($request['max_leaves_cap']) : to_int($contract['max_paid_leaves_cap'] ?? 4, 4));
            if (!in_array($max_cap, [2, 4], true)) $max_cap = 4;

            $shift_hours = array_key_exists('shift_hours', $request) ? to_float($request['shift_hours']) : to_float($contract['standard_shift_hours'] ?? 9, 9);
            $week_off_day = trim($request['weekly_off_day'] ?? $request['week_off_day'] ?? ($contract['week_off_day'] ?? 'Sunday'));
            $admin_id = $request['admin_id'] ?? null;

            $pdo->beginTransaction();

            if (!empty($request['password'])) {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET branch_id=?, role=?, department=?, name=?, mobile_number=?, password=?, status=?, feature_permissions=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $branch_id ?: null, $role, $department ?: null, $name, $mobile,
                    password_hash($request['password'], PASSWORD_DEFAULT),
                    $status, $permissions, $user_id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET branch_id=?, role=?, department=?, name=?, mobile_number=?, status=?, feature_permissions=?
                    WHERE id=?
                ");
                $stmt->execute([
                    $branch_id ?: null, $role, $department ?: null, $name, $mobile,
                    $status, $permissions, $user_id
                ]);
            }

            $existsContractStmt = $pdo->prepare("SELECT COUNT(*) FROM employee_contracts WHERE user_id=?");
            $existsContractStmt->execute([$user_id]);
            $hasContract = (int)$existsContractStmt->fetchColumn() > 0;

            if ($hasContract) {
                $stmt = $pdo->prepare("
                    UPDATE employee_contracts
                    SET monthly_fixed_salary=?, monthly_paid_leaves=?, standard_shift_hours=?, week_off_day=?, max_paid_leaves_cap=?
                    WHERE user_id=?
                ");
                $stmt->execute([$salary, $paid_leaves, $shift_hours, $week_off_day, $max_cap, $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO employee_contracts
                    (user_id, monthly_fixed_salary, monthly_paid_leaves, max_advance_percentage, standard_shift_hours, week_off_day, max_paid_leaves_cap, pre_advance_balance, final_advance_balance)
                    VALUES (?, ?, ?, 30.00, ?, ?, ?, 0, 0)
                ");
                $stmt->execute([$user_id, $salary, $paid_leaves, $shift_hours, $week_off_day, $max_cap]);
            }

            $pdo->commit();

            log_action($pdo, $admin_id, $branch_id, 'USER_UPDATED', "Employee '$name' (ID:$user_id) updated.");
            json_response(['status' => 'success', 'message' => 'Employee updated successfully.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = ((int)$e->getCode() === 23000) ? 'This mobile number is already registered.' : ('Employee update failed: ' . $e->getMessage());
            json_response(['status' => 'error', 'message' => $message]);
        }
        break;

    case 'delete_user':
        $user_id = to_int($request['user_id'] ?? 0);
        $admin_id = $request['admin_id'] ?? null;
        if (!$user_id) json_response(['status' => 'error', 'message' => 'User ID required.'], 400);

        try {
            $user = fetch_user_basic($pdo, $user_id);
            if (!$user) json_response(['status' => 'error', 'message' => 'User not found.']);

            $stmt = $pdo->prepare("UPDATE users SET status='inactive' WHERE id=?");
            $stmt->execute([$user_id]);

            log_action($pdo, $admin_id, $user['branch_id'] ?? null, 'USER_DELETED', "Employee '{$user['name']}' (ID:$user_id) marked inactive.");
            json_response(['status' => 'success', 'message' => 'Employee removed successfully.']);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Delete failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_branch_leave_rules':
    case 'get_leave_rules':
        $branch_id = to_int($request['branch_id'] ?? 0);
        if (!$branch_id) json_response(['status' => 'error', 'message' => 'Branch ID required.'], 400);

        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM branch_leave_rules
                WHERE branch_id = ?
                ORDER BY max_leaves_cap ASC, min_working_days ASC
            ");
            $stmt->execute([$branch_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $grouped = ['2' => [], '4' => []];
            foreach ($rows as $row) {
                $key = (string)$row['max_leaves_cap'];
                if (!isset($grouped[$key])) $grouped[$key] = [];
                $grouped[$key][] = $row;
            }

            json_response([
                'status' => 'success',
                'data' => $grouped,
                'rules' => $rows
            ]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Leave rules fetch failed: ' . $e->getMessage()]);
        }
        break;

    case 'save_leave_rules':
        $branch_id = to_int($request['branch_id'] ?? 0);
        $cap = to_int($request['cap'] ?? 4, 4);
        $rules = $request['rules'] ?? [];
        $admin_id = $request['admin_id'] ?? null;

        if (!$branch_id || !is_array($rules)) {
            json_response(['status' => 'error', 'message' => 'branch_id and rules are required.'], 400);
        }

        try {
            $pdo->beginTransaction();

            $del = $pdo->prepare("DELETE FROM branch_leave_rules WHERE branch_id = ? AND max_leaves_cap = ?");
            $del->execute([$branch_id, $cap]);

            $ins = $pdo->prepare("
                INSERT INTO branch_leave_rules (branch_id, min_working_days, earned_paid_leaves, max_leaves_cap, max_days_exclusive)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($rules as $r) {
                $ins->execute([
                    $branch_id,
                    to_float($r['min_working_days'] ?? 0),
                    to_int($r['earned_paid_leaves'] ?? 0),
                    $cap,
                    isset($r['max_days_exclusive']) && $r['max_days_exclusive'] !== '' ? to_float($r['max_days_exclusive']) : null
                ]);
            }

            $pdo->commit();

            log_action($pdo, $admin_id, $branch_id, 'LEAVE_RULES_UPDATED', "Leave rules (cap=$cap) updated for branch $branch_id.");
            json_response(['status' => 'success', 'message' => 'Leave rules saved successfully.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_response(['status' => 'error', 'message' => 'Leave rules save failed: ' . $e->getMessage()]);
        }
        break;

    case 'delete_branch_leave_rule':
        $id = to_int($request['id'] ?? 0);
        $admin_id = $request['admin_id'] ?? null;
        if (!$id) json_response(['status' => 'error', 'message' => 'Rule ID required.'], 400);

        try {
            $stmt = $pdo->prepare("SELECT branch_id, max_leaves_cap FROM branch_leave_rules WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rule) json_response(['status' => 'error', 'message' => 'Rule not found.']);

            $del = $pdo->prepare("DELETE FROM branch_leave_rules WHERE id = ?");
            $del->execute([$id]);

            log_action($pdo, $admin_id, $rule['branch_id'] ?? null, 'LEAVE_RULE_DELETED', "Leave rule $id deleted.");
            json_response(['status' => 'success', 'message' => 'Leave rule deleted.']);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Rule delete failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_branch_face_roster':
    case 'get_branch_descriptors':
        $branch_id = to_int($request['branch_id'] ?? 0);
        if (!$branch_id) json_response(['status' => 'error', 'message' => 'Branch ID required.'], 400);

        try {
            $stmt = $pdo->prepare("
                SELECT id, branch_id, name, role, department, face_descriptor
                FROM users
                WHERE branch_id = ? AND status = 'active' AND face_descriptor IS NOT NULL
                ORDER BY FIELD(role,'manager','staff'), name ASC
            ");
            $stmt->execute([$branch_id]);
            json_response(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Face roster fetch failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_branch_live_status':
    case 'get_live_attendance':
        $branch_id = to_int($request['branch_id'] ?? 0);
        $date = trim($request['date'] ?? date('Y-m-d'));
        if (!$branch_id) json_response(['status' => 'error', 'message' => 'Branch ID required.'], 400);

        try {
            $staffStmt = $pdo->prepare("
                SELECT u.id, u.name, u.role, u.department, c.standard_shift_hours
                FROM users u
                LEFT JOIN employee_contracts c ON c.user_id = u.id
                WHERE u.branch_id = ? AND u.status = 'active' AND u.role != 'admin'
                ORDER BY FIELD(u.role,'manager','staff'), u.name ASC
            ");
            $staffStmt->execute([$branch_id]);
            $staff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

            $punchStmt = $pdo->prepare("
                SELECT p.user_id, DATE_FORMAT(p.punch_time, '%Y-%m-%d %H:%i:%s') AS punch_time
                FROM attendance_punches p
                INNER JOIN users u ON u.id = p.user_id
                WHERE u.branch_id = ? AND DATE(p.punch_time) = ?
                ORDER BY p.punch_time ASC
            ");
            $punchStmt->execute([$branch_id, $date]);

            $punchMap = [];
            foreach ($punchStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $punchMap[$row['user_id']][] = $row['punch_time'];
            }

            $all = [];
            $active = [];

            foreach ($staff as $s) {
                $punches = $punchMap[$s['id']] ?? [];
                $count = count($punches);
                $status = 'absent';
                $first_in = $count ? $punches[0] : null;
                $last_punch = $count ? end($punches) : null;
                $minutes = calculate_work_minutes_from_punches($punches);

                if ($count > 0) {
                    $status = ($count % 2 === 1) ? 'working' : 'on_break';
                    if ($status === 'working' && $last_punch) {
                        $minutes += round((time() - strtotime($last_punch)) / 60);
                    }
                }

                $row = [
                    'id' => (int)$s['id'],
                    'name' => $s['name'],
                    'role' => $s['role'],
                    'department' => $s['department'],
                    'target_hours' => to_float($s['standard_shift_hours'] ?? 9, 9),
                    'status' => $status,
                    'first_in' => $first_in,
                    'last_punch' => $last_punch,
                    'punch_count' => $count,
                    'total_working_minutes' => $minutes,
                    'punches' => $punches
                ];

                $all[] = $row;
                if ($status !== 'absent') $active[] = $row;
            }

            json_response([
                'status' => 'success',
                'date' => $date,
                'present_count' => count($active),
                'total_staff' => count($all),
                'data' => [
                    'active_people' => $active,
                    'all_people' => $all
                ]
            ]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Live status fetch failed: ' . $e->getMessage()]);
        }
        break;

        case 'register_face':
            $user_id    = $request['user_id']    ?? null;
            $manager_id = $request['manager_id'] ?? null;
            $branch_id  = $request['branch_id']  ?? null;
            $descriptor = $request['descriptor'] ?? null;
        
            if (!$user_id || !$descriptor) {
                echo json_encode(['status' => 'error', 'message' => 'User ID and face descriptor are required.']);
                exit;
            }
        
            try {
                if (is_array($descriptor) || is_object($descriptor)) {
                    $descriptor = json_encode($descriptor);
                } elseif (!is_string($descriptor)) {
                    $descriptor = json_encode($descriptor);
                }
        
                if (!$descriptor || $descriptor === 'null') {
                    echo json_encode(['status' => 'error', 'message' => 'Invalid face descriptor format.']);
                    exit;
                }
        
                $pdo->prepare("UPDATE users SET face_descriptor = ? WHERE id = ?")
                    ->execute([$descriptor, $user_id]);
        
                $pdo->prepare(
                    "INSERT INTO face_registration_log (employee_id, registered_by, branch_id) VALUES (?, ?, ?)"
                )->execute([$user_id, $manager_id ?? $user_id, $branch_id]);
        
                $emp_name = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $emp_name->execute([$user_id]);
                $emp_name = $emp_name->fetchColumn();
        
                log_action(
                    $pdo,
                    $manager_id,
                    $branch_id,
                    'FACE_REGISTERED',
                    "Face biometric registered/updated for '$emp_name' (ID:$user_id)."
                );
        
                echo json_encode(['status' => 'success', 'message' => 'Face registered successfully.']);
            } catch (PDOException $e) {
                echo json_encode(['status' => 'error', 'message' => 'Face registration failed.']);
            }
            break;

    case 'terminal_punch':
        case 'log_punch':
            $user_id   = $request['user_id'] ?? null;
            $branch_id = $request['branch_id'] ?? null;
        
            if (!$user_id || !$branch_id) {
                echo json_encode(['status' => 'error', 'message' => 'User ID and Branch ID required.']);
                exit;
            }
        
            try {
                $check = $pdo->prepare(
                    "SELECT id, name, branch_id, role
                     FROM users
                     WHERE id = ? AND branch_id = ? AND status = 'active'
                     LIMIT 1"
                );
                $check->execute([$user_id, $branch_id]);
                $user = $check->fetch(PDO::FETCH_ASSOC);
        
                if (!$user) {
                    echo json_encode(['status' => 'error', 'message' => 'User not found in this branch.']);
                    exit;
                }
        
                $insert = $pdo->prepare(
                    "INSERT INTO attendance_punches (user_id, punch_time) VALUES (?, NOW())"
                );
                $insert->execute([$user_id]);
        
                $todayCountStmt = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM attendance_punches
                     WHERE user_id = ? AND DATE(punch_time) = CURDATE()"
                );
                $todayCountStmt->execute([$user_id]);
                $count = (int)$todayCountStmt->fetchColumn();
        
                $state = ($count % 2 === 1) ? 'in' : 'out';
                $punch_type = ($state === 'in') ? 'Punch In' : 'Punch Out';
        
                log_action(
                    $pdo,
                    $user_id,
                    $branch_id,
                    'ATTENDANCE_' . strtoupper($state),
                    "{$user['name']} marked {$punch_type} at branch ID {$branch_id}."
                );
        
                echo json_encode([
                    'status'       => 'success',
                    'message'      => "{$punch_type} recorded for {$user['name']}",
                    'user_name'    => $user['name'],
                    'user_id'      => (int)$user_id,
                    'branch_id'    => (int)$branch_id,
                    'state'        => $state,
                    'punch_type'   => $punch_type,
                    'punch_time'   => date('Y-m-d H:i:s'),
                    'punch_number' => $count,
                ]);
            } catch (PDOException $e) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Punch log failed: ' . $e->getMessage()
                ]);
            }
            break;

    case 'get_monthly_attendance':
        $branch_id = to_int($request['branch_id'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));
        if (!$branch_id) json_response(['status' => 'error', 'message' => 'Branch ID required.'], 400);

        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.branch_id, u.name, u.role, u.department, b.branch_name
                FROM users u
                LEFT JOIN branches b ON b.id = u.branch_id
                WHERE u.branch_id = ? AND u.status='active' AND u.role != 'admin'
                ORDER BY FIELD(u.role,'manager','staff'), u.name ASC
            ");
            $stmt->execute([$branch_id]);

            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
                $rows[] = calculate_employee_payroll($pdo, $user, $month, $year);
            }

            json_response([
                'status' => 'success',
                'month' => $month,
                'year' => $year,
                'days_in_month' => cal_days_in_month(CAL_GREGORIAN, $month, $year),
                'data' => $rows
            ]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Monthly attendance generation failed: ' . $e->getMessage()]);
        }
        break;

    case 'set_attendance_override':
        $user_id = to_int($request['user_id'] ?? 0);
        $date = trim($request['date'] ?? '');
        $status = trim($request['status'] ?? '');
        $admin_id = $request['admin_id'] ?? null;
        $reason = trim($request['reason'] ?? '');
        $valid = ['F', 'H', 'A', 'WO', 'PH', 'M'];

        if (!$user_id || !$date || !in_array($status, $valid, true)) {
            json_response(['status' => 'error', 'message' => 'user_id, date, and valid status are required.'], 400);
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO attendance_overrides (user_id, date, override_status, overridden_by, reason)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    override_status = VALUES(override_status),
                    overridden_by = VALUES(overridden_by),
                                        reason = VALUES(reason)
            ");
            $stmt->execute([$user_id, $date, $status, $admin_id ?: null, $reason]);

            $user = fetch_user_basic($pdo, $user_id);
            log_action(
                $pdo,
                $admin_id,
                $user['branch_id'] ?? null,
                'ATTENDANCE_OVERRIDE',
                "Attendance override set for {$user['name']} on {$date} as {$status}."
            );

            json_response(['status' => 'success', 'message' => 'Attendance override saved successfully.']);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Attendance override failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_global_payroll_data':
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));
        $branch_id = trim((string)($request['branch_id'] ?? ''));

        try {
            $rows = fetch_payroll_rows($pdo, $month, $year, $branch_id !== '' ? (int)$branch_id : null);
            json_response([
                'status' => 'success',
                'data' => $rows,
                'month' => $month,
                'year' => $year
            ]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Global payroll load failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_payroll_data':
        $branch_id = to_int($request['branch_id'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));

        if (!$branch_id) {
            json_response(['status' => 'error', 'message' => 'Branch ID required.'], 400);
        }

        try {
            $rows = fetch_payroll_rows($pdo, $month, $year, $branch_id);
            json_response([
                'status' => 'success',
                'data' => $rows,
                'month' => $month,
                'year' => $year,
                'branch_id' => $branch_id
            ]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Payroll data load failed: ' . $e->getMessage()]);
        }
        break;

    case 'save_payroll_ledger':
        $user_id = to_int($request['user_id'] ?? 0);
        $branch_id = to_int($request['branch_id'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));
        $finalized_by = $request['finalized_by'] ?? $request['admin_id'] ?? null;
        $status = trim($request['status'] ?? 'draft');
        $remarks = trim($request['remarks'] ?? '');

        if (!$user_id || !$branch_id) {
            json_response(['status' => 'error', 'message' => 'user_id and branch_id are required.'], 400);
        }

        try {
            $user = fetch_user_basic($pdo, $user_id);
            if (!$user) json_response(['status' => 'error', 'message' => 'User not found.']);

            $payroll = calculate_employee_payroll($pdo, $user, $month, $year);

            $stmt = $pdo->prepare("
                INSERT INTO payroll_ledgers
                (branch_id, user_id, payroll_month, payroll_year, total_duty, paid_leaves, paid_duty, base_salary,
                 pre_advance, final_advance, shop_advance, shop_bill, total_advance, deduction, salary_to_pay,
                 status, paid_amount, advance_due, remarks, finalized_by, finalized_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    branch_id = VALUES(branch_id),
                    total_duty = VALUES(total_duty),
                    paid_leaves = VALUES(paid_leaves),
                    paid_duty = VALUES(paid_duty),
                    base_salary = VALUES(base_salary),
                    pre_advance = VALUES(pre_advance),
                    final_advance = VALUES(final_advance),
                    shop_advance = VALUES(shop_advance),
                    shop_bill = VALUES(shop_bill),
                    total_advance = VALUES(total_advance),
                    deduction = VALUES(deduction),
                    salary_to_pay = VALUES(salary_to_pay),
                    status = VALUES(status),
                    remarks = VALUES(remarks),
                    finalized_by = VALUES(finalized_by),
                    finalized_at = VALUES(finalized_at)
            ");
            $stmt->execute([
                $branch_id,
                $user_id,
                $month,
                $year,
                $payroll['total_duty'],
                $payroll['paid_leaves'],
                $payroll['paid_duty'],
                $payroll['base_salary'],
                $payroll['pre_advance'],
                $payroll['final_advance'],
                $payroll['shop_advance'],
                $payroll['shop_bill'],
                $payroll['total_advance'],
                $payroll['deduction'],
                $payroll['salary_to_pay'],
                $status,
                $payroll['paid_amount'] ?? 0,
                $payroll['advance_due'] ?? 0,
                $remarks,
                $finalized_by ?: null
            ]);

            $paystubStmt = $pdo->prepare("
                INSERT INTO monthly_paystubs
                (user_id, month, year, total_days_in_month, worked_days, earned_leaves_applied, gross_salary, total_advances, total_shop_bills, net_payable_salary, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_days_in_month = VALUES(total_days_in_month),
                    worked_days = VALUES(worked_days),
                    earned_leaves_applied = VALUES(earned_leaves_applied),
                    gross_salary = VALUES(gross_salary),
                    total_advances = VALUES(total_advances),
                    total_shop_bills = VALUES(total_shop_bills),
                    net_payable_salary = VALUES(net_payable_salary),
                    status = VALUES(status)
            ");
            $paystubStmt->execute([
                $user_id,
                $month,
                $year,
                $payroll['days_in_month'],
                $payroll['total_duty'],
                $payroll['paid_leaves'],
                $payroll['base_salary'],
                $payroll['total_advance'],
                $payroll['shop_bill'],
                $payroll['salary_to_pay'],
                $status === 'paid' ? 'paid' : 'generated'
            ]);

            log_action($pdo, $finalized_by, $branch_id, 'PAYROLL_SAVED', "Payroll saved for {$user['name']} for {$month}/{$year}.");
            json_response(['status' => 'success', 'message' => 'Payroll ledger saved successfully.']);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Payroll save failed: ' . $e->getMessage()]);
        }
        break;

    case 'mark_salary_paid':
        $user_id = to_int($request['user_id'] ?? 0);
        $branch_id = to_int($request['branch_id'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));
        $amount = to_float($request['amount'] ?? 0);
        $actor_id = $request['actor_id'] ?? $request['admin_id'] ?? $request['manager_id'] ?? null;

        if (!$user_id || !$branch_id) {
            json_response(['status' => 'error', 'message' => 'user_id and branch_id are required.'], 400);
        }

        try {
            $user = fetch_user_basic($pdo, $user_id);
            if (!$user) json_response(['status' => 'error', 'message' => 'User not found.']);

            $payroll = calculate_employee_payroll($pdo, $user, $month, $year);
            if ($amount <= 0) $amount = (float)$payroll['salary_to_pay'];

            $advance_due = round((float)$payroll['salary_to_pay'] - $amount, 2);

            $stmt = $pdo->prepare("
                INSERT INTO payroll_ledgers
                (branch_id, user_id, payroll_month, payroll_year, total_duty, paid_leaves, paid_duty, base_salary,
                 pre_advance, final_advance, shop_advance, shop_bill, total_advance, deduction, salary_to_pay,
                 status, paid_amount, advance_due, remarks, finalized_by, finalized_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    branch_id = VALUES(branch_id),
                    total_duty = VALUES(total_duty),
                    paid_leaves = VALUES(paid_leaves),
                    paid_duty = VALUES(paid_duty),
                    base_salary = VALUES(base_salary),
                    pre_advance = VALUES(pre_advance),
                    final_advance = VALUES(final_advance),
                    shop_advance = VALUES(shop_advance),
                    shop_bill = VALUES(shop_bill),
                    total_advance = VALUES(total_advance),
                    deduction = VALUES(deduction),
                    salary_to_pay = VALUES(salary_to_pay),
                    status = 'paid',
                    paid_amount = VALUES(paid_amount),
                    advance_due = VALUES(advance_due),
                    finalized_by = VALUES(finalized_by),
                    finalized_at = VALUES(finalized_at)
            ");
            $stmt->execute([
                $branch_id,
                $user_id,
                $month,
                $year,
                $payroll['total_duty'],
                $payroll['paid_leaves'],
                $payroll['paid_duty'],
                $payroll['base_salary'],
                $payroll['pre_advance'],
                $payroll['final_advance'],
                $payroll['shop_advance'],
                $payroll['shop_bill'],
                $payroll['total_advance'],
                $payroll['deduction'],
                $payroll['salary_to_pay'],
                $amount,
                $advance_due,
                'Marked paid via payroll master page',
                $actor_id ?: null
            ]);

            $paystubStmt = $pdo->prepare("
                INSERT INTO monthly_paystubs
                (user_id, month, year, total_days_in_month, worked_days, earned_leaves_applied, gross_salary, total_advances, total_shop_bills, net_payable_salary, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid')
                ON DUPLICATE KEY UPDATE
                    total_days_in_month = VALUES(total_days_in_month),
                    worked_days = VALUES(worked_days),
                    earned_leaves_applied = VALUES(earned_leaves_applied),
                    gross_salary = VALUES(gross_salary),
                    total_advances = VALUES(total_advances),
                    total_shop_bills = VALUES(total_shop_bills),
                    net_payable_salary = VALUES(net_payable_salary),
                    status = 'paid'
            ");
            $paystubStmt->execute([
                $user_id,
                $month,
                $year,
                $payroll['days_in_month'],
                $payroll['total_duty'],
                $payroll['paid_leaves'],
                $payroll['base_salary'],
                $payroll['total_advance'],
                $payroll['shop_bill'],
                $amount
            ]);

            log_action($pdo, $actor_id, $branch_id, 'SALARY_PAID', "Salary marked paid for {$user['name']} for {$month}/{$year}, amount ₹{$amount}.");
            json_response(['status' => 'success', 'message' => 'Salary marked as paid successfully.']);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Mark salary paid failed: ' . $e->getMessage()]);
        }
        break;

    case 'download_salary_slip':
        $user_id = to_int($request['user_id'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));

        if (!$user_id) {
            json_response(['status' => 'error', 'message' => 'User ID required.'], 400);
        }

        $url = build_api_url([
            'render' => 'salary_slip',
            'user_id' => $user_id,
            'month' => $month,
            'year' => $year
        ]);

        json_response([
            'status' => 'success',
            'url' => $url
        ]);
        break;

    case 'get_payroll_report':
        $user_id = to_int($request['user_id'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));

        if (!$user_id) {
            json_response(['status' => 'error', 'message' => 'User ID required.'], 400);
        }

        try {
            $user = fetch_user_basic($pdo, $user_id);
            if (!$user) json_response(['status' => 'error', 'message' => 'User not found.']);

            $payroll = calculate_employee_payroll($pdo, $user, $month, $year);

            $historyStmt = $pdo->prepare("
                SELECT al.*, lu.name AS logged_by_name
                FROM advance_ledger al
                LEFT JOIN users lu ON lu.id = al.logged_by
                WHERE al.user_id = ? AND al.month = ? AND al.year = ?
                ORDER BY al.created_at DESC
            ");
            $historyStmt->execute([$user_id, $month, $year]);

            json_response([
                'status' => 'success',
                'data' => [
                    'payroll' => $payroll,
                    'advance_history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC)
                ]
            ]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Payroll report load failed: ' . $e->getMessage()]);
        }
        break;

    case 'log_advance':
    case 'pre_advance':
    case 'final_advance':
    case 'shop_advance':
    case 'shop_bill':
    case 'fine':
    case 'repayment':
        $user_id = to_int($request['user_id'] ?? 0);
        $branch_id = to_int($request['branch_id'] ?? 0);
        $logged_by = $request['logged_by'] ?? $request['admin_id'] ?? $request['manager_id'] ?? null;
        $type = $action === 'log_advance' ? trim($request['type'] ?? 'pre_advance') : $action;
        $amount = to_float($request['amount'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));
        $remarks = trim($request['remarks'] ?? '');

        $validTypes = ['pre_advance','final_advance','shop_advance','shop_bill','fine','repayment','other'];

        if (!$user_id || !$branch_id || $amount <= 0 || !in_array($type, $validTypes, true)) {
            json_response(['status' => 'error', 'message' => 'Valid user_id, branch_id, amount, and type are required.'], 400);
        }

        try {
            $user = fetch_user_basic($pdo, $user_id);
            if (!$user) json_response(['status' => 'error', 'message' => 'User not found.']);

            $contract = fetch_user_contract($pdo, $user_id);
            $salary = to_float($contract['monthly_fixed_salary'] ?? 0);
            $maxAllowed = round($salary * (to_float($contract['max_advance_percentage'] ?? 30) / 100), 2);

            if ($type === 'pre_advance' && $amount > $maxAllowed) {
                json_response([
                    'status' => 'error',
                    'message' => "Pre advance exceeds 30% limit. Maximum allowed is ₹" . number_format($maxAllowed, 2)
                ]);
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO advance_ledger (user_id, branch_id, logged_by, type, amount, month, year, remarks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $branch_id, $logged_by ?: null, $type, $amount, $month, $year, $remarks]);

            if ($type === 'pre_advance') {
                $up = $pdo->prepare("UPDATE employee_contracts SET pre_advance_balance = pre_advance_balance + ? WHERE user_id = ?");
                $up->execute([$amount, $user_id]);
            } elseif ($type === 'final_advance') {
                $up = $pdo->prepare("UPDATE employee_contracts SET final_advance_balance = final_advance_balance + ? WHERE user_id = ?");
                $up->execute([$amount, $user_id]);
            } elseif ($type === 'repayment') {
                $up = $pdo->prepare("
                    UPDATE employee_contracts
                    SET pre_advance_balance = GREATEST(pre_advance_balance - ?, 0)
                    WHERE user_id = ?
                ");
                $up->execute([$amount, $user_id]);
            }

            $pdo->commit();

            log_action($pdo, $logged_by, $branch_id, 'ADVANCE_LOGGED', "{$type} of ₹{$amount} logged for {$user['name']}.");
            json_response(['status' => 'success', 'message' => ucfirst(str_replace('_', ' ', $type)) . ' logged successfully.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_response(['status' => 'error', 'message' => 'Advance log failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_advance_history':
        $user_id = to_int($request['user_id'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));

        if (!$user_id) {
            json_response(['status' => 'error', 'message' => 'User ID required.'], 400);
        }

        try {
            $stmt = $pdo->prepare("
                SELECT al.*, u.name AS logged_by_name
                FROM advance_ledger al
                LEFT JOIN users u ON u.id = al.logged_by
                WHERE al.user_id = ? AND al.month = ? AND al.year = ?
                ORDER BY al.created_at DESC
            ");
            $stmt->execute([$user_id, $month, $year]);

            json_response(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Advance history load failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_user_profile':
    case 'get_my_profile':
        $user_id = to_int($request['user_id'] ?? 0);
        if (!$user_id) json_response(['status' => 'error', 'message' => 'User ID required.'], 400);

        try {
            $stmt = $pdo->prepare("
                SELECT
                    u.id, u.branch_id, u.name, u.role, u.department, u.mobile_number, u.status, u.created_at,
                    b.branch_name, b.address AS branch_address,
                    c.monthly_fixed_salary, c.monthly_paid_leaves, c.max_advance_percentage,
                    c.standard_shift_hours, c.week_off_day, c.max_paid_leaves_cap,
                    c.pre_advance_balance, c.final_advance_balance,
                    (u.face_descriptor IS NOT NULL) AS face_registered
                FROM users u
                LEFT JOIN branches b ON b.id = u.branch_id
                LEFT JOIN employee_contracts c ON c.user_id = u.id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) json_response(['status' => 'error', 'message' => 'User not found.'], 404);

            json_response(['status' => 'success', 'data' => $row]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Profile fetch failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_my_attendance':
        $user_id = to_int($request['user_id'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));
        if (!$user_id) json_response(['status' => 'error', 'message' => 'User ID required.'], 400);

        try {
            $contract = fetch_user_contract($pdo, $user_id);
            $targetHours = to_float($contract['standard_shift_hours'] ?? 9, 9);

            $stmt = $pdo->prepare("
                SELECT 
                    DATE(punch_time) AS date,
                    GROUP_CONCAT(DATE_FORMAT(punch_time, '%Y-%m-%d %H:%i:%s') ORDER BY punch_time ASC SEPARATOR '||') AS punches
                FROM attendance_punches
                WHERE user_id = ? AND MONTH(punch_time) = ? AND YEAR(punch_time) = ?
                GROUP BY DATE(punch_time)
                ORDER BY DATE(punch_time) ASC
            ");
            $stmt->execute([$user_id, $month, $year]);

            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $punches = explode('||', $r['punches']);
                $minutes = calculate_work_minutes_from_punches($punches);
                $statusCalc = get_day_status_from_punches($punches, $targetHours);

                $breakMinutes = 0;
                for ($i = 1; $i + 1 < count($punches); $i += 2) {
                    $out = strtotime($punches[$i]);
                    $nextIn = strtotime($punches[$i + 1]);
                    if ($nextIn > $out) $breakMinutes += round(($nextIn - $out) / 60);
                }

                $rows[] = [
                    'date' => $r['date'],
                    'status' => $statusCalc['status'],
                    'first_in' => $punches[0] ?? null,
                    'last_out' => count($punches) >= 2 ? end($punches) : null,
                    'hours_worked' => round($minutes / 60, 2),
                    'work_time' => round($minutes / 60, 2),
                    'break_time' => round($breakMinutes / 60, 2),
                    'remark' => $statusCalc['status'] === 'M' ? 'Missing punch' : '',
                    'punch_count' => count($punches),
                    'punches' => $punches
                ];
            }

            json_response(['status' => 'success', 'data' => $rows]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Attendance fetch failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_my_financials':
    case 'get_my_payroll':
        $user_id = to_int($request['user_id'] ?? 0);
        $month = to_int($request['month'] ?? date('m'));
        $year = to_int($request['year'] ?? date('Y'));
        if (!$user_id) json_response(['status' => 'error', 'message' => 'User ID required.'], 400);

        try {
            $user = fetch_user_basic($pdo, $user_id);
            if (!$user) json_response(['status' => 'error', 'message' => 'User not found.']);

            $payroll = calculate_employee_payroll($pdo, $user, $month, $year);

            $historyStmt = $pdo->prepare("
                SELECT al.*, lu.name AS logged_by_name
                FROM advance_ledger al
                LEFT JOIN users lu ON lu.id = al.logged_by
                WHERE al.user_id = ? AND al.month = ? AND al.year = ?
                ORDER BY al.created_at DESC
            ");
            $historyStmt->execute([$user_id, $month, $year]);

            $data = $payroll;
            $data['advance_history'] = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

            json_response(['status' => 'success', 'data' => $data]);
        } catch (Throwable $e) {
            json_response(['status' => 'error', 'message' => 'Financial fetch failed: ' . $e->getMessage()]);
        }
        break;

    default:
        json_response([
            'status' => 'error',
            'message' => 'Invalid action: ' . $action
        ], 400);
        break;
}
