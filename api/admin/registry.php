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

function registry_activity_log(PDO $database, array $admin, string $actionKey, array $details): void
{
    $logStmt = $database->prepare(
        'INSERT INTO activity_logs (actor_user_id, actor_role_id, department_id, action_key, entity_type, entity_id, details, ip_address, user_agent)
         VALUES (?, ?, ?, ?, \'registry\', NULL, ?, ?, ?)'
    );
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $logStmt->execute([
        (int)$admin['user_id'],
        (int)$admin['role_id'],
        $admin['department_id'],
        $actionKey,
        json_encode($details, JSON_UNESCAPED_SLASHES),
        $ip,
        $ua,
    ]);
}

try {
    $database = admin_database();
    $admin = require_admin_api($database);
    if (!admin_has_permission($database, $admin, 'students.read') && !admin_has_permission($database, $admin, 'students.manage')) {
        admin_json_response(['success' => false, 'message' => 'You are not authorized to perform this action.'], 403);
    }

    if ($method === 'GET') {
        $settingStmt = $database->prepare('SELECT setting_value FROM system_settings WHERE setting_key = \'require_registry_match\' LIMIT 1');
        $settingStmt->execute();
        $rawSetting = $settingStmt->fetchColumn();
        $requireMatch = false;
        if ($rawSetting !== false) {
            $decoded = json_decode((string)$rawSetting, true);
            $requireMatch = ($decoded === true || $decoded === 1 || $decoded === '1' || $rawSetting === 'true' || $rawSetting === '1');
        }

        $countStmt = $database->query('SELECT COUNT(*) FROM enrolled_student_registry');
        $totalCount = (int)$countStmt->fetchColumn();

        $recentStmt = $database->query('SELECT id, student_number, last_name, program, year_level, created_at FROM enrolled_student_registry ORDER BY id DESC LIMIT 25');
        $records = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        admin_json_response([
            'success' => true,
            'require_registry_match' => $requireMatch,
            'total_count' => $totalCount,
            'recent_records' => $records,
        ]);
    }

    verify_request_origin();

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = read_json_request();
    } else {
        $input = $_POST;
    }

    verify_csrf_token($input['csrf_token'] ?? null);
    $action = (string)($input['action'] ?? '');

    if ($action === 'toggle_setting') {
        require_admin_permission($database, $admin, 'students.manage');
        $enabled = !empty($input['enabled']);
        $valueJson = json_encode($enabled);

        $stmt = $database->prepare(
            'INSERT INTO system_settings (setting_key, setting_value, updated_by)
             VALUES (\'require_registry_match\', :val, :admin_id)
             ON DUPLICATE KEY UPDATE setting_value = :val2, updated_by = :admin_id2'
        );
        $stmt->execute([
            ':val' => $valueJson,
            ':admin_id' => $admin['admin_id'],
            ':val2' => $valueJson,
            ':admin_id2' => $admin['admin_id'],
        ]);

        registry_activity_log(
            $database,
            $admin,
            'registry.setting_updated',
            ['require_registry_match' => $enabled]
        );

        admin_json_response([
            'success' => true,
            'message' => 'Registry match requirement ' . ($enabled ? 'enabled' : 'disabled') . '.',
            'require_registry_match' => $enabled,
        ]);
    }

    if ($action === 'import_csv') {
        require_admin_permission($database, $admin, 'students.manage');
        $csvContent = '';
        if (isset($_FILES['csv_file']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
        } elseif (!empty($input['csv_content'])) {
            $csvContent = (string)$input['csv_content'];
        } else {
            admin_json_response(['success' => false, 'message' => 'No CSV file or content provided.'], 400);
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent));
        if (empty($lines) || empty($lines[0])) {
            admin_json_response(['success' => false, 'message' => 'The CSV is empty.'], 400);
        }

        $header = str_getcsv(array_shift($lines));
        $headerMap = [];
        foreach ($header as $idx => $col) {
            $cleanedCol = strtolower(trim((string)$col, " \t\n\r\0\x0B\"'"));
            $headerMap[$cleanedCol] = $idx;
        }

        $requiredCols = ['student_number', 'last_name', 'program', 'year_level'];
        foreach ($requiredCols as $req) {
            if (!isset($headerMap[$req])) {
                admin_json_response([
                    'success' => false,
                    'message' => "Missing required column '{$req}' in CSV header.",
                ], 400);
            }
        }

        $imported = 0;
        $rejected = [];
        $validRows = [];

        foreach ($lines as $lineNum => $line) {
            $rowNum = $lineNum + 2;
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line);

            $studentNumber = trim((string)($row[$headerMap['student_number']] ?? ''));
            $lastName = trim((string)($row[$headerMap['last_name']] ?? ''));
            $program = trim((string)($row[$headerMap['program']] ?? ''));
            $yearLevel = trim((string)($row[$headerMap['year_level']] ?? ''));

            if (!preg_match('/\A(?:824[0-9]{4}|[0-9]{12})\z/', $studentNumber)) {
                $rejected[] = [
                    'row' => $rowNum,
                    'student_number' => $studentNumber,
                    'reason' => 'Invalid student ID (must be a 7-digit CTU ID starting with 824 or a 12-digit LRN)',
                ];
                continue;
            }

            if ($lastName === '' || strlen($lastName) > 100) {
                $rejected[] = [
                    'row' => $rowNum,
                    'student_number' => $studentNumber,
                    'reason' => 'Last name is required and must not exceed 100 characters',
                ];
                continue;
            }

            if ($program === '' || strlen($program) > 150) {
                $rejected[] = [
                    'row' => $rowNum,
                    'student_number' => $studentNumber,
                    'reason' => 'Program is required and must not exceed 150 characters',
                ];
                continue;
            }

            $canonicalYear = normalize_year_level($yearLevel);
            if (!$canonicalYear) {
                $rejected[] = [
                    'row' => $rowNum,
                    'student_number' => $studentNumber,
                    'reason' => "Invalid year level '{$yearLevel}'",
                ];
                continue;
            }

            $validRows[] = [
                'student_number' => $studentNumber,
                'last_name' => $lastName,
                'program' => $program,
                'year_level' => $canonicalYear,
            ];
        }

        if (!empty($validRows)) {
            $database->beginTransaction();
            try {
                $upsertStmt = $database->prepare(
                    'INSERT INTO enrolled_student_registry (student_number, last_name, program, year_level)
                     VALUES (:student_number, :last_name, :program, :year_level)
                     ON DUPLICATE KEY UPDATE
                        last_name = VALUES(last_name),
                        program = VALUES(program),
                        year_level = VALUES(year_level)'
                );

                foreach ($validRows as $row) {
                    $upsertStmt->execute([
                        ':student_number' => $row['student_number'],
                        ':last_name' => $row['last_name'],
                        ':program' => $row['program'],
                        ':year_level' => $row['year_level'],
                    ]);
                    $imported++;
                }

                registry_activity_log(
                    $database,
                    $admin,
                    'registry.csv_imported',
                    ['imported_count' => $imported, 'rejected_count' => count($rejected)]
                );

                $database->commit();
            } catch (Throwable $e) {
                if ($database->inTransaction()) {
                    $database->rollBack();
                }
                admin_json_response(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
            }
        }

        admin_json_response([
            'success' => true,
            'imported_count' => $imported,
            'rejected_count' => count($rejected),
            'rejected' => $rejected,
            'message' => "Successfully imported {$imported} student(s)." . (count($rejected) > 0 ? " " . count($rejected) . " row(s) were rejected." : ""),
        ]);
    }

    admin_json_response(['success' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    error_log('Registry operation failed: ' . $error->getMessage());
    admin_json_response(['success' => false, 'message' => 'The registry service is unavailable.'], 503);
}
