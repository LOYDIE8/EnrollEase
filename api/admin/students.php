<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once dirname(__DIR__) . '/enrollment-booking.php';
start_admin_session();

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    admin_json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

function admin_student_summary(array $student): array
{
    $assignment = enrollment_year_assignment((string)($student['year_level'] ?? ''));
    return [
        'id' => (int)$student['id'],
        'studentId' => $student['student_number'],
        'firstName' => $student['first_name'],
        'middleName' => $student['middle_name'] ?? '',
        'lastName' => $student['last_name'],
        'name' => trim(implode(' ', array_filter([
            $student['first_name'],
            $student['middle_name'] ?? '',
            $student['last_name'],
        ], static fn($part): bool => trim((string)$part) !== ''))),
        'email' => $student['email'],
        'contactNumber' => $student['phone'] ?? '',
        'program' => $student['program'],
        'yearLevel' => $student['year_level'] ?? '',
        'campus' => $student['campus_name'],
        'studentStatus' => $student['student_status'],
        'academicStatus' => $student['academic_status'] ?? 'Regular',
        'assignedEnrollmentWeek' => $assignment ? 'Week ' . $assignment['week_number'] : '',
        'createdAt' => $student['created_at'] ?? '',
        'departmentId' => (int)$student['department_id'],
    ];
}

function admin_student_audit(
    PDO $database,
    array $admin,
    int $studentId,
    string $action,
    ?array $oldData,
    ?array $newData
): void {
    $statement = $database->prepare(
        'INSERT INTO admin_audit_logs (admin_id, student_id, action, old_data, new_data)
         VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([
        (int)$admin['admin_id'],
        $studentId,
        $action,
        $oldData === null ? null : json_encode($oldData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $newData === null ? null : json_encode($newData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
}

function admin_student_input(array $request): array
{
    $studentNumber = trim(is_string($request['studentId'] ?? null) ? $request['studentId'] : '');
    $firstName = trim(is_string($request['firstName'] ?? null) ? $request['firstName'] : '');
    $middleName = trim(is_string($request['middleName'] ?? null) ? $request['middleName'] : '');
    $lastName = trim(is_string($request['lastName'] ?? null) ? $request['lastName'] : '');
    $email = strtolower(trim(is_string($request['email'] ?? null) ? $request['email'] : ''));
    $phone = trim(is_string($request['contactNumber'] ?? null) ? $request['contactNumber'] : '');
    $program = trim(is_string($request['program'] ?? null) ? $request['program'] : '');
    $yearLevel = $request['yearLevel'] ?? null;
    $status = $request['studentStatus'] ?? null;

    if (!preg_match('/\A(?:824[0-9]{4}|[0-9]{12})\z/', $studentNumber)) {
        admin_json_response([
            'success' => false,
            'message' => 'Enter a 7-digit CTU Student ID beginning with 824 or a 12-digit LRN.',
        ], 400);
    }
    if (
        $firstName === '' || strlen($firstName) > 100 ||
        strlen($middleName) > 100 ||
        $lastName === '' || strlen($lastName) > 100 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 ||
        !preg_match('/\A[\d\s()+-]{7,32}\z/', $phone) ||
        $program === '' || strlen($program) > 150
    ) {
        admin_json_response(['success' => false, 'message' => 'Complete all required student details with valid values.'], 400);
    }
    $canonicalYear = is_string($yearLevel) ? normalize_year_level($yearLevel) : null;
    $yearLevel = $canonicalYear;
    if (!$canonicalYear) {
        admin_json_response(['success' => false, 'message' => 'Please select a valid year level.'], 400);
    }
    if (!is_string($status) || !in_array($status, ['Active', 'Inactive'], true)) {
        admin_json_response(['success' => false, 'message' => 'Select a valid student status.'], 400);
    }
    $academicStatus = $request['academicStatus'] ?? 'Regular';
    $validStatuses = ['Regular', 'Irregular', 'Returnee', 'Transferee', 'Shiftee', 'New Student'];
    if (!is_string($academicStatus) || !in_array($academicStatus, $validStatuses, true)) {
        $academicStatus = 'Regular';
    }
    if (
        (array_key_exists('campus', $request) && $request['campus'] !== 'Daanbantayan Campus') ||
        array_key_exists('campusId', $request) ||
        array_key_exists('campus_id', $request) ||
        array_key_exists('campusName', $request)
    ) {
        admin_json_response([
            'success' => false,
            'message' => 'Access denied. This enrollment system is exclusively for CTU Daanbantayan Campus students.',
        ], 403);
    }

    return [
        'student_number' => $studentNumber,
        'first_name' => $firstName,
        'middle_name' => $middleName === '' ? null : $middleName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'program' => $program,
        'year_level' => $yearLevel,
        'student_status' => $status,
        'academic_status' => $academicStatus,
    ];
}

try {
    $database = admin_database();
    $admin = require_admin_api($database);

    if ($method === 'GET') {
        $auditRequested = ($_GET['audit'] ?? '') === '1';
        if ($auditRequested) {
            if (!admin_can_read_students($database, $admin)) {
                admin_json_response([
                    'success' => false,
                    'message' => 'You are not authorized to perform this action.',
                ], 403);
            }
            require_admin_permission($database, $admin, 'activity_logs.read');
            $auditSql = 'SELECT logs.log_id, logs.student_id, logs.action, logs.old_data,
                                logs.new_data, logs.date_created, u.username,
                                students.student_number
                         FROM admin_audit_logs AS logs
                         LEFT JOIN admins AS admins ON admins.id = logs.admin_id
                         LEFT JOIN users AS u ON u.id = admins.user_id
                         LEFT JOIN students ON students.id = logs.student_id
                         LEFT JOIN campuses ON campuses.campus_id = students.campus_id
                         WHERE campuses.campus_name = ?';
            $auditParameters = ['Daanbantayan Campus'];
            if (!admin_has_permission($database, $admin, 'students.read')) {
                $auditSql .= ' AND students.department_id = ?';
                $auditParameters[] = (int)$admin['department_id'];
            }
            $auditSql .= ' ORDER BY logs.date_created DESC, logs.log_id DESC LIMIT 250';
            $auditQuery = $database->prepare($auditSql);
            $auditQuery->execute($auditParameters);
            admin_json_response(['success' => true, 'auditLogs' => $auditQuery->fetchAll()]);
        }

        if (!admin_can_read_students($database, $admin)) {
            admin_json_response([
                'success' => false,
                'message' => 'You are not authorized to perform this action.',
            ], 403);
        }

        $studentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $assignedOnly = !admin_has_permission($database, $admin, 'students.read');
        $sql = 'SELECT s.id, s.student_number, s.middle_name, s.program, s.year_level,
                       s.student_status, s.academic_status, s.department_id, s.created_at,
                       u.first_name, u.last_name, u.email, u.phone,
                       campuses.campus_name
                FROM students AS s
                INNER JOIN users AS u ON u.id = s.user_id
                INNER JOIN campuses ON campuses.campus_id = s.campus_id
                WHERE campuses.campus_name = ?';
        $parameters = ['Daanbantayan Campus'];
        if ($assignedOnly) {
            $sql .= ' AND s.department_id = ?';
            $parameters[] = (int)$admin['department_id'];
        }

        if ($studentId) {
            $sql .= ' AND s.id = ? LIMIT 1';
            $parameters[] = (int)$studentId;
            $query = $database->prepare($sql);
            $query->execute($parameters);
            $student = $query->fetch();
            if (!$student) {
                admin_json_response(['success' => false, 'message' => 'No student was found.'], 404);
            }
            admin_json_response(['success' => true, 'student' => admin_student_summary($student)]);
        }

        $search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
        $search = substr($search, 0, 100);
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
        $yearLevel = is_string($_GET['yearLevel'] ?? null) ? $_GET['yearLevel'] : '';
        if ($status !== '' && !in_array($status, ['Active', 'Inactive'], true)) {
            admin_json_response(['success' => false, 'message' => 'Select a valid student status filter.'], 400);
        }
        if ($yearLevel !== '' && !in_array($yearLevel, ['1st Year', '2nd Year', '3rd Year', '4th Year'], true)) {
            admin_json_response(['success' => false, 'message' => 'Select a valid year level filter.'], 400);
        }
        if ($search !== '') {
            $sql .= ' AND (CONCAT(u.first_name, \' \', COALESCE(s.middle_name, \'\'), \' \', u.last_name) LIKE ?
                         OR u.email LIKE ? OR s.student_number LIKE ? OR s.program LIKE ?)';
            $like = '%' . $search . '%';
            array_push($parameters, $like, $like, $like, $like);
        }
        if ($status !== '') {
            $sql .= ' AND s.student_status = ?';
            $parameters[] = $status;
        }
        if ($yearLevel !== '') {
            $sql .= ' AND s.year_level = ?';
            $parameters[] = $yearLevel;
        }
        $sql .= ' ORDER BY u.created_at DESC LIMIT 250';
        $query = $database->prepare($sql);
        $query->execute($parameters);
        $students = array_map('admin_student_summary', $query->fetchAll());
        admin_json_response(['success' => true, 'students' => $students]);
    }

    verify_request_origin();
    $request = read_json_request();
    verify_csrf_token($request['csrf_token'] ?? null);
    if (
        (array_key_exists('campus', $request) && $request['campus'] !== 'Daanbantayan Campus') ||
        array_key_exists('campusId', $request) ||
        array_key_exists('campus_id', $request) ||
        array_key_exists('campusName', $request)
    ) {
        admin_json_response([
            'success' => false,
            'message' => 'Access denied. This enrollment system is exclusively for CTU Daanbantayan Campus students.',
        ], 403);
    }
    if (!admin_can_manage_students($database, $admin)) {
        admin_json_response([
            'success' => false,
            'message' => 'You are not authorized to perform this action.',
        ], 403);
    }

    $action = $request['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['create', 'update', 'activate', 'deactivate'], true)) {
        admin_json_response(['success' => false, 'message' => 'Select a valid student action.'], 400);
    }

    if ($action === 'activate' || $action === 'deactivate') {
        $id = filter_var($request['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            admin_json_response(['success' => false, 'message' => 'Select a valid student.'], 400);
        }
        $database->beginTransaction();
        $select = $database->prepare(
            'SELECT s.id, s.student_number, s.middle_name, s.program, s.year_level,
                    s.student_status, u.first_name, u.last_name, u.email, u.phone,
                    campuses.campus_name
             FROM students AS s
             INNER JOIN users AS u ON u.id = s.user_id
             INNER JOIN campuses ON campuses.campus_id = s.campus_id
             WHERE s.id = ? FOR UPDATE'
        );
        $select->execute([(int)$id]);
        $current = $select->fetch();
        if (!$current || $current['campus_name'] !== 'Daanbantayan Campus') {
            $database->rollBack();
            admin_json_response(['success' => false, 'message' => 'No student was found.'], 404);
        }
        $newStatus = $action === 'activate' ? 'Active' : 'Inactive';
        if ($current['student_status'] !== $newStatus) {
            $oldData = admin_student_summary($current);
            $update = $database->prepare('UPDATE students SET student_status = ? WHERE id = ?');
            $update->execute([$newStatus, (int)$id]);
            $current['student_status'] = $newStatus;
            admin_student_audit(
                $database,
                $admin,
                (int)$id,
                $action === 'activate' ? 'ACTIVATE_STUDENT' : 'DEACTIVATE_STUDENT',
                $oldData,
                admin_student_summary($current)
            );
        }
        $database->commit();
        admin_json_response([
            'success' => true,
            'message' => $newStatus === 'Active' ? 'Student activated successfully.' : 'Student deactivated successfully.',
        ]);
    }

    $student = admin_student_input($request);
    if ($action === 'create') {
        $password = $request['password'] ?? null;
        $confirmPassword = $request['confirmPassword'] ?? null;
        if (
            !is_string($password) || strlen($password) < 8 || strlen($password) > 72 ||
            !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password) ||
            !is_string($confirmPassword) || !hash_equals($password, $confirmPassword)
        ) {
            admin_json_response([
                'success' => false,
                'message' => 'Enter a password between 8 and 72 characters containing at least one letter and one number, and confirm it.',
            ], 400);
        }
        $campusQuery = $database->prepare('SELECT campus_id FROM campuses WHERE campus_name = ? LIMIT 1');
        $campusQuery->execute(['Daanbantayan Campus']);
        $campusId = $campusQuery->fetchColumn();
        $departmentQuery = $database->prepare('SELECT id FROM departments WHERE department_code = ? LIMIT 1');
        $departmentQuery->execute(['CodeTech']);
        $departmentId = $departmentQuery->fetchColumn();
        if (!$campusId || !$departmentId) {
            throw new RuntimeException('Student registration campus or department is not configured.');
        }
        $database->beginTransaction();
        $userInsert = $database->prepare(
            'INSERT INTO users (email, password_hash, first_name, last_name, phone, account_type)
             VALUES (?, ?, ?, ?, ?, \'student\')'
        );
        $userInsert->execute([
            $student['email'],
            password_hash($password, PASSWORD_DEFAULT),
            $student['first_name'],
            $student['last_name'],
            $student['phone'],
        ]);
        $userId = (int)$database->lastInsertId();
        $studentInsert = $database->prepare(
            'INSERT INTO students
              (user_id, student_number, middle_name, program, year_level, campus_id,
               student_status, department_id, academic_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $studentInsert->execute([
            $userId,
            $student['student_number'],
            $student['middle_name'],
            $student['program'],
            $student['year_level'],
            (int)$campusId,
            $student['student_status'],
            (int)$departmentId,
            $student['academic_status'],
        ]);
        $studentId = (int)$database->lastInsertId();
        $newData = $student;
        $newData['campus'] = 'Daanbantayan Campus';
        $newData['assignedEnrollmentWeek'] = enrollment_year_assignment($student['year_level'])['week_number'];
        admin_student_audit($database, $admin, $studentId, 'ADD_STUDENT', null, $newData);
        $database->commit();
        admin_json_response(['success' => true, 'message' => 'Student added successfully.']);
    }

    $id = filter_var($request['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        admin_json_response(['success' => false, 'message' => 'Select a valid student.'], 400);
    }
    $database->beginTransaction();
    $select = $database->prepare(
        'SELECT s.id, s.student_number, s.middle_name, s.program, s.year_level,
                s.student_status, s.academic_status, s.department_id, s.created_at,
                u.id AS user_id, u.first_name, u.last_name, u.email, u.phone,
                campuses.campus_name
         FROM students AS s
         INNER JOIN users AS u ON u.id = s.user_id
         INNER JOIN campuses ON campuses.campus_id = s.campus_id
         WHERE s.id = ? FOR UPDATE'
    );
    $select->execute([(int)$id]);
    $current = $select->fetch();
    if (!$current || $current['campus_name'] !== 'Daanbantayan Campus') {
        $database->rollBack();
        admin_json_response(['success' => false, 'message' => 'No student was found.'], 404);
    }
    $oldData = admin_student_summary($current);

    $duplicateId = $database->prepare('SELECT 1 FROM students WHERE student_number = ? AND id <> ? LIMIT 1');
    $duplicateId->execute([$student['student_number'], (int)$id]);
    if ($duplicateId->fetchColumn()) {
        $database->rollBack();
        admin_json_response(['success' => false, 'message' => 'This Student ID is already registered.'], 409);
    }
    $duplicateEmail = $database->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $duplicateEmail->execute([$student['email'], (int)$current['user_id']]);
    if ($duplicateEmail->fetchColumn()) {
        $database->rollBack();
        admin_json_response(['success' => false, 'message' => 'This email address is already registered.'], 409);
    }

    $userUpdate = $database->prepare(
        'UPDATE users SET email = ?, first_name = ?, last_name = ?, phone = ? WHERE id = ?'
    );
    $userUpdate->execute([
        $student['email'],
        $student['first_name'],
        $student['last_name'],
        $student['phone'],
        (int)$current['user_id'],
    ]);
    $studentUpdate = $database->prepare(
        'UPDATE students
         SET student_number = ?, middle_name = ?, program = ?, year_level = ?,
             student_status = ?, academic_status = ?, campus_id = (
               SELECT campus_id FROM campuses WHERE campus_name = \'Daanbantayan Campus\' LIMIT 1
             )
         WHERE id = ?'
    );
    $studentUpdate->execute([
        $student['student_number'],
        $student['middle_name'],
        $student['program'],
        $student['year_level'],
        $student['student_status'],
        $student['academic_status'],
        (int)$id,
    ]);
    $newData = $student;
    $newData['campus'] = 'Daanbantayan Campus';
    $newData['assignedEnrollmentWeek'] = enrollment_year_assignment($student['year_level'])['week_number'];
    admin_student_audit($database, $admin, (int)$id, 'UPDATE_STUDENT', $oldData, $newData);
    $database->commit();
    admin_json_response(['success' => true, 'message' => 'Student information updated successfully.']);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('Admin student operation failed: ' . $error->getMessage());
    if ($error instanceof PDOException && $error->getCode() === '23000') {
        $detail = (string)($error->errorInfo[2] ?? '');
        $message = str_contains($detail, 'student_number') || str_contains($detail, 'students_number')
            ? 'This Student ID is already registered.'
            : 'This email address is already registered.';
        admin_json_response(['success' => false, 'message' => $message], 409);
    }
    admin_json_response(['success' => false, 'message' => 'The student operation could not be completed.'], 503);
}
