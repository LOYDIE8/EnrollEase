<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../enrollment-eligibility.php';
student_session_start();
student_require_method('GET');

try {
    $database = admin_database();
    $profile = student_require_profile($database);
    $excludeReference = trim(is_string($_GET['excludeReference'] ?? null) ? $_GET['excludeReference'] : '');
    if (strlen($excludeReference) > 40) {
        student_json_response(['success' => false, 'message' => 'The appointment reference is invalid.'], 400);
    }
    $excludeAppointmentId = null;
    if ($excludeReference !== '') {
        $excludeQuery = $database->prepare(
            'SELECT a.id
             FROM appointments AS a
             INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
             WHERE a.student_id = ? AND a.reference_number = ?
               AND a.appointment_type = \'Enrollment Appointment\'
               AND statuses.status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')
             LIMIT 1'
        );
        $excludeQuery->execute([(int)$profile['student_id'], $excludeReference]);
        $excludeAppointmentId = $excludeQuery->fetchColumn();
        if ($excludeAppointmentId === false) {
            student_json_response(['success' => false, 'message' => 'That active appointment could not be found.'], 404);
        }
        $excludeAppointmentId = (int)$excludeAppointmentId;
    }

    $eligibility = enrollment_eligibility($database, (int)$profile['student_id']);
    if (!$eligibility['eligible']) {
        student_json_response([
            'success' => true,
            'eligible' => false,
            'student' => student_public_profile($profile),
            'eligibility' => $eligibility,
            'schedule' => null,
            'slots' => [],
        ]);
    }

    $schedule = enrollment_schedule_for_student($database, (string)$profile['year_level']);
    if (!$schedule) {
        student_json_response([
            'success' => true,
            'eligible' => true,
            'student' => student_public_profile($profile),
            'schedule' => null,
            'slots' => [],
            'message' => 'There is no active enrollment schedule for your year level. Please contact the registrar.',
        ]);
    }

    $currentDateQuery = $database->prepare('SELECT CURRENT_DATE');
    $currentDateQuery->execute();
    $currentDate = (string)$currentDateQuery->fetchColumn();
    if ($currentDate < $schedule['start_date']) {
        $msg = !empty($schedule['is_late'])
            ? 'You missed your assigned enrollment week (Week ' . ($schedule['missed_week'] ?? '') . '). You can only enroll during Late Enrollment, which opens on ' . $schedule['start_date'] . ' (Week 5).'
            : 'Booking has not opened yet for your year level (opens on ' . $schedule['start_date'] . ').';
        student_json_response([
            'success' => false,
            'eligible' => true,
            'isLateEnrollment' => !empty($schedule['is_late']),
            'student' => student_public_profile($profile),
            'schedule' => $schedule,
            'slots' => [],
            'message' => $msg,
        ], 422);
    }
    if ($currentDate > $schedule['end_date']) {
        student_json_response([
            'success' => false,
            'eligible' => true,
            'isLateEnrollment' => !empty($schedule['is_late']),
            'student' => student_public_profile($profile),
            'schedule' => $schedule,
            'slots' => [],
            'message' => 'The enrollment booking window has ended (closed on ' . $schedule['end_date'] . '). Please contact the registrar.',
        ], 422);
    }

    $activeQuery = $database->prepare(
        'SELECT 1
         FROM appointments AS a
         INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
         WHERE a.student_id = ? AND a.appointment_type = \'Enrollment Appointment\'
           AND statuses.status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')' .
            ($excludeAppointmentId === null ? '' : ' AND a.id <> ?') . '
         LIMIT 1'
    );
    $activeQuery->execute($excludeAppointmentId === null
        ? [(int)$profile['student_id']]
        : [(int)$profile['student_id'], $excludeAppointmentId]);
    if ($activeQuery->fetchColumn()) {
        student_json_response([
            'success' => true,
            'eligible' => true,
            'student' => student_public_profile($profile),
            'schedule' => $schedule,
            'slots' => [],
            'message' => 'You already have an active enrollment appointment.',
        ]);
    }

    $excludeSlot = $excludeAppointmentId === null ? '' : ' AND booked.id <> ?';
    $excludeDaily = $excludeAppointmentId === null ? '' : ' AND a.id <> ?';
    $query = $database->prepare(
        'SELECT slots.slot_date, slots.slot_time, slots.capacity,
                capacity.daily_capacity, COUNT(booked.id) AS slot_booked,
                COALESCE(daily.daily_booked, 0) AS daily_booked,
                GREATEST(
                    0,
                    LEAST(
                        slots.capacity - COUNT(booked.id),
                        LEAST(150, capacity.daily_capacity) - COALESCE(daily.daily_booked, 0)
                    )
                ) AS remaining
         FROM enrollment_appointment_slots AS slots
         INNER JOIN enrollment_schedule_days AS days
           ON days.appointment_date = slots.slot_date AND days.schedule_id = ?
         INNER JOIN enrollment_daily_capacity AS capacity
           ON capacity.appointment_date = days.appointment_date AND capacity.is_open = 1
         INNER JOIN enrollment_schedules AS schedules
           ON schedules.schedule_id = days.schedule_id
         LEFT JOIN appointments AS booked
           ON booked.scheduled_at = TIMESTAMP(slots.slot_date, slots.slot_time)
           AND booked.appointment_type = \'Enrollment Appointment\'
           AND booked.appointment_status_id IN (
             SELECT id FROM appointment_status
             WHERE status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')
           )' . $excludeSlot . '
         LEFT JOIN (
           SELECT DATE(a.scheduled_at) AS appointment_date, COUNT(*) AS daily_booked
           FROM appointments AS a
           INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
           WHERE a.appointment_type = \'Enrollment Appointment\'
             AND statuses.status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')
             ' . $excludeDaily . '
           GROUP BY DATE(a.scheduled_at)
         ) AS daily ON daily.appointment_date = slots.slot_date
         WHERE schedules.status = \'Active\' AND days.is_open = 1
           AND slots.is_open = 1 AND slots.slot_date >= CURRENT_DATE
         GROUP BY slots.id, slots.slot_date, slots.slot_time, slots.capacity,
                  capacity.daily_capacity, daily.daily_booked
         ORDER BY slots.slot_date, slots.slot_time'
    );
    $parameters = [(int)$schedule['schedule_id']];
    if ($excludeAppointmentId !== null) {
        $parameters[] = $excludeAppointmentId;
        $parameters[] = $excludeAppointmentId;
    }
    $query->execute($parameters);
    $slots = array_map(static function (array $row): array {
        return [
            'date' => $row['slot_date'],
            'time' => substr($row['slot_time'], 0, 5),
            'remaining' => (int)$row['remaining'],
            'dailyBooked' => (int)$row['daily_booked'],
            'dailyCapacity' => min(150, (int)$row['daily_capacity']),
            'dailyRemaining' => max(0, min(150, (int)$row['daily_capacity']) - (int)$row['daily_booked']),
        ];
    }, $query->fetchAll());

    student_json_response([
        'success' => true,
        'eligible' => true,
        'student' => student_public_profile($profile),
        'schedule' => $schedule,
        'slots' => $slots,
    ]);
} catch (Throwable $error) {
    error_log('Enrollment slot lookup failed: ' . $error->getMessage());
    student_json_response(
        student_debug_error_detail(['success' => false, 'message' => 'Available enrollment slots could not be loaded.'], $error),
        503
    );
}
