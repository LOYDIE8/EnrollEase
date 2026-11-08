<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../rate-limit.php';

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '1800');

    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    session_name('ENROLLEASE_ADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (
        isset($_SESSION['admin_user_id'], $_SESSION['admin_last_activity']) &&
        time() - (int)$_SESSION['admin_last_activity'] > 1800
    ) {
        unset($_SESSION['admin_user_id'], $_SESSION['admin_last_activity']);
        session_regenerate_id(true);
    }
    if (isset($_SESSION['admin_user_id'])) {
        $_SESSION['admin_last_activity'] = time();
    }

    if (!isset($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
}

function admin_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function require_http_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        admin_json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
    }
}

function verify_request_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $expected = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    if (!hash_equals($expected, $origin)) {
        admin_json_response(['success' => false, 'message' => 'Request could not be verified.'], 403);
    }
}

function verify_csrf_token($token): void
{
    $known = $_SESSION['admin_csrf_token'] ?? '';
    if (!is_string($token) || $known === '' || !hash_equals($known, $token)) {
        admin_json_response(['success' => false, 'message' => 'Request could not be verified.'], 403);
    }
}

function read_json_request(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 8192) {
        admin_json_response(['success' => false, 'message' => 'Request is too large.'], 413);
    }

    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($decoded)) {
        admin_json_response(['success' => false, 'message' => 'Invalid request.'], 400);
    }

    return $decoded;
}

function admin_role_assignment_is_valid(array $admin): bool
{
    $roleKey = $admin['role_key'] ?? null;
    $departmentCode = $admin['department_code'] ?? null;
    if (in_array($roleKey, ['SHARED_ADMIN', 'MIS'], true)) {
        return ($admin['department_id'] ?? null) === null;
    }

    $departmentByRole = [
        'SSG_ADMIN' => 'SSG',
        'CODETECH_ADMIN' => 'CodeTech',
        'COED_ADMIN' => 'COED',
        'HM_ADMIN' => 'HM',
        'BIT_ADMIN' => 'BIT',
        'REGISTRAR_ADMIN' => 'Registrar',
        'CLINIC_ADMIN' => 'Clinic',
        'CASHIER_ADMIN' => 'Cashier',
        'GUIDANCE_ADMIN' => 'Guidance',
    ];
    return is_string($roleKey) && isset($departmentByRole[$roleKey]) &&
        $departmentCode === $departmentByRole[$roleKey] &&
        $admin['department_id'] !== null;
}

function current_admin(PDO $database): ?array
{
    $userId = filter_var($_SESSION['admin_user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) {
        return null;
    }

    $statement = $database->prepare(
        'SELECT u.id AS user_id, u.username, u.first_name, u.last_name, u.password_hash,
                a.id AS admin_id, a.role_id, a.department_id,
                r.role_key, r.role_name, d.department_code
         FROM users AS u
         INNER JOIN admins AS a ON a.user_id = u.id
         INNER JOIN roles AS r ON r.id = a.role_id
         LEFT JOIN departments AS d ON d.id = a.department_id
         WHERE u.id = ?
           AND u.account_type = \'admin\'
           AND u.is_active = 1
           AND a.is_active = 1
           AND a.deactivated_at IS NULL
         LIMIT 1'
    );
    $statement->execute([$userId]);
    $admin = $statement->fetch();
    $passwordFingerprint = $_SESSION['admin_password_fingerprint'] ?? '';
    if (
        !$admin ||
        !is_string($passwordFingerprint) ||
        !hash_equals($passwordFingerprint, hash('sha256', $admin['password_hash']))
    ) {
        unset($_SESSION['admin_user_id'], $_SESSION['admin_password_fingerprint']);
        return null;
    }

    if (!admin_role_assignment_is_valid($admin)) {
        unset($_SESSION['admin_user_id'], $_SESSION['admin_password_fingerprint']);
        return null;
    }

    unset($admin['password_hash']);
    $admin['name'] = trim($admin['first_name'] . ' ' . $admin['last_name']);
    return $admin;
}

function require_admin_api(PDO $database): array
{
    $admin = current_admin($database);
    if (!$admin) {
        admin_json_response(['success' => false, 'message' => 'Authentication required.'], 401);
    }

    return $admin;
}

function admin_has_permission(PDO $database, array $admin, string $permission): bool
{
    $area = explode('.', $permission, 2)[0];
    $statement = $database->prepare(
        'SELECT 1 FROM role_permissions
         WHERE role_id = ? AND permission_key IN (?, ?, \'system.*\')
         LIMIT 1'
    );
    $statement->execute([
        (int)$admin['role_id'],
        $permission,
        $area . '.*',
    ]);
    return (bool)$statement->fetchColumn();
}

function require_admin_permission(PDO $database, array $admin, string $permission): void
{
    if (!admin_has_permission($database, $admin, $permission)) {
        admin_json_response([
            'success' => false,
            'message' => 'You are not authorized to perform this action.',
        ], 403);
    }
}

function admin_can_read_students(PDO $database, array $admin): bool
{
    return admin_has_permission($database, $admin, 'students.read') ||
        admin_has_permission($database, $admin, 'students.read.assigned');
}

function admin_can_manage_students(PDO $database, array $admin): bool
{
    return admin_has_permission($database, $admin, 'students.write');
}

function admin_can_read_section(PDO $database, array $admin, string $section): bool
{
    switch ($section) {
        case 'appointments':
            return admin_can_read_appointments($database, $admin);
        case 'eligibility':
        case 'eligibility-records':
            return admin_has_permission($database, $admin, 'students.update');
        case 'students':
            return admin_can_read_students($database, $admin);
        case 'admins':
            return admin_has_permission($database, $admin, 'admins.read') || admin_has_permission($database, $admin, 'admins.*');
        case 'enrollments':
            return admin_has_permission($database, $admin, 'enrollment_records.read');
        case 'clearances':
            return admin_has_permission($database, $admin, 'clearances.read') ||
                admin_has_permission($database, $admin, 'clearances.medical.read') ||
                admin_has_permission($database, $admin, 'clearances.guidance.read');
        case 'payments':
            return admin_has_permission($database, $admin, 'payments.read');
        default:
            return false;
    }
}

function admin_can_read_appointments(PDO $database, array $admin): bool
{
    return admin_has_permission($database, $admin, 'appointments.read') ||
        admin_has_permission($database, $admin, 'appointments.read.assigned');
}

function admin_can_update_appointments(PDO $database, array $admin): bool
{
    return admin_has_permission($database, $admin, 'appointments.update') ||
        admin_has_permission($database, $admin, 'appointments.update.assigned');
}

function ensure_default_admin_exists(PDO $database): void
{
    try {
        $count = (int)$database->query("SELECT COUNT(*) FROM admins WHERE is_active = 1")->fetchColumn();
        if ($count === 0) {
            $roleStmt = $database->prepare("SELECT id FROM roles WHERE role_key = 'SHARED_ADMIN' LIMIT 1");
            $roleStmt->execute();
            $roleId = $roleStmt->fetchColumn();
            if (!$roleId) {
                $database->exec("INSERT INTO roles (role_key, role_name, description) VALUES ('SHARED_ADMIN', 'System Administrator', 'Full administrative access across all campus departments')");
                $roleId = (int)$database->lastInsertId();
            }

            $userStmt = $database->prepare("SELECT id FROM users WHERE username = 'admin' OR email = 'admin@example.com' LIMIT 1");
            $userStmt->execute();
            $userId = $userStmt->fetchColumn();
            $hash = password_hash('Admin-Enrollment-2026!', PASSWORD_DEFAULT);
            if (!$userId) {
                $insertUser = $database->prepare("INSERT INTO users (username, email, password_hash, first_name, last_name, account_type, is_active) VALUES ('admin', 'admin@example.com', ?, 'System', 'Admin', 'admin', 1)");
                $insertUser->execute([$hash]);
                $userId = (int)$database->lastInsertId();
            } else {
                $database->prepare("UPDATE users SET password_hash = ?, is_active = 1, account_type = 'admin' WHERE id = ?")->execute([$hash, (int)$userId]);
            }

            $adminStmt = $database->prepare("SELECT id FROM admins WHERE user_id = ? LIMIT 1");
            $adminStmt->execute([$userId]);
            $adminId = $adminStmt->fetchColumn();
            if (!$adminId) {
                $database->prepare("INSERT INTO admins (user_id, role_id, department_id, is_active) VALUES (?, ?, NULL, 1)")->execute([(int)$userId, (int)$roleId]);
            } else {
                $database->prepare("UPDATE admins SET is_active = 1, role_id = ? WHERE id = ?")->execute([(int)$roleId, (int)$adminId]);
            }
        }
    } catch (Throwable $e) {
        // Silently continue if database tables are not initialized yet
    }
}
