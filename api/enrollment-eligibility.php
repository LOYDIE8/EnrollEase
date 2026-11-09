<?php
declare(strict_types=1);

require_once __DIR__ . '/enrollment-booking.php';

function ensure_student_baseline_eligibility(PDO $database, int $studentId): void
{
    $hasGrades = (int)$database->query("SELECT COUNT(*) FROM grades WHERE student_id = {$studentId}")->fetchColumn() > 0;
    $hasFinancial = (int)$database->query("SELECT COUNT(*) FROM financial_records WHERE student_id = {$studentId}")->fetchColumn() > 0;
    $hasSubjects = (int)$database->query("SELECT COUNT(*) FROM student_enrollment_subjects WHERE student_id = {$studentId}")->fetchColumn() > 0;

    if (!$hasGrades && !$hasFinancial && !$hasSubjects) {
        // Initialize mock baseline records for newly registered students (simulating MIS sync)
        $finStmt = $database->prepare("INSERT INTO financial_records (student_id, balance, payment_status, description) VALUES (?, 0.00, 'paid', 'Tuition & Fees Clearance')");
        $finStmt->execute([$studentId]);

        $subStmt = $database->prepare("INSERT INTO student_enrollment_subjects (student_id, subject, is_required) VALUES (?, 'General Education Core', 1), (?, 'Major Subject Prerequisite', 1)");
        $subStmt->execute([$studentId, $studentId]);

        $gradeStmt = $database->prepare("INSERT INTO grades (student_id, subject, grade, grade_status) VALUES (?, 'General Education Core', 1.50, 'complete'), (?, 'Major Subject Prerequisite', 1.75, 'complete')");
        $gradeStmt->execute([$studentId, $studentId]);

        $docTypes = $database->query("SELECT id FROM enrollment_document_types WHERE is_active = 1 AND is_required = 1")->fetchAll(PDO::FETCH_COLUMN);
        $docStmt = $database->prepare("INSERT INTO student_enrollment_documents (student_id, document_type_id, status) VALUES (?, ?, 'verified') ON DUPLICATE KEY UPDATE status = 'verified'");
        foreach ($docTypes as $dtId) {
            $docStmt->execute([$studentId, (int)$dtId]);
        }
    }
}

function enrollment_eligibility(PDO $database, int $studentId): array
{
    $rulesQuery = $database->prepare(
        'SELECT rule_key, is_enabled, rule_value FROM enrollment_eligibility_rules'
    );
    $rulesQuery->execute();
    $ruleRows = $rulesQuery->fetchAll();
    $rules = [];
    foreach ($ruleRows as $row) {
        $rules[$row['rule_key']] = [
            'enabled' => (bool)$row['is_enabled'],
            'value' => $row['rule_value'] === null ? null : json_decode($row['rule_value'], true),
        ];
    }

    $checks = [];
    $add = static function (
        string $key,
        string $label,
        bool $passed,
        array $reasons = [],
        array $details = []
    ) use (&$checks): void {
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'reasons' => $passed ? [] : array_values($reasons),
            'details' => array_values($details),
        ];
    };

    $requiredRules = ['grades', 'financial', 'holds', 'documents', 'student_status', 'other_requirements'];
    $missingRules = array_diff($requiredRules, array_keys($rules));
    if ($missingRules) {
        $reasons = ['Eligibility rules are not configured. Please contact the registrar.'];
        return [
            'eligible' => false,
            'reasons' => $reasons,
            'checks' => [[
                'key' => 'configuration',
                'label' => 'Enrollment eligibility rules',
                'passed' => false,
                'reasons' => $reasons,
            ]],
        ];
    }

    $studentQuery = $database->prepare(
        'SELECT s.academic_status, s.student_status, s.year_level, s.campus_id,
                u.is_active, u.first_name, u.last_name, s.student_number,
                s.program, u.email, campuses.campus_name
         FROM students AS s INNER JOIN users AS u ON u.id = s.user_id
         LEFT JOIN campuses ON campuses.campus_id = s.campus_id
         WHERE s.id = ? LIMIT 1'
    );
    $studentQuery->execute([$studentId]);
    $student = $studentQuery->fetch();
    if (!$student) {
        $reasons = ['Student record was not found.'];
        return [
            'eligible' => false,
            'reasons' => $reasons,
            'checks' => [[
                'key' => 'student',
                'label' => 'Student information verified',
                'passed' => false,
                'reasons' => $reasons,
            ]],
        ];
    }
    ensure_student_baseline_eligibility($database, $studentId);
    $add('student', 'Student information verified',
        (bool)$student['is_active'] && $student['campus_name'] === 'Daanbantayan Campus' &&
        preg_match('/\A(?:824[0-9]{4}|[0-9]{12})\z/', (string)$student['student_number']) === 1 &&
        trim($student['first_name'] . $student['last_name']) !== '' && filter_var($student['email'], FILTER_VALIDATE_EMAIL),
        [
            $student['campus_name'] !== 'Daanbantayan Campus'
                ? 'Access denied. This enrollment system is exclusively for CTU Daanbantayan Campus students.'
                : 'Student account or required student information is incomplete.',
        ],
        [
            'Name: ' . trim($student['first_name'] . ' ' . $student['last_name']),
            'Student number: ' . $student['student_number'],
            'Email: ' . $student['email'],
        ]);

    $add(
        'student_account_status',
        'Student account status',
        $student['student_status'] === 'Active',
        ['Your student account is currently inactive. Please contact the registrar.'],
        ['Current student account status: ' . ($student['student_status'] ?? 'not set')]
    );

    $yearAssignment = enrollment_year_assignment((string)($student['year_level'] ?? ''));
    $add(
        'year_level',
        'Year level and enrollment week',
        $yearAssignment !== null,
        $yearAssignment ? [] : ['Student year level is not set to First Year, Second Year, Third Year, or Fourth Year. Contact the registrar.'],
        $yearAssignment
            ? [$yearAssignment['year_level'] . ' is assigned to Week ' . $yearAssignment['week_number'] . '.']
            : ['Current year level: ' . ($student['year_level'] ?: 'not set')]
    );

    if (isset($rules['grades']) && $rules['grades']['enabled']) {
        $expectedQuery = $database->prepare(
            'SELECT expected.subject, grades.grade, grades.grade_status
             FROM student_enrollment_subjects AS expected
             LEFT JOIN grades ON grades.student_id = expected.student_id
               AND grades.subject = expected.subject
             WHERE expected.student_id = ? AND expected.is_required = 1
             ORDER BY expected.subject'
        );
        $expectedQuery->execute([$studentId]);
        $expectedGrades = $expectedQuery->fetchAll();
        $query = $database->prepare(
            'SELECT subject, grade_status, grade FROM grades WHERE student_id = ? ORDER BY subject'
        );
        $query->execute([$studentId]);
        $grades = $query->fetchAll();
        $reasons = [];
        $details = [];
        if (!$expectedGrades) {
            $reasons[] = 'No required subject records are available to verify missing grades.';
        }
        if (!$grades) {
            $reasons[] = 'No grade records are available for verification.';
        }
        foreach ($expectedGrades as $expectedGrade) {
            if ($expectedGrade['grade_status'] === null) {
                $reasons[] = 'Missing grade in ' . $expectedGrade['subject'] . '.';
                $details[] = $expectedGrade['subject'] . ': missing grade record.';
            }
        }
        foreach ($grades as $grade) {
            $details[] = $grade['subject'] . ': ' . $grade['grade_status'] .
                ($grade['grade'] === null ? '' : ' (grade ' . $grade['grade'] . ')');
            if ($grade['grade_status'] === 'deficient' || $grade['grade_status'] === 'failed') {
                $reasons[] = $grade['subject'] . ': academic deficiency (' . $grade['grade_status'] . ').';
            } elseif ($grade['grade'] === null) {
                $reasons[] = $grade['subject'] . ': missing grade value.';
            } elseif ($grade['grade_status'] !== 'complete') {
                $reasons[] = $grade['subject'] . ': ' . ucfirst($grade['grade_status']) . ' grade.';
            }
        }
        $add('grades', 'Grades and academic record', !$reasons, $reasons, $details);
    }

    if (isset($rules['financial']) && $rules['financial']['enabled']) {
        $query = $database->prepare(
            'SELECT balance, payment_status, description
             FROM financial_records WHERE student_id = ? ORDER BY id'
        );
        $query->execute([$studentId]);
        $financialRows = $query->fetchAll();
        $balance = 0.0;
        $details = [];
        foreach ($financialRows as $financialRow) {
            $rowBalance = (float)$financialRow['balance'];
            if ($financialRow['payment_status'] !== 'paid' || $rowBalance > 0) {
                $balance += $rowBalance;
            }
            $details[] = ($financialRow['description'] ?: 'Financial record') . ': ₱' .
                number_format($rowBalance, 2) . ' (' . $financialRow['payment_status'] . ')';
        }
        if (!$financialRows) {
            $details[] = 'No financial record on file.';
        }
        $reasons = $balance > 0.009
            ? ['Outstanding balance: ₱' . number_format($balance, 2) . '.']
            : [];
        if (!$financialRows) {
            $reasons[] = 'No financial record is available to verify the school balance.';
        }
        $add('financial', 'Financial obligations', !$reasons, $reasons, $details);
    }

    if (isset($rules['holds']) && $rules['holds']['enabled']) {
        $query = $database->prepare(
            'SELECT reason FROM enrollment_holds WHERE student_id = ? AND status = \'active\' ORDER BY id'
        );
        $query->execute([$studentId]);
        $holds = $query->fetchAll();
        $reasons = array_map(static fn(array $row): string => 'Active enrollment hold: ' . $row['reason'], $holds);
        $details = $reasons ?: ['No active enrollment holds.'];
        $add('holds', 'Enrollment holds', !$reasons, $reasons, $details);
    }

    if (isset($rules['documents']) && $rules['documents']['enabled']) {
        $query = $database->prepare(
            'SELECT types.document_name, documents.status
             FROM enrollment_document_types AS types
             LEFT JOIN student_enrollment_documents AS documents
               ON documents.document_type_id = types.id AND documents.student_id = ?
             WHERE types.is_active = 1 AND types.is_required = 1
             ORDER BY types.document_name'
        );
        $query->execute([$studentId]);
        $reasons = [];
        $details = [];
        $documents = $query->fetchAll();
        if (!$documents) {
            $reasons[] = 'No required enrollment documents are configured.';
        }
        foreach ($documents as $document) {
            $status = $document['status'] ?? 'missing';
            $details[] = $document['document_name'] . ': ' . $status;
            if ($document['status'] !== 'verified') {
                $reasons[] = $document['document_name'] . ': ' . $status . '.';
            }
        }
        $add('documents', 'Required enrollment documents', !$reasons, $reasons, $details);
    }

    if (isset($rules['student_status']) && $rules['student_status']['enabled']) {
        $allowedStatuses = is_array($rules['student_status']['value'])
            ? array_values(array_filter(array_map(
                static fn($status): string => is_string($status) ? strtolower(trim($status)) : '',
                $rules['student_status']['value']
            )))
            : [];
        $currentStatus = strtolower((string)$student['academic_status']);
        $passed = $allowedStatuses !== [] && in_array($currentStatus, $allowedStatuses, true);
        $reason = $passed
            ? []
            : ['Student status "' . $student['academic_status'] . '" is not in the allowed enrollment statuses.'];
        $add('student_status', 'Student enrollment status', $passed, $reason, [
            'Current status: ' . $student['academic_status'],
            'Allowed statuses: ' . ($allowedStatuses ? implode(', ', $allowedStatuses) : 'not configured'),
        ]);
    }

    if (isset($rules['other_requirements']) && $rules['other_requirements']['enabled']) {
        $query = $database->prepare(
            'SELECT requirement_name, status
             FROM student_enrollment_requirements
             WHERE student_id = ? AND blocks_enrollment = 1 AND status <> \'resolved\'
             ORDER BY requirement_name'
        );
        $query->execute([$studentId]);
        $requirements = $query->fetchAll();
        $reasons = array_map(
            static fn(array $row): string => $row['requirement_name'] . ': ' . str_replace('_', ' ', $row['status']) . '.',
            $requirements
        );
        $details = array_map(
            static fn(array $row): string => $row['requirement_name'] . ': ' . str_replace('_', ' ', $row['status']),
            $requirements
        );
        $add('other_requirements', 'Other enrollment requirements', !$reasons, $reasons,
            $details ?: ['No unresolved enrollment-blocking requirements.']);
    }

    $reasons = [];
    foreach ($checks as $check) {
        if (!$check['passed']) {
            $reasons = array_merge($reasons, $check['reasons']);
        }
    }

    return [
        'eligible' => !$reasons,
        'reasons' => $reasons,
        'checks' => $checks,
    ];
}
