<?php
declare(strict_types=1);

function normalize_year_level(string $yearLevel): ?string
{
    $normalized = strtolower(trim($yearLevel));
    $canonical = [
        '1st year' => '1st Year',
        'first year' => '1st Year',
        '1st' => '1st Year',
        'first' => '1st Year',
        '2nd year' => '2nd Year',
        'second year' => '2nd Year',
        '2nd' => '2nd Year',
        'second' => '2nd Year',
        '3rd year' => '3rd Year',
        'third year' => '3rd Year',
        '3rd' => '3rd Year',
        'third' => '3rd Year',
        '4th year' => '4th Year',
        'fourth year' => '4th Year',
        '4th' => '4th Year',
        'fourth' => '4th Year',
    ];

    return $canonical[$normalized] ?? null;
}

function enrollment_year_assignment(string $yearLevel): ?array
{
    $normalized = strtolower(trim($yearLevel));
    $assignments = [
        'first year' => ['year_level' => 'First Year', 'week_number' => 1],
        '1st year' => ['year_level' => 'First Year', 'week_number' => 1],
        'first' => ['year_level' => 'First Year', 'week_number' => 1],
        'second year' => ['year_level' => 'Second Year', 'week_number' => 2],
        '2nd year' => ['year_level' => 'Second Year', 'week_number' => 2],
        'second' => ['year_level' => 'Second Year', 'week_number' => 2],
        'third year' => ['year_level' => 'Third Year', 'week_number' => 3],
        '3rd year' => ['year_level' => 'Third Year', 'week_number' => 3],
        'third' => ['year_level' => 'Third Year', 'week_number' => 3],
        'fourth year' => ['year_level' => 'Fourth Year', 'week_number' => 4],
        '4th year' => ['year_level' => 'Fourth Year', 'week_number' => 4],
        'fourth' => ['year_level' => 'Fourth Year', 'week_number' => 4],
        'late enrollment' => ['year_level' => 'Late Enrollment', 'week_number' => 5],
        'late' => ['year_level' => 'Late Enrollment', 'week_number' => 5],
        'week 5' => ['year_level' => 'Late Enrollment', 'week_number' => 5],
    ];

    return $assignments[$normalized] ?? null;
}

function enrollment_late_schedule(PDO $database): ?array
{
    $query = $database->prepare(
        'SELECT schedule_id, year_level, week_number, start_date, end_date, status
         FROM enrollment_schedules
         WHERE (week_number = 5 OR LOWER(year_level) = \'late enrollment\')
           AND status = \'Active\'
         LIMIT 1'
    );
    $query->execute();
    $schedule = $query->fetch();
    return $schedule ?: null;
}

function enrollment_schedule_for_student(PDO $database, string $yearLevel, ?string $currentDate = null): ?array
{
    $assignment = enrollment_year_assignment($yearLevel);
    if (!$assignment) {
        return null;
    }

    $canonical = normalize_year_level($yearLevel);
    $query = $database->prepare(
        'SELECT schedule_id, year_level, week_number, start_date, end_date, status
         FROM enrollment_schedules
         WHERE (LOWER(year_level) = LOWER(?) OR LOWER(year_level) = LOWER(?) OR week_number = ?)
           AND status = \'Active\'
         LIMIT 1'
    );
    $query->execute([$assignment['year_level'], $canonical ?? $assignment['year_level'], $assignment['week_number']]);
    $schedule = $query->fetch();
    if (!$schedule) {
        return null;
    }

    if ($currentDate === null) {
        $curDateStmt = $database->query('SELECT CURRENT_DATE');
        $currentDate = (string)$curDateStmt->fetchColumn();
    }

    // If student's assigned cohort week has already ended, route to Week 5 (Late Enrollment)
    if ($currentDate > $schedule['end_date'] && (int)$schedule['week_number'] < 5) {
        $lateSchedule = enrollment_late_schedule($database);
        if ($lateSchedule) {
            $lateSchedule['is_late'] = true;
            $lateSchedule['missed_week'] = (int)$schedule['week_number'];
            $lateSchedule['missed_schedule'] = $schedule;
            return $lateSchedule;
        }
    }

    $schedule['is_late'] = false;
    return $schedule;
}

function enrollment_lock_schedule_day(PDO $database, string $yearLevel, string $date): ?array
{
    $day = enrollment_lock_schedule_days($database, $yearLevel, [$date])[$date] ?? null;
    if (
        !$day ||
        $day['schedule_status'] !== 'Active' ||
        !(bool)$day['day_open'] ||
        !(bool)$day['capacity_open'] ||
        $date < $day['start_date'] ||
        $date > $day['end_date']
    ) {
        return null;
    }

    return $day;
}

function enrollment_lock_schedule_days(PDO $database, string $yearLevel, array $dates): array
{
    $effectiveSchedule = enrollment_schedule_for_student($database, $yearLevel);
    if (!$effectiveSchedule) {
        return [];
    }

    $dates = array_values(array_unique(array_filter(
        $dates,
        static fn($date): bool => is_string($date) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) === 1
    )));
    sort($dates, SORT_STRING);
    if (!$dates) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($dates), '?'));
    $query = $database->prepare(
        'SELECT days.schedule_day_id, days.schedule_id, days.appointment_date,
                days.is_open AS day_open, capacity.daily_capacity,
                capacity.is_open AS capacity_open, schedules.year_level,
                schedules.week_number, schedules.start_date, schedules.end_date,
                schedules.status AS schedule_status
         FROM enrollment_schedule_days AS days
         INNER JOIN enrollment_schedules AS schedules ON schedules.schedule_id = days.schedule_id
         LEFT JOIN enrollment_daily_capacity AS capacity
           ON capacity.appointment_date = days.appointment_date
         WHERE days.appointment_date IN (' . $placeholders . ')
         ORDER BY days.appointment_date, days.schedule_day_id
         FOR UPDATE'
    );
    $query->execute($dates);

    $days = [];
    foreach ($query->fetchAll() as $day) {
        // Must belong to the student's active effective schedule (their cohort week or late enrollment week)
        if ((int)$day['schedule_id'] === (int)$effectiveSchedule['schedule_id']) {
            $days[$day['appointment_date']] = $day;
        }
    }
    return $days;
}

function enrollment_daily_active_count(PDO $database, string $date, ?int $excludeAppointmentId = null): int
{
    $sql = 'SELECT COUNT(*) AS active_count
            FROM appointments AS a
            INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
            WHERE a.appointment_type = \'Enrollment Appointment\'
              AND DATE(a.scheduled_at) = ?
              AND statuses.status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')';
    $parameters = [$date];
    if ($excludeAppointmentId !== null) {
        $sql .= ' AND a.id <> ?';
        $parameters[] = $excludeAppointmentId;
    }
    $sql .= ' FOR UPDATE';

    $query = $database->prepare($sql);
    $query->execute($parameters);
    return (int)$query->fetchColumn();
}

function enrollment_slot_active_count(
    PDO $database,
    string $date,
    string $time,
    ?int $excludeAppointmentId = null
): int {
    $sql = 'SELECT COUNT(*) AS active_count
            FROM appointments AS a
            INNER JOIN appointment_status AS statuses ON statuses.id = a.appointment_status_id
            WHERE a.appointment_type = \'Enrollment Appointment\'
              AND a.scheduled_at = TIMESTAMP(?, ?)
              AND statuses.status_code IN (\'submitted\', \'pending\', \'approved\', \'confirmed\', \'rescheduled\')';
    $parameters = [$date, $time];
    if ($excludeAppointmentId !== null) {
        $sql .= ' AND a.id <> ?';
        $parameters[] = $excludeAppointmentId;
    }
    $sql .= ' FOR UPDATE';

    $query = $database->prepare($sql);
    $query->execute($parameters);
    return (int)$query->fetchColumn();
}
