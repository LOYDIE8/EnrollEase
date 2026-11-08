<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../enrollment-eligibility.php';
student_session_start();
student_require_method('POST');
student_verify_origin();
student_verify_csrf();

$request = student_read_json();
$date = is_string($request['date'] ?? null) ? $request['date'] : '';
$time = is_string($request['time'] ?? null) ? $request['time'] : '';
$validDate = DateTime::createFromFormat('!Y-m-d', $date);
$validTime = DateTime::createFromFormat('!H:i', $time);
if (
    !$validDate || $validDate->format('Y-m-d') !== $date ||
    !$validTime || $validTime->format('H:i') !== $time
) {
    student_json_response(['success' => false, 'message' => 'Select a valid enrollment appointment date and time.'], 400);
}

try {
    $database = admin_database();
    $profile = student_require_profile($database);
    $database->beginTransaction();

    $studentLock = $database->prepare(
        'SELECT s.id, s.student_number, s.year_level, s.student_status,
                campuses.campus_name
         FROM students AS s
         LEFT JOIN campuses ON campuses.campus_id = s.campus_id
         WHERE s.id = ? AND s.user_id = ? FOR UPDATE'
    );
    $studentLock->execute([(int)$profile['student_id'], (int)$profile['user_id']]);
    $lockedStudent = $studentLock->fetch();
    if (!$lockedStudent) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'The student record could not be verified.'], 403);
    }
    if ($lockedStudent['campus_name'] !== 'Daanbantayan Campus') {
        $database->rollBack();
        student_campus_access_denied();
    }
    if ($lockedStudent['student_status'] !== 'Active') {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => 'Your student account is currently inactive. Please contact the registrar.',
        ], 403);
    }
    if (!preg_match('/\A(?:824[0-9]{4}|[0-9]{12})\z/', (string)$lockedStudent['student_number'])) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => 'Enter a 7-digit CTU Student ID beginning with 824 or a 12-digit LRN.',
        ], 403);
    }
    $profile['year_level'] = $lockedStudent['year_level'];
    $profile['student_number'] = $lockedStudent['student_number'];

    $eligibility = enrollment_eligibility($database, (int)$profile['student_id']);
    if (!$eligibility['eligible']) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'eligible' => false,
            'eligibility' => $eligibility,
            'message' => 'Enrollment appointment booking is unavailable until the listed requirements are resolved.',
        ], 403);
    }

    $activeQuery = $database->prepare(
        'SELECT a.reference_number
         FROM appointments AS a
         INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
         WHERE a.student_id = ? AND a.appointment_type = \'Enrollment Appointment\'
           AND statuses.status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')
         LIMIT 1 FOR UPDATE'
    );
    $activeQuery->execute([(int)$profile['student_id']]);
    if ($activeQuery->fetch()) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'You already have an active enrollment appointment.'], 409);
    }

    $schedule = enrollment_schedule_for_student($database, (string)$profile['year_level']);
    if (!$schedule) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'There is no active enrollment schedule for your year level.'], 409);
    }
    $currentDateQuery = $database->prepare('SELECT CURRENT_DATE');
    $currentDateQuery->execute();
    $currentDate = (string)$currentDateQuery->fetchColumn();
    if ($currentDate < $schedule['start_date']) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => 'Booking has not opened yet for your year level (opens on ' . $schedule['start_date'] . ').',
        ], 422);
    }
    if ($currentDate > $schedule['end_date']) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => 'The booking window for your year level has closed (closed on ' . $schedule['end_date'] . ').',
        ], 422);
    }
    if ($date < $currentDate) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'Past enrollment dates cannot be booked.'], 400);
    }
    if ($date < $schedule['start_date'] || $date > $schedule['end_date']) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => 'The selected appointment date falls outside your assigned enrollment week (' . $schedule['start_date'] . ' to ' . $schedule['end_date'] . ').',
        ], 422);
    }

    $scheduleDay = enrollment_lock_schedule_day($database, (string)$profile['year_level'], $date);
    if (!$scheduleDay) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => 'The selected appointment date falls outside your assigned enrollment week (' . $schedule['start_date'] . ' to ' . $schedule['end_date'] . ').',
        ], 422);
    }

    $slotQuery = $database->prepare(
        'SELECT slots.id, slots.capacity
         FROM enrollment_appointment_slots AS slots
         WHERE slots.slot_date = ? AND slots.slot_time = ? AND slots.is_open = 1
           AND slots.slot_date >= CURRENT_DATE
         LIMIT 1 FOR UPDATE'
    );
    $slotQuery->execute([$date, $time . ':00']);
    $slot = $slotQuery->fetch();
    if (!$slot) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'That enrollment appointment time is no longer available. Please choose another.'], 409);
    }

    $dailyBooked = enrollment_daily_active_count($database, $date);
    $dailyCapacity = min(150, (int)$scheduleDay['daily_capacity']);
    if ($dailyBooked >= $dailyCapacity) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => 'Sorry, the appointment capacity for this date has been reached.',
        ], 409);
    }
    if (enrollment_slot_active_count($database, $date, $time . ':00') >= (int)$slot['capacity']) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'That enrollment appointment time is full. Please choose another time.'], 409);
    }

    $registrarQuery = $database->prepare('SELECT id FROM departments WHERE department_code = ? LIMIT 1');
    $registrarQuery->execute(['Registrar']);
    $departmentId = $registrarQuery->fetchColumn();
    $pendingQuery = $database->prepare('SELECT id FROM appointment_status WHERE status_code = ? LIMIT 1');
    $pendingQuery->execute(['pending']);
    $pendingStatusId = $pendingQuery->fetchColumn();
    if (!$departmentId || !$pendingStatusId) {
        throw new RuntimeException('Enrollment appointment configuration is incomplete.');
    }

    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
    $insert = $database->prepare(
        'INSERT INTO appointments
         (public_id, reference_number, student_id, department_id, appointment_status_id,
          appointment_type, scheduled_at, created_by)
         VALUES (?, NULL, ?, ?, ?, \'Enrollment Appointment\', TIMESTAMP(?, ?), ?)'
    );
    $insert->execute([
        $uuid, (int)$profile['student_id'], (int)$departmentId,
        (int)$pendingStatusId, $date, $time . ':00', (int)$profile['user_id'],
    ]);
    $appointmentId = (int)$database->lastInsertId();
    $reference = 'ENR-' . date('Y', strtotime($date)) . '-' . str_pad((string)$appointmentId, 4, '0', STR_PAD_LEFT);
    $referenceUpdate = $database->prepare('UPDATE appointments SET reference_number = ? WHERE id = ?');
    $referenceUpdate->execute([$reference, $appointmentId]);

    $history = $database->prepare(
        'INSERT INTO appointment_status_history (appointment_id, from_status_id, to_status_id, changed_by)
         VALUES (?, NULL, ?, ?)'
    );
    $history->execute([$appointmentId, (int)$pendingStatusId, (int)$profile['user_id']]);
    $database->commit();

    $appointment = [
        'reference_number' => $reference,
        'scheduled_at' => $date . ' ' . $time . ':00',
        'status' => 'Pending',
        'week_start' => $schedule['start_date'],
        'week_end' => $schedule['end_date'],
    ];
    $emailSent = false;
    if (student_mail_is_configured()) {
        try {
            $emailSent = student_send_appointment_email($profile, $appointment, 'booked');
            $message = $emailSent
                ? 'Enrollment appointment booked. A confirmation email was sent.'
                : 'Enrollment appointment booked, but the confirmation email could not be sent. Please contact the registrar.';
        } catch (Throwable $error) {
            error_log('Enrollment appointment was saved but confirmation email failed: ' . $error->getMessage());
            $emailSent = false;
            $message = 'Enrollment appointment booked, but the confirmation email could not be sent. Please contact the registrar.';
        }
    } else {
        $message = 'Enrollment appointment booked. Email notifications are not enabled.';
    }

    try {
        $notification = $database->prepare(
            'INSERT INTO notifications
             (user_id, appointment_id, notification_type, subject, message, delivery_channel, delivery_status, sent_at)
             VALUES (?, ?, \'enrollment_appointment_booked\', ?, ?, \'email\', ?, ?)'
        );
        $notification->execute([
            (int)$profile['user_id'], $appointmentId, 'Enrollment appointment booked',
            $message, $emailSent ? 'sent' : 'failed', $emailSent ? date('Y-m-d H:i:s') : null,
        ]);
    } catch (Throwable $error) {
        error_log('Enrollment appointment notification record failed: ' . $error->getMessage());
    }

    student_json_response([
        'success' => true,
        'appointment' => [
            'referenceNumber' => $reference,
            'date' => $date,
            'time' => date('g:i A', strtotime($time)),
            'status' => 'Pending',
            'emailSent' => $emailSent,
        ],
        'message' => $message,
    ], 201);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('Enrollment appointment booking failed: ' . $error->getMessage());
    student_json_response(
        student_debug_error_detail(
            ['success' => false, 'message' => 'The enrollment appointment could not be saved. Please try again later.'],
            $error
        ),
        503
    );
}
