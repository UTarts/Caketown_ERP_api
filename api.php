<?php
// ================================================================
// CAKETOWN ERP — API GATEWAY v2
// Complete rewrite. All original endpoints preserved + extended.
// Architecture: single-file action router over POST JSON body.
// ================================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'config.php';
date_default_timezone_set('Asia/Kolkata');

$raw_input = file_get_contents("php://input");
$request   = json_decode($raw_input, true) ?? [];
$action    = $request['action'] ?? '';

// ================================================================
// HELPERS
// ================================================================

function log_action($pdo, $user_id, $branch_id, $action_type, $description) {
    $stmt = $pdo->prepare(
        "INSERT INTO system_logs (user_id, branch_id, action_type, description) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$user_id, $branch_id, $action_type, $description]);
}

/**
 * Calculate earned paid holidays for an employee based on their
 * total working days and their max_paid_leaves_cap (2 or 4).
 * Uses the branch_leave_rules table. Falls back to hardcoded defaults.
 *
 * Scenario A (cap=4): 0-9d=0, 10-13d=1, 14-19d=2, 20-23d=3, 24+d=4
 * Scenario B (cap=2): 0-13d=0, 14-23d=1, 24+d=2
 */
function calculate_paid_holidays($pdo, $branch_id, $total_working_days, $max_cap) {
    // Try DB rules first
    $stmt = $pdo->prepare(
        "SELECT earned_paid_leaves FROM branch_leave_rules
         WHERE branch_id = ? AND max_leaves_cap = ?
           AND min_working_days <= ?
         ORDER BY min_working_days DESC LIMIT 1"
    );
    $stmt->execute([$branch_id, $max_cap, $total_working_days]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['earned_paid_leaves'];

    // Hardcoded fallback (mirrors seeded data)
    if ($max_cap == 4) {
        if ($total_working_days >= 24) return 4;
        if ($total_working_days >= 20) return 3;
        if ($total_working_days >= 14) return 2;
        if ($total_working_days >= 10) return 1;
        return 0;
    } else { // cap = 2
        if ($total_working_days >= 24) return 2;
        if ($total_working_days >= 14) return 1;
        return 0;
    }
}

/**
 * Calculate full payroll row for one employee for a given month/year.
 * Returns the complete data matching Spreadsheet 2 columns exactly.
 */
function calculate_employee_payroll($pdo, $user_id, $branch_id, $month, $year) {
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    // --- Contract data ---
    $contract = $pdo->prepare(
        "SELECT monthly_fixed_salary, monthly_paid_leaves, max_paid_leaves_cap,
                standard_shift_hours, week_off_day, pre_advance_balance, final_advance_balance
         FROM employee_contracts WHERE user_id = ?"
    );
    $contract->execute([$user_id]);
    $contract = $contract->fetch();
    if (!$contract) return null;

    $base_salary = (float)$contract['monthly_fixed_salary'];
    $max_cap     = (int)$contract['max_paid_leaves_cap'];
    $target_hrs  = (float)$contract['standard_shift_hours'];
    $week_off    = strtolower(trim($contract['week_off_day'] ?? ''));

    // --- Attendance: count present days from attendance_punches ---
    // A day counts if the employee has at least 2 punches (in + out)
    // and worked >= target/2 hours (half day = 0.5, full day = 1)
    $punches_stmt = $pdo->prepare(
        "SELECT DATE(punch_time) as p_date, MIN(punch_time) as first_in, MAX(punch_time) as last_out,
                COUNT(*) as punch_count
         FROM attendance_punches
         WHERE user_id = ? AND MONTH(punch_time) = ? AND YEAR(punch_time) = ?
         GROUP BY DATE(punch_time)"
    );
    $punches_stmt->execute([$user_id, $month, $year]);
    $daily_punches = $punches_stmt->fetchAll();

    // Check for manual overrides
    $override_stmt = $pdo->prepare(
        "SELECT date, override_status FROM attendance_overrides
         WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?"
    );
    $override_stmt->execute([$user_id, $month, $year]);
    $overrides = [];
    foreach ($override_stmt->fetchAll() as $ov) {
        $overrides[$ov['date']] = $ov['override_status'];
    }

    $total_full_days = 0;
    $total_half_days = 0;
    $week_off_count  = 0;

    // Map of day-of-week string to PHP date('l') format
    $day_map = [
        'sunday'=>'Sunday','monday'=>'Monday','tuesday'=>'Tuesday',
        'wednesday'=>'Wednesday','thursday'=>'Thursday','friday'=>'Friday','saturday'=>'Saturday'
    ];

    // Build a per-date index of punch data
    $punch_index = [];
    foreach ($daily_punches as $dp) {
        $punch_index[$dp['p_date']] = $dp;
    }

    for ($d = 1; $d <= $days_in_month; $d++) {
        $date_str   = sprintf("%04d-%02d-%02d", $year, $month, $d);
        $day_name   = date('l', strtotime($date_str));
        $is_week_off = ($week_off && strtolower($day_name) === $week_off);

        // Check override first
        if (isset($overrides[$date_str])) {
            $ov_status = $overrides[$date_str];
            if ($ov_status === 'F')  { $total_full_days++; }
            elseif ($ov_status === 'H')  { $total_half_days++; }
            elseif ($ov_status === 'WO') { $week_off_count++; }
            continue;
        }

        if ($is_week_off) { $week_off_count++; continue; }

        if (!isset($punch_index[$date_str])) continue; // Absent

        $dp = $punch_index[$date_str];
        if ($dp['punch_count'] < 2) continue; // Missed punch-out = not counted

        $hours = (strtotime($dp['last_out']) - strtotime($dp['first_in'])) / 3600;
        if ($hours >= ($target_hrs - 0.5)) {
            $total_full_days++;
        } elseif ($hours >= ($target_hrs / 2)) {
            $total_half_days++;
        }
        // else: short attendance = absent, not counted
    }

    // Total working days = full + half(as 0.5)
    $total_duty = $total_full_days + ($total_half_days * 0.5);

    // --- Paid Holidays ---
    $paid_holidays = calculate_paid_holidays($pdo, $branch_id, $total_duty, $max_cap);

    // Paid duty = total duty + paid holidays (cannot exceed days_in_month)
    $paid_duty = min($total_duty + $paid_holidays, $days_in_month);

    // --- Gross Salary ---
    // Formula: (monthly_salary / days_in_month) * paid_duty
    $gross_salary = ($days_in_month > 0) ? ($base_salary / $days_in_month) * $paid_duty : 0;
    $gross_salary = round($gross_salary, 2);

    // --- Advances for this month from advance_ledger ---
    $adv_stmt = $pdo->prepare(
        "SELECT type, SUM(amount) as total
         FROM advance_ledger
         WHERE user_id = ? AND month = ? AND year = ?
         GROUP BY type"
    );
    $adv_stmt->execute([$user_id, $month, $year]);
    $adv_rows    = $adv_stmt->fetchAll();
    $pre_advance  = 0; $final_advance = 0;
    $shop_advance = 0; $shop_bill     = 0;
    $fine         = 0; $repayment     = 0;
    foreach ($adv_rows as $ar) {
        switch ($ar['type']) {
            case 'pre_advance':   $pre_advance   = (float)$ar['total']; break;
            case 'final_advance': $final_advance = (float)$ar['total']; break;
            case 'shop_advance':  $shop_advance  = (float)$ar['total']; break;
            case 'shop_bill':     $shop_bill     = (float)$ar['total']; break;
            case 'fine':          $fine          = (float)$ar['total']; break;
            case 'repayment':     $repayment     = (float)$ar['total']; break;
        }
    }

    $total_advance  = $pre_advance + $final_advance + $shop_advance + $shop_bill + $fine;
    $deduction      = $total_advance; // Can be extended later for other deductions
    $salary_to_pay  = round(max($gross_salary - $deduction + $repayment, 0), 2);

    // 30% advance cap = max advance employee is eligible to take
    $max_advance_allowed = round($base_salary * 0.30, 2);

    return [
        'user_id'             => $user_id,
        'base_salary'         => $base_salary,
        'days_in_month'       => $days_in_month,
        'total_full_days'     => $total_full_days,
        'total_half_days'     => $total_half_days,
        'week_off_count'      => $week_off_count,
        'total_duty'          => $total_duty,         // Total working days (full + half*0.5)
        'paid_holidays'       => $paid_holidays,       // Earned via leave tier formula
        'paid_duty'           => $paid_duty,           // total_duty + paid_holidays
        'gross_salary'        => $gross_salary,
        'pre_advance'         => $pre_advance,
        'final_advance'       => $final_advance,
        'shop_advance'        => $shop_advance,
        'shop_bill'           => $shop_bill,
        'fine'                => $fine,
        'repayment'           => $repayment,
        'total_advance'       => $total_advance,
        'deduction'           => $deduction,
        'salary_to_pay'       => $salary_to_pay,
        'max_advance_allowed' => $max_advance_allowed, // 30% cap
        'max_paid_leaves_cap' => $max_cap,
    ];
}

// ================================================================
// ACTION ROUTER
// ================================================================

switch ($action) {

    // ----------------------------------------
    // SYSTEM
    // ----------------------------------------
    case 'ping':
        echo json_encode(['status' => 'success', 'message' => 'Caketown ERP API v2 is live.', 'time' => date('Y-m-d H:i:s')]);
        break;

    // ----------------------------------------
    // AUTH
    // ----------------------------------------
    case 'login':
        $mobile   = trim($request['mobile_number'] ?? '');
        $password = $request['password'] ?? '';
        if (empty($mobile) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Mobile number and password are required.']); exit;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT u.id, u.branch_id, u.role, u.department, u.name, u.mobile_number,
                        u.password, u.status, u.feature_permissions,
                        c.monthly_fixed_salary, c.monthly_paid_leaves, c.max_paid_leaves_cap,
                        c.standard_shift_hours, c.week_off_day, c.max_advance_percentage
                 FROM users u
                 LEFT JOIN employee_contracts c ON u.id = c.user_id
                 WHERE u.mobile_number = ? LIMIT 1"
            );
            $stmt->execute([$mobile]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') {
                    echo json_encode(['status' => 'error', 'message' => 'Your account is inactive. Contact admin.']); exit;
                }
                log_action($pdo, $user['id'], $user['branch_id'], 'AUTH_LOGIN', "{$user['name']} logged in.");
                unset($user['password']);
                $user['feature_permissions'] = json_decode($user['feature_permissions'] ?? '[]', true);
                echo json_encode(['status' => 'success', 'user' => $user]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid mobile number or password.']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Login system error.']);
        }
        break;

    // ----------------------------------------
    // ADMIN DASHBOARD
    // ----------------------------------------
    case 'get_admin_dashboard':
        try {
            $month = $request['month'] ?? (int)date('m');
            $year  = $request['year']  ?? (int)date('Y');

            $total_branches  = $pdo->query("SELECT COUNT(*) FROM branches WHERE status='active'")->fetchColumn();
            $total_employees = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin' AND status='active'")->fetchColumn();

            // Total salary expenditure for the given month/year from finalized payroll ledgers
            $salary_stmt = $pdo->prepare(
                "SELECT COALESCE(SUM(salary_to_pay),0) as total
                 FROM payroll_ledgers WHERE payroll_month=? AND payroll_year=?"
            );
            $salary_stmt->execute([$month, $year]);
            $salary_expenditure = $salary_stmt->fetchColumn();

            // Branch grid with live today's attendance count
            $branch_grid = $pdo->query(
                "SELECT b.id, b.branch_name, b.address, b.status,
                        COUNT(DISTINCT u.id) as staff_count,
                        COUNT(DISTINCT ap.user_id) as present_today
                 FROM branches b
                 LEFT JOIN users u ON b.id = u.branch_id AND u.status='active' AND u.role != 'admin'
                 LEFT JOIN attendance_punches ap ON ap.user_id = u.id AND DATE(ap.punch_time) = CURDATE()
                 GROUP BY b.id ORDER BY b.created_at DESC"
            )->fetchAll();

            // System history feed (last 50 entries)
            $logs = $pdo->query(
                "SELECT l.id, l.action_type, l.description, l.created_at,
                        u.name as actor_name, u.role as actor_role,
                        b.branch_name
                 FROM system_logs l
                 LEFT JOIN users u ON l.user_id = u.id
                 LEFT JOIN branches b ON l.branch_id = b.id
                 ORDER BY l.id DESC LIMIT 50"
            )->fetchAll();

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'total_branches'      => (int)$total_branches,
                    'total_employees'     => (int)$total_employees,
                    'salary_expenditure'  => (float)$salary_expenditure,
                    'month'               => (int)$month,
                    'year'                => (int)$year,
                    'branch_grid'         => $branch_grid,
                    'system_history'      => $logs,
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Dashboard load failed.']);
        }
        break;

    // ----------------------------------------
    // SYSTEM HISTORY (paginated)
    // ----------------------------------------
    case 'get_system_history':
        $page     = max(1, (int)($request['page'] ?? 1));
        $per_page = min(100, max(10, (int)($request['per_page'] ?? 25)));
        $offset   = ($page - 1) * $per_page;
        $branch_filter = $request['branch_id'] ?? null;
        try {
            $where  = $branch_filter ? "WHERE l.branch_id = $branch_filter" : '';
            $total  = $pdo->query("SELECT COUNT(*) FROM system_logs l $where")->fetchColumn();
            $stmt   = $pdo->prepare(
                "SELECT l.id, l.action_type, l.description, l.created_at,
                        u.name as actor_name, u.role as actor_role, b.branch_name
                 FROM system_logs l
                 LEFT JOIN users u ON l.user_id = u.id
                 LEFT JOIN branches b ON l.branch_id = b.id
                 $where ORDER BY l.id DESC LIMIT $per_page OFFSET $offset"
            );
            $stmt->execute();
            echo json_encode([
                'status' => 'success',
                'data'   => $stmt->fetchAll(),
                'total'  => (int)$total,
                'page'   => $page,
                'per_page' => $per_page,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'History fetch failed.']);
        }
        break;

    // ----------------------------------------
    // BRANCHES
    // ----------------------------------------
    case 'get_branches':
        try {
            $rows = $pdo->query(
                "SELECT b.*, COUNT(u.id) as staff_count
                 FROM branches b LEFT JOIN users u ON b.id=u.branch_id AND u.status='active' AND u.role!='admin'
                 GROUP BY b.id ORDER BY b.created_at DESC"
            )->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $rows]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Fetch failed.']);
        }
        break;

    case 'create_branch':
        $name    = trim($request['branch_name'] ?? '');
        $address = trim($request['address'] ?? '');
        $admin_id = $request['admin_id'] ?? null;
        if (empty($name)) { echo json_encode(['status' => 'error', 'message' => 'Branch name is required.']); exit; }
        try {
            $pdo->prepare("INSERT INTO branches (branch_name, address) VALUES (?, ?)")->execute([$name, $address]);
            $new_id = $pdo->lastInsertId();
            log_action($pdo, $admin_id, $new_id, 'BRANCH_CREATED', "Branch '$name' created.");
            echo json_encode(['status' => 'success', 'message' => 'Branch created.', 'branch_id' => (int)$new_id]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Branch creation failed.']);
        }
        break;

    case 'update_branch':
        $id      = $request['id'] ?? null;
        $name    = trim($request['branch_name'] ?? '');
        $address = trim($request['address'] ?? '');
        $status  = $request['status'] ?? 'active';
        $admin_id = $request['admin_id'] ?? null;
        if (!$id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $pdo->prepare("UPDATE branches SET branch_name=?, address=?, status=? WHERE id=?")
                ->execute([$name, $address, $status, $id]);
            log_action($pdo, $admin_id, $id, 'BRANCH_UPDATED', "Branch ID $id updated.");
            echo json_encode(['status' => 'success', 'message' => 'Branch updated.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Update failed.']);
        }
        break;

    // ----------------------------------------
    // BRANCH MASTER VIEW (admin opens a branch)
    // ----------------------------------------
    case 'get_branch_master':
        $branch_id = $request['branch_id'] ?? null;
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $branch_stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $branch_stmt->execute([$branch_id]);
            $branch = $branch_stmt->fetch();

            $staff_stmt = $pdo->prepare(
                "SELECT u.id, u.name, u.role, u.department, u.mobile_number, u.status,
                        u.feature_permissions, u.created_at,
                        (u.face_descriptor IS NOT NULL) as face_registered,
                        c.monthly_fixed_salary, c.monthly_paid_leaves, c.max_paid_leaves_cap,
                        c.standard_shift_hours, c.week_off_day, c.max_advance_percentage,
                        c.pre_advance_balance, c.final_advance_balance
                 FROM users u
                 LEFT JOIN employee_contracts c ON u.id = c.user_id
                 WHERE u.branch_id = ?
                 ORDER BY FIELD(u.role,'manager','staff'), u.name ASC"
            );
            $staff_stmt->execute([$branch_id]);
            $staff = $staff_stmt->fetchAll();
            foreach ($staff as &$s) {
                $s['feature_permissions'] = json_decode($s['feature_permissions'] ?? '[]', true);
            }
            unset($s);

            // Today's recent punches for the activity feed
            $punches_stmt = $pdo->prepare(
                "SELECT u.name, u.id as user_id, p.punch_time
                 FROM attendance_punches p
                 JOIN users u ON p.user_id = u.id
                 WHERE u.branch_id = ? AND DATE(p.punch_time) = CURDATE()
                 ORDER BY p.punch_time DESC LIMIT 30"
            );
            $punches_stmt->execute([$branch_id]);
            $recent_punches = $punches_stmt->fetchAll();

            echo json_encode([
                'status' => 'success',
                'data'   => [
                    'branch'         => $branch,
                    'staff'          => $staff,
                    'recent_punches' => $recent_punches,
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to load branch.']);
        }
        break;

    // ----------------------------------------
    // EMPLOYEE MANAGEMENT (create / update / delete)
    // ----------------------------------------
    case 'create_user':
        $role       = $request['role'] ?? 'staff';
        $branch_id  = ($role === 'admin') ? null : ($request['branch_id'] ?? null);
        $name       = trim($request['name'] ?? '');
        $mobile     = trim($request['mobile_number'] ?? '');
        $password   = $request['password'] ?? '';
        $department = trim($request['department'] ?? '');
        $salary     = (float)($request['salary'] ?? 0);
        $paid_leaves     = (int)($request['paid_leaves'] ?? 0);
        $max_leaves_cap  = in_array((int)($request['max_leaves_cap'] ?? 4), [2,4]) ? (int)$request['max_leaves_cap'] : 4;
        $shift_hours     = (float)($request['shift_hours'] ?? 9);
        $week_off_day    = $request['week_off_day'] ?? 'Sunday';
        $permissions     = json_encode($request['permissions'] ?? []);
        $admin_id        = $request['admin_id'] ?? null;

        if (empty($name) || empty($mobile) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Name, mobile, and password are required.']); exit;
        }
        try {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $pdo->beginTransaction();

            $pdo->prepare(
                "INSERT INTO users (branch_id, role, department, name, mobile_number, password, feature_permissions)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([$branch_id, $role, $department, $name, $mobile, $hashed, $permissions]);
            $new_id = $pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO employee_contracts
                 (user_id, monthly_fixed_salary, monthly_paid_leaves, max_paid_leaves_cap,
                  standard_shift_hours, week_off_day)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$new_id, $salary, $paid_leaves, $max_leaves_cap, $shift_hours, $week_off_day]);

            log_action($pdo, $admin_id, $branch_id, 'USER_CREATED', "New $role '$name' (ID:$new_id) created.");
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Employee created successfully.', 'user_id' => (int)$new_id]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = ($e->getCode() == 23000) ? 'This mobile number is already registered.' : 'Employee creation failed.';
            echo json_encode(['status' => 'error', 'message' => $msg]);
        }
        break;

    case 'update_user':
        $user_id    = $request['user_id'] ?? null;
        $admin_id   = $request['admin_id'] ?? null;
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'User ID required.']); exit; }
        try {
            // Fetch existing to only update provided fields
            $existing = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $existing->execute([$user_id]);
            $existing = $existing->fetch();
            if (!$existing) { echo json_encode(['status' => 'error', 'message' => 'User not found.']); exit; }

            $name       = trim($request['name']       ?? $existing['name']);
            $mobile     = trim($request['mobile_number'] ?? $existing['mobile_number']);
            $department = trim($request['department'] ?? $existing['department']);
            $role       = $request['role']   ?? $existing['role'];
            $status     = $request['status'] ?? $existing['status'];
            $branch_id  = array_key_exists('branch_id', $request) ? $request['branch_id'] : $existing['branch_id'];
            $permissions = array_key_exists('permissions', $request)
                ? json_encode($request['permissions'])
                : $existing['feature_permissions'];

            $pdo->beginTransaction();

            if (!empty($request['password'])) {
                $pdo->prepare(
                    "UPDATE users SET name=?,mobile_number=?,department=?,role=?,status=?,branch_id=?,feature_permissions=?,password=? WHERE id=?"
                )->execute([$name,$mobile,$department,$role,$status,$branch_id,$permissions,password_hash($request['password'],PASSWORD_DEFAULT),$user_id]);
            } else {
                $pdo->prepare(
                    "UPDATE users SET name=?,mobile_number=?,department=?,role=?,status=?,branch_id=?,feature_permissions=? WHERE id=?"
                )->execute([$name,$mobile,$department,$role,$status,$branch_id,$permissions,$user_id]);
            }

            // Update contract if contract fields provided
            if (isset($request['salary']) || isset($request['paid_leaves']) || isset($request['shift_hours']) ||
                isset($request['week_off_day']) || isset($request['max_leaves_cap'])) {

                $con = $pdo->prepare("SELECT * FROM employee_contracts WHERE user_id = ?");
                $con->execute([$user_id]);
                $con = $con->fetch();

                $salary        = (float)($request['salary']       ?? ($con['monthly_fixed_salary']  ?? 0));
                $paid_leaves   = (int)  ($request['paid_leaves']  ?? ($con['monthly_paid_leaves']   ?? 0));
                $cap           = in_array((int)($request['max_leaves_cap'] ?? ($con['max_paid_leaves_cap'] ?? 4)),[2,4])
                                    ? (int)($request['max_leaves_cap'] ?? $con['max_paid_leaves_cap']) : 4;
                $shift_hours   = (float)($request['shift_hours']  ?? ($con['standard_shift_hours']  ?? 9));
                $week_off      = $request['week_off_day'] ?? ($con['week_off_day'] ?? 'Sunday');

                if ($con) {
                    $pdo->prepare(
                        "UPDATE employee_contracts SET monthly_fixed_salary=?,monthly_paid_leaves=?,
                         max_paid_leaves_cap=?,standard_shift_hours=?,week_off_day=? WHERE user_id=?"
                    )->execute([$salary,$paid_leaves,$cap,$shift_hours,$week_off,$user_id]);
                } else {
                    $pdo->prepare(
                        "INSERT INTO employee_contracts (user_id,monthly_fixed_salary,monthly_paid_leaves,max_paid_leaves_cap,standard_shift_hours,week_off_day) VALUES (?,?,?,?,?,?)"
                    )->execute([$user_id,$salary,$paid_leaves,$cap,$shift_hours,$week_off]);
                }
            }

            log_action($pdo, $admin_id, $branch_id, 'USER_UPDATED', "Employee '$name' (ID:$user_id) updated.");
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Employee updated successfully.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = ($e->getCode() == 23000) ? 'This mobile number is already registered.' : 'Update failed.';
            echo json_encode(['status' => 'error', 'message' => $msg]);
        }
        break;

    case 'delete_user':
        $user_id  = $request['user_id'] ?? null;
        $admin_id = $request['admin_id'] ?? null;
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'User ID required.']); exit; }
        try {
            $name_row = $pdo->prepare("SELECT name, branch_id FROM users WHERE id = ?");
            $name_row->execute([$user_id]);
            $name_row = $name_row->fetch();
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
            log_action($pdo, $admin_id, $name_row['branch_id'] ?? null, 'USER_DELETED', "Employee '{$name_row['name']}' (ID:$user_id) deleted.");
            echo json_encode(['status' => 'success', 'message' => 'Employee removed.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Delete failed.']);
        }
        break;

    case 'get_branch_staff':
        $branch_id = $request['branch_id'] ?? null;
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $stmt = $pdo->prepare(
                "SELECT u.id, u.name, u.role, u.department, u.mobile_number, u.status,
                        (u.face_descriptor IS NOT NULL) as face_registered,
                        c.monthly_fixed_salary, c.standard_shift_hours
                 FROM users u LEFT JOIN employee_contracts c ON u.id = c.user_id
                 WHERE u.branch_id = ?
                 ORDER BY FIELD(u.role,'manager','staff'), u.name ASC"
            );
            $stmt->execute([$branch_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Fetch failed.']);
        }
        break;

    // ----------------------------------------
    // LIVE ATTENDANCE DASHBOARD
    // ----------------------------------------
    case 'get_live_attendance':
        $branch_id = $request['branch_id'] ?? null;
        $date      = $request['date'] ?? date('Y-m-d');
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $staff_stmt = $pdo->prepare(
                "SELECT u.id, u.name, u.role, u.department, c.standard_shift_hours
                 FROM users u LEFT JOIN employee_contracts c ON u.id = c.user_id
                 WHERE u.branch_id = ? AND u.status = 'active'
                 ORDER BY FIELD(u.role,'manager','staff'), u.name ASC"
            );
            $staff_stmt->execute([$branch_id]);
            $staff_list = $staff_stmt->fetchAll();

            $punches_stmt = $pdo->prepare(
                "SELECT p.user_id, p.punch_time
                 FROM attendance_punches p
                 JOIN users u ON p.user_id = u.id
                 WHERE u.branch_id = ? AND DATE(p.punch_time) = ?
                 ORDER BY p.punch_time ASC"
            );
            $punches_stmt->execute([$branch_id, $date]);
            $all_punches = $punches_stmt->fetchAll();

            // Index punches by user
            $punch_map = [];
            foreach ($all_punches as $p) {
                $punch_map[$p['user_id']][] = $p['punch_time'];
            }

            $result = [];
            foreach ($staff_list as $s) {
                $uid        = $s['id'];
                $punches    = $punch_map[$uid] ?? [];
                $target_hrs = (float)($s['standard_shift_hours'] ?? 9);
                $now_ts     = time();

                $status           = 'absent';
                $first_in         = null;
                $last_punch       = null;
                $total_working_min = 0;
                $current_session_min = 0;
                $is_on_break      = false;

                if (count($punches) > 0) {
                    $first_in   = $punches[0];
                    $last_punch = end($punches);
                    $punch_count = count($punches);

                    // Odd punches = currently punched IN, even = on break
                    $is_punched_in = ($punch_count % 2 === 1);

                    // Calculate total active (working) minutes from paired punches
                    $total_working_min = 0;
                    for ($i = 0; $i + 1 < $punch_count; $i += 2) {
                        $total_working_min += (strtotime($punches[$i+1]) - strtotime($punches[$i])) / 60;
                    }
                    // If currently punched in, add current open session
                    if ($is_punched_in) {
                        $current_session_min = ($now_ts - strtotime($last_punch)) / 60;
                        $total_working_min  += $current_session_min;
                        $status = 'working';
                    } else {
                        $is_on_break = true;
                        $status      = 'on_break';
                    }
                }

                $result[] = [
                    'id'                    => $uid,
                    'name'                  => $s['name'],
                    'role'                  => $s['role'],
                    'department'            => $s['department'],
                    'target_hours'          => $target_hrs,
                    'status'                => $status,   // absent | working | on_break
                    'first_in'              => $first_in,
                    'last_punch'            => $last_punch,
                    'total_working_minutes' => round($total_working_min),
                    'punches'               => $punches,
                ];
            }

            $present_count = count(array_filter($result, fn($r) => $r['status'] !== 'absent'));

            echo json_encode([
                'status'        => 'success',
                'date'          => $date,
                'present_count' => $present_count,
                'total_staff'   => count($result),
                'data'          => $result,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Live attendance fetch failed.']);
        }
        break;

    // ----------------------------------------
    // MONTHLY ATTENDANCE SHEET (Spreadsheet 1)
    // ----------------------------------------
    case 'get_monthly_attendance':
        $branch_id = $request['branch_id'] ?? null;
        $month     = (int)($request['month'] ?? date('m'));
        $year      = (int)($request['year']  ?? date('Y'));
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

            $staff_stmt = $pdo->prepare(
                "SELECT u.id, u.name, u.department, c.standard_shift_hours, c.week_off_day,
                        c.monthly_fixed_salary, c.max_paid_leaves_cap
                 FROM users u LEFT JOIN employee_contracts c ON u.id = c.user_id
                 WHERE u.branch_id = ? AND u.status = 'active'
                 ORDER BY FIELD(u.role,'manager','staff'), u.name ASC"
            );
            $staff_stmt->execute([$branch_id]);
            $staff = $staff_stmt->fetchAll();

            // Load all punches for the month
            $punches_stmt = $pdo->prepare(
                "SELECT p.user_id, DATE(p.punch_time) as p_date, MIN(p.punch_time) as first_in,
                        MAX(p.punch_time) as last_out, COUNT(*) as punch_count
                 FROM attendance_punches p
                 JOIN users u ON p.user_id = u.id
                 WHERE u.branch_id = ? AND MONTH(p.punch_time)=? AND YEAR(p.punch_time)=?
                 GROUP BY p.user_id, DATE(p.punch_time)"
            );
            $punches_stmt->execute([$branch_id, $month, $year]);
            $punch_index = [];
            foreach ($punches_stmt->fetchAll() as $p) {
                $punch_index[$p['user_id']][$p['p_date']] = $p;
            }

            // Load overrides
            $ov_stmt = $pdo->prepare(
                "SELECT user_id, date, override_status FROM attendance_overrides
                 WHERE MONTH(date)=? AND YEAR(date)=?"
            );
            $ov_stmt->execute([$month, $year]);
            $overrides = [];
            foreach ($ov_stmt->fetchAll() as $ov) {
                $overrides[$ov['user_id']][$ov['date']] = $ov['override_status'];
            }

            $grid = [];
            foreach ($staff as $s) {
                $uid        = $s['id'];
                $target_hrs = (float)($s['standard_shift_hours'] ?? 9);
                $week_off   = strtolower(trim($s['week_off_day'] ?? ''));
                $days_data  = [];
                $totals     = ['F' => 0, 'H' => 0, 'A' => 0, 'WO' => 0, 'M' => 0];

                for ($d = 1; $d <= $days_in_month; $d++) {
                    $dt       = sprintf("%04d-%02d-%02d", $year, $month, $d);
                    $day_name = strtolower(date('l', strtotime($dt)));

                    // Override takes highest precedence
                    if (isset($overrides[$uid][$dt])) {
                        $st = $overrides[$uid][$dt];
                        $totals[$st] = ($totals[$st] ?? 0) + 1;
                        $days_data[$d] = ['status' => $st, 'hours' => 0, 'overridden' => true];
                        continue;
                    }

                    if ($week_off && $day_name === $week_off) {
                        $totals['WO']++;
                        $days_data[$d] = ['status' => 'WO', 'hours' => 0];
                        continue;
                    }

                    if (!isset($punch_index[$uid][$dt])) {
                        $totals['A']++;
                        $days_data[$d] = ['status' => 'A', 'hours' => 0];
                        continue;
                    }

                    $dp = $punch_index[$uid][$dt];
                    if ($dp['punch_count'] < 2) {
                        $totals['M']++;
                        $days_data[$d] = ['status' => 'M', 'hours' => 0]; // Missed punch-out
                        continue;
                    }

                    $hours = (strtotime($dp['last_out']) - strtotime($dp['first_in'])) / 3600;
                    if ($hours >= ($target_hrs - 0.5)) {
                        $st = 'F'; $totals['F']++;
                    } elseif ($hours >= ($target_hrs / 2)) {
                        $st = 'H'; $totals['H']++;
                    } else {
                        $st = 'A'; $totals['A']++;
                    }
                    $days_data[$d] = ['status' => $st, 'hours' => round($hours, 1), 'first_in' => $dp['first_in'], 'last_out' => $dp['last_out']];
                }

                $total_duty  = $totals['F'] + ($totals['H'] * 0.5);
                $paid_holidays = calculate_paid_holidays($pdo, $branch_id, $total_duty, (int)($s['max_paid_leaves_cap'] ?? 4));

                $grid[] = [
                    'user_id'        => $uid,
                    'name'           => $s['name'],
                    'department'     => $s['department'],
                    'salary'         => (float)$s['monthly_fixed_salary'],
                    'days'           => $days_data,
                    'totals'         => $totals,
                    'total_duty'     => $total_duty,
                    'week_off_count' => $totals['WO'],
                    'paid_holidays'  => $paid_holidays,
                    'paid_duty'      => $total_duty + $paid_holidays,
                ];
            }

            echo json_encode([
                'status'        => 'success',
                'month'         => $month,
                'year'          => $year,
                'days_in_month' => $days_in_month,
                'data'          => $grid,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Attendance sheet generation failed.']);
        }
        break;

    // ----------------------------------------
    // ATTENDANCE OVERRIDE (admin can manually set F/H/A/WO for a day)
    // ----------------------------------------
    case 'set_attendance_override':
        $user_id   = $request['user_id']   ?? null;
        $date      = $request['date']      ?? null;   // YYYY-MM-DD
        $status    = $request['status']    ?? null;   // F | H | A | WO | PH
        $reason    = $request['reason']    ?? null;
        $admin_id  = $request['admin_id']  ?? null;
        $valid_statuses = ['F','H','A','WO','PH'];
        if (!$user_id || !$date || !in_array($status, $valid_statuses)) {
            echo json_encode(['status' => 'error', 'message' => 'user_id, date, and valid status (F/H/A/WO/PH) required.']); exit;
        }
        try {
            $pdo->prepare(
                "INSERT INTO attendance_overrides (user_id, date, override_status, overridden_by, reason)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE override_status=VALUES(override_status), overridden_by=VALUES(overridden_by), reason=VALUES(reason)"
            )->execute([$user_id, $date, $status, $admin_id, $reason]);
            log_action($pdo, $admin_id, null, 'ATTENDANCE_OVERRIDE', "Attendance for UID:$user_id on $date manually set to '$status'.");
            echo json_encode(['status' => 'success', 'message' => 'Attendance override applied.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Override failed.']);
        }
        break;

    // ----------------------------------------
    // FACE BIOMETRICS
    // ----------------------------------------
    case 'register_face':
        $user_id    = $request['user_id']    ?? null;
        $manager_id = $request['manager_id'] ?? null;
        $branch_id  = $request['branch_id']  ?? null;
        $descriptor = $request['descriptor'] ?? null;
        if (!$user_id || !$descriptor) { echo json_encode(['status' => 'error', 'message' => 'User ID and face descriptor are required.']); exit; }
        try {
            $pdo->prepare("UPDATE users SET face_descriptor = ? WHERE id = ?")->execute([$descriptor, $user_id]);
            // Log to face_registration_log for full audit trail
            $pdo->prepare(
                "INSERT INTO face_registration_log (employee_id, registered_by, branch_id) VALUES (?, ?, ?)"
            )->execute([$user_id, $manager_id ?? $user_id, $branch_id]);
            $emp_name = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $emp_name->execute([$user_id]);
            $emp_name = $emp_name->fetchColumn();
            log_action($pdo, $manager_id, $branch_id, 'FACE_REGISTERED', "Face biometric registered/updated for '$emp_name' (ID:$user_id).");
            echo json_encode(['status' => 'success', 'message' => 'Face registered successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Face registration failed.']);
        }
        break;

    case 'get_branch_descriptors':
        $branch_id = $request['branch_id'] ?? null;
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $stmt = $pdo->prepare(
                "SELECT id, name, role, face_descriptor FROM users
                 WHERE branch_id = ? AND face_descriptor IS NOT NULL AND status = 'active'"
            );
            $stmt->execute([$branch_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to load biometric data.']);
        }
        break;

    // ----------------------------------------
    // ATTENDANCE TERMINAL — PUNCH LOG
    // ----------------------------------------
    case 'log_punch':
        $user_id   = $request['user_id']   ?? null;
        $branch_id = $request['branch_id'] ?? null;
        if (!$user_id || !$branch_id) { echo json_encode(['status' => 'error', 'message' => 'User ID and Branch ID required.']); exit; }
        try {
            // Verify user belongs to this branch
            $check = $pdo->prepare("SELECT name FROM users WHERE id = ? AND branch_id = ? AND status = 'active'");
            $check->execute([$user_id, $branch_id]);
            $user_name = $check->fetchColumn();
            if (!$user_name) { echo json_encode(['status' => 'error', 'message' => 'User not found in this branch.']); exit; }

            $pdo->prepare("INSERT INTO attendance_punches (user_id, punch_time) VALUES (?, NOW())")->execute([$user_id]);

            // Determine punch type (in/out) based on today's punch count parity
            $today_count = $pdo->prepare(
                "SELECT COUNT(*) FROM attendance_punches WHERE user_id = ? AND DATE(punch_time) = CURDATE()"
            );
            $today_count->execute([$user_id]);
            $count = (int)$today_count->fetchColumn();
            $punch_type = ($count % 2 === 1) ? 'Punch In' : 'Punch Out';

            echo json_encode([
                'status'     => 'success',
                'message'    => "$punch_type recorded for $user_name",
                'user_name'  => $user_name,
                'punch_type' => $punch_type,
                'punch_time' => date('Y-m-d H:i:s'),
                'punch_number' => $count,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Punch log failed.']);
        }
        break;

    // ----------------------------------------
    // ADVANCE LEDGER — LOG ANY ADVANCE/DEDUCTION
    // ----------------------------------------
    case 'log_advance':
        $user_id   = $request['user_id']   ?? null;
        $branch_id = $request['branch_id'] ?? null;
        $logged_by = $request['logged_by'] ?? null;
        $type      = $request['type']      ?? null;  // pre_advance|final_advance|shop_advance|shop_bill|fine|repayment|other
        $amount    = (float)($request['amount'] ?? 0);
        $month     = (int)($request['month']  ?? date('m'));
        $year      = (int)($request['year']   ?? date('Y'));
        $remarks   = trim($request['remarks'] ?? '');

        $valid_types = ['pre_advance','final_advance','shop_advance','shop_bill','fine','repayment','other'];
        if (!$user_id || !$logged_by || !$type || !in_array($type, $valid_types) || $amount <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'user_id, logged_by, valid type, and positive amount are required.']); exit;
        }
        try {
            // Check 30% advance cap for pre_advance and final_advance types
            if (in_array($type, ['pre_advance', 'final_advance'])) {
                $cap_stmt = $pdo->prepare(
                    "SELECT monthly_fixed_salary * (max_advance_percentage/100) as max_allowed
                     FROM employee_contracts WHERE user_id = ?"
                );
                $cap_stmt->execute([$user_id]);
                $cap_row = $cap_stmt->fetch();
                if ($cap_row) {
                    // Sum existing advances of this type this month
                    $existing_stmt = $pdo->prepare(
                        "SELECT COALESCE(SUM(amount),0) FROM advance_ledger
                         WHERE user_id=? AND type IN ('pre_advance','final_advance') AND month=? AND year=?"
                    );
                    $existing_stmt->execute([$user_id, $month, $year]);
                    $existing_total = (float)$existing_stmt->fetchColumn();
                    $max_allowed    = (float)$cap_row['max_allowed'];
                    if (($existing_total + $amount) > $max_allowed) {
                        echo json_encode([
                            'status'      => 'error',
                            'message'     => 'Advance exceeds 30% salary cap.',
                            'max_allowed' => $max_allowed,
                            'already_given' => $existing_total,
                        ]); exit;
                    }
                }
            }

            $pdo->prepare(
                "INSERT INTO advance_ledger (user_id, branch_id, logged_by, type, amount, month, year, remarks)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$user_id, $branch_id, $logged_by, $type, $amount, $month, $year, $remarks]);

            // Fetch employee name for log
            $emp = $pdo->prepare("SELECT name FROM users WHERE id=?");
            $emp->execute([$user_id]);
            $emp_name = $emp->fetchColumn();

            log_action($pdo, $logged_by, $branch_id, 'ADVANCE_LOGGED',
                "$type of ₹$amount logged for '$emp_name' (Month: $month/$year). Remarks: $remarks");

            echo json_encode(['status' => 'success', 'message' => ucfirst(str_replace('_',' ',$type)) . " of ₹$amount logged successfully."]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Advance log failed.']);
        }
        break;

    case 'get_advance_history':
        $user_id = $request['user_id'] ?? null;
        $month   = $request['month']   ?? null;
        $year    = $request['year']    ?? null;
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'User ID required.']); exit; }
        try {
            $where_parts = ['al.user_id = ?'];
            $params      = [$user_id];
            if ($month && $year) { $where_parts[] = 'al.month = ? AND al.year = ?'; $params[] = $month; $params[] = $year; }
            $where = implode(' AND ', $where_parts);
            $stmt = $pdo->prepare(
                "SELECT al.*, u.name as logged_by_name
                 FROM advance_ledger al LEFT JOIN users u ON al.logged_by = u.id
                 WHERE $where ORDER BY al.created_at DESC"
            );
            $stmt->execute($params);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Advance history fetch failed.']);
        }
        break;

    // ----------------------------------------
    // PAYROLL ENGINE
    // ----------------------------------------
    case 'get_payroll_data':
        $branch_id = $request['branch_id'] ?? null;
        $month     = (int)($request['month'] ?? date('m'));
        $year      = (int)($request['year']  ?? date('Y'));
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $staff_stmt = $pdo->prepare(
                "SELECT u.id, u.name, u.role, u.department FROM users u
                 WHERE u.branch_id = ? AND u.status = 'active'
                 ORDER BY FIELD(u.role,'manager','staff'), u.name ASC"
            );
            $staff_stmt->execute([$branch_id]);
            $staff = $staff_stmt->fetchAll();

            $result = [];
            foreach ($staff as $s) {
                $payroll = calculate_employee_payroll($pdo, $s['id'], $branch_id, $month, $year);
                if (!$payroll) continue;

                // Merge employee info + payroll calculation
                $row = array_merge($s, $payroll);

                // Check if a saved ledger exists (for paid/advance_due status)
                $ledger_stmt = $pdo->prepare(
                    "SELECT status, paid_amount, advance_due, remarks FROM payroll_ledgers
                     WHERE user_id=? AND payroll_month=? AND payroll_year=?"
                );
                $ledger_stmt->execute([$s['id'], $month, $year]);
                $ledger = $ledger_stmt->fetch();
                $row['ledger_status']  = $ledger['status']      ?? 'draft';
                $row['paid_amount']    = (float)($ledger['paid_amount']  ?? 0);
                $row['advance_due']    = (float)($ledger['advance_due']  ?? 0);
                $row['remarks']        = $ledger['remarks']     ?? '';

                $result[] = $row;
            }

            $total_payable = array_sum(array_column($result, 'salary_to_pay'));

            echo json_encode([
                'status'        => 'success',
                'month'         => $month,
                'year'          => $year,
                'total_payable' => round($total_payable, 2),
                'data'          => $result,
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Payroll generation failed.']);
        }
        break;

    case 'save_payroll_ledger':
        // Called when admin/manager finalizes or updates the payroll row for one employee.
        $user_id   = $request['user_id']   ?? null;
        $branch_id = $request['branch_id'] ?? null;
        $month     = (int)($request['month'] ?? date('m'));
        $year      = (int)($request['year']  ?? date('Y'));
        $saved_by  = $request['saved_by']  ?? null;
        if (!$user_id || !$branch_id) { echo json_encode(['status' => 'error', 'message' => 'user_id and branch_id required.']); exit; }
        try {
            $calc = calculate_employee_payroll($pdo, $user_id, $branch_id, $month, $year);
            if (!$calc) { echo json_encode(['status' => 'error', 'message' => 'Could not calculate payroll.']); exit; }

            $paid_amount = (float)($request['paid_amount'] ?? 0);
            $advance_due = (float)($request['advance_due'] ?? 0);
            $remarks     = trim($request['remarks'] ?? '');
            $status      = $request['status'] ?? 'draft'; // draft | generated | paid

            $pdo->prepare(
                "INSERT INTO payroll_ledgers
                 (branch_id, user_id, payroll_month, payroll_year,
                  total_duty, paid_leaves, paid_duty, base_salary,
                  pre_advance, final_advance, shop_advance, shop_bill,
                  total_advance, deduction, salary_to_pay,
                  paid_amount, advance_due, remarks, status, finalized_by, finalized_at)
                 VALUES (?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?,?, ?,NOW())
                 ON DUPLICATE KEY UPDATE
                  total_duty=VALUES(total_duty), paid_leaves=VALUES(paid_leaves),
                  paid_duty=VALUES(paid_duty), base_salary=VALUES(base_salary),
                  pre_advance=VALUES(pre_advance), final_advance=VALUES(final_advance),
                  shop_advance=VALUES(shop_advance), shop_bill=VALUES(shop_bill),
                  total_advance=VALUES(total_advance), deduction=VALUES(deduction),
                  salary_to_pay=VALUES(salary_to_pay),
                  paid_amount=VALUES(paid_amount), advance_due=VALUES(advance_due),
                  remarks=VALUES(remarks), status=VALUES(status),
                  finalized_by=VALUES(finalized_by), finalized_at=NOW()"
            )->execute([
                $branch_id, $user_id, $month, $year,
                $calc['total_duty'], $calc['paid_holidays'], $calc['paid_duty'], $calc['base_salary'],
                $calc['pre_advance'], $calc['final_advance'], $calc['shop_advance'], $calc['shop_bill'],
                $calc['total_advance'], $calc['deduction'], $calc['salary_to_pay'],
                $paid_amount, $advance_due, $remarks, $status, $saved_by,
            ]);

            $emp_name = $pdo->prepare("SELECT name FROM users WHERE id=?");
            $emp_name->execute([$user_id]);
            $emp_name = $emp_name->fetchColumn();
            log_action($pdo, $saved_by, $branch_id, 'PAYROLL_SAVED', "Payroll for '$emp_name' ($month/$year) saved. Status: $status.");

            echo json_encode(['status' => 'success', 'message' => 'Payroll ledger saved.', 'data' => $calc]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Payroll save failed: ' . $e->getMessage()]);
        }
        break;

    case 'get_payroll_report':
        // Returns full detail for one employee one month — used for salary slip PDF generation
        $user_id   = $request['user_id']   ?? null;
        $branch_id = $request['branch_id'] ?? null;
        $month     = (int)($request['month'] ?? date('m'));
        $year      = (int)($request['year']  ?? date('Y'));
        if (!$user_id || !$branch_id) { echo json_encode(['status' => 'error', 'message' => 'user_id and branch_id required.']); exit; }
        try {
            $user_stmt = $pdo->prepare(
                "SELECT u.name, u.department, u.role, u.mobile_number, b.branch_name,
                        c.monthly_fixed_salary, c.standard_shift_hours, c.week_off_day
                 FROM users u
                 LEFT JOIN employee_contracts c ON u.id = c.user_id
                 LEFT JOIN branches b ON u.branch_id = b.id
                 WHERE u.id = ?"
            );
            $user_stmt->execute([$user_id]);
            $user_info = $user_stmt->fetch();

            $calc   = calculate_employee_payroll($pdo, $user_id, $branch_id, $month, $year);

            // Full advance breakdown
            $adv_stmt = $pdo->prepare(
                "SELECT al.type, al.amount, al.remarks, al.created_at, u.name as logged_by_name
                 FROM advance_ledger al LEFT JOIN users u ON al.logged_by = u.id
                 WHERE al.user_id=? AND al.month=? AND al.year=?
                 ORDER BY al.created_at ASC"
            );
            $adv_stmt->execute([$user_id, $month, $year]);
            $advance_detail = $adv_stmt->fetchAll();

            // Attendance detail per day
            $att_stmt = $pdo->prepare(
                "SELECT DATE(punch_time) as date, MIN(punch_time) as first_in, MAX(punch_time) as last_out, COUNT(*) as punches
                 FROM attendance_punches
                 WHERE user_id=? AND MONTH(punch_time)=? AND YEAR(punch_time)=?
                 GROUP BY DATE(punch_time) ORDER BY date ASC"
            );
            $att_stmt->execute([$user_id, $month, $year]);
            $attendance_detail = $att_stmt->fetchAll();

            echo json_encode([
                'status'             => 'success',
                'employee'           => $user_info,
                'payroll'            => $calc,
                'advance_detail'     => $advance_detail,
                'attendance_detail'  => $attendance_detail,
                'month'              => $month,
                'year'               => $year,
                'days_in_month'      => cal_days_in_month(CAL_GREGORIAN, $month, $year),
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Report generation failed.']);
        }
        break;

    // ----------------------------------------
    // LEAVE RULES MANAGEMENT
    // ----------------------------------------
    case 'get_leave_rules':
        $branch_id = $request['branch_id'] ?? null;
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT * FROM branch_leave_rules WHERE branch_id=? ORDER BY max_leaves_cap ASC, min_working_days ASC");
            $stmt->execute([$branch_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Fetch failed.']);
        }
        break;

    case 'save_leave_rules':
        // Admin replaces ALL leave rules for a branch + cap combo
        $branch_id = $request['branch_id']     ?? null;
        $cap       = (int)($request['cap']     ?? 4);   // 2 or 4
        $rules     = $request['rules']         ?? [];   // array of {min_working_days, earned_paid_leaves}
        $admin_id  = $request['admin_id']      ?? null;
        if (!$branch_id || empty($rules)) { echo json_encode(['status' => 'error', 'message' => 'branch_id and rules array required.']); exit; }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM branch_leave_rules WHERE branch_id=? AND max_leaves_cap=?")->execute([$branch_id, $cap]);
            foreach ($rules as $i => $r) {
                $min = (float)($r['min_working_days'] ?? 0);
                $earned = (int)($r['earned_paid_leaves'] ?? 0);
                $max_days = isset($r['max_days_exclusive']) ? (float)$r['max_days_exclusive'] : null;
                $pdo->prepare(
                    "INSERT INTO branch_leave_rules (branch_id, min_working_days, earned_paid_leaves, max_leaves_cap, max_days_exclusive) VALUES (?,?,?,?,?)"
                )->execute([$branch_id, $min, $earned, $cap, $max_days]);
            }
            log_action($pdo, $admin_id, $branch_id, 'LEAVE_RULES_UPDATED', "Leave rules (cap=$cap) updated for branch $branch_id.");
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Leave rules saved.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Save failed.']);
        }
        break;

    // ----------------------------------------
    // STAFF SELF-SERVICE
    // ----------------------------------------
    case 'get_my_profile':
        $user_id = $request['user_id'] ?? null;
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'User ID required.']); exit; }
        try {
            $stmt = $pdo->prepare(
                "SELECT u.id, u.name, u.role, u.department, u.mobile_number, u.status, u.created_at,
                        b.branch_name, b.address as branch_address,
                        c.monthly_fixed_salary, c.monthly_paid_leaves, c.standard_shift_hours,
                        c.week_off_day, c.max_advance_percentage,
                        (u.face_descriptor IS NOT NULL) as face_registered
                 FROM users u
                 LEFT JOIN branches b ON u.branch_id = b.id
                 LEFT JOIN employee_contracts c ON u.id = c.user_id
                 WHERE u.id = ?"
            );
            $stmt->execute([$user_id]);
            $profile = $stmt->fetch();
            echo json_encode(['status' => 'success', 'data' => $profile]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Profile fetch failed.']);
        }
        break;

    case 'get_my_attendance':
        $user_id = $request['user_id'] ?? null;
        $month   = (int)($request['month'] ?? date('m'));
        $year    = (int)($request['year']  ?? date('Y'));
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'User ID required.']); exit; }
        try {
            $stmt = $pdo->prepare(
                "SELECT DATE(punch_time) as date, MIN(punch_time) as first_in, MAX(punch_time) as last_out,
                        COUNT(*) as punch_count
                 FROM attendance_punches WHERE user_id=? AND MONTH(punch_time)=? AND YEAR(punch_time)=?
                 GROUP BY DATE(punch_time) ORDER BY date ASC"
            );
            $stmt->execute([$user_id, $month, $year]);
            $records = $stmt->fetchAll();

            // Fetch contract for status calc
            $con = $pdo->prepare("SELECT standard_shift_hours FROM employee_contracts WHERE user_id=?");
            $con->execute([$user_id]);
            $target_hrs = (float)(($con->fetch())['standard_shift_hours'] ?? 9);

            foreach ($records as &$r) {
                $hours = (strtotime($r['last_out']) - strtotime($r['first_in'])) / 3600;
                $r['hours_worked'] = round($hours, 2);
                if ($r['punch_count'] < 2) {
                    $r['status'] = 'M';
                } elseif ($hours >= ($target_hrs - 0.5)) {
                    $r['status'] = 'F';
                } elseif ($hours >= ($target_hrs / 2)) {
                    $r['status'] = 'H';
                } else {
                    $r['status'] = 'A';
                }
            }
            echo json_encode(['status' => 'success', 'data' => $records]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Attendance fetch failed.']);
        }
        break;

    case 'get_my_payroll':
        $user_id = $request['user_id'] ?? null;
        $month   = (int)($request['month'] ?? date('m'));
        $year    = (int)($request['year']  ?? date('Y'));
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'User ID required.']); exit; }
        try {
            // Get branch_id for the user
            $b = $pdo->prepare("SELECT branch_id FROM users WHERE id=?");
            $b->execute([$user_id]);
            $branch_id = $b->fetchColumn();
            $calc = calculate_employee_payroll($pdo, $user_id, $branch_id, $month, $year);
            echo json_encode(['status' => 'success', 'data' => $calc]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Payroll fetch failed.']);
        }
        break;

    // ----------------------------------------
    // DEFAULT
    // ----------------------------------------
    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Unknown action: '$action'"]);
        break;
}
?>
