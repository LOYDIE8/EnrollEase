<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/admin/common.php';
require_once __DIR__ . '/../api/enrollment-booking.php';
require_once __DIR__ . '/../api/student/common.php';
require_once __DIR__ . '/../api/enrollment-eligibility.php';
start_admin_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$loginUrl = '../admin-login/admin-login.html';
$sections = [
    'appointments' => 'Enrollment appointments',
    'eligibility' => 'Eligibility rules',
    'eligibility-records' => 'Student eligibility records',
    'students' => 'Student Management',
    'admins' => 'Admin accounts',
    'enrollments' => 'Enrollment records',
    'clearances' => 'Clearances',
    'payments' => 'Payments',
];
$sectionDescriptions = [
    'appointments' => 'Search enrollment appointments, review eligibility, and update appointment status.',
    'eligibility' => 'View and manage enrollment eligibility checks and allowed academic statuses.',
    'eligibility-records' => 'Update student eligibility records and immediately review the booking checks.',
    'students' => 'Search, review, add, edit, activate, and deactivate Daanbantayan Campus student accounts.',
    'admins' => 'Manage administrator accounts, assign roles, and configure system permissions.',
    'enrollments' => 'Review submitted enrollment records and their current status.',
    'clearances' => 'Review student clearance records across all offices.',
    'payments' => 'Review payment records across all offices.',
];
$sectionIcons = [
    'appointments' => '▦',
    'eligibility' => '✓',
    'eligibility-records' => '♙',
    'students' => '♙',
    'admins' => '👥',
    'enrollments' => '▧',
    'clearances' => '✓',
    'payments' => '₱',
];
$section = isset($_GET['section']) && is_string($_GET['section']) && isset($sections[$_GET['section']])
    ? $_GET['section']
    : 'appointments';
$records = [];
try {
    $database = admin_database();
    $admin = current_admin($database);
    if (!$admin) {
        header('Location: ' . $loginUrl, true, 303);
        exit;
    }

    $canRead = admin_can_read_section($database, $admin, $section);
    $canUpdate = admin_can_update_appointments($database, $admin);
    $canManageStudents = admin_can_manage_students($database, $admin);
    $canReadAudit = admin_has_permission($database, $admin, 'activity_logs.read') &&
        admin_can_read_students($database, $admin);
    $canManageSystem = admin_has_permission($database, $admin, 'system.write');
    $errorMessage = '';

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_request_origin();
        $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
            ? $_POST['csrf_token']
            : null;
        $knownToken = $_SESSION['admin_csrf_token'] ?? '';
        if (!is_string($token) || $knownToken === '' || !hash_equals($knownToken, $token)) {
            http_response_code(403);
            $errorMessage = 'This request could not be verified. Reload the page and try again.';
        } elseif (($_POST['action'] ?? '') === 'update_eligibility_rules') {
            http_response_code(400);
            $errorMessage = 'Required enrollment eligibility checks cannot be disabled.';
        } elseif (!$canUpdate) {
            http_response_code(403);
            $errorMessage = 'You are not authorized to perform this action.';
        } else {
            $appointmentId = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT);
            $newStatusCode = $_POST['status_code'] ?? '';
            $validStatusCodes = ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'];

            if (!$appointmentId || !is_string($newStatusCode) || !in_array($newStatusCode, $validStatusCodes, true)) {
                http_response_code(400);
                $errorMessage = 'Select a valid appointment and status.';
            } else {
                try {
                    $database->beginTransaction();
                    $recordSql = 'SELECT a.id, a.student_id, a.reference_number, a.scheduled_at,
                                         a.appointment_status_id, s.year_level, s.department_id
                                  FROM appointments AS a
                                  INNER JOIN students AS s ON s.id = a.student_id
                                  INNER JOIN campuses ON campuses.campus_id = s.campus_id
                                  WHERE a.id = ? AND campuses.campus_name = \'Daanbantayan Campus\'
                                  FOR UPDATE';
                    $recordQuery = $database->prepare($recordSql);
                    $recordQuery->execute([$appointmentId]);
                    $record = $recordQuery->fetch();
                    if (!$record) {
                        throw new RuntimeException('The appointment record was not found.');
                    }
                    if (
                        !admin_has_permission($database, $admin, 'appointments.update') &&
                        (int)$record['department_id'] !== (int)$admin['department_id']
                    ) {
                        throw new RuntimeException('You are not authorized to perform this action.');
                    }

                    $statusQuery = $database->prepare(
                        'SELECT id FROM appointment_status WHERE status_code = ? LIMIT 1'
                    );
                    $statusQuery->execute([$newStatusCode]);
                    $newStatusId = $statusQuery->fetchColumn();
                    if (!$newStatusId) {
                        throw new RuntimeException('The selected appointment status is not configured.');
                    }
                    if (in_array($newStatusCode, ['pending', 'confirmed', 'rescheduled'], true)) {
                        $otherActive = $database->prepare(
                            'SELECT 1 FROM appointments AS a
                             INNER JOIN appointment_status AS st ON st.id = a.appointment_status_id
                             WHERE a.student_id = ? AND a.id <> ?
                               AND st.status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')
                             LIMIT 1 FOR UPDATE'
                        );
                        $otherActive->execute([(int)$record['student_id'], (int)$appointmentId]);
                        if ($otherActive->fetchColumn()) {
                            throw new RuntimeException('The student already has another active enrollment appointment.');
                        }
                    }

                    $scheduledAt = $record['scheduled_at'];
                    if ($newStatusCode === 'rescheduled') {
                        $newDate = $_POST['appointment_date'] ?? '';
                        $newTime = $_POST['appointment_time'] ?? '';
                        $candidate = is_string($newDate) && is_string($newTime)
                            ? DateTime::createFromFormat('!Y-m-d H:i', $newDate . ' ' . $newTime)
                            : false;
                        if (!$candidate || $candidate->format('Y-m-d H:i') !== $newDate . ' ' . $newTime) {
                            throw new RuntimeException('Choose a valid new appointment date and time.');
                        }
                        $scheduledAt = $candidate->format('Y-m-d H:i:s');
                    }

                    if (in_array($newStatusCode, ['pending', 'confirmed', 'rescheduled'], true)) {
                        $candidateDate = substr($scheduledAt, 0, 10);
                        $candidateTime = substr($scheduledAt, 11, 8);
                        $schedule = enrollment_schedule_for_student($database, (string)$record['year_level']);
                        if (!$schedule) {
                            throw new RuntimeException('There is no active enrollment schedule for this student year level.');
                        }
                        $currentDateQuery = $database->prepare('SELECT CURRENT_DATE');
                        $currentDateQuery->execute();
                        if ($candidateDate < (string)$currentDateQuery->fetchColumn()) {
                            throw new RuntimeException('Past enrollment dates cannot be booked.');
                        }
                        if ($candidateDate < $schedule['start_date'] || $candidateDate > $schedule['end_date']) {
                            throw new RuntimeException('This date is outside the student assigned enrollment week.');
                        }
                        $scheduleDay = enrollment_lock_schedule_day(
                            $database,
                            (string)$record['year_level'],
                            $candidateDate
                        );
                        if (!$scheduleDay) {
                            throw new RuntimeException('This date is outside the student assigned enrollment week.');
                        }

                        $slotQuery = $database->prepare(
                            'SELECT id, capacity FROM enrollment_appointment_slots
                             WHERE slot_date = ? AND slot_time = ? AND is_open = 1
                             LIMIT 1 FOR UPDATE'
                        );
                        $slotQuery->execute([$candidateDate, $candidateTime]);
                        $slot = $slotQuery->fetch();
                        if (!$slot) {
                            throw new RuntimeException('The selected enrollment appointment time is unavailable.');
                        }
                        if (
                            enrollment_daily_active_count($database, $candidateDate, (int)$appointmentId) >=
                            min(150, (int)$scheduleDay['daily_capacity'])
                        ) {
                            throw new RuntimeException('The selected enrollment date is full (150 of 150 students).');
                        }
                        if (enrollment_slot_active_count($database, $candidateDate, $candidateTime, (int)$appointmentId) >= (int)$slot['capacity']) {
                            throw new RuntimeException('The selected enrollment appointment time is full.');
                        }
                    }

                    $changed = (int)$record['appointment_status_id'] !== (int)$newStatusId ||
                        $scheduledAt !== $record['scheduled_at'];
                    if ($changed) {
                        $update = $database->prepare(
                            'UPDATE appointments
                             SET appointment_status_id = ?, scheduled_at = ?, updated_by = ?
                             WHERE id = ?'
                        );
                        $update->execute([
                            (int)$newStatusId,
                            $scheduledAt,
                            (int)$admin['user_id'],
                            (int)$appointmentId,
                        ]);
                        $history = $database->prepare(
                            'INSERT INTO appointment_status_history
                             (appointment_id, from_status_id, to_status_id, changed_by)
                             VALUES (?, ?, ?, ?)'
                        );
                        $history->execute([
                            (int)$appointmentId,
                            (int)$record['appointment_status_id'],
                            (int)$newStatusId,
                            (int)$admin['user_id'],
                        ]);
                    }
                    $database->commit();

                    if ($changed && in_array($newStatusCode, ['confirmed', 'cancelled', 'rescheduled'], true)) {
                        try {
                            $profileQuery = $database->prepare(
                                'SELECT u.email, u.first_name, u.last_name, s.student_number, s.year_level
                                 FROM appointments AS a
                                 INNER JOIN students AS s ON s.id = a.student_id
                                 INNER JOIN users AS u ON u.id = s.user_id
                                 WHERE a.id = ? LIMIT 1'
                            );
                            $profileQuery->execute([(int)$appointmentId]);
                            $emailProfile = $profileQuery->fetch();
                            if ($emailProfile) {
                                $mailSchedule = enrollment_schedule_for_student(
                                    $database,
                                    (string)$emailProfile['year_level']
                                );
                                $emailSent = student_send_appointment_email(
                                    $emailProfile,
                                    [
                                        'reference_number' => $record['reference_number'],
                                        'scheduled_at' => $scheduledAt,
                                        'week_start' => $mailSchedule['start_date'] ?? '',
                                        'week_end' => $mailSchedule['end_date'] ?? '',
                                    ],
                                    $newStatusCode
                                );
                                $notification = $database->prepare(
                                    'INSERT INTO notifications
                                     (user_id, appointment_id, notification_type, subject, message,
                                      delivery_channel, delivery_status, sent_at)
                                     SELECT u.id, ?, ?, ?, ?, \'email\', ?, ?
                                     FROM users AS u
                                     INNER JOIN students AS s ON s.user_id = u.id
                                     INNER JOIN appointments AS a ON a.student_id = s.id
                                     WHERE a.id = ?'
                                );
                                $notification->execute([
                                    (int)$appointmentId,
                                    'enrollment_appointment_' . $newStatusCode,
                                    'Enrollment appointment ' . $newStatusCode,
                                    'Enrollment appointment ' . $newStatusCode . '.',
                                    $emailSent ? 'sent' : 'failed',
                                    $emailSent ? date('Y-m-d H:i:s') : null,
                                    (int)$appointmentId,
                                ]);
                            }
                        } catch (Throwable $error) {
                            error_log('Appointment status was saved but email notification failed: ' . $error->getMessage());
                        }
                    }

                    $redirectQuery = [];
                    if (!empty($_POST['q']) && is_string($_POST['q'])) {
                        $redirectQuery['q'] = substr(trim($_POST['q']), 0, 100);
                    }
                    if (!empty($_POST['status']) && is_string($_POST['status'])) {
                        $redirectQuery['status'] = $_POST['status'];
                    }
                    if (!empty($_POST['date']) && is_string($_POST['date'])) {
                        $redirectQuery['date'] = $_POST['date'];
                    }
                    if (!empty($_POST['year_level']) && is_string($_POST['year_level'])) {
                        $redirectQuery['year_level'] = $_POST['year_level'];
                    }
                    $redirectQuery['section'] = 'appointments';
                    header(
                        'Location: dashboard.php?' . http_build_query($redirectQuery),
                        true,
                        303
                    );
                    exit;
                } catch (Throwable $error) {
                    if ($database->inTransaction()) {
                        $database->rollBack();
                    }
                    error_log('Appointment status update failed: ' . $error->getMessage());
                    http_response_code(400);
                    $errorMessage = $error instanceof PDOException
                        ? 'The appointment status could not be updated. Check that the record is still available and try again.'
                        : $error->getMessage();
                }
            }
        }
    }

    $search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    $search = substr($search, 0, 100);
    $selectedStatus = isset($_GET['status']) && is_string($_GET['status'])
        ? $_GET['status']
        : '';
    $selectedDate = isset($_GET['date']) && is_string($_GET['date']) &&
        preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $_GET['date'])
        ? $_GET['date']
        : '';
    $yearLevels = ['First Year', 'Second Year', 'Third Year', 'Fourth Year'];
    $selectedYearLevel = isset($_GET['year_level']) && is_string($_GET['year_level']) &&
        in_array($_GET['year_level'], $yearLevels, true)
        ? $_GET['year_level']
        : '';
    $statusQuery = $database->prepare(
        'SELECT status_code, status_name FROM appointment_status ORDER BY sort_order, status_name'
    );
    $statusQuery->execute();
    $statusList = $statusQuery->fetchAll();
    $statusList = array_values(array_filter(
        $statusList,
        static fn(array $status): bool => in_array(
            $status['status_code'],
            ['pending', 'confirmed', 'rescheduled', 'completed', 'cancelled'],
            true
        )
    ));
    $statusCodes = array_column($statusList, 'status_code');
    if (!in_array($selectedStatus, $statusCodes, true)) {
        $selectedStatus = '';
    }

    $appointments = [];
    $eligibilityRulesQuery = $database->prepare(
        'SELECT rule_key, rule_name, is_enabled FROM enrollment_eligibility_rules ORDER BY rule_key'
    );
    $eligibilityRulesQuery->execute();
    $eligibilityRules = $eligibilityRulesQuery->fetchAll();
    if ($canRead && $section === 'appointments') {
        $where = [
            'a.appointment_type = \'Enrollment Appointment\'',
            'campuses.campus_name = \'Daanbantayan Campus\'',
        ];
        $params = [];
        if (!admin_has_permission($database, $admin, 'appointments.read')) {
            $where[] = 's.department_id = ?';
            $params[] = (int)$admin['department_id'];
        }
        if ($search !== '') {
            $where[] = '(CONCAT(u.first_name, \' \', u.last_name) LIKE ?
                         OR u.email LIKE ?
                         OR s.student_number LIKE ?
                         OR s.program LIKE ?
                         OR a.public_id LIKE ?
                         OR a.reference_number LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        if ($selectedStatus !== '') {
            $where[] = 'st.status_code = ?';
            $params[] = $selectedStatus;
        }
        if ($selectedDate !== '') {
            $where[] = 'DATE(a.scheduled_at) = ?';
            $params[] = $selectedDate;
        }
        if ($selectedYearLevel !== '') {
            $where[] = 's.year_level = ?';
            $params[] = $selectedYearLevel;
        }

            $sql = 'SELECT a.id, a.public_id, a.reference_number, a.student_id,
                           a.appointment_type, a.scheduled_at,
                           a.student_note, a.admin_note, a.created_at,
                           u.first_name, u.last_name, u.email,
                           s.student_number, s.program, s.year_level,
                       d.department_name, st.status_code, st.status_name
                FROM appointments AS a
                INNER JOIN students AS s ON s.id = a.student_id
                INNER JOIN users AS u ON u.id = s.user_id
                INNER JOIN campuses ON campuses.campus_id = s.campus_id
                INNER JOIN departments AS d ON d.id = a.department_id
                INNER JOIN appointment_status AS st ON st.id = a.appointment_status_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.scheduled_at DESC, a.id DESC LIMIT 250';
        $query = $database->prepare($sql);
        $query->execute($params);
        $appointments = $query->fetchAll();
    }

    if ($canRead && $section === 'enrollments') {
        $query = $database->prepare(
            'SELECT e.id, e.academic_year, e.semester, e.enrollment_status,
                    e.confirmed_at, e.notes, u.first_name, u.last_name,
                    s.student_number, s.program
             FROM enrollment_records AS e
             INNER JOIN students AS s ON s.id = e.student_id
             INNER JOIN users AS u ON u.id = s.user_id
             INNER JOIN campuses ON campuses.campus_id = s.campus_id
             WHERE campuses.campus_name = \'Daanbantayan Campus\'
               AND (? = \'\' OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?
                    OR s.student_number LIKE ? OR s.program LIKE ?)
             ORDER BY e.created_at DESC
             LIMIT 250'
        );
        $like = '%' . $search . '%';
        $query->execute([$search, $like, $like, $like]);
        $records = $query->fetchAll();
    } elseif ($canRead && $section === 'clearances') {
        $clearanceSql =
            'SELECT c.clearance_status, c.reviewed_at, c.notes,
                    req.requirement_name, d.department_name,
                    u.first_name, u.last_name, s.student_number, s.program
             FROM clearance_records AS c
             INNER JOIN students AS s ON s.id = c.student_id
             INNER JOIN users AS u ON u.id = s.user_id
             INNER JOIN campuses ON campuses.campus_id = s.campus_id
             INNER JOIN requirements AS req ON req.id = c.requirement_id
             INNER JOIN departments AS d ON d.id = req.department_id
             WHERE campuses.campus_name = \'Daanbantayan Campus\'
               AND (? = \'\' OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?
                    OR s.student_number LIKE ? OR req.requirement_name LIKE ?
                    OR d.department_name LIKE ?)';
        $like = '%' . $search . '%';
        $clearanceParameters = [$search, $like, $like, $like, $like];
        if (!admin_has_permission($database, $admin, 'clearances.read')) {
            $clearanceSql .= ' AND d.department_code = ?';
            $clearanceParameters[] = admin_has_permission($database, $admin, 'clearances.medical.read')
                ? 'Clinic'
                : 'Guidance';
        }
        $clearanceSql .= ' ORDER BY c.updated_at DESC LIMIT 250';
        $query = $database->prepare($clearanceSql);
        $query->execute($clearanceParameters);
        $records = $query->fetchAll();
    } elseif ($canRead && $section === 'payments') {
        $query = $database->prepare(
            'SELECT p.receipt_number, p.amount, p.currency, p.payment_status,
                    p.payment_method, p.paid_at, p.notes, u.first_name,
                    u.last_name, s.student_number, s.program
             FROM payments AS p
             INNER JOIN students AS s ON s.id = p.student_id
             INNER JOIN users AS u ON u.id = s.user_id
             INNER JOIN campuses ON campuses.campus_id = s.campus_id
             WHERE campuses.campus_name = \'Daanbantayan Campus\'
               AND (? = \'\' OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?
                    OR s.student_number LIKE ? OR p.receipt_number LIKE ?)
             ORDER BY p.created_at DESC
             LIMIT 250'
        );
        $like = '%' . $search . '%';
        $query->execute([$search, $like, $like, $like]);
        $records = $query->fetchAll();
    }
} catch (Throwable $error) {
    error_log('Admin dashboard failed: ' . $error->getMessage());
    http_response_code(503);
    $admin = null;
    $canRead = false;
    $canUpdate = false;
    $canManageStudents = false;
    $canReadAudit = false;
    $canManageSystem = false;
    $appointments = [];
    $statusList = [];
    $search = '';
    $selectedStatus = '';
    $selectedDate = '';
    $selectedYearLevel = '';
    $eligibilityRules = [];
    $records = [];
    $errorMessage = 'The admin dashboard is temporarily unavailable. Please try again later.';
}

if (!$admin) {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Admin dashboard unavailable</title>';
    echo '<p>' . htmlspecialchars($errorMessage ?? 'Unable to load the admin dashboard.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></html>';
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5f7fb">
  <title>CTU Daanbantayan Campus Enrollment System | Admin dashboard</title>
  <link rel="stylesheet" href="../admin-dashboard.css">
  <style>
    .content { max-width: 1500px; }
    .toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
    .filter-form { display: flex; flex-wrap: wrap; gap: 8px; }
    .filter-form input, .filter-form select, .status-form select { min-height: 36px; padding: 0 10px; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
    .filter-form input { width: min(320px, 70vw); }
    .status-form { display: flex; align-items: center; gap: 6px; }
    .details-cell { max-width: 220px; white-space: normal; }
    .details-cell summary { color: var(--blue); cursor: pointer; font-weight: 600; }
    .record-details { min-width: 190px; padding: 8px 0 2px; color: var(--muted); line-height: 1.6; }
    .notice { margin-bottom: 15px; padding: 12px 14px; border: 1px solid #e8ebf3; border-radius: 8px; background: #f9faff; color: #64708a; }
    .alert { margin-bottom: 15px; padding: 11px 13px; border-radius: 8px; color: #9c3842; background: #fff0f0; }
    .logout-form { margin: 0; }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    .student-dialog { width: min(680px, calc(100vw - 28px)); max-height: 90vh; margin: auto; padding: 22px; border: 0; border-radius: 12px; box-shadow: 0 18px 60px #10233f55; }
    .student-dialog::backdrop { background: #10233f88; }
    .student-editor { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .student-editor h2, .student-editor .panel-caption, .student-editor .student-dialog-message, .student-dialog-actions, .student-editor dl { grid-column: 1 / -1; }
    .student-editor label { display: grid; gap: 5px; font-size: 13px; font-weight: 600; }
    .student-editor input, .student-editor select { width: 100%; min-height: 38px; padding: 7px 9px; border: 1px solid var(--line); border-radius: 7px; background: #fff; }
    .student-editor dl { display: grid; grid-template-columns: minmax(120px, 1fr) 2fr; gap: 8px; }
    .student-editor dd { margin: 0; }
    .student-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; }
    .student-actions { display: flex; flex-wrap: wrap; gap: 5px; }
    #student-audit-panel { margin-top: 24px; }
    #student-audit-panel pre { max-width: 260px; max-height: 180px; overflow: auto; white-space: pre-wrap; }
    @media (max-width: 700px) {
      .topbar { height: auto; min-height: 72px; flex-wrap: wrap; padding: 14px 18px; }
      .topbar-right { width: 100%; justify-content: space-between; }
      .content { padding: 22px 16px 35px; }
      .page-heading { align-items: flex-start; flex-direction: column; }
    }
    @media (max-width: 520px) {
      .student-editor { grid-template-columns: 1fr; }
      .student-editor h2, .student-editor .panel-caption, .student-editor .student-dialog-message, .student-dialog-actions, .student-editor dl { grid-column: 1; }
    }
  </style>
</head>
<body>
<div class="app-shell">
  <aside class="sidebar" id="adminSidebar">
    <a class="brand" href="../landing.html"><span class="brand-mark">E</span><span class="brand-name">enroll<span>ease</span></span></a>
    <div class="nav-label">Administration</div>
    <nav class="nav-list" aria-label="Admin navigation">
      <?php foreach ($sections as $key => $label): ?>
        <?php if (!in_array($key, ['clearances', 'payments'], true) && admin_can_read_section($database, $admin, $key)): ?>
          <a class="nav-link <?= $section === $key ? 'active' : '' ?>" href="dashboard.php?section=<?= h($key) ?>"><span class="nav-icon"><?= h($sectionIcons[$key]) ?></span><?= h($label) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if ($canManageSystem): ?>
        <a class="nav-link" href="schedules.php"><span class="nav-icon">▣</span>Schedules and capacity</a>
      <?php endif; ?>
    </nav>
    <div class="sidebar-bottom">
      <div class="role-card">
        <small><?= h($admin['role_name']) ?></small>
        <strong><?= h($admin['name']) ?></strong>
        <span class="role-badge"><?= h($admin['role_name']) ?></span>
      </div>
    </div>
  </aside>
  <button class="sidebar-overlay" id="adminSidebarOverlay" type="button" aria-label="Close admin navigation"></button>
  <div class="main-column">
    <header class="topbar">
      <div class="topbar-left">
        <button class="admin-menu-toggle" id="adminMenuButton" type="button" aria-label="Open admin navigation" aria-controls="adminSidebar" aria-expanded="false">☰</button>
        <span class="breadcrumb">Administration /</span><strong><?= h($sections[$section]) ?></strong>
      </div>
      <div class="topbar-right">
        <span><?= h($admin['username']) ?></span>
        <form class="logout-form" action="../api/admin/logout.php" method="post">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_csrf_token']) ?>">
          <button class="button signout-button" type="submit">Log out</button>
        </form>
      </div>
    </header>
    <main class="content">
      <section class="page-heading">
        <div><p class="eyebrow">ADMIN PORTAL</p><h1><?= h($sections[$section]) ?></h1>
          <p class="heading-subtitle"><?= h($sectionDescriptions[$section]) ?></p></div>
      </section>
      <?php if ($errorMessage !== ''): ?>
        <div class="alert" role="alert"><?= h($errorMessage) ?></div>
      <?php endif; ?>
      <?php if (!$canRead): ?>
        <div class="notice" role="status">Your administrator account does not have permission to view this section.</div>
      <?php elseif ($section === 'eligibility'): ?>
      <section class="panel" id="eligibility-settings-manager" data-csrf="<?= h($_SESSION['admin_csrf_token']) ?>">
        <div class="toolbar" style="margin-bottom: 20px;">
          <div>
            <h2 style="margin: 0 0 4px; font-size: 18px;">Enrollment Eligibility Configuration</h2>
            <p class="panel-caption" style="margin: 0;">Configure dynamic enrollment criteria, allowed academic statuses, and document verification prerequisites.</p>
          </div>
        </div>
        
        <p id="eligibility-settings-message" class="notice" hidden></p>
        
        <div style="background: #f8fafc; border: 1px solid var(--line); border-radius: 10px; padding: 18px; margin-bottom: 24px;">
          <h3 style="margin-top: 0; font-size: 15px; color: var(--navy); display: flex; align-items: center; gap: 8px;">
            <span>🛡</span> Mandatory System Integrity Checks
          </h3>
          <p class="panel-caption" style="margin-bottom: 12px;">These core integrity rules are enforced directly by the server engine prior to confirming any appointment:</p>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; font-size: 13px;">
            <div style="background: #fff; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px;">✓ Daanbantayan Campus student account</div>
            <div style="background: #fff; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px;">✓ Active account &amp; assigned year level</div>
            <div style="background: #fff; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px;">✓ No missing, incomplete, or failed grades</div>
            <div style="background: #fff; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px;">✓ Zero outstanding balance (₱0.00)</div>
            <div style="background: #fff; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px;">✓ No active administrative holds</div>
            <div style="background: #fff; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 6px;">✓ All required documents verified</div>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr; gap: 24px;">
          <!-- Allowed Academic Statuses Form -->
          <div style="background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 20px;">
            <h3 style="margin: 0 0 6px; font-size: 16px;">Allowed Academic Statuses</h3>
            <p class="panel-caption" style="margin-bottom: 16px;">Check all student academic statuses that are permitted to book enrollment appointments.</p>
            <form id="allowed-statuses-form">
              <div id="statuses-checkboxes" style="display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; padding: 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                <span class="muted" style="font-size: 13px;">Loading allowed statuses...</span>
              </div>
              <button type="submit" class="button primary">Save Allowed Statuses</button>
            </form>
          </div>

          <!-- Document Requirements Manager -->
          <div style="background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
              <div>
                <h3 style="margin: 0 0 4px; font-size: 16px;">Required Enrollment Documents</h3>
                <p class="panel-caption" style="margin: 0;">Configure document types that students must submit and have verified before booking.</p>
              </div>
            </div>
            <div class="table-wrap" style="margin-bottom: 20px;">
              <table>
                <thead>
                  <tr>
                    <th>Document Name</th>
                    <th>Requirement Type</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="doc-types-table-body">
                  <tr><td colspan="4" class="empty-state">Loading document types...</td></tr>
                </tbody>
              </table>
            </div>

            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
              <h4 style="margin: 0 0 10px; font-size: 14px;">Add New Document Type</h4>
              <form id="add-doc-type-form" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                <input type="text" name="name" placeholder="Document name (e.g. Certificate of Good Moral)" required style="flex: 1; min-width: 250px; padding: 8px 12px; border: 1px solid var(--line); border-radius: 6px; font-size: 14px;">
                <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: normal; cursor: pointer;">
                  <input type="checkbox" name="isRequired" checked>
                  <span>Mandatory prerequisite</span>
                </label>
                <button type="submit" class="button primary">Add Document</button>
              </form>
            </div>
          </div>
        </div>
      </section>
      <?php elseif ($section === 'eligibility-records'): ?>
      <section class="panel" id="student-eligibility-manager" data-csrf="<?= h($_SESSION['admin_csrf_token']) ?>">
        <form class="filter-form" id="eligibility-student-search">
          <label for="eligibility-student-number">Student ID / LRN</label>
          <input id="eligibility-student-number" name="studentNumber" type="text" inputmode="numeric" pattern="(824[0-9]{4}|[0-9]{12})" maxlength="12" value="<?= h(isset($_GET['student_number']) && is_string($_GET['student_number']) ? substr(trim($_GET['student_number']), 0, 12) : '') ?>" placeholder="8240000 or 12-digit LRN" required>
          <button class="button primary" type="submit">Load student</button>
        </form>
        <p id="eligibility-manager-message" role="status" aria-live="polite"></p>
        <div id="eligibility-record-editor" hidden></div>
      </section>
      <?php else: ?>
        <?php if ($section === 'appointments'): ?>
        <section class="panel">
          <div class="toolbar">
            <p class="panel-caption"><?= count($appointments) ?> records shown (maximum 250).</p>
            <form class="filter-form" method="get" action="dashboard.php">
              <input type="hidden" name="section" value="appointments">
              <label class="sr-only" for="appointment-search">Search appointments</label>
              <input id="appointment-search" name="q" type="search" maxlength="100" value="<?= h($search) ?>" placeholder="Search student number, name, or reference">
              <label class="sr-only" for="appointment-status">Filter by status</label>
              <select id="appointment-status" name="status">
                <option value="">All statuses</option>
                <?php foreach ($statusList as $status): ?>
                  <option value="<?= h($status['status_code']) ?>" <?= $selectedStatus === $status['status_code'] ? 'selected' : '' ?>><?= h($status['status_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <label class="sr-only" for="appointment-date">Filter by appointment date</label>
              <input id="appointment-date" name="date" type="date" value="<?= h($selectedDate) ?>">
              <label class="sr-only" for="appointment-year-level">Filter by year level</label>
              <select id="appointment-year-level" name="year_level">
                <option value="">All year levels</option>
                <?php foreach (['First Year', 'Second Year', 'Third Year', 'Fourth Year'] as $yearLevel): ?>
                  <option value="<?= h($yearLevel) ?>" <?= $selectedYearLevel === $yearLevel ? 'selected' : '' ?>><?= h($yearLevel) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="button primary" type="submit">Search</button>
            </form>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Student</th><th>Program</th><th>Year level</th><th>Appointment</th><th>Scheduled</th><th>Status</th><th>Eligibility and requirements</th><th>Update</th></tr></thead>
              <tbody>
              <?php if (!$appointments): ?>
                <tr><td colspan="8" class="empty-state">No enrollment appointments match this search.</td></tr>
              <?php else: foreach ($appointments as $appointment): ?>
                <?php $appointmentEligibility = enrollment_eligibility($database, (int)$appointment['student_id']); ?>
                <tr>
                  <td><strong><?= h(trim($appointment['first_name'] . ' ' . $appointment['last_name'])) ?></strong><br><span class="student-id"><?= h($appointment['student_number']) ?></span></td>
                  <td><?= h($appointment['program']) ?></td>
                  <td><?= h($appointment['year_level'] ?: 'Not set') ?></td>
                  <td><?= h($appointment['appointment_type']) ?></td>
                  <td><?= h($appointment['scheduled_at']) ?></td>
                  <td><span class="status"><?= h($appointment['status_name']) ?></span></td>
                  <td class="details-cell">
                    <details>
                      <summary><?= $appointmentEligibility['eligible'] ? 'Eligible' : 'Not eligible' ?> · View details</summary>
                      <div class="record-details">
                        <?= h($appointment['email']) ?><br>
                        Reference: <?= h($appointment['reference_number'] ?: $appointment['public_id']) ?><br>
                        Submitted: <?= h($appointment['created_at']) ?><br>
                        <?php foreach ($appointmentEligibility['checks'] as $check): ?>
                          <strong><?= $check['passed'] ? '✓' : '✗' ?> <?= h($check['label']) ?></strong><br>
                          <?php foreach ($check['details'] ?? [] as $detail): ?>
                            <?= h($detail) ?><br>
                          <?php endforeach; ?>
                          <?php foreach ($check['reasons'] as $reason): ?>
                            <?= h($reason) ?><br>
                          <?php endforeach; ?>
                        <?php endforeach; ?>
                        Student note: <?= h($appointment['student_note'] ?: 'None') ?><br>
                        Admin note: <?= h($appointment['admin_note'] ?: 'None') ?>
                      </div>
                    </details>
                  </td>
                  <td>
                    <?php if ($canUpdate): ?>
                      <form class="status-form" method="post" action="dashboard.php">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_csrf_token']) ?>">
                        <input type="hidden" name="appointment_id" value="<?= (int)$appointment['id'] ?>">
                        <input type="hidden" name="q" value="<?= h($search) ?>">
                        <input type="hidden" name="status" value="<?= h($selectedStatus) ?>">
                        <input type="hidden" name="date" value="<?= h($selectedDate) ?>">
                        <input type="hidden" name="year_level" value="<?= h($selectedYearLevel) ?>">
                        <label class="sr-only" for="status-<?= (int)$appointment['id'] ?>">New status for <?= h($appointment['public_id']) ?></label>
                        <select id="status-<?= (int)$appointment['id'] ?>" name="status_code">
                          <?php foreach ($statusList as $status): ?>
                            <option value="<?= h($status['status_code']) ?>" <?= $appointment['status_code'] === $status['status_code'] ? 'selected' : '' ?>><?= h($status['status_name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <label class="sr-only" for="date-<?= (int)$appointment['id'] ?>">New enrollment appointment date (required to reschedule)</label>
                        <input id="date-<?= (int)$appointment['id'] ?>" name="appointment_date" type="date" value="<?= h(substr($appointment['scheduled_at'], 0, 10)) ?>">
                        <label class="sr-only" for="time-<?= (int)$appointment['id'] ?>">New enrollment appointment time (required to reschedule)</label>
                        <input id="time-<?= (int)$appointment['id'] ?>" name="appointment_time" type="time" value="<?= h(substr($appointment['scheduled_at'], 11, 5)) ?>">
                        <button class="button small" type="submit">Save</button>
                      </form>
                    <?php else: ?>
                      <span class="panel-caption">View only</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <p class="panel-caption" style="margin-top:14px">Appointments are retained for audit history. Use the Cancelled status instead of deleting a record.</p>
        </section>
        <?php elseif ($section === 'admins' && $canRead): ?>
        <section class="panel" id="admin-accounts-manager"
                 data-csrf="<?= h($_SESSION['admin_csrf_token']) ?>">
          <div class="toolbar">
            <h2>Administrator accounts</h2>
            <button class="button primary" type="button" id="add-admin-btn">Add Admin</button>
          </div>
          <p id="admin-accounts-message" class="notice" hidden></p>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Administrator</th>
                  <th>Email</th>
                  <th>Role &amp; Department</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="admin-table-body">
                <tr><td colspan="6" class="empty-state">Loading administrator accounts...</td></tr>
              </tbody>
            </table>
          </div>
          <dialog id="admin-dialog" class="student-dialog"></dialog>
        </section>
        <?php elseif ($section === 'students'): ?>
        <section class="panel" id="student-management"
                 data-csrf="<?= h($_SESSION['admin_csrf_token']) ?>"
                 data-can-manage="<?= $canManageStudents ? 'true' : 'false' ?>"
                 data-can-audit="<?= $canReadAudit ? 'true' : 'false' ?>">
          <div class="toolbar">
            <p class="panel-caption">Search and filter Daanbantayan Campus student accounts (maximum 250).</p>
            <div class="toolbar">
              <?php if ($canManageStudents): ?><button class="button primary" type="button" id="add-student-button">+ Add Student</button><?php endif; ?>
              <?php if ($canReadAudit): ?><button class="button" type="button" id="student-audit-toggle">Audit Logs</button><?php endif; ?>
              <button class="button" type="button" id="registry-toggle-btn">Enrolled Registry</button>
            </div>
          </div>
          <form class="filter-form" id="student-search">
            <label class="sr-only" for="student-search-query">Search students</label>
            <input id="student-search-query" name="q" type="search" maxlength="100" value="<?= h($search) ?>" placeholder="Search name, student ID, email, or program">
            <label for="student-status-filter">Status</label>
            <select id="student-status-filter" name="status">
              <option value="">All statuses</option>
              <option value="Active">Active</option>
              <option value="Inactive">Inactive</option>
            </select>
            <label for="student-year-filter">Year level</label>
            <select id="student-year-filter" name="yearLevel">
              <option value="">All year levels</option>
              <option value="1st Year">1st Year</option>
              <option value="2nd Year">2nd Year</option>
              <option value="3rd Year">3rd Year</option>
              <option value="4th Year">4th Year</option>
            </select>
            <button class="button primary" type="submit">Apply filters</button>
          </form>
          <p id="student-management-message" role="status" aria-live="polite"></p>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Student ID</th><th>Name</th><th>Program</th><th>Year Level</th><th>Campus</th><th>Status</th><th>Academic Status</th><th>Actions</th></tr></thead>
              <tbody id="student-table-body"><tr><td colspan="8" class="empty-state">Loading students…</td></tr></tbody>
            </table>
          </div>
          <section id="student-audit-panel" hidden aria-label="Student audit logs">
            <h2>Student audit logs</h2>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Date</th><th>Administrator</th><th>Student ID</th><th>Action</th><th>Old information</th><th>New information</th></tr></thead>
                <tbody id="student-audit-body"></tbody>
              </table>
            </div>
                    </section>
          <section id="student-registry-panel" hidden aria-label="Enrolled Student Registry" style="margin-top:20px;border-top:1px solid #e2e8f0;padding-top:16px;">
            <h2>Enrolled Student Registry</h2>
            <p class="panel-caption">Pre-registration validation registry for incoming and continuing students.</p>
            <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
              <span id="registry-status-display" style="font-weight:600;">Status: Loading…</span>
              <button class="button small" type="button" id="registry-toggle-setting-btn">Toggle Requirement</button>
            </div>
            <div style="background:#f8fafc;padding:16px;border-radius:8px;border:1px solid #e2e8f0;max-width:600px;">
              <h3>Import Registry CSV</h3>
              <p class="panel-caption">CSV header format: <code>student_number,last_name,program,year_level</code></p>
              <form id="registry-import-form" style="display:flex;flex-direction:column;gap:10px;">
                <textarea name="csv_content" rows="4" placeholder="student_number,last_name,program,year_level&#10;8240099,Santos,BSIT,1st Year" style="font-family:monospace;font-size:13px;padding:8px;width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:4px;"></textarea>
                <div style="display:flex;gap:10px;align-items:center;">
                  <input type="file" name="csv_file" accept=".csv" style="flex:1;">
                  <button type="submit" class="button primary">Upload &amp; Import</button>
                </div>
              </form>
              <div id="registry-import-msg" style="margin-top:10px;" role="status"></div>
            </div>
          </section>
        </section>
        <?php else: ?>
        <section class="panel">
          <div class="toolbar">
            <p class="panel-caption"><?= count($records) ?> records shown (maximum 250).</p>
            <form class="filter-form" method="get" action="dashboard.php">
              <input type="hidden" name="section" value="<?= h($section) ?>">
              <label class="sr-only" for="record-search">Search <?= h(strtolower($sections[$section])) ?></label>
              <input id="record-search" name="q" type="search" maxlength="100" value="<?= h($search) ?>" placeholder="Search name, student ID, email, or record">
              <button class="button primary" type="submit">Search</button>
            </form>
          </div>
          <div class="table-wrap">
            <?php if ($section === 'enrollments'): ?>
              <table>
                <thead><tr><th>Student</th><th>Program</th><th>Academic year</th><th>Semester</th><th>Status</th><th>Confirmed</th><th>Notes</th></tr></thead>
                <tbody>
                  <?php if (!$records): ?><tr><td colspan="7" class="empty-state">No enrollment records match this search.</td></tr>
                  <?php else: foreach ($records as $record): ?>
                    <tr>
                      <td><strong><?= h(trim($record['first_name'] . ' ' . $record['last_name'])) ?></strong><br><span class="student-id"><?= h($record['student_number']) ?></span></td>
                      <td><?= h($record['program']) ?></td><td><?= h($record['academic_year']) ?></td>
                      <td><?= h($record['semester']) ?></td><td><?= h($record['enrollment_status']) ?></td>
                      <td><?= h($record['confirmed_at'] ?: '—') ?></td><td class="details-cell"><?= h($record['notes'] ?: '—') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            <?php elseif ($section === 'clearances'): ?>
              <!-- Legacy section: Clearances. Retained for historical records; hidden from primary navigation. -->
              <table>
                <thead><tr><th>Student</th><th>Program</th><th>Office</th><th>Requirement</th><th>Status</th><th>Reviewed</th><th>Notes</th></tr></thead>
                <tbody>
                  <?php if (!$records): ?><tr><td colspan="7" class="empty-state">No clearance records match this search.</td></tr>
                  <?php else: foreach ($records as $record): ?>
                    <tr>
                      <td><strong><?= h(trim($record['first_name'] . ' ' . $record['last_name'])) ?></strong><br><span class="student-id"><?= h($record['student_number']) ?></span></td>
                      <td><?= h($record['program']) ?></td><td><?= h($record['department_name']) ?></td>
                      <td><?= h($record['requirement_name']) ?></td><td><?= h($record['clearance_status']) ?></td>
                      <td><?= h($record['reviewed_at'] ?: '—') ?></td><td class="details-cell"><?= h($record['notes'] ?: '—') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            <?php else: ?>
              <!-- Legacy section: Payments. Retained for historical records; hidden from primary navigation. -->
              <table>
                <thead><tr><th>Student</th><th>Program</th><th>Receipt</th><th>Amount</th><th>Status</th><th>Method</th><th>Paid at</th><th>Notes</th></tr></thead>
                <tbody>
                  <?php if (!$records): ?><tr><td colspan="8" class="empty-state">No payment records match this search.</td></tr>
                  <?php else: foreach ($records as $record): ?>
                    <tr>
                      <td><strong><?= h(trim($record['first_name'] . ' ' . $record['last_name'])) ?></strong><br><span class="student-id"><?= h($record['student_number']) ?></span></td>
                      <td><?= h($record['program']) ?></td><td><?= h($record['receipt_number'] ?: '—') ?></td>
                      <td><?= h($record['currency']) ?> <?= h($record['amount']) ?></td><td><?= h($record['payment_status']) ?></td>
                      <td><?= h($record['payment_method'] ?: '—') ?></td><td><?= h($record['paid_at'] ?: '—') ?></td>
                      <td class="details-cell"><?= h($record['notes'] ?: '—') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </section>
        <?php endif; ?>
      <?php endif; ?>
    </main>
  </div>
</div>
<?php if ($section === 'eligibility'): ?>
  <script src="../js/admin-eligibility-settings.js" defer></script>
<?php elseif ($section === 'eligibility-records'): ?>
  <script src="../js/admin-eligibility.js" defer></script>
<?php elseif ($section === 'students' && $canRead): ?>
  <script src="../js/admin-students.js" defer></script>
<?php elseif ($section === 'admins' && $canRead): ?>
  <script src="../js/admin-accounts.js" defer></script>
<?php endif; ?>
<script src="../js/admin-panel.js" defer></script>
</body>
</html>
