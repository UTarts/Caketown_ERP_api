<?php
// api.php - Central API Gateway
// Caketown ERP - Phase 1 Extended Build
// DO NOT EDIT WITHOUT READING THE FULL COMMENT BLOCK

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'config.php';
date_default_timezone_set('Asia/Kolkata');

$raw_input = file_get_contents("php://input");
$request = json_decode($raw_input, true);
$action = $request['action'] ?? '';

// ============================================================
// HELPER: Log every significant action to system_logs
// ============================================================
function log_action($pdo, $user_id, $branch_id, $action_type, $description) {
    $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, branch_id, action_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $branch_id, $action_type, $description]);
}

// ============================================================
// HELPER: Check if a user has a specific permission
// Usage: check_permission($permissions_json, 'payroll_view', 'read')
// Returns: true or false
// ============================================================
function check_permission($permissions_json, $feature_key, $access_type = 'read') {
    $perms = json_decode($permissions_json ?? '{}', true);
    return isset($perms[$feature_key][$access_type]) && $perms[$feature_key][$access_type] === true;
}

// ============================================================
// HELPER: Calculate paid holidays earned based on branch rules and employee cap
// $days_present = total full days (F) + 0.5 * half days (H)
// $max_cap = 4 or 2 (from employee_contracts.paid_leaves_cap)
// $branch_id = to fetch the correct tier rules
// Returns: integer (earned paid holidays)
// ============================================================
function calculate_paid_holidays($pdo, $branch_id, $days_present, $max_cap) {
    // Fetch all tiers for this branch ordered by threshold descending
    $stmt = $pdo->prepare("SELECT min_working_days, earned_paid_leaves FROM branch_leave_rules WHERE branch_id = ? ORDER BY min_working_days DESC");
    $stmt->execute([$branch_id]);
    $tiers = $stmt->fetchAll();

    $earned = 0;
    foreach ($tiers as $tier) {
        if ($days_present >= $tier['min_working_days']) {
            $earned = (int)$tier['earned_paid_leaves'];
            break; // Highest matching tier wins
        }
    }
    // Enforce the employee's personal cap (4 or 2)
    return min($earned, (int)$max_cap);
}

// ============================================================
// HELPER: Calculate NET salary from payroll_ledger data
// Formula: (salary / total_days) * (total_duty + paid_holidays) - total_advance - deduction
// ============================================================
function calculate_net_salary($monthly_salary, $total_days_in_month, $total_duty, $paid_holidays, $total_advance, $deduction) {
    if ($total_days_in_month <= 0) return 0;
    $daily_rate = $monthly_salary / $total_days_in_month;
    $paid_duty = $total_duty + $paid_holidays;
    $gross = $daily_rate * $paid_duty;
    $net = $gross - $total_advance - $deduction;
    return round(max($net, 0), 2); // Never negative
}

// ============================================================
// ROUTE SWITCH
// ============================================================
switch ($action) {

    // ----------------------------------------------------------
    // HEALTH CHECK
    // ----------------------------------------------------------
    case 'ping':
        echo json_encode(['status' => 'success', 'message' => 'API Live. Phase 1 Active.']);
        break;

    // ----------------------------------------------------------
    // AUTH: LOGIN
    // Works for admin, manager, and staff
    // Returns: user object with role, branch_id, feature_permissions
    // ----------------------------------------------------------
    case 'login':
        $mobile_number = $request['mobile_number'] ?? '';
        $password = $request['password'] ?? '';
        if (empty($mobile_number) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Credentials required.']); exit;
        }
        try {
            $stmt = $pdo->prepare("SELECT id, branch_id, role, name, password, status, feature_permissions FROM users WHERE mobile_number = ? LIMIT 1");
            $stmt->execute([$mobile_number]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') {
                    echo json_encode(['status' => 'error', 'message' => 'Account is inactive. Contact admin.']); exit;
                }
                log_action($pdo, $user['id'], $user['branch_id'], 'AUTH_LOGIN', "{$user['name']} logged in.");
                unset($user['password']);
                $user['feature_permissions'] = json_decode($user['feature_permissions'] ?? '{}', true);
                echo json_encode(['status' => 'success', 'user' => $user]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid mobile number or password.']);
            }
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'System error during login.']);
        }
        break;

    // ----------------------------------------------------------
    // ADMIN: Get Dashboard Stats + Branch Grid + System History
    // ----------------------------------------------------------
    case 'get_admin_dashboard':
        try {
            $total_branches = $pdo->query("SELECT COUNT(*) FROM branches WHERE status='active'")->fetchColumn();
            $total_employees = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin' AND status='active'")->fetchColumn();

            // Total salary expenditure this month
            $month = $request['month'] ?? date('m');
            $year = $request['year'] ?? date('Y');
            $salary_stmt = $pdo->prepare("SELECT COALESCE(SUM(salary_to_pay), 0) FROM payroll_ledgers WHERE payroll_month=? AND payroll_year=?");
            $salary_stmt->execute([$month, $year]);
            $monthly_salary_total = $salary_stmt->fetchColumn();

            // System history feed (last 30 actions)
            $recent_logs = $pdo->query("
                SELECT l.id, l.action_type, l.description, l.created_at,
                       u.name as user_name, u.role as user_role, b.branch_name
                FROM system_logs l
                LEFT JOIN users u ON l.user_id = u.id
                LEFT JOIN branches b ON l.branch_id = b.id
                ORDER BY l.id DESC LIMIT 30
            ")->fetchAll();

            // Branch cards with live present count
            $branch_grid = $pdo->query("
                SELECT b.id, b.branch_name, b.address, b.status,
                       COUNT(DISTINCT u.id) as total_staff,
                       COUNT(DISTINCT CASE WHEN DATE(p.punch_time) = CURDATE() THEN p.user_id END) as present_today
                FROM branches b
                LEFT JOIN users u ON b.id = u.branch_id AND u.status='active' AND u.role != 'admin'
                LEFT JOIN attendance_punches p ON u.id = p.user_id
                GROUP BY b.id ORDER BY b.created_at DESC
            ")->fetchAll();

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'total_branches'       => (int)$total_branches,
                    'total_employees'      => (int)$total_employees,
                    'monthly_salary_total' => (float)$monthly_salary_total,
                    'month'                => (int)$month,
                    'year'                 => (int)$year,
                    'recent_logs'          => $recent_logs,
                    'branch_grid'          => $branch_grid
                ]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Dashboard load failed.']);
        }
        break;

    // ----------------------------------------------------------
    // BRANCHES: Create
    // ----------------------------------------------------------
    case 'create_branch':
        $name = $request['branch_name'] ?? '';
        $address = $request['address'] ?? '';
        $admin_id = $request['admin_id'] ?? null;
        if (empty($name)) { echo json_encode(['status' => 'error', 'message' => 'Branch name required.']); exit; }
        try {
            $pdo->prepare("INSERT INTO branches (branch_name, address) VALUES (?, ?)")->execute([$name, $address]);
            $new_branch_id = $pdo->lastInsertId();
            log_action($pdo, $admin_id, $new_branch_id, 'BRANCH_CREATED', "Branch '{$name}' created.");
            echo json_encode(['status' => 'success', 'message' => 'Branch created.', 'branch_id' => $new_branch_id]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Branch creation failed.']);
        }
        break;

    // ----------------------------------------------------------
    // BRANCHES: List All
    // ----------------------------------------------------------
    case 'get_branches':
        try {
            $branches = $pdo->query("SELECT * FROM branches ORDER BY created_at DESC")->fetchAll();
            echo json_encode(['status' => 'success', 'data' => $branches]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Fetch failed.']);
        }
        break;

    // ----------------------------------------------------------
    // BRANCHES: Update
    // ----------------------------------------------------------
    case 'update_branch':
        $branch_id = $request['branch_id'] ?? null;
        $name = $request['branch_name'] ?? '';
        $address = $request['address'] ?? '';
        $status = $request['status'] ?? 'active';
        $admin_id = $request['admin_id'] ?? null;
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $pdo->prepare("UPDATE branches SET branch_name=?, address=?, status=? WHERE id=?")->execute([$name, $address, $status, $branch_id]);
            log_action($pdo, $admin_id, $branch_id, 'BRANCH_UPDATED', "Branch ID {$branch_id} updated.");
            echo json_encode(['status' => 'success', 'message' => 'Branch updated.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Update failed.']);
        }
        break;

    // ----------------------------------------------------------
    // EMPLOYEES: Create (Admin Only)
    // Creates user + employee_contract in a single transaction
    // ----------------------------------------------------------
    case 'create_user':
        $role = $request['role'] ?? 'staff';
        $branch_id = ($role === 'admin') ? null : ($request['branch_id'] ?? null);
        $name = $request['name'] ?? '';
        $mobile_number = $request['mobile_number'] ?? '';
        $password = $request['password'] ?? '';
        $department = $request['department'] ?? '';
        $salary = $request['salary'] ?? 0;
        $paid_leaves = $request['paid_leaves'] ?? 0;
        $paid_leaves_cap = $request['paid_leaves_cap'] ?? 4;
        $shift_hours = $request['shift_hours'] ?? 9;
        $week_off_day = $request['week_off_day'] ?? 'Sunday';
        $week_off_count = $request['week_off_count'] ?? 4;
        $permissions = json_encode($request['permissions'] ?? (object)[]);
        $admin_id = $request['admin_id'] ?? null;

        if (empty($name) || empty($mobile_number) || empty($password)) {
            echo json_encode(['status' => 'error', 'message' => 'Name, mobile, and password are required.']); exit;
        }
        try {
            $pdo->beginTransaction();
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (branch_id, role, department, name, mobile_number, password, feature_permissions) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $role, $department, $name, $mobile_number, $hashed_password, $permissions]);
            $new_user_id = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO employee_contracts (user_id, monthly_fixed_salary, monthly_paid_leaves, paid_leaves_cap, standard_shift_hours, week_off_day, week_off_count) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$new_user_id, $salary, $paid_leaves, $paid_leaves_cap, $shift_hours, $week_off_day, $week_off_count]);

            log_action($pdo, $admin_id, $branch_id, 'USER_CREATED', "New {$role} '{$name}' created.");
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Employee created successfully.', 'user_id' => $new_user_id]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => ($e->getCode() == 23000) ? 'Mobile number already registered.' : 'Creation failed.']);
        }
        break;

    // ----------------------------------------------------------
    // EMPLOYEES: Update (Admin Only)
    // Updates user + employee_contract + permissions
    // ----------------------------------------------------------
    case 'update_user':
        $user_id = $request['user_id'] ?? null;
        $admin_id = $request['admin_id'] ?? null;
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'User ID required.']); exit; }
        try {
            $pdo->beginTransaction();

            // Update users table
            $fields = [];
            $values = [];
            $allowed_user_fields = ['name','mobile_number','department','role','branch_id','status'];
            foreach ($allowed_user_fields as $f) {
                if (isset($request[$f])) { $fields[] = "$f=?"; $values[] = $request[$f]; }
            }
            // Update password only if provided
            if (!empty($request['password'])) {
                $fields[] = "password=?";
                $values[] = password_hash($request['password'], PASSWORD_DEFAULT);
            }
            // Update permissions if provided
            if (isset($request['permissions'])) {
                $fields[] = "feature_permissions=?";
                $values[] = json_encode($request['permissions']);
            }
            if (!empty($fields)) {
                $values[] = $user_id;
                $pdo->prepare("UPDATE users SET " . implode(',', $fields) . " WHERE id=?")->execute($values);
            }

            // Update employee_contracts table
            $c_fields = [];
            $c_values = [];
            $allowed_contract_fields = ['monthly_fixed_salary','monthly_paid_leaves','paid_leaves_cap','standard_shift_hours','week_off_day','week_off_count'];
            foreach ($allowed_contract_fields as $f) {
                if (isset($request[$f])) { $c_fields[] = "$f=?"; $c_values[] = $request[$f]; }
            }
            if (!empty($c_fields)) {
                $c_values[] = $user_id;
                $check = $pdo->prepare("SELECT user_id FROM employee_contracts WHERE user_id=?");
                $check->execute([$user_id]);
                if ($check->fetch()) {
                    $pdo->prepare("UPDATE employee_contracts SET " . implode(',', $c_fields) . " WHERE user_id=?")->execute($c_values);
                } else {
                    $pdo->prepare("INSERT INTO employee_contracts (user_id) VALUES (?)")->execute([$user_id]);
                    $pdo->prepare("UPDATE employee_contracts SET " . implode(',', $c_fields) . " WHERE user_id=?")->execute($c_values);
                }
            }

            log_action($pdo, $admin_id, $request['branch_id'] ?? null, 'USER_UPDATED', "Employee ID {$user_id} updated.");
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Employee updated successfully.']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()]);
        }
        break;

    // ----------------------------------------------------------
    // EMPLOYEES: Delete (Admin Only)
    // Soft delete — sets status to inactive
    // ----------------------------------------------------------
    case 'delete_user':
        $user_id = $request['user_id'] ?? null;
        $admin_id = $request['admin_id'] ?? null;
        if (!$user_id) { echo json_encode(['status' => 'error', 'message' => 'User ID required.']); exit; }
        try {
            $pdo->prepare("UPDATE users SET status='inactive' WHERE id=?")->execute([$user_id]);
            log_action($pdo, $admin_id, null, 'USER_DEACTIVATED', "Employee ID {$user_id} deactivated.");
            echo json_encode(['status' => 'success', 'message' => 'Employee deactivated.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Delete failed.']);
        }
        break;

    // ----------------------------------------------------------
    // BRANCH MASTER: Full branch data (staff + punches + branch info)
    // Used by admin when clicking into a branch
    // ----------------------------------------------------------
    case 'get_branch_master':
        $branch_id = $request['branch_id'] ?? null;
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $b_stmt = $pdo->prepare("SELECT * FROM branches WHERE id=?");
            $b_stmt->execute([$branch_id]);
            $branch = $b_stmt->fetch();

            $s_stmt = $pdo->prepare("
                SELECT u.id, u.name, u.role, u.department, u.mobile_number, u.status, u.feature_permissions,
                       c.monthly_fixed_salary, c.monthly_paid_leaves, c.paid_leaves_cap,
                       c.standard_shift_hours, c.week_off_day, c.week_off_count,
                       (u.face_descriptor IS NOT NULL) as is_registered
                FROM users u
                LEFT JOIN employee_contracts c ON u.id = c.user_id
                WHERE u.branch_id=? AND u.status='active'
                ORDER BY FIELD(u.role,'manager','staff'), u.name ASC
            ");
            $s_stmt->execute([$branch_id]);
            $staff = $s_stmt->fetchAll();
            foreach ($staff as &$s) {
                $s['feature_permissions'] = json_decode($s['feature_permissions'] ?? '{}', true);
            }

            $p_stmt = $pdo->prepare("
                SELECT u.id, u.name, p.punch_time
                FROM attendance_punches p
                JOIN users u ON p.user_id = u.id
                WHERE u.branch_id=? AND DATE(p.punch_time)=CURDATE()
                ORDER BY p.punch_time DESC LIMIT 20
            ");
            $p_stmt->execute([$branch_id]);
            $recent_punches = $p_stmt->fetchAll();

            echo json_encode([
                'status' => 'success',
                'data' => ['branch' => $branch, 'staff' => $staff, 'recent_punches' => $recent_punches]
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to load branch data.']);
        }
        break;

    // ----------------------------------------------------------
    // ATTENDANCE: Live dashboard for today
    // Returns each employee with all their punches for today
    // Frontend calculates current working time and break time from this
    // ----------------------------------------------------------
    case 'get_live_attendance':
        $branch_id = $request['branch_id'] ?? null;
        $date = $request['date'] ?? date('Y-m-d');
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $staff_stmt = $pdo->prepare("
                SELECT u.id, u.name, u.role, u.department, c.standard_shift_hours
                FROM users u
                LEFT JOIN employee_contracts c ON u.id = c.user_id
                WHERE u.branch_id=? AND u.status='active'
                ORDER BY u.name ASC
            ");
            $staff_stmt->execute([$branch_id]);
            $staff_list = $staff_stmt->fetchAll();

            $punches_stmt = $pdo->prepare("
                SELECT p.user_id, p.punch_time
                FROM attendance_punches p
                JOIN users u ON p.user_id = u.id
                WHERE u.branch_id=? AND DATE(p.punch_time)=?
                ORDER BY p.punch_time ASC
            ");
            $punches_stmt->execute([$branch_id, $date]);
            $all_punches = $punches_stmt->fetchAll();

            // Group punches by user
            $punches_by_user = [];
            foreach ($all_punches as $p) {
                $punches_by_user[$p['user_id']][] = $p['punch_time'];
            }

            $dashboard_data = [];
            foreach ($staff_list as $s) {
                $uid = $s['id'];
                $punches = $punches_by_user[$uid] ?? [];
                $s['punches'] = $punches;
                $s['punch_count'] = count($punches);

                // Calculate working time and break time from punch sequence
                // Odd punches = punch-in, Even punches = punch-out
                $total_working_seconds = 0;
                $total_break_seconds = 0;
                for ($i = 0; $i < count($punches); $i++) {
                    if ($i % 2 === 0 && isset($punches[$i + 1])) {
                        // Working segment: punch-in to punch-out
                        $total_working_seconds += strtotime($punches[$i + 1]) - strtotime($punches[$i]);
                    } elseif ($i % 2 === 1 && isset($punches[$i + 1])) {
                        // Break segment: punch-out to next punch-in
                        $total_break_seconds += strtotime($punches[$i + 1]) - strtotime($punches[$i]);
                    }
                }
                // If currently punched in (odd total punches), add ongoing working time
                if (count($punches) % 2 === 1) {
                    $total_working_seconds += time() - strtotime(end($punches));
                }
                $s['total_working_minutes'] = round($total_working_seconds / 60);
                $s['total_break_minutes'] = round($total_break_seconds / 60);
                $s['is_currently_in'] = count($punches) % 2 === 1;
                $s['first_punch_in'] = !empty($punches) ? $punches[0] : null;

                $dashboard_data[] = $s;
            }

            echo json_encode(['status' => 'success', 'data' => $dashboard_data, 'date' => $date]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to load live attendance.']);
        }
        break;

    // ----------------------------------------------------------
    // ATTENDANCE: Monthly Grid (the full spreadsheet view)
    // Returns day-by-day F/H/A/M status for every employee
    // ----------------------------------------------------------
    case 'get_monthly_attendance':
        $branch_id = $request['branch_id'] ?? null;
        $month = (int)($request['month'] ?? date('m'));
        $year = (int)($request['year'] ?? date('Y'));
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }

        try {
            $staff_stmt = $pdo->prepare("
                SELECT u.id, u.name, u.department, c.standard_shift_hours, c.week_off_day, c.monthly_fixed_salary,
                       c.monthly_paid_leaves, c.paid_leaves_cap, c.week_off_count
                FROM users u
                LEFT JOIN employee_contracts c ON u.id = c.user_id
                WHERE u.branch_id=? AND u.status='active'
                ORDER BY u.name ASC
            ");
            $staff_stmt->execute([$branch_id]);
            $staff = $staff_stmt->fetchAll();

            $punches_stmt = $pdo->prepare("
                SELECT user_id, DATE(punch_time) as p_date, punch_time
                FROM attendance_punches
                WHERE MONTH(punch_time)=? AND YEAR(punch_time)=?
                ORDER BY punch_time ASC
            ");
            $punches_stmt->execute([$month, $year]);
            $all_punches = $punches_stmt->fetchAll();

            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

            // Map day number to day-of-week letter for the header
            $day_letters = ['S','M','T','W','T','F','S']; // 0=Sun
            $day_header = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $dow = (int)date('w', mktime(0,0,0,$month,$d,$year));
                $day_header[$d] = $day_letters[$dow];
            }

            $attendance_grid = [];
            foreach ($staff as $s) {
                $week_off_letter = strtolower(substr($s['week_off_day'] ?? 'sunday', 0, 3));
                $week_off_dow = array_search($week_off_letter, ['sun','mon','tue','wed','thu','fri','sat']);

                $grid_entry = [
                    'user_id'       => $s['id'],
                    'name'          => $s['name'],
                    'department'    => $s['department'],
                    'salary'        => (float)$s['monthly_fixed_salary'],
                    'target_hours'  => (float)($s['standard_shift_hours'] ?? 9),
                    'week_off_day'  => $s['week_off_day'],
                    'paid_leaves_cap' => (int)($s['paid_leaves_cap'] ?? 4),
                    'days'          => [],
                    'totals'        => ['P' => 0, 'H' => 0, 'A' => 0, 'N' => 0, 'WO' => 0, 'M' => 0]
                ];

                // Pre-fill all days: W/O for week-off day, else Absent
                for ($d = 1; $d <= $daysInMonth; $d++) {
                    $dow = (int)date('w', mktime(0,0,0,$month,$d,$year));
                    $is_week_off = ($week_off_dow !== false && $dow === $week_off_dow);
                    if ($is_week_off) {
                        $grid_entry['days'][$d] = ['status' => 'WO', 'hours' => 0];
                        $grid_entry['totals']['WO']++;
                    } else {
                        $grid_entry['days'][$d] = ['status' => 'A', 'hours' => 0];
                        $grid_entry['totals']['A']++;
                    }
                }
                $attendance_grid[$s['id']] = $grid_entry;
            }

            // Group punches by user and date
            $user_punches = [];
            foreach ($all_punches as $p) {
                $uid = $p['user_id'];
                $dt = $p['p_date'];
                $user_punches[$uid][$dt][] = $p['punch_time'];
            }

            // F/H/A/M Engine
            foreach ($user_punches as $uid => $dates) {
                if (!isset($attendance_grid[$uid])) continue;
                $target = (float)$attendance_grid[$uid]['target_hours'];

                foreach ($dates as $dt => $punches) {
                    $d = (int)date('j', strtotime($dt));
                    $prev_status = $attendance_grid[$uid]['days'][$d]['status'];

                    // Reverse the old A/WO counter before applying new status
                    if ($prev_status === 'A') $attendance_grid[$uid]['totals']['A']--;
                    // Do not remove WO from totals — punching in on a week-off day is overtime; keep it WO

                    if (count($punches) >= 2) {
                        $first = strtotime(min($punches));
                        $last = strtotime(max($punches));
                        $hours = ($last - $first) / 3600;

                        // Night shift detection: last punch after midnight OR first punch before 6am
                        $is_night = (date('H', strtotime(max($punches))) < 6) || (date('H', strtotime(min($punches))) >= 21);

                        if ($hours >= ($target - 0.5)) {
                            $status = $is_night ? 'N' : 'P';
                            $attendance_grid[$uid]['totals'][$status]++;
                        } elseif ($hours >= ($target / 2)) {
                            $status = 'H';
                            $attendance_grid[$uid]['totals']['H']++;
                        } else {
                            $status = 'A';
                            $attendance_grid[$uid]['totals']['A']++;
                        }
                        $attendance_grid[$uid]['days'][$d] = ['status' => $status, 'hours' => round($hours, 1)];
                    } elseif (count($punches) === 1) {
                        $attendance_grid[$uid]['days'][$d] = ['status' => 'M', 'hours' => 0];
                        $attendance_grid[$uid]['totals']['M']++;
                    }
                }
            }

            // Calculate summary stats for each employee (for the salary calculation side)
            foreach ($attendance_grid as $uid => &$entry) {
                $total_duty = $entry['totals']['P'] + $entry['totals']['N'] + ($entry['totals']['H'] * 0.5);
                $paid_holidays = calculate_paid_holidays($pdo, $branch_id, $total_duty, $entry['paid_leaves_cap']);
                $entry['summary'] = [
                    'total_duty'     => $total_duty,
                    'paid_holidays'  => $paid_holidays,
                    'paid_duty'      => $total_duty + $paid_holidays,
                    'week_offs'      => $entry['totals']['WO'],
                    'absent'         => $entry['totals']['A'],
                    'missed_punch'   => $entry['totals']['M'],
                    'days_in_month'  => $daysInMonth
                ];
            }

            echo json_encode([
                'status'       => 'success',
                'data'         => array_values($attendance_grid),
                'day_header'   => $day_header,
                'days_in_month'=> $daysInMonth,
                'month'        => $month,
                'year'         => $year
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to generate attendance grid.']);
        }
        break;

    // ----------------------------------------------------------
    // PAYROLL: Get full payroll data for a branch + month
    // The MASTER page. Returns everything needed to render the spreadsheet.
    // ----------------------------------------------------------
    case 'get_payroll_data':
        $branch_id = $request['branch_id'] ?? null;
        $month = (int)($request['month'] ?? date('m'));
        $year = (int)($request['year'] ?? date('Y'));
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }

        try {
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

            // Get all active employees with contracts
            $staff_stmt = $pdo->prepare("
                SELECT u.id, u.name, u.department, u.role,
                       c.monthly_fixed_salary, c.monthly_paid_leaves, c.paid_leaves_cap,
                       c.standard_shift_hours, c.week_off_day, c.week_off_count
                FROM users u
                LEFT JOIN employee_contracts c ON u.id = c.user_id
                WHERE u.branch_id=? AND u.status='active'
                ORDER BY FIELD(u.role,'manager','staff'), u.name ASC
            ");
            $staff_stmt->execute([$branch_id]);
            $staff = $staff_stmt->fetchAll();

            // Get working days per employee for this month
            $punches_stmt = $pdo->prepare("
                SELECT user_id,
                       SUM(CASE WHEN punch_status='P' THEN 1
                                WHEN punch_status='N' THEN 1
                                WHEN punch_status='H' THEN 0.5
                                ELSE 0 END) as total_duty
                FROM daily_attendance_ledger
                WHERE MONTH(date)=? AND YEAR(date)=?
                GROUP BY user_id
            ");
            $punches_stmt->execute([$month, $year]);
            $duty_map = [];
            while ($row = $punches_stmt->fetch()) {
                $duty_map[$row['user_id']] = (float)$row['total_duty'];
            }

            // Fallback: if daily_attendance_ledger is empty, calculate from raw punches
            if (empty($duty_map)) {
                $raw_stmt = $pdo->prepare("
                    SELECT user_id, DATE(punch_time) as p_date,
                           MIN(punch_time) as first_in, MAX(punch_time) as last_out,
                           COUNT(*) as punch_count
                    FROM attendance_punches
                    WHERE MONTH(punch_time)=? AND YEAR(punch_time)=?
                    GROUP BY user_id, DATE(punch_time)
                ");
                $raw_stmt->execute([$month, $year]);
                $raw_days = $raw_stmt->fetchAll();
                foreach ($raw_days as $rd) {
                    $uid = $rd['user_id'];
                    if ($rd['punch_count'] < 2) continue;
                    $hours = (strtotime($rd['last_out']) - strtotime($rd['first_in'])) / 3600;
                    // Use 9 hours as default target if no contract
                    $target = 9;
                    if (!isset($duty_map[$uid])) $duty_map[$uid] = 0;
                    if ($hours >= ($target - 0.5)) $duty_map[$uid] += 1;
                    elseif ($hours >= ($target / 2)) $duty_map[$uid] += 0.5;
                }
            }

            // Get advances and deductions for this month
            $adv_stmt = $pdo->prepare("
                SELECT user_id, type, COALESCE(SUM(amount), 0) as total
                FROM advance_requests
                WHERE month=? AND year=? AND branch_id=?
                GROUP BY user_id, type
            ");
            $adv_stmt->execute([$month, $year, $branch_id]);
            $advance_map = [];
            while ($row = $adv_stmt->fetch()) {
                $advance_map[$row['user_id']][$row['type']] = (float)$row['total'];
            }

            // Get existing payroll ledger entries (if payroll was previously saved)
            $ledger_stmt = $pdo->prepare("SELECT * FROM payroll_ledgers WHERE branch_id=? AND payroll_month=? AND payroll_year=?");
            $ledger_stmt->execute([$branch_id, $month, $year]);
            $ledger_map = [];
            while ($row = $ledger_stmt->fetch()) {
                $ledger_map[$row['user_id']] = $row;
            }

            // Build payroll rows
            $payroll_rows = [];
            foreach ($staff as $s) {
                $uid = $s['id'];
                $salary = (float)$s['monthly_fixed_salary'];
                $total_duty = $duty_map[$uid] ?? 0;
                $paid_holidays = calculate_paid_holidays($pdo, $branch_id, $total_duty, $s['paid_leaves_cap'] ?? 4);

                $pre_advance   = $advance_map[$uid]['pre_advance'] ?? 0;
                $final_advance = $advance_map[$uid]['final_advance'] ?? 0;
                $shop_advance  = $advance_map[$uid]['shop_advance'] ?? 0;
                $shop_bill     = $advance_map[$uid]['shop_bill'] ?? 0;
                $penalty       = $advance_map[$uid]['penalty'] ?? 0;
                $total_advance = $pre_advance + $final_advance + $shop_advance + $shop_bill + $penalty;
                $deduction     = 0; // Extra deductions can be added here

                $net_salary = calculate_net_salary($salary, $daysInMonth, $total_duty, $paid_holidays, $total_advance, $deduction);

                // 30% cap for advance
                $max_advance_allowed = round($salary * 0.30, 2);

                // If ledger exists, use saved status and paid amount
                $ledger = $ledger_map[$uid] ?? null;
                $paid_amount = $ledger ? (float)$ledger['salary_to_pay'] : 0;
                $status = $ledger ? $ledger['status'] : 'draft';
                $advance_due = $total_advance - ($ledger ? (float)$ledger['pre_advance'] : 0);

                $payroll_rows[] = [
                    'user_id'            => $uid,
                    'name'               => $s['name'],
                    'department'         => $s['department'],
                    'role'               => $s['role'],
                    'base_salary'        => $salary,
                    'days_in_month'      => $daysInMonth,
                    'total_duty'         => $total_duty,
                    'paid_leaves'        => $paid_holidays,
                    'paid_duty'          => $total_duty + $paid_holidays,
                    'pre_advance'        => $pre_advance,
                    'final_advance'      => $final_advance,
                    'shop_advance'       => $shop_advance,
                    'shop_bill'          => $shop_bill,
                    'penalty'            => $penalty,
                    'total_advance'      => $total_advance,
                    'deduction'          => $deduction,
                    'salary_to_pay'      => $net_salary,
                    'max_advance_allowed'=> $max_advance_allowed,
                    'status'             => $status,
                    'paid'               => $paid_amount,
                    'advance_due'        => round($advance_due, 2),
                    'week_offs'          => (int)($s['week_off_count'] ?? 4),
                    'remark'             => $ledger['status'] ?? ''
                ];
            }

            echo json_encode([
                'status'        => 'success',
                'data'          => $payroll_rows,
                'days_in_month' => $daysInMonth,
                'month'         => $month,
                'year'          => $year,
                'branch_id'     => $branch_id
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Payroll generation failed: ' . $e->getMessage()]);
        }
        break;

    // ----------------------------------------------------------
    // PAYROLL: Save/Update ledger entry
    // Called when admin finalizes or updates a row in the payroll page
    // ----------------------------------------------------------
    case 'save_payroll_entry':
        $branch_id = $request['branch_id'] ?? null;
        $user_id = $request['user_id'] ?? null;
        $month = $request['month'] ?? date('m');
        $year = $request['year'] ?? date('Y');
        $admin_id = $request['admin_id'] ?? null;
        if (!$branch_id || !$user_id) { echo json_encode(['status' => 'error', 'message' => 'Branch and User ID required.']); exit; }

        try {
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $total_duty = $request['total_duty'] ?? 0;
            $paid_leaves = $request['paid_leaves'] ?? 0;
            $paid_duty = $total_duty + $paid_leaves;
            $base_salary = $request['base_salary'] ?? 0;
            $pre_advance = $request['pre_advance'] ?? 0;
            $final_advance = $request['final_advance'] ?? 0;
            $shop_advance = $request['shop_advance'] ?? 0;
            $shop_bill = $request['shop_bill'] ?? 0;
            $total_advance = $pre_advance + $final_advance + $shop_advance + $shop_bill;
            $deduction = $request['deduction'] ?? 0;
            $salary_to_pay = calculate_net_salary($base_salary, $daysInMonth, $total_duty, $paid_leaves, $total_advance, $deduction);
            $status = $request['status'] ?? 'draft';

            // UPSERT: insert or update if exists
            $pdo->prepare("
                INSERT INTO payroll_ledgers
                    (branch_id, user_id, payroll_month, payroll_year, total_duty, paid_leaves, paid_duty,
                     base_salary, pre_advance, final_advance, shop_advance, shop_bill,
                     total_advance, deduction, salary_to_pay, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    total_duty=VALUES(total_duty), paid_leaves=VALUES(paid_leaves), paid_duty=VALUES(paid_duty),
                    base_salary=VALUES(base_salary), pre_advance=VALUES(pre_advance), final_advance=VALUES(final_advance),
                    shop_advance=VALUES(shop_advance), shop_bill=VALUES(shop_bill),
                    total_advance=VALUES(total_advance), deduction=VALUES(deduction),
                    salary_to_pay=VALUES(salary_to_pay), status=VALUES(status)
            ")->execute([$branch_id, $user_id, $month, $year, $total_duty, $paid_leaves, $paid_duty,
                          $base_salary, $pre_advance, $final_advance, $shop_advance, $shop_bill,
                          $total_advance, $deduction, $salary_to_pay, $status]);

            log_action($pdo, $admin_id, $branch_id, 'PAYROLL_SAVED', "Payroll saved for user {$user_id} ({$month}/{$year}).");
            echo json_encode(['status' => 'success', 'message' => 'Payroll entry saved.', 'salary_to_pay' => $salary_to_pay]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Save failed: ' . $e->getMessage()]);
        }
        break;

    // ----------------------------------------------------------
    // ADVANCES: Log an advance/deduction entry
    // Called by manager or admin from payroll/finance page
    // ----------------------------------------------------------
    case 'log_advance':
        $user_id = $request['user_id'] ?? null;
        $branch_id = $request['branch_id'] ?? null;
        $logged_by = $request['logged_by'] ?? null;
        $month = $request['month'] ?? date('m');
        $year = $request['year'] ?? date('Y');
        $amount = $request['amount'] ?? 0;
        $type = $request['type'] ?? null;
        $remarks = $request['remarks'] ?? '';

        $valid_types = ['pre_advance','final_advance','shop_advance','shop_bill','penalty'];
        if (!$user_id || !$branch_id || !$type || !in_array($type, $valid_types)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid advance data.']); exit;
        }
        try {
            $pdo->prepare("INSERT INTO advance_requests (user_id, branch_id, logged_by, month, year, amount, type, remarks) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$user_id, $branch_id, $logged_by, $month, $year, $amount, $type, $remarks]);
            log_action($pdo, $logged_by, $branch_id, 'ADVANCE_LOGGED', "Advance ₹{$amount} ({$type}) logged for user {$user_id}.");
            echo json_encode(['status' => 'success', 'message' => 'Advance logged successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to log advance.']);
        }
        break;

    // ----------------------------------------------------------
    // ADVANCES: Get all advances for a user in a month
    // ----------------------------------------------------------
    case 'get_advances':
        $user_id = $request['user_id'] ?? null;
        $branch_id = $request['branch_id'] ?? null;
        $month = $request['month'] ?? date('m');
        $year = $request['year'] ?? date('Y');
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }

        try {
            $where = "branch_id=? AND month=? AND year=?";
            $params = [$branch_id, $month, $year];
            if ($user_id) { $where .= " AND user_id=?"; $params[] = $user_id; }

            $stmt = $pdo->prepare("
                SELECT a.*, u.name as user_name
                FROM advance_requests a
                JOIN users u ON a.user_id = u.id
                WHERE {$where}
                ORDER BY a.created_at DESC
            ");
            $stmt->execute($params);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Fetch failed.']);
        }
        break;

    // ----------------------------------------------------------
    // FACE BIOMETRICS: Register face descriptor for an employee
    // Called by manager from face registration page
    // ----------------------------------------------------------
    case 'register_face':
        $user_id = $request['user_id'] ?? null;
        $manager_id = $request['manager_id'] ?? null;
        $branch_id = $request['branch_id'] ?? null;
        $descriptor = $request['descriptor'] ?? null;
        if (!$user_id || !$descriptor) { echo json_encode(['status' => 'error', 'message' => 'User ID and face descriptor required.']); exit; }
        try {
            // Store descriptor as JSON string
            $descriptor_json = is_array($descriptor) ? json_encode($descriptor) : $descriptor;
            $pdo->prepare("UPDATE users SET face_descriptor=? WHERE id=?")->execute([$descriptor_json, $user_id]);
            log_action($pdo, $manager_id, $branch_id, 'FACE_REGISTERED', "Biometric data updated for user ID: {$user_id}.");
            echo json_encode(['status' => 'success', 'message' => 'Face registered successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Face registration failed.']);
        }
        break;

    // ----------------------------------------------------------
    // FACE BIOMETRICS: Get all descriptors for a branch (for terminal matching)
    // ----------------------------------------------------------
    case 'get_branch_descriptors':
        $branch_id = $request['branch_id'] ?? null;
        try {
            $stmt = $pdo->prepare("SELECT id, name, face_descriptor FROM users WHERE branch_id=? AND face_descriptor IS NOT NULL AND status='active'");
            $stmt->execute([$branch_id]);
            $rows = $stmt->fetchAll();
            // Parse descriptor JSON for each user
            foreach ($rows as &$r) {
                $r['face_descriptor'] = json_decode($r['face_descriptor'], true);
            }
            echo json_encode(['status' => 'success', 'data' => $rows]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to load biometrics.']);
        }
        break;

    // ----------------------------------------------------------
    // ATTENDANCE TERMINAL: Log a punch
    // Called after face recognition confirms identity
    // ----------------------------------------------------------
    case 'log_punch':
        $user_id = $request['user_id'] ?? null;
        $branch_id = $request['branch_id'] ?? null;
        if (!$user_id || !$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Invalid punch request.']); exit; }
        try {
            $pdo->prepare("INSERT INTO attendance_punches (user_id, punch_time) VALUES (?, NOW())")->execute([$user_id]);

            // Get current punch count to determine IN/OUT
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance_punches WHERE user_id=? AND DATE(punch_time)=CURDATE()");
            $count_stmt->execute([$user_id]);
            $punch_count = (int)$count_stmt->fetchColumn();
            $punch_type = ($punch_count % 2 === 1) ? 'IN' : 'OUT';

            $name = $pdo->query("SELECT name FROM users WHERE id={$user_id}")->fetchColumn();
            echo json_encode([
                'status'     => 'success',
                'message'    => "Punch {$punch_type} recorded for {$name}",
                'user_name'  => $name,
                'punch_type' => $punch_type,
                'punch_time' => date('Y-m-d H:i:s')
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to log punch.']);
        }
        break;

    // ----------------------------------------------------------
    // TERMINAL SESSIONS: Start a face recognition terminal session
    // ----------------------------------------------------------
    case 'start_terminal_session':
        $branch_id = $request['branch_id'] ?? null;
        $manager_id = $request['manager_id'] ?? null;
        if (!$branch_id || !$manager_id) { echo json_encode(['status' => 'error', 'message' => 'Branch and Manager ID required.']); exit; }
        try {
            // Close any existing active session for this branch
            $pdo->prepare("UPDATE face_recognition_sessions SET status='closed', ended_at=NOW() WHERE branch_id=? AND status='active'")->execute([$branch_id]);
            // Start new session
            $pdo->prepare("INSERT INTO face_recognition_sessions (branch_id, started_by) VALUES (?,?)")->execute([$branch_id, $manager_id]);
            $session_id = $pdo->lastInsertId();
            log_action($pdo, $manager_id, $branch_id, 'TERMINAL_STARTED', "Face recognition terminal started.");
            echo json_encode(['status' => 'success', 'session_id' => $session_id]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to start session.']);
        }
        break;

    // ----------------------------------------------------------
    // TERMINAL SESSIONS: Close a face recognition terminal session
    // ----------------------------------------------------------
    case 'close_terminal_session':
        $branch_id = $request['branch_id'] ?? null;
        $manager_id = $request['manager_id'] ?? null;
        try {
            $pdo->prepare("UPDATE face_recognition_sessions SET status='closed', ended_at=NOW() WHERE branch_id=? AND status='active'")->execute([$branch_id]);
            log_action($pdo, $manager_id, $branch_id, 'TERMINAL_CLOSED', "Face recognition terminal closed.");
            echo json_encode(['status' => 'success', 'message' => 'Terminal closed.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to close session.']);
        }
        break;

    // ----------------------------------------------------------
    // BRANCH STAFF: Get staff list (for manager face registration page)
    // ----------------------------------------------------------
    case 'get_branch_staff':
        $branch_id = $request['branch_id'] ?? null;
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $stmt = $pdo->prepare("
                SELECT id, name, role, department, mobile_number, status,
                       (face_descriptor IS NOT NULL) as is_registered
                FROM users WHERE branch_id=? AND status='active' ORDER BY name ASC
            ");
            $stmt->execute([$branch_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Fetch failed.']);
        }
        break;

    // ----------------------------------------------------------
    // SYSTEM LOGS: Get paginated system history
    // ----------------------------------------------------------
    case 'get_system_logs':
        $branch_id = $request['branch_id'] ?? null;
        $limit = min((int)($request['limit'] ?? 50), 200);
        $offset = (int)($request['offset'] ?? 0);
        try {
            $where = $branch_id ? "WHERE l.branch_id=?" : "WHERE 1=1";
            $params = $branch_id ? [$branch_id] : [];
            $stmt = $pdo->prepare("
                SELECT l.id, l.action_type, l.description, l.created_at,
                       u.name as user_name, u.role as user_role,
                       b.branch_name
                FROM system_logs l
                LEFT JOIN users u ON l.user_id = u.id
                LEFT JOIN branches b ON l.branch_id = b.id
                {$where}
                ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}
            ");
            $stmt->execute($params);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Log fetch failed.']);
        }
        break;

    // ----------------------------------------------------------
    // LEAVE RULES: Get or Update branch leave tiers
    // ----------------------------------------------------------
    case 'get_leave_rules':
        $branch_id = $request['branch_id'] ?? null;
        if (!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT * FROM branch_leave_rules WHERE branch_id=? ORDER BY min_working_days ASC");
            $stmt->execute([$branch_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Fetch failed.']);
        }
        break;

    case 'update_leave_rules':
        $branch_id = $request['branch_id'] ?? null;
        $rules = $request['rules'] ?? []; // Array of {min_working_days, earned_paid_leaves}
        $admin_id = $request['admin_id'] ?? null;
        if (!$branch_id || empty($rules)) { echo json_encode(['status' => 'error', 'message' => 'Invalid data.']); exit; }
        try {
            $pdo->prepare("DELETE FROM branch_leave_rules WHERE branch_id=?")->execute([$branch_id]);
            $ins = $pdo->prepare("INSERT INTO branch_leave_rules (branch_id, min_working_days, earned_paid_leaves) VALUES (?,?,?)");
            foreach ($rules as $r) { $ins->execute([$branch_id, $r['min_working_days'], $r['earned_paid_leaves']]); }
            log_action($pdo, $admin_id, $branch_id, 'LEAVE_RULES_UPDATED', "Leave rules updated for branch {$branch_id}.");
            echo json_encode(['status' => 'success', 'message' => 'Leave rules saved.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Update failed.']);
        }
        break;

    // ----------------------------------------------------------
    // DEFAULT: Invalid action
    // ----------------------------------------------------------
    default:
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Invalid action: '{$action}'"]);
        break;
}
?>
