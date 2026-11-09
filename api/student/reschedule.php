<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../enrollment-eligibility.php';
student_session_start();
student_require_method('POST');
student_verify_origin();
student_verify_csrf();

$request = student_read_json();
$referenceNumber = trim(is_string($request['referenceNumber'] ?? null) ? $request['referenceNumber'] : '');
$date = is_string($request['date'] ?? null) ? $request['date'] : '';
$time = is_string($request['time'] ?? null) ? $request['time'] : '';
$validDate = DateTime::createFromFormat('!Y-m-d', $date);
$validTime = DateTime::createFromFormat('!H:i', $time);
if (
    $referenceNumber === '' || strlen($referenceNumber) > 40 ||
    !$validDate || $validDate->format('Y-m-d') !== $date ||
    !$validTime || $validTime->format('H:i') !== $time
) {
    student_json_response(['success' => false, 'message' => 'Select a valid appointment and enrollment date and time.'], 400);
}

try {
    $database = admin_database();
    $profile = student_require_profile($database);
    $database->beginTransaction();

    $studentLock = $database->prepare(
        'SELECT s.id, s.student_status, s.student_number, s.year_level,
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
    $profile['year_level'] = $lockedStudent['year_level'];
    $profile['student_number'] = $lockedStudent['student_number'];

    $eligibility = enrollment_eligibility($database, (int)$profile['student_id']);
    if (!$eligibility['eligible']) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'eligible' => false,
            'eligibility' => $eligibility,
            'message' => 'Your enrollment appointment cannot be rescheduled until the listed requirements are resolved.',
        ], 403);
    }

    $appointmentQuery = $database->prepare(
        'SELECT a.id, a.reference_number, a.scheduled_at, a.appointment_status_id,
                statuses.status_code, statuses.status_name
         FROM appointments AS a
         INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
         WHERE a.student_id = ? AND a.reference_number = ?
           AND a.appointment_type = \'Enrollment Appointment\'
         LIMIT 1'
    );
    $appointmentQuery->execute([(int)$profile['student_id'], $referenceNumber]);
    $appointment = $appointmentQuery->fetch();
    $activeStatuses = ['submitted', 'pending', 'approved', 'confirmed', 'rescheduled'];
    if (!$appointment || !in_array($appointment['status_code'], $activeStatuses, true)) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'That active appointment could not be found.'], 404);
    }

    $appointmentId = (int)$appointment['id'];
    $originalScheduledAt = (string)$appointment['scheduled_at'];
    $originalDate = substr($originalScheduledAt, 0, 10);
    $originalTime = substr($originalScheduledAt, 11, 8);
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
            'message' => !empty($schedule['is_late'])
                ? 'You missed your assigned enrollment week. You can only reschedule to Late Enrollment, which opens on ' . $schedule['start_date'] . ' (Week 5).'
                : 'Booking has not opened yet for your year level (opens on ' . $schedule['start_date'] . ').',
        ], 422);
    }
    if ($currentDate > $schedule['end_date']) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => 'The enrollment booking window has ended (closed on ' . $schedule['end_date'] . '). Please contact the registrar.',
        ], 422);
    }
    if ($date < $currentDate) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'Past enrollment dates cannot be selected.'], 400);
    }
    if ($date < $schedule['start_date'] || $date > $schedule['end_date']) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => !empty($schedule['is_late'])
                ? 'You missed your assigned enrollment week. You can only reschedule to Late Enrollment (' . $schedule['start_date'] . ' to ' . $schedule['end_date'] . ').'
                : 'The selected appointment date falls outside your assigned enrollment week (' . $schedule['start_date'] . ' to ' . $schedule['end_date'] . ').',
        ], 422);
    }
    if ($date === $originalDate && $time . ':00' === $originalTime) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'Choose a different appointment date or time.'], 400);
    }

    $lockedDays = enrollment_lock_schedule_days($database, (string)$profile['year_level'], [
        $originalDate,
        $date,
    ]);
    $scheduleDay = $lockedDays[$date] ?? null;
    if (
        !$scheduleDay ||
        $scheduleDay['schedule_id'] != $schedule['schedule_id'] ||
        $scheduleDay['schedule_status'] !== 'Active' ||
        !(bool)$scheduleDay['day_open'] ||
        !(bool)$scheduleDay['capacity_open'] ||
        $date < $scheduleDay['start_date'] ||
        $date > $scheduleDay['end_date']
    ) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'This enrollment date is not open for appointments.'], 403);
    }

    $slotQuery = $database->prepare(
        'SELECT slots.capacity
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

    $appointmentLock = $database->prepare(
        'SELECT a.id, a.scheduled_at, a.appointment_status_id, statuses.status_code
         FROM appointments AS a
         INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
         WHERE a.id = ? AND a.student_id = ? AND a.reference_number = ?
         LIMIT 1 FOR UPDATE'
    );
    $appointmentLock->execute([$appointmentId, (int)$profile['student_id'], $referenceNumber]);
    $lockedAppointment = $appointmentLock->fetch();
    if (
        !$lockedAppointment ||
        $lockedAppointment['scheduled_at'] !== $originalScheduledAt ||
        !in_array($lockedAppointment['status_code'], $activeStatuses, true)
    ) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'The appointment changed. Reload your schedule and try again.'], 409);
    }

    $otherAppointment = $database->prepare(
        'SELECT a.id
         FROM appointments AS a
         INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
         WHERE a.student_id = ? AND a.appointment_type = \'Enrollment Appointment\'
           AND a.id <> ?
           AND statuses.status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')
         LIMIT 1 FOR UPDATE'
    );
    $otherAppointment->execute([(int)$profile['student_id'], $appointmentId]);
    if ($otherAppointment->fetchColumn()) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'Another active enrollment appointment already exists. Contact the registrar.'], 409);
    }

    $dailyBooked = enrollment_daily_active_count($database, $date, $appointmentId);
    if ($dailyBooked >= min(150, (int)$scheduleDay['daily_capacity'])) {
        $database->rollBack();
        student_json_response([
            'success' => false,
            'message' => 'Sorry, the appointment capacity for this date has been reached.',
        ], 409);
    }
    if (enrollment_slot_active_count($database, $date, $time . ':00', $appointmentId) >= (int)$slot['capacity']) {
        $database->rollBack();
        student_json_response(['success' => false, 'message' => 'That enrollment appointment time is full. Please choose another time.'], 409);
    }

    $statusQuery = $database->prepare('SELECT id FROM appointment_status WHERE status_code = ? LIMIT 1');
    $statusQuery->execute(['rescheduled']);
    $rescheduledStatusId = $statusQuery->fetchColumn();
    if (!$rescheduledStatusId) {
        throw new RuntimeException('The rescheduled appointment status is not configured.');
    }

    $update = $database->prepare(
        'UPDATE appointments
         SET scheduled_at = TIMESTAMP(?, ?), appointment_status_id = ?, updated_by = ?
         WHERE id = ? AND student_id = ?'
    );
    $update->execute([
        $date,
        $time . ':00',
        (int)$rescheduledStatusId,
        (int)$profile['user_id'],
        $appointmentId,
        (int)$profile['student_id'],
    ]);
    $history = $database->prepare(
        'INSERT INTO appointment_status_history (appointment_id, from_status_id, to_status_id, changed_by, note)
         VALUES (?, ?, ?, ?, ?)'
    );
    $history->execute([
        $appointmentId,
        (int)$lockedAppointment['appointment_status_id'],
        (int)$rescheduledStatusId,
        (int)$profile['user_id'],
        'Rescheduled by student.',
    ]);
    $notification = $database->prepare(
        'INSERT INTO notifications
         (user_id, appointment_id, notification_type, subject, message, delivery_channel, delivery_status)
         VALUES (?, ?, \'enrollment_appointment_rescheduled\', ?, ?, \'email\', \'queued\')'
    );
    $notification->execute([
        (int)$profile['user_id'],
        $appointmentId,
        'Enrollment appointment rescheduled',
        'Enrollment appointment rescheduled; email delivery is pending.',
    ]);
    $notificationId = (int)$database->lastInsertId();
    $database->commit();

    $updatedAppointment = [
        'reference_number' => $referenceNumber,
        'scheduled_at' => $date . ' ' . $time . ':00',
        'week_start' => $schedule['start_date'],
        'week_end' => $schedule['end_date'],
    ];
    $emailSent = false;
    if (student_mail_is_configured()) {
        try {
            $emailSent = student_send_appointment_email($profile, $updatedAppointment, 'rescheduled');
            $message = $emailSent
                ? 'Enrollment appointment rescheduled. A confirmation email was sent.'
                : 'Enrollment appointment rescheduled, but the confirmation email could not be sent. Please contact the registrar.';
        } catch (Throwable $error) {
            error_log('Enrollment appointment was rescheduled but notification email failed: ' . $error->getMessage());
            $emailSent = false;
            $message = 'Enrollment appointment rescheduled, but the confirmation email could not be sent. Please contact the registrar.';
        }
    } else {
        $message = 'Enrollment appointment rescheduled. Email notifications are not enabled.';
    }
    try {
        $notificationUpdate = $database->prepare(
            'UPDATE notifications
             SET message = ?, delivery_status = ?, sent_at = ?
             WHERE id = ?'
        );
        $notificationUpdate->execute([
            $message,
            $emailSent ? 'sent' : 'failed',
            $emailSent ? date('Y-m-d H:i:s') : null,
            $notificationId,
        ]);
    } catch (Throwable $error) {
        error_log('Enrollment appointment reschedule notification status update failed: ' . $error->getMessage());
    }

    student_json_response([
        'success' => true,
        'appointment' => [
            'referenceNumber' => $referenceNumber,
            'date' => $date,
            'time' => date('g:i A', strtotime($time)),
            'status' => 'Rescheduled',
            'emailSent' => $emailSent,
        ],
        'message' => $message,
    ]);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('Enrollment appointment reschedule failed: ' . $error->getMessage());
    student_json_response(
        student_debug_error_detail(
            ['success' => false, 'message' => 'The enrollment appointment could not be rescheduled. Please try again later.'],
            $error
        ),
        503
    );
}
