<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/admin/common.php';
require_once __DIR__ . '/../api/enrollment-booking.php';
start_admin_session();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$loginUrl = '../admin-login/admin-login.html';
$errorMessage = '';
$successMessage = '';

try {
    $database = admin_database();
    $admin = current_admin($database);
    if (!$admin) {
        header('Location: ' . $loginUrl, true, 303);
        exit;
    }
    if (!admin_has_permission($database, $admin, 'system.write')) {
        http_response_code(403);
        exit('You are not authorized to perform this action.');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        verify_request_origin();
        $token = $_POST['csrf_token'] ?? null;
        $knownToken = $_SESSION['admin_csrf_token'] ?? '';
        if (!is_string($token) || $knownToken === '' || !hash_equals($knownToken, $token)) {
            http_response_code(403);
            throw new RuntimeException('This request could not be verified. Reload the page and try again.');
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'save_schedule') {
            $yearLevel = $_POST['year_level'] ?? '';
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';
            $status = $_POST['status'] ?? '';
            $scheduleId = filter_var($_POST['schedule_id'] ?? 0, FILTER_VALIDATE_INT);
            $assignment = is_string($yearLevel) ? enrollment_year_assignment($yearLevel) : null;
            $start = is_string($startDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $startDate) : false;
            $end = is_string($endDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $endDate) : false;

            if (
                !$assignment || !$start || !$end ||
                $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate ||
                $start > $end || (int)$start->diff($end)->format('%a') !== 4 ||
                $start->format('N') !== '1' || $end->format('N') !== '5' ||
                !in_array($status, ['Active', 'Inactive'], true)
            ) {
                throw new RuntimeException('Use a valid year level, Monday-to-Friday date range, and schedule status.');
            }

            $database->beginTransaction();
            $overlap = $database->prepare(
                'SELECT schedule_id FROM enrollment_schedules
                 WHERE status = \'Active\' AND start_date <= ? AND end_date >= ?
                   AND schedule_id <> ? LIMIT 1 FOR UPDATE'
            );
            $overlap->execute([$endDate, $startDate, $scheduleId ?: 0]);
            if ($status === 'Active' && $overlap->fetchColumn()) {
                throw new RuntimeException('Another active enrollment schedule already uses part of those dates.');
            }

            if ($scheduleId) {
                $existing = $database->prepare(
                    'SELECT schedule_id FROM enrollment_schedules WHERE schedule_id = ? FOR UPDATE'
                );
                $existing->execute([$scheduleId]);
                if (!$existing->fetchColumn()) {
                    throw new RuntimeException('The enrollment schedule no longer exists.');
                }
                $bookedOutside = $database->prepare(
                    'SELECT COUNT(*)
                     FROM appointments AS a
                     INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
                     INNER JOIN enrollment_schedule_days AS days
                       ON days.appointment_date = DATE(a.scheduled_at)
                     WHERE days.schedule_id = ? AND a.appointment_type = \'Enrollment Appointment\'
                       AND statuses.status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')
                       AND DATE(a.scheduled_at) NOT BETWEEN ? AND ?'
                );
                $bookedOutside->execute([$scheduleId, $startDate, $endDate]);
                if ((int)$bookedOutside->fetchColumn() > 0) {
                    throw new RuntimeException('The dates cannot be changed because active appointments are already booked outside the new range.');
                }
                $save = $database->prepare(
                    'UPDATE enrollment_schedules
                     SET year_level = ?, week_number = ?, start_date = ?, end_date = ?, status = ?
                     WHERE schedule_id = ?'
                );
                $save->execute([
                    $assignment['year_level'],
                    $assignment['week_number'],
                    $startDate,
                    $endDate,
                    $status,
                    $scheduleId,
                ]);
            } else {
                $save = $database->prepare(
                    'INSERT INTO enrollment_schedules (year_level, week_number, start_date, end_date, status)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $save->execute([
                    $assignment['year_level'],
                    $assignment['week_number'],
                    $startDate,
                    $endDate,
                    $status,
                ]);
                $scheduleId = (int)$database->lastInsertId();
            }

            $removeDays = $database->prepare(
                'DELETE FROM enrollment_schedule_days
                 WHERE schedule_id = ? AND appointment_date NOT BETWEEN ? AND ?'
            );
            $removeDays->execute([$scheduleId, $startDate, $endDate]);
            $capacityInsert = $database->prepare(
                'INSERT INTO enrollment_daily_capacity (appointment_date, is_open)
                 VALUES (?, 1)
                 ON DUPLICATE KEY UPDATE is_open = 1'
            );
            $dayInsert = $database->prepare(
                'INSERT INTO enrollment_schedule_days (schedule_id, appointment_date, is_open)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_open = VALUES(is_open)'
            );
            $slotInsert = $database->prepare(
                'INSERT IGNORE INTO enrollment_appointment_slots (slot_date, slot_time, capacity, is_open)
                 VALUES (?, ?, 150, 1)'
            );
            $defaultTimes = ['08:00:00', '09:00:00', '10:00:00', '13:00:00', '14:00:00'];
            for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
                if ((int)$day->format('N') > 5) {
                    continue;
                }
                $dayString = $day->format('Y-m-d');
                $capacityInsert->execute([$dayString]);
                $dayInsert->execute([$scheduleId, $dayString, $status === 'Active' ? 1 : 0]);
                if ($status === 'Active') {
                    foreach ($defaultTimes as $time) {
                        $slotInsert->execute([$dayString, $time]);
                    }
                }
            }
            $database->commit();
            header('Location: schedules.php?saved=1', true, 303);
            exit;
        }

        if ($action === 'save_slot') {
            $date = $_POST['slot_date'] ?? '';
            $time = $_POST['slot_time'] ?? '';
            $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);
            $isOpen = isset($_POST['is_open']) ? 1 : 0;
            $validDate = is_string($date) ? DateTime::createFromFormat('!Y-m-d', $date) : false;
            $validTime = is_string($time) ? DateTime::createFromFormat('!H:i', $time) : false;
            if (
                !$validDate || $validDate->format('Y-m-d') !== $date ||
                !$validTime || $validTime->format('H:i') !== $time ||
                !$capacity || $capacity > 150
            ) {
                throw new RuntimeException('Enter a valid date, time, and time-slot capacity from 1 to 150.');
            }

            $database->beginTransaction();
            $dayQuery = $database->prepare(
                'SELECT days.schedule_day_id
                 FROM enrollment_schedule_days AS days
                 INNER JOIN enrollment_schedules AS schedules ON schedules.schedule_id = days.schedule_id
                 WHERE days.appointment_date = ? AND days.is_open = 1 AND schedules.status = \'Active\'
                 LIMIT 1 FOR UPDATE'
            );
            $dayQuery->execute([$date]);
            if (!$dayQuery->fetchColumn()) {
                throw new RuntimeException('The selected date is not an open day in an active enrollment schedule.');
            }
            $activeOnSlot = enrollment_slot_active_count($database, $date, $time . ':00');
            if ($capacity < $activeOnSlot) {
                throw new RuntimeException('The slot capacity cannot be less than the number of active appointments already booked.');
            }
            $saveSlot = $database->prepare(
                'INSERT INTO enrollment_appointment_slots (slot_date, slot_time, capacity, is_open)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE capacity = VALUES(capacity), is_open = VALUES(is_open)'
            );
            $saveSlot->execute([$date, $time . ':00', $capacity, $isOpen]);
            $database->commit();
            header('Location: schedules.php?saved=1#time-slots', true, 303);
            exit;
        }

        if ($action === 'toggle_slot') {
            $slotId = filter_var($_POST['slot_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$slotId) {
                throw new RuntimeException('Select a valid appointment time slot.');
            }
            $updateSlot = $database->prepare(
                'UPDATE enrollment_appointment_slots SET is_open = IF(is_open = 1, 0, 1) WHERE id = ?'
            );
            $updateSlot->execute([$slotId]);
            if ($updateSlot->rowCount() !== 1) {
                throw new RuntimeException('The appointment time slot could not be updated.');
            }
            header('Location: schedules.php?saved=1#time-slots', true, 303);
            exit;
        }

        throw new RuntimeException('Unknown schedule action.');
    }

    if (isset($_GET['saved'])) {
        $successMessage = 'Schedule information saved.';
    }
    $scheduleQuery = $database->prepare(
        'SELECT schedules.schedule_id, schedules.year_level, schedules.week_number,
                schedules.start_date, schedules.end_date, schedules.status,
                days.appointment_date, capacity.daily_capacity, days.is_open,
                COUNT(appointments.id) AS active_count
         FROM enrollment_schedules AS schedules
         LEFT JOIN enrollment_schedule_days AS days ON days.schedule_id = schedules.schedule_id
         LEFT JOIN enrollment_daily_capacity AS capacity
           ON capacity.appointment_date = days.appointment_date
         LEFT JOIN appointments
           ON DATE(appointments.scheduled_at) = days.appointment_date
           AND appointments.appointment_type = \'Enrollment Appointment\'
           AND appointments.appointment_status_id IN (
             SELECT id FROM appointment_status
             WHERE status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')
           )
         GROUP BY schedules.schedule_id, schedules.year_level, schedules.week_number,
                  schedules.start_date, schedules.end_date, schedules.status,
                  days.appointment_date, capacity.daily_capacity, days.is_open
         ORDER BY schedules.week_number, schedules.start_date'
    );
    $scheduleQuery->execute();
    $scheduleRows = $scheduleQuery->fetchAll();
    $scheduleGroups = [];
    foreach ($scheduleRows as $row) {
        $scheduleGroups[(int)$row['schedule_id']][] = $row;
    }
    $slotsQuery = $database->prepare(
        'SELECT slots.id, slots.slot_date, slots.slot_time, slots.capacity, slots.is_open,
                COUNT(appointments.id) AS active_count
         FROM enrollment_appointment_slots AS slots
         LEFT JOIN appointments
           ON appointments.scheduled_at = TIMESTAMP(slots.slot_date, slots.slot_time)
           AND appointments.appointment_type = \'Enrollment Appointment\'
           AND appointments.appointment_status_id IN (
             SELECT id FROM appointment_status
             WHERE status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')
           )
         GROUP BY slots.id, slots.slot_date, slots.slot_time, slots.capacity, slots.is_open
         ORDER BY slots.slot_date, slots.slot_time
         LIMIT 500'
    );
    $slotsQuery->execute();
    $slots = $slotsQuery->fetchAll();
} catch (Throwable $error) {
    if (isset($database) && $database instanceof PDO && $database->inTransaction()) {
        $database->rollBack();
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !headers_sent() && http_response_code() < 400) {
        http_response_code(400);
    }
    error_log('Enrollment schedule management failed: ' . $error->getMessage());
    $errorMessage = $error->getMessage();
    $scheduleGroups = $scheduleGroups ?? [];
    $slots = $slots ?? [];
    if (!isset($admin) || !$admin) {
        header('Location: ' . $loginUrl, true, 303);
        exit;
    }
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
  <title>Schedules and capacity | EnrollEase</title>
  <link rel="stylesheet" href="../admin-dashboard.css">
  <style>
    .content { max-width: 1500px; }
    .toolbar, .form-row { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; }
    .schedule-card { margin: 18px 0; padding: 18px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
    .schedule-card h2 { margin-top: 0; }
    .form-row label { display: grid; gap: 5px; }
    .form-row input, .form-row select { min-height: 36px; padding: 0 9px; border: 1px solid var(--line); border-radius: 7px; }
    .notice { margin: 12px 0; padding: 10px 12px; border-radius: 7px; background: #f5f7fb; }
    .alert { margin: 12px 0; padding: 10px 12px; border-radius: 7px; background: #fff0f0; color: #9c3842; }
    .success { background: #eefaf2; color: #226b3a; }
    .logout-form { margin: 0; }
  </style>
</head>
<body>
<div class="app-shell">
  <aside class="sidebar" id="adminSidebar">
    <a class="brand" href="../landing.html"><span class="brand-mark">E</span><span class="brand-name">enroll<span>ease</span></span></a>
    <div class="nav-label">Administration</div>
    <nav class="nav-list" aria-label="Admin navigation">
      <a class="nav-link" href="dashboard.php?section=appointments"><span class="nav-icon">▦</span>Enrollment appointments</a>
      <a class="nav-link" href="dashboard.php?section=eligibility"><span class="nav-icon">✓</span>Eligibility rules</a>
      <a class="nav-link" href="dashboard.php?section=eligibility-records"><span class="nav-icon">♙</span>Student eligibility records</a>
      <a class="nav-link" href="dashboard.php?section=students"><span class="nav-icon">♙</span>Student Management</a>
      <a class="nav-link" href="dashboard.php?section=admins"><span class="nav-icon">👥</span>Admin accounts</a>
      <a class="nav-link" href="dashboard.php?section=enrollments"><span class="nav-icon">▧</span>Enrollment records</a>
      <a class="nav-link active" href="schedules.php"><span class="nav-icon">▣</span>Schedules and capacity</a>
    </nav>
    <div class="sidebar-bottom"><div class="role-card"><small>Shared administrator</small><strong><?= h($admin['name']) ?></strong><span class="role-badge">Administrator</span></div></div>
  </aside>
  <button class="sidebar-overlay" id="adminSidebarOverlay" type="button" aria-label="Close admin navigation"></button>
  <div class="main-column">
    <header class="topbar">
      <div class="topbar-left">
        <button class="admin-menu-toggle" id="adminMenuButton" type="button" aria-label="Open admin navigation" aria-controls="adminSidebar" aria-expanded="false">☰</button>
        <span class="breadcrumb">Administration /</span><strong>Schedules and capacity</strong>
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
      <section class="page-heading"><div><p class="eyebrow">ADMIN PORTAL</p><h1>Enrollment schedules and capacity</h1>
        <p class="heading-subtitle">A student's database year level determines their only bookable week. Each date is capped at 150 active appointments.</p></div></section>
      <?php if ($errorMessage !== ''): ?><div class="alert" role="alert"><?= h($errorMessage) ?></div><?php endif; ?>
      <?php if ($successMessage !== ''): ?><div class="notice success" role="status"><?= h($successMessage) ?></div><?php endif; ?>

      <section class="schedule-card">
        <h2>Add an enrollment schedule</h2>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_csrf_token']) ?>">
          <input type="hidden" name="action" value="save_schedule">
          <div class="form-row">
            <label>Year level
              <select name="year_level" required>
                <option value="First Year">First Year — Week 1</option>
                <option value="Second Year">Second Year — Week 2</option>
                <option value="Third Year">Third Year — Week 3</option>
                <option value="Fourth Year">Fourth Year — Week 4</option>
                <option value="Late Enrollment">Late Enrollment — Week 5</option>
              </select>
            </label>
            <label>Start date (Monday)<input name="start_date" type="date" required></label>
            <label>End date (Friday)<input name="end_date" type="date" required></label>
            <label>Status
              <select name="status"><option>Active</option><option>Inactive</option></select>
            </label>
            <button class="button primary" type="submit">Add schedule</button>
          </div>
        </form>
      </section>

      <h2>Year-level schedules</h2>
      <?php foreach ($scheduleGroups as $scheduleDays): ?>
        <?php $schedule = $scheduleDays[0]; ?>
        <section class="schedule-card">
          <h2><?= h($schedule['year_level']) ?> · Week <?= (int)$schedule['week_number'] ?> · <?= h($schedule['status']) ?></h2>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_csrf_token']) ?>">
            <input type="hidden" name="action" value="save_schedule">
            <input type="hidden" name="schedule_id" value="<?= (int)$schedule['schedule_id'] ?>">
            <input type="hidden" name="year_level" value="<?= h($schedule['year_level']) ?>">
            <div class="form-row">
              <label>Start date (Monday)<input name="start_date" type="date" value="<?= h($schedule['start_date']) ?>" required></label>
              <label>End date (Friday)<input name="end_date" type="date" value="<?= h($schedule['end_date']) ?>" required></label>
              <label>Status
                <select name="status">
                  <option <?= $schedule['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                  <option <?= $schedule['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
              </label>
              <button class="button primary" type="submit">Save schedule</button>
            </div>
          </form>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Date</th><th>Daily bookings</th><th>Remaining</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach ($scheduleDays as $day): ?>
                <tr>
                  <td><?= h($day['appointment_date']) ?></td>
                  <td><?= (int)$day['active_count'] ?> / <?= (int)$day['daily_capacity'] ?></td>
                  <td><?= max(0, (int)$day['daily_capacity'] - (int)$day['active_count']) ?></td>
                  <td><?= $day['is_open'] ? ((int)$day['active_count'] >= 150 ? 'FULL' : 'Open') : 'Closed' ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endforeach; ?>

      <section class="schedule-card" id="time-slots">
        <h2>Manage appointment times</h2>
        <p class="notice">The combined active appointments for any date are still limited to 150 across every time slot.</p>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_csrf_token']) ?>">
          <input type="hidden" name="action" value="save_slot">
          <div class="form-row">
            <label>Date<input name="slot_date" type="date" required></label>
            <label>Time<input name="slot_time" type="time" required></label>
            <label>Time-slot capacity<input name="capacity" type="number" min="1" max="150" value="150" required></label>
            <label>Open for booking<input name="is_open" type="checkbox" checked></label>
            <button class="button primary" type="submit">Add or update time</button>
          </div>
        </form>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Date</th><th>Time</th><th>Time capacity</th><th>Active bookings</th><th>Availability</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($slots as $slot): ?>
              <tr>
                <td><?= h($slot['slot_date']) ?></td><td><?= h(date('g:i A', strtotime($slot['slot_time']))) ?></td>
                <td><?= (int)$slot['capacity'] ?></td><td><?= (int)$slot['active_count'] ?></td>
                <td><?= $slot['is_open'] ? 'Open' : 'Closed' ?></td>
                <td><form method="post">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_csrf_token']) ?>">
                  <input type="hidden" name="action" value="toggle_slot">
                  <input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>">
                  <button class="button small" type="submit"><?= $slot['is_open'] ? 'Close' : 'Open' ?></button>
                </form></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$slots): ?><tr><td colspan="6">No appointment times are configured.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>
</div>
<script src="../js/admin-panel.js" defer></script>
</body>
</html>
