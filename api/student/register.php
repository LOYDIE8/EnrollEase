<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../enrollment-eligibility.php';
student_session_start();
student_require_method('POST');
student_verify_origin();
$request = student_read_json();

$studentNumber = trim(is_string($request['studentId'] ?? null) ? $request['studentId'] : '');
$fullName = trim(is_string($request['fullName'] ?? null) ? $request['fullName'] : '');
$nameParts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$firstName = array_shift($nameParts) ?? '';
$lastName = array_pop($nameParts) ?? '';
$middleName = implode(' ', $nameParts);
$email = strtolower(trim(is_string($request['email'] ?? null) ? $request['email'] : ''));
$phone = trim(is_string($request['phone'] ?? null) ? $request['phone'] : '');
$program = trim(is_string($request['program'] ?? null) ? $request['program'] : '');
$yearLevel = trim(is_string($request['yearLevel'] ?? null) ? $request['yearLevel'] : '');
$password = $request['password'] ?? null;
$confirmPassword = $request['confirmPassword'] ?? null;

if (
    (array_key_exists('campus', $request) && $request['campus'] !== 'Daanbantayan Campus') ||
    array_key_exists('campusId', $request) ||
    array_key_exists('campus_id', $request)
) {
    student_campus_access_denied();
}
if (!preg_match('/\A(?:824[0-9]{4}|[0-9]{12})\z/', $studentNumber)) {
    student_json_response([
        'success' => false,
        'message' => 'Enter a 7-digit CTU Student ID beginning with 824 or a 12-digit LRN.',
    ], 400);
}
if (
    $firstName === '' || strlen($firstName) > 100 ||
    strlen($middleName) > 100 ||
    $lastName === '' || strlen($lastName) > 100 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 ||
    !preg_match('/\A[\d\s()+-]{7,32}\z/', $phone)
) {
    student_json_response(['success' => false, 'message' => 'Enter a full name, valid email address, and valid phone number.'], 400);
}
if (
    !is_string($password) || strlen($password) < 8 || strlen($password) > 72 ||
    !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)
) {
    student_json_response([
        'success' => false,
        'message' => 'Password must be between 8 and 72 characters and contain at least one letter and one number.',
    ], 400);
}
if (!is_string($confirmPassword) || !hash_equals($password, $confirmPassword)) {
    student_json_response([
        'success' => false,
        'message' => 'Passwords do not match.',
    ], 400);
}

try {
    $database = admin_database();
    $retryAfter = auth_rate_limit_retry_after($database, 'student-register', $email);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        student_json_response([
            'success' => false,
            'message' => "Too many registration attempts. Try again in {$retryAfter} seconds.",
            'retryAfter' => $retryAfter,
        ], 429);
    }
    auth_rate_limit_record_attempt($database, 'student-register', $email);

    $settingStmt = $database->prepare('SELECT setting_value FROM system_settings WHERE setting_key = \'require_registry_match\' LIMIT 1');
    $settingStmt->execute();
    $rawSetting = $settingStmt->fetchColumn();
    $requireRegistryMatch = false;
    if ($rawSetting !== false) {
        $decoded = json_decode((string)$rawSetting, true);
        $requireRegistryMatch = ($decoded === true || $decoded === 1 || $decoded === '1' || $rawSetting === 'true' || $rawSetting === '1');
    }

    if ($requireRegistryMatch) {
        $regQuery = $database->prepare('SELECT last_name FROM enrolled_student_registry WHERE student_number = ? LIMIT 1');
        $regQuery->execute([$studentNumber]);
        $regLastName = $regQuery->fetchColumn();
        if ($regLastName === false || strcasecmp(trim((string)$regLastName), trim($lastName)) !== 0) {
            student_json_response([
                'success' => false,
                'message' => 'Student ID/LRN and last name do not match the official enrolled student registry. Please contact the registrar.',
            ], 422);
        }
    }

    $regRecordStmt = $database->prepare('SELECT program, year_level FROM enrolled_student_registry WHERE student_number = ? LIMIT 1');
    $regRecordStmt->execute([$studentNumber]);
    $regRecord = $regRecordStmt->fetch();
    if ($regRecord) {
        if (!empty($regRecord['program'])) {
            $program = $regRecord['program'];
        }
        if (!empty($regRecord['year_level'])) {
            $yearLevel = $regRecord['year_level'];
        }
    }
    if ($program === '' || $program === 'Not provided') {
        $program = 'BSIT';
    }
    $canonicalYearLevel = normalize_year_level($yearLevel);
    $yearLevel = $canonicalYearLevel ?: 'First Year';

    $campusQuery = $database->prepare(
        'SELECT campus_id FROM campuses WHERE campus_name = ? LIMIT 1'
    );
    $campusQuery->execute(['Daanbantayan Campus']);
    $campusId = $campusQuery->fetchColumn();
    $departmentQuery = $database->prepare(
        'SELECT id FROM departments WHERE department_code = ? LIMIT 1'
    );
    $departmentQuery->execute(['CodeTech']);
    $departmentId = $departmentQuery->fetchColumn();
    if (!$campusId || !$departmentId) {
        throw new RuntimeException('Student registration campus or department is not configured.');
    }

    $existingId = $database->prepare('SELECT 1 FROM students WHERE student_number = ? LIMIT 1');
    $existingId->execute([$studentNumber]);
    if ($existingId->fetchColumn()) {
        student_json_response(['success' => false, 'message' => 'This Student ID is already registered.'], 409);
    }
    $existingEmail = $database->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
    $existingEmail->execute([$email]);
    if ($existingEmail->fetchColumn()) {
        student_json_response(['success' => false, 'message' => 'This email address is already registered.'], 409);
    }

    $database->beginTransaction();
    $userQuery = $database->prepare(
        'INSERT INTO users (email, password_hash, first_name, last_name, phone, account_type)
         VALUES (?, ?, ?, ?, ?, \'student\')'
    );
    $userQuery->execute([
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $firstName,
        $lastName,
        $phone,
    ]);
    $userId = (int)$database->lastInsertId();
    $studentQuery = $database->prepare(
        'INSERT INTO students
          (user_id, student_number, middle_name, program, year_level, campus_id,
           student_status, department_id, academic_status)
         VALUES (?, ?, ?, ?, ?, ?, \'Active\', ?, \'active\')'
    );
    $studentQuery->execute([
        $userId,
        $studentNumber,
        $middleName === '' ? null : $middleName,
        $program,
        $yearLevel,
        (int)$campusId,
        (int)$departmentId,
    ]);
    $studentId = (int)$database->lastInsertId();
    ensure_student_baseline_eligibility($database, $studentId);
    $database->commit();
    auth_rate_limit_clear($database, 'student-register', $email);

    student_json_response([
        'success' => true,
        'studentId' => $studentNumber,
        'message' => 'Account created successfully.',
    ], 201);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('Student registration failed: ' . $error->getMessage());
    if ($error instanceof PDOException && $error->getCode() === '23000') {
        $detail = (string)($error->errorInfo[2] ?? '');
        $message = str_contains($detail, 'student_number') || str_contains($detail, 'students_number')
            ? 'This Student ID is already registered.'
            : 'This email address is already registered.';
        student_json_response(['success' => false, 'message' => $message], 409);
    }
    student_json_response(
        student_debug_error_detail(['success' => false, 'message' => 'The registration service is unavailable.'], $error),
        503
    );
}
