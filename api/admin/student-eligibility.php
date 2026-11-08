<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../enrollment-booking.php';
require_once __DIR__ . '/../enrollment-eligibility.php';
start_admin_session();

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    admin_json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

try {
    $database = admin_database();
    $admin = require_admin_api($database);
    require_admin_permission($database, $admin, 'students.update');

    if ($method === 'GET') {
        $studentNumber = trim(is_string($_GET['studentNumber'] ?? null) ? $_GET['studentNumber'] : '');
        if (!preg_match('/\A(?:824[0-9]{4}|[0-9]{12})\z/', $studentNumber)) {
            admin_json_response(['success' => false, 'message' => 'Enter a 7-digit CTU Student ID beginning with 824 or a 12-digit LRN.'], 400);
        }

        $record = admin_student_eligibility_record($database, $studentNumber);
        if (!$record) {
            admin_json_response(['success' => false, 'message' => 'No student was found with that student number.'], 404);
        }
        admin_json_response(['success' => true, 'record' => $record]);
    }

    verify_request_origin();
    $request = read_json_request();
    verify_csrf_token($request['csrf_token'] ?? null);
    $studentNumber = trim(is_string($request['studentNumber'] ?? null) ? $request['studentNumber'] : '');
    if (!preg_match('/\A(?:824[0-9]{4}|[0-9]{12})\z/', $studentNumber)) {
        admin_json_response(['success' => false, 'message' => 'Enter a 7-digit CTU Student ID beginning with 824 or a 12-digit LRN.'], 400);
    }

    $yearLevelInput = array_key_exists('yearLevel', $request) ? $request['yearLevel'] : null;
    $yearLevelExplicit = array_key_exists('yearLevel', $request);
    $yearLevel = null;
    if ($yearLevelInput === '') {
        $yearLevel = null;
    } elseif ($yearLevelInput !== null) {
        if (!is_string($yearLevelInput) || normalize_year_level($yearLevelInput) === null) {
            admin_json_response(['success' => false, 'message' => 'Select a supported year level or leave it unset.'], 400);
        }
        $yearLevel = normalize_year_level($yearLevelInput);
    }

    $academicStatus = $request['academicStatus'] ?? null;
    $academicStatuses = ['applicant', 'active', 'inactive', 'graduated', 'dropped', 'suspended', 'enrolling', 'enrolled'];
    if (!is_string($academicStatus) || !in_array($academicStatus, $academicStatuses, true)) {
        admin_json_response(['success' => false, 'message' => 'Select a valid academic status.'], 400);
    }

    $subjectsInput = $request['subjects'] ?? null;
    if (!is_array($subjectsInput) || count($subjectsInput) > 100) {
        admin_json_response(['success' => false, 'message' => 'Submit no more than 100 subject records.'], 400);
    }
    $subjects = [];
    $seenSubjects = [];
    $gradeStatuses = ['complete', 'missing', 'incomplete', 'deficient', 'failed', 'pending'];
    foreach ($subjectsInput as $row) {
        if (!is_array($row) || !is_string($row['subject'] ?? null) || !is_bool($row['isRequired'] ?? null)) {
            admin_json_response(['success' => false, 'message' => 'Each subject needs a name and a required/not-required value.'], 400);
        }
        $subject = trim($row['subject']);
        $gradeStatus = $row['gradeStatus'] ?? null;
        $gradeInput = $row['grade'] ?? null;
        if (
            $subject === '' || strlen($subject) > 150 ||
            isset($seenSubjects[strtolower($subject)]) ||
            !is_string($gradeStatus) || !in_array($gradeStatus, $gradeStatuses, true)
        ) {
            admin_json_response(['success' => false, 'message' => 'Subject names must be unique and each grade status must be valid.'], 400);
        }
        $seenSubjects[strtolower($subject)] = true;
        $grade = null;
        if ($gradeInput !== null && $gradeInput !== '') {
            if (!is_numeric($gradeInput) || !is_finite((float)$gradeInput) || (float)$gradeInput < 0 || (float)$gradeInput > 100) {
                admin_json_response(['success' => false, 'message' => 'Grades must be between 0 and 100, or left blank.'], 400);
            }
            $grade = number_format((float)$gradeInput, 2, '.', '');
        }
        $subjects[] = [
            'subject' => $subject,
            'isRequired' => $row['isRequired'],
            'grade' => $grade,
            'gradeStatus' => $gradeStatus,
        ];
    }

    $financialInput = $request['financialRecords'] ?? null;
    if (!is_array($financialInput) || count($financialInput) > 100) {
        admin_json_response(['success' => false, 'message' => 'Submit no more than 100 financial records.'], 400);
    }
    $financialRecords = [];
    foreach ($financialInput as $row) {
        if (!is_array($row)) {
            admin_json_response(['success' => false, 'message' => 'A financial record is invalid.'], 400);
        }
        $id = $row['id'] ?? null;
        $balance = $row['balance'] ?? null;
        $paymentStatus = $row['paymentStatus'] ?? null;
        $description = $row['description'] ?? '';
        if (
            ($id !== null && (!is_int($id) || $id < 1)) ||
            !is_numeric($balance) || !is_finite((float)$balance) ||
            (float)$balance < 0 || (float)$balance > 99999999.99 ||
            !is_string($paymentStatus) || !in_array($paymentStatus, ['paid', 'unpaid', 'pending'], true) ||
            !is_string($description) || strlen($description) > 255
        ) {
            admin_json_response(['success' => false, 'message' => 'Check the financial amount, status, and description.'], 400);
        }
        $financialRecords[] = [
            'id' => $id,
            'balance' => number_format((float)$balance, 2, '.', ''),
            'paymentStatus' => $paymentStatus,
            'description' => trim($description) !== '' ? trim($description) : null,
        ];
    }

    $holdsInput = $request['holds'] ?? null;
    if (!is_array($holdsInput) || count($holdsInput) > 100) {
        admin_json_response(['success' => false, 'message' => 'Submit no more than 100 enrollment holds.'], 400);
    }
    $holds = [];
    foreach ($holdsInput as $row) {
        if (!is_array($row)) {
            admin_json_response(['success' => false, 'message' => 'An enrollment hold is invalid.'], 400);
        }
        $id = $row['id'] ?? null;
        $reason = $row['reason'] ?? null;
        $status = $row['status'] ?? null;
        if (
            ($id !== null && (!is_int($id) || $id < 1)) ||
            !is_string($reason) || trim($reason) === '' || strlen($reason) > 500 ||
            !is_string($status) || !in_array($status, ['active', 'released'], true)
        ) {
            admin_json_response(['success' => false, 'message' => 'Each enrollment hold needs a reason and valid status.'], 400);
        }
        $holds[] = ['id' => $id, 'reason' => trim($reason), 'status' => $status];
    }

    $requirementsInput = $request['requirements'] ?? null;
    if (!is_array($requirementsInput) || count($requirementsInput) > 100) {
        admin_json_response(['success' => false, 'message' => 'Submit no more than 100 enrollment requirements.'], 400);
    }
    $requirements = [];
    foreach ($requirementsInput as $row) {
        if (!is_array($row)) {
            admin_json_response(['success' => false, 'message' => 'An enrollment requirement is invalid.'], 400);
        }
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        $status = $row['status'] ?? null;
        $blocksEnrollment = $row['blocksEnrollment'] ?? null;
        if (
            ($id !== null && (!is_int($id) || $id < 1)) ||
            !is_string($name) || trim($name) === '' || strlen($name) > 180 ||
            !is_string($status) || !in_array($status, ['unresolved', 'in_progress', 'resolved'], true) ||
            !is_bool($blocksEnrollment)
        ) {
            admin_json_response(['success' => false, 'message' => 'Each requirement needs a name, valid status, and blocking setting.'], 400);
        }
        $requirements[] = [
            'id' => $id,
            'name' => trim($name),
            'status' => $status,
            'blocksEnrollment' => $blocksEnrollment,
        ];
    }

    $documentsInput = $request['documents'] ?? null;
    if (!is_array($documentsInput) || count($documentsInput) > 100) {
        admin_json_response(['success' => false, 'message' => 'Submit no more than 100 enrollment documents.'], 400);
    }
    $documents = [];
    $documentStatuses = ['missing', 'submitted', 'verified', 'rejected'];
    $seenDocumentTypes = [];
    foreach ($documentsInput as $row) {
        if (!is_array($row)) {
            admin_json_response(['success' => false, 'message' => 'An enrollment document is invalid.'], 400);
        }
        $documentTypeId = $row['documentTypeId'] ?? null;
        $status = $row['status'] ?? null;
        if (
            !is_int($documentTypeId) || $documentTypeId < 1 ||
            isset($seenDocumentTypes[$documentTypeId]) ||
            !is_string($status) || !in_array($status, $documentStatuses, true)
        ) {
            admin_json_response(['success' => false, 'message' => 'Select a valid status for each required document.'], 400);
        }
        $seenDocumentTypes[$documentTypeId] = true;
        $documents[] = ['documentTypeId' => $documentTypeId, 'status' => $status];
    }

    $database->beginTransaction();
    $studentQuery = $database->prepare(
        'SELECT id FROM students WHERE student_number = ? LIMIT 1 FOR UPDATE'
    );
    $studentQuery->execute([$studentNumber]);
    $studentId = $studentQuery->fetchColumn();
    if ($studentId === false) {
        $database->rollBack();
        admin_json_response(['success' => false, 'message' => 'No student was found with that student number.'], 404);
    }
    $studentId = (int)$studentId;

    $before = admin_student_eligibility_record($database, $studentNumber);
    if (!$before) {
        throw new RuntimeException('The student record disappeared while it was being updated.');
    }

    $studentUpdate = $database->prepare('UPDATE students SET year_level = ?, academic_status = ? WHERE id = ?');
    $studentUpdate->execute([$yearLevel, $academicStatus, $studentId]);

    $subjectUpsert = $database->prepare(
        'INSERT INTO student_enrollment_subjects (student_id, subject, is_required)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE is_required = VALUES(is_required)'
    );
    $gradeLookup = $database->prepare(
        'SELECT id FROM grades WHERE student_id = ? AND subject = ? ORDER BY id LIMIT 1 FOR UPDATE'
    );
    $gradeUpdate = $database->prepare(
        'UPDATE grades SET grade = ?, grade_status = ? WHERE student_id = ? AND subject = ?'
    );
    $gradeInsert = $database->prepare(
        'INSERT INTO grades (student_id, subject, grade, grade_status) VALUES (?, ?, ?, ?)'
    );
    foreach ($subjects as $subject) {
        $subjectUpsert->execute([$studentId, $subject['subject'], $subject['isRequired'] ? 1 : 0]);
        $gradeLookup->execute([$studentId, $subject['subject']]);
        if ($gradeLookup->fetchColumn() !== false) {
            $gradeUpdate->execute([
                $subject['grade'],
                $subject['gradeStatus'],
                $studentId,
                $subject['subject'],
            ]);
        } else {
            $gradeInsert->execute([
                $studentId,
                $subject['subject'],
                $subject['grade'],
                $subject['gradeStatus'],
            ]);
        }
    }

    $financialLookup = $database->prepare(
        'SELECT id FROM financial_records WHERE id = ? AND student_id = ? LIMIT 1 FOR UPDATE'
    );
    $financialUpdate = $database->prepare(
        'UPDATE financial_records
         SET balance = ?, payment_status = ?, description = ?
         WHERE id = ? AND student_id = ?'
    );
    $financialInsert = $database->prepare(
        'INSERT INTO financial_records (student_id, balance, payment_status, description)
         VALUES (?, ?, ?, ?)'
    );
    foreach ($financialRecords as $financialRecord) {
        if ($financialRecord['id'] === null) {
            $financialInsert->execute([
                $studentId,
                $financialRecord['balance'],
                $financialRecord['paymentStatus'],
                $financialRecord['description'],
            ]);
            continue;
        }
        $financialLookup->execute([$financialRecord['id'], $studentId]);
        if ($financialLookup->fetchColumn() === false) {
            throw new InvalidArgumentException('A financial record does not belong to this student.');
        }
        $financialUpdate->execute([
            $financialRecord['balance'],
            $financialRecord['paymentStatus'],
            $financialRecord['description'],
            $financialRecord['id'],
            $studentId,
        ]);
    }

    $holdLookup = $database->prepare(
        'SELECT id FROM enrollment_holds WHERE id = ? AND student_id = ? LIMIT 1 FOR UPDATE'
    );
    $holdUpdate = $database->prepare(
        'UPDATE enrollment_holds SET reason = ?, status = ? WHERE id = ? AND student_id = ?'
    );
    $holdInsert = $database->prepare(
        'INSERT INTO enrollment_holds (student_id, reason, status) VALUES (?, ?, ?)'
    );
    foreach ($holds as $hold) {
        if ($hold['id'] === null) {
            $holdInsert->execute([$studentId, $hold['reason'], $hold['status']]);
            continue;
        }
        $holdLookup->execute([$hold['id'], $studentId]);
        if ($holdLookup->fetchColumn() === false) {
            throw new InvalidArgumentException('An enrollment hold does not belong to this student.');
        }
        $holdUpdate->execute([$hold['reason'], $hold['status'], $hold['id'], $studentId]);
    }

    $requirementLookup = $database->prepare(
        'SELECT id FROM student_enrollment_requirements WHERE id = ? AND student_id = ? LIMIT 1 FOR UPDATE'
    );
    $requirementUpdate = $database->prepare(
        'UPDATE student_enrollment_requirements
         SET requirement_name = ?, status = ?, blocks_enrollment = ?
         WHERE id = ? AND student_id = ?'
    );
    $requirementInsert = $database->prepare(
        'INSERT INTO student_enrollment_requirements
         (student_id, requirement_name, status, blocks_enrollment)
         VALUES (?, ?, ?, ?)'
    );
    foreach ($requirements as $requirement) {
        if ($requirement['id'] === null) {
            $requirementInsert->execute([
                $studentId,
                $requirement['name'],
                $requirement['status'],
                $requirement['blocksEnrollment'] ? 1 : 0,
            ]);
            continue;
        }
        $requirementLookup->execute([$requirement['id'], $studentId]);
        if ($requirementLookup->fetchColumn() === false) {
            throw new InvalidArgumentException('An enrollment requirement does not belong to this student.');
        }
        $requirementUpdate->execute([
            $requirement['name'],
            $requirement['status'],
            $requirement['blocksEnrollment'] ? 1 : 0,
            $requirement['id'],
            $studentId,
        ]);
    }

    $requiredDocumentQuery = $database->prepare(
        'SELECT id FROM enrollment_document_types
         WHERE is_active = 1 AND is_required = 1
         ORDER BY id'
    );
    $requiredDocumentQuery->execute();
    $requiredDocumentIds = array_map('intval', $requiredDocumentQuery->fetchAll(PDO::FETCH_COLUMN));
    $submittedDocumentIds = array_keys($seenDocumentTypes);
    sort($requiredDocumentIds);
    sort($submittedDocumentIds);
    if ($requiredDocumentIds !== $submittedDocumentIds) {
        throw new InvalidArgumentException('Submit a status for every active required document type.');
    }
    $documentUpsert = $database->prepare(
        'INSERT INTO student_enrollment_documents (student_id, document_type_id, status)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status)'
    );
    foreach ($documents as $document) {
        $documentUpsert->execute([$studentId, $document['documentTypeId'], $document['status']]);
    }

    $after = admin_student_eligibility_record($database, $studentNumber);
    if (!$after) {
        throw new RuntimeException('The updated student record could not be reloaded.');
    }
    $auditDetails = json_encode([
        'before' => admin_student_eligibility_audit_snapshot($before),
        'after' => admin_student_eligibility_audit_snapshot($after),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $audit = $database->prepare(
        'INSERT INTO activity_logs
         (actor_user_id, actor_role_id, department_id, action_key, entity_type, entity_id,
          details, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $audit->execute([
        (int)$admin['user_id'],
        (int)$admin['role_id'],
        $admin['department_id'] === null ? null : (int)$admin['department_id'],
        'student.eligibility.updated',
        'student',
        $studentId,
        $auditDetails,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
    ]);

    $database->commit();
    admin_json_response(['success' => true, 'record' => $after]);
} catch (InvalidArgumentException $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    admin_json_response(['success' => false, 'message' => $error->getMessage()], 400);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('Student eligibility record update failed: ' . $error->getMessage());
    admin_json_response(['success' => false, 'message' => 'The student eligibility record could not be saved. Please try again.'], 503);
}

function admin_student_eligibility_record(PDO $database, string $studentNumber): ?array
{
    $studentQuery = $database->prepare(
        'SELECT s.id AS student_id, s.student_number, s.middle_name, s.program, s.year_level,
                s.academic_status, u.first_name, u.last_name, u.email, u.is_active
         FROM students AS s
         INNER JOIN users AS u ON u.id = s.user_id
         INNER JOIN campuses ON campuses.campus_id = s.campus_id
         WHERE s.student_number = ? AND campuses.campus_name = ?
         LIMIT 1'
    );
    $studentQuery->execute([$studentNumber, 'Daanbantayan Campus']);
    $student = $studentQuery->fetch();
    if (!$student) {
        return null;
    }
    $studentId = (int)$student['student_id'];

    $subjects = [];
    $requiredSubjects = $database->prepare(
        'SELECT subject, is_required
         FROM student_enrollment_subjects
         WHERE student_id = ?
         ORDER BY subject'
    );
    $requiredSubjects->execute([$studentId]);
    foreach ($requiredSubjects->fetchAll() as $row) {
        $subjects[$row['subject']] = [
            'subject' => $row['subject'],
            'isRequired' => (bool)$row['is_required'],
            'grade' => null,
            'gradeStatus' => 'missing',
        ];
    }
    $grades = $database->prepare(
        'SELECT subject, grade, grade_status
         FROM grades
         WHERE student_id = ?
         ORDER BY subject, id'
    );
    $grades->execute([$studentId]);
    foreach ($grades->fetchAll() as $row) {
        if (!isset($subjects[$row['subject']])) {
            $subjects[$row['subject']] = [
                'subject' => $row['subject'],
                'isRequired' => false,
                'grade' => null,
                'gradeStatus' => 'missing',
            ];
        }
        $subjects[$row['subject']]['grade'] = $row['grade'];
        $subjects[$row['subject']]['gradeStatus'] = $row['grade_status'];
    }
    usort($subjects, static fn(array $left, array $right): int => strcmp($left['subject'], $right['subject']));

    $financialQuery = $database->prepare(
        'SELECT id, balance, payment_status, description
         FROM financial_records
         WHERE student_id = ?
         ORDER BY id'
    );
    $financialQuery->execute([$studentId]);

    $holdQuery = $database->prepare(
        'SELECT id, reason, status
         FROM enrollment_holds
         WHERE student_id = ?
         ORDER BY id'
    );
    $holdQuery->execute([$studentId]);

    $requirementQuery = $database->prepare(
        'SELECT id, requirement_name, status, blocks_enrollment
         FROM student_enrollment_requirements
         WHERE student_id = ?
         ORDER BY id'
    );
    $requirementQuery->execute([$studentId]);

    $documentQuery = $database->prepare(
        'SELECT types.id AS document_type_id, types.document_name, types.is_required,
                COALESCE(documents.status, \'missing\') AS status
         FROM enrollment_document_types AS types
         LEFT JOIN student_enrollment_documents AS documents
           ON documents.document_type_id = types.id AND documents.student_id = ?
         WHERE types.is_active = 1 AND types.is_required = 1
         ORDER BY types.document_name'
    );
    $documentQuery->execute([$studentId]);

    return [
        'student' => [
            'studentNumber' => $student['student_number'],
            'name' => trim(implode(' ', array_filter([
                $student['first_name'],
                $student['middle_name'] ?? '',
                $student['last_name'],
            ], static fn($part): bool => trim((string)$part) !== ''))),
            'email' => $student['email'],
            'program' => $student['program'],
            'yearLevel' => $student['year_level'],
            'academicStatus' => $student['academic_status'],
            'accountActive' => (bool)$student['is_active'],
        ],
        'subjects' => array_values($subjects),
        'financialRecords' => array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'balance' => $row['balance'],
            'paymentStatus' => $row['payment_status'],
            'description' => $row['description'],
        ], $financialQuery->fetchAll()),
        'holds' => array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'reason' => $row['reason'],
            'status' => $row['status'],
        ], $holdQuery->fetchAll()),
        'requirements' => array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'name' => $row['requirement_name'],
            'status' => $row['status'],
            'blocksEnrollment' => (bool)$row['blocks_enrollment'],
        ], $requirementQuery->fetchAll()),
        'documents' => array_map(static fn(array $row): array => [
            'documentTypeId' => (int)$row['document_type_id'],
            'name' => $row['document_name'],
            'isRequired' => (bool)$row['is_required'],
            'status' => $row['status'],
        ], $documentQuery->fetchAll()),
        'eligibility' => enrollment_eligibility($database, $studentId),
    ];
}

function admin_student_eligibility_audit_snapshot(array $record): array
{
    $student = $record['student'];
    return [
        'studentNumber' => $student['studentNumber'],
        'yearLevel' => $student['yearLevel'],
        'academicStatus' => $student['academicStatus'],
        'subjects' => $record['subjects'],
        'financialRecords' => $record['financialRecords'],
        'holds' => $record['holds'],
        'requirements' => $record['requirements'],
        'documents' => $record['documents'],
    ];
}
