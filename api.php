<?php
// api.php - Central API Gateway

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

function log_action($pdo, $user_id, $branch_id, $action_type, $description) {
    $stmt = $pdo->prepare("INSERT INTO system_logs (user_id, branch_id, action_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $branch_id, $action_type, $description]);
}

switch ($action) {
    case 'ping': echo json_encode(['status' => 'success', 'message' => 'API Live.']); break;

    case 'login':
        $mobile_number = $request['mobile_number'] ?? ''; $password = $request['password'] ?? '';
        if(empty($mobile_number) || empty($password)) { echo json_encode(['status' => 'error', 'message' => 'Credentials required.']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT id, branch_id, role, name, password, status, feature_permissions FROM users WHERE mobile_number = ? LIMIT 1");
            $stmt->execute([$mobile_number]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') { echo json_encode(['status' => 'error', 'message' => 'Account inactive.']); exit; }
                log_action($pdo, $user['id'], $user['branch_id'], 'AUTH_LOGIN', "{$user['name']} logged in.");
                unset($user['password']);
                echo json_encode(['status' => 'success', 'user' => $user]);
            } else { echo json_encode(['status' => 'error', 'message' => 'Invalid credentials.']); }
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'System error.']); }
        break;

    case 'get_admin_dashboard':
        try {
            $total_branches = $pdo->query("SELECT COUNT(*) as count FROM branches")->fetch()['count'];
            $total_employees = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role != 'admin'")->fetch()['count'];
            $recent_logs = $pdo->query("SELECT l.action_type, l.description, l.created_at, b.branch_name FROM system_logs l LEFT JOIN branches b ON l.branch_id = b.id ORDER BY l.id DESC LIMIT 10")->fetchAll();
            $branch_grid = $pdo->query("SELECT b.id, b.branch_name, b.status, COUNT(u.id) as staff_count FROM branches b LEFT JOIN users u ON b.id = u.branch_id GROUP BY b.id ORDER BY b.created_at DESC")->fetchAll();
            echo json_encode(['status' => 'success', 'data' => ['total_branches' => $total_branches, 'total_employees' => $total_employees, 'recent_logs' => $recent_logs, 'branch_grid' => $branch_grid]]);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Dashboard error.']); }
        break;

    case 'create_branch':
        $name = $request['branch_name'] ?? ''; $address = $request['address'] ?? '';
        if(empty($name)) { echo json_encode(['status' => 'error', 'message' => 'Branch name required.']); exit; }
        try {
            $pdo->prepare("INSERT INTO branches (branch_name, address) VALUES (?, ?)")->execute([$name, $address]);
            echo json_encode(['status' => 'success', 'message' => 'Branch created.']);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Creation failed.']); }
        break;

    case 'get_branches':
        try { echo json_encode(['status' => 'success', 'data' => $pdo->query("SELECT * FROM branches ORDER BY created_at DESC")->fetchAll()]); } 
        catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Fetch failed.']); }
        break;

    // --- UPGRADED: EMPLOYEE FORGE INCLUDES DEPARTMENT & WEEK OFF ---
    case 'create_user':
        $role = $request['role'] ?? 'staff';
        $branch_id = ($role === 'admin') ? null : ($request['branch_id'] ?? null);
        $name = $request['name'] ?? ''; 
        $mobile_number = $request['mobile_number'] ?? ''; 
        $password = $request['password'] ?? '';
        $department = $request['department'] ?? '';
        
        $salary = $request['salary'] ?? 0; 
        $paid_leaves = $request['paid_leaves'] ?? 0;
        $shift_hours = $request['shift_hours'] ?? 9;
        $week_off_day = $request['week_off_day'] ?? 'Sunday';
        $permissions = json_encode($request['permissions'] ?? []);

        if(empty($name) || empty($mobile_number) || empty($password)) { echo json_encode(['status' => 'error', 'message' => 'Identity fields required.']); exit; }
        
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO users (branch_id, role, department, name, mobile_number, password, feature_permissions) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branch_id, $role, $department, $name, $mobile_number, $hashed_password, $permissions]);
            $new_user_id = $pdo->lastInsertId();
            
            $contract_stmt = $pdo->prepare("INSERT INTO employee_contracts (user_id, monthly_fixed_salary, monthly_paid_leaves, standard_shift_hours, week_off_day) VALUES (?, ?, ?, ?, ?)");
            $contract_stmt->execute([$new_user_id, $salary, $paid_leaves, $shift_hours, $week_off_day]);
            
            log_action($pdo, $request['admin_id'] ?? null, $branch_id, 'USER_CREATED', "New $role ($name) registered.");
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Personnel deployed successfully.']);
        } catch(PDOException $e) {
            $pdo->rollBack(); echo json_encode(['status' => 'error', 'message' => ($e->getCode() == 23000) ? 'Mobile already exists.' : 'Creation failed.']);
        }
        break;

    case 'get_branch_master':
        $branch_id = $request['branch_id'] ?? null;
        if(!$branch_id) { echo json_encode(['status'=>'error','message'=>'Branch ID required']); exit; }
        try {
            $b_stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $b_stmt->execute([$branch_id]);
            $branch = $b_stmt->fetch();

            $s_stmt = $pdo->prepare("
                SELECT u.id, u.name, u.role, u.department, u.mobile_number, u.status, u.feature_permissions,
                       c.monthly_fixed_salary, c.monthly_paid_leaves, c.standard_shift_hours, c.week_off_day
                FROM users u LEFT JOIN employee_contracts c ON u.id = c.user_id
                WHERE u.branch_id = ? ORDER BY u.role ASC, u.name ASC
            ");
            $s_stmt->execute([$branch_id]);
            $staff = $s_stmt->fetchAll();
            foreach($staff as &$s) { $s['feature_permissions'] = json_decode($s['feature_permissions'] ?? '[]', true); }

            $p_stmt = $pdo->prepare("
                SELECT u.name, p.punch_time FROM attendance_punches p
                JOIN users u ON p.user_id = u.id WHERE u.branch_id = ? AND DATE(p.punch_time) = ?
                ORDER BY p.punch_time DESC LIMIT 20
            ");
            $p_stmt->execute([$branch_id, date('Y-m-d')]);
            $recent_punches = $p_stmt->fetchAll();

            echo json_encode(['status' => 'success', 'data' => ['branch' => $branch, 'staff' => $staff, 'recent_punches' => $recent_punches]]);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to load branch matrix.']); }
        break;

    // --- UPGRADED: AUTOMATED F/H/A SPREADSHEET ENGINE ---
    case 'get_monthly_attendance':
        $branch_id = $request['branch_id'] ?? null;
        $month = $request['month'] ?? date('m');
        $year = $request['year'] ?? date('Y');
        if(!$branch_id) { echo json_encode(['status'=>'error','message'=>'Branch ID required']); exit; }
        
        try {
            // 1. Get Staff and Shift Targets
            $staff_stmt = $pdo->prepare("
                SELECT u.id, u.name, u.department, c.standard_shift_hours, c.week_off_day 
                FROM users u LEFT JOIN employee_contracts c ON u.id = c.user_id 
                WHERE u.branch_id = ? AND u.status = 'active' ORDER BY u.name ASC
            ");
            $staff_stmt->execute([$branch_id]);
            $staff = $staff_stmt->fetchAll();

            // 2. Get All Punches for the Month
            $punches_stmt = $pdo->prepare("
                SELECT user_id, DATE(punch_time) as p_date, punch_time 
                FROM attendance_punches 
                WHERE MONTH(punch_time) = ? AND YEAR(punch_time) = ?
                ORDER BY punch_time ASC
            ");
            $punches_stmt->execute([$month, $year]);
            $all_punches = $punches_stmt->fetchAll();

            // 3. Initialize Grid with 'A' (Absent) for every day
            $attendance_grid = [];
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
            
            foreach($staff as $s) {
                $attendance_grid[$s['id']] = [
                    'name' => $s['name'], 
                    'department' => $s['department'], 
                    'target_hours' => $s['standard_shift_hours'] ?? 9,
                    'week_off' => $s['week_off_day'],
                    'totals' => ['F' => 0, 'H' => 0, 'A' => 0, 'M' => 0],
                    'days' => []
                ];
                for($d = 1; $d <= $daysInMonth; $d++) {
                    $dt = sprintf("%04d-%02d-%02d", $year, $month, $d);
                    $attendance_grid[$s['id']]['days'][$dt] = ['status' => 'A', 'hours' => 0]; // Default Absent
                }
            }

            // 4. Map Punches to Users and Dates
            $user_punches = [];
            foreach($all_punches as $p) {
                $uid = $p['user_id'];
                $dt = $p['p_date'];
                if(!isset($user_punches[$uid])) $user_punches[$uid] = [];
                if(!isset($user_punches[$uid][$dt])) $user_punches[$uid][$dt] = [];
                $user_punches[$uid][$dt][] = $p['punch_time'];
            }

            // 5. Mathematical F/H/A Engine
            foreach($user_punches as $uid => $dates) {
                if(!isset($attendance_grid[$uid])) continue;
                $target = (float)$attendance_grid[$uid]['target_hours'];
                
                foreach($dates as $dt => $punches) {
                    if (count($punches) > 1) {
                        $first = strtotime(min($punches));
                        $last = strtotime(max($punches));
                        $hours = ($last - $first) / 3600;
                        
                        // F/H/A Logic Rules
                        if ($hours >= ($target - 0.5)) { // 30 min grace period for full day
                            $status = 'F';
                            $attendance_grid[$uid]['totals']['F']++;
                        } elseif ($hours >= ($target / 2)) {
                            $status = 'H';
                            $attendance_grid[$uid]['totals']['H']++;
                        } else {
                            $status = 'A';
                            $attendance_grid[$uid]['totals']['A']++;
                        }
                        $attendance_grid[$uid]['days'][$dt] = ['status' => $status, 'hours' => round($hours, 1)];
                    } elseif (count($punches) == 1) {
                        // Missed punch-out
                        $attendance_grid[$uid]['days'][$dt] = ['status' => 'M', 'hours' => 0];
                        $attendance_grid[$uid]['totals']['M']++;
                    }
                }
            }
            
            // Note: In Phase 4, we will check the 'Week Off' string against the current day to auto-mark W/O instead of A.

            echo json_encode(['status' => 'success', 'data' => array_values($attendance_grid), 'days_in_month' => $daysInMonth]);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to generate spreadsheet.']); }
        break;


    // --- (Keeping Terminal and Dashboard API identical) ---
    case 'get_payroll_data':
        $branch_id = $request['branch_id'] ?? null; $month = $request['month'] ?? date('m'); $year = $request['year'] ?? date('Y');
        if(!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT u.id, u.name, u.role, c.monthly_fixed_salary FROM users u LEFT JOIN employee_contracts c ON u.id = c.user_id WHERE u.branch_id = ? AND u.status = 'active' ORDER BY u.name ASC");
            $stmt->execute([$branch_id]);
            $staff = $stmt->fetchAll();
            $attendance_stmt = $pdo->prepare("SELECT user_id, COUNT(DISTINCT DATE(punch_time)) as days_worked FROM attendance_punches WHERE MONTH(punch_time) = ? AND YEAR(punch_time) = ? GROUP BY user_id");
            $attendance_stmt->execute([$month, $year]);
            $attendance_counts = [];
            while ($row = $attendance_stmt->fetch()) { $attendance_counts[$row['user_id']] = $row['days_worked']; }
            $payroll_data = [];
            foreach ($staff as $s) {
                $s['days_worked'] = $attendance_counts[$s['id']] ?? 0;
                $s['paid_leaves'] = 0; $s['advance_deduction'] = 0; $s['shop_bill'] = 0;
                $payroll_data[] = $s;
            }
            echo json_encode(['status' => 'success', 'data' => $payroll_data]);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to generate payroll data.']); }
        break;

    case 'get_branch_staff':
        $branch_id = $request['branch_id'] ?? null;
        if(!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $stmt = $pdo->prepare("SELECT id, name, role, mobile_number, status, (face_descriptor IS NOT NULL) as is_registered FROM users WHERE branch_id = ? ORDER BY name ASC");
            $stmt->execute([$branch_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Fetch failed.']); }
        break;

    case 'register_face':
        $user_id = $request['user_id'] ?? null; $manager_id = $request['manager_id'] ?? null; $branch_id = $request['branch_id'] ?? null; $descriptor = $request['descriptor'] ?? null; 
        if(!$user_id || !$descriptor) { echo json_encode(['status' => 'error', 'message' => 'Missing data.']); exit; }
        try {
            $pdo->prepare("UPDATE users SET face_descriptor = ? WHERE id = ?")->execute([$descriptor, $user_id]);
            log_action($pdo, $manager_id, $branch_id, 'FACE_REGISTERED', "Biometrics updated for UID: $user_id.");
            echo json_encode(['status' => 'success', 'message' => 'Facial matrix secured.']);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Registration failed.']); }
        break;

    case 'get_branch_descriptors':
        $branch_id = $request['branch_id'] ?? null;
        try {
            $stmt = $pdo->prepare("SELECT id, name, face_descriptor FROM users WHERE branch_id = ? AND face_descriptor IS NOT NULL");
            $stmt->execute([$branch_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to load biometric data.']); }
        break;

    case 'log_punch':
        $user_id = $request['user_id'] ?? null; $branch_id = $request['branch_id'] ?? null;
        if(!$user_id || !$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Invalid Request']); exit; }
        try {
            $pdo->prepare("INSERT INTO attendance_punches (user_id, punch_time) VALUES (?, NOW())")->execute([$user_id]);
            $name = $pdo->query("SELECT name FROM users WHERE id = $user_id")->fetchColumn();
            echo json_encode(['status' => 'success', 'message' => "Punch logged for $name", 'user_name' => $name]);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to log punch.']); }
        break;

    case 'get_live_attendance':
        $branch_id = $request['branch_id'] ?? null; $date = $request['date'] ?? date('Y-m-d');
        if(!$branch_id) { echo json_encode(['status' => 'error', 'message' => 'Branch ID required.']); exit; }
        try {
            $staff_stmt = $pdo->prepare("SELECT u.id, u.name, u.role, c.standard_shift_hours FROM users u LEFT JOIN employee_contracts c ON u.id = c.user_id WHERE u.branch_id = ? AND u.status = 'active' ORDER BY u.name ASC");
            $staff_stmt->execute([$branch_id]);
            $staff_list = $staff_stmt->fetchAll();
            $punches_stmt = $pdo->prepare("SELECT p.user_id, p.punch_time FROM attendance_punches p JOIN users u ON p.user_id = u.id WHERE u.branch_id = ? AND DATE(p.punch_time) = ? ORDER BY p.punch_time ASC");
            $punches_stmt->execute([$branch_id, $date]);
            $all_punches = $punches_stmt->fetchAll();
            $dashboard_data = [];
            foreach ($staff_list as $staff) {
                $staff['punches'] = [];
                foreach ($all_punches as $punch) {
                    if ($punch['user_id'] == $staff['id']) { $staff['punches'][] = $punch['punch_time']; }
                }
                $dashboard_data[] = $staff;
            }
            echo json_encode(['status' => 'success', 'data' => $dashboard_data]);
        } catch(PDOException $e) { echo json_encode(['status' => 'error', 'message' => 'Failed to fetch timeline data.']); }
        break;

    default: http_response_code(400); echo json_encode(['status' => 'error', 'message' => 'Invalid action.']); break;
}
?>