<?php
declare(strict_types=1);

function auth_rate_limit_keys(string $scope, string $identifier): array
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return [
        hash('sha256', $scope . "\0ip\0" . $ip),
        hash('sha256', $scope . "\0identifier\0" . strtolower(trim($identifier))),
    ];
}

function auth_rate_limit_retry_after(
    PDO $database,
    string $scope,
    string $identifier,
    int $windowSeconds = 60
): int {
    $keys = auth_rate_limit_keys($scope, $identifier);
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $expire = $database->prepare(
        "DELETE FROM auth_rate_limits
         WHERE scope = ?
           AND (
             (locked_until IS NOT NULL AND locked_until <= ?)
             OR (locked_until IS NULL AND window_started_at < ?)
           )"
    );
    $expire->execute([
        $scope,
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', time() - $windowSeconds),
    ]);

    $query = $database->prepare(
        "SELECT locked_until
         FROM auth_rate_limits
         WHERE scope = ? AND subject_hash IN ({$placeholders})
           AND locked_until > ?"
    );
    $query->execute(array_merge([$scope], $keys, [date('Y-m-d H:i:s')]));
    $retryAfter = 0;
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $lockedUntil) {
        $retryAfter = max($retryAfter, (int)strtotime((string)$lockedUntil) - time());
    }

    return max(0, $retryAfter);
}

function auth_rate_limit_record_attempt(
    PDO $database,
    string $scope,
    string $identifier,
    int $maxAttempts = 5,
    int $windowSeconds = 60
): void {
    $now = time();
    $nowText = date('Y-m-d H:i:s', $now);
    $cutoff = date('Y-m-d H:i:s', $now - $windowSeconds);
    $lockedUntil = date('Y-m-d H:i:s', $now + $windowSeconds);
    $statement = $database->prepare(
        'INSERT INTO auth_rate_limits
           (scope, subject_hash, attempts, window_started_at, locked_until)
         VALUES (?, ?, 1, ?, NULL)
         ON DUPLICATE KEY UPDATE
           locked_until = CASE
             WHEN window_started_at < ? THEN NULL
             WHEN attempts + 1 >= ? THEN ?
             ELSE locked_until
           END,
           attempts = CASE
             WHEN window_started_at < ? THEN 1
             ELSE attempts + 1
           END,
           window_started_at = CASE
             WHEN window_started_at < ? THEN ?
             ELSE window_started_at
           END'
    );

    foreach (auth_rate_limit_keys($scope, $identifier) as $key) {
        $statement->execute([
            $scope,
            $key,
            $nowText,
            $cutoff,
            $maxAttempts,
            $lockedUntil,
            $cutoff,
            $cutoff,
            $nowText,
        ]);
    }
}

function auth_rate_limit_clear(PDO $database, string $scope, string $identifier): void
{
    $keys = auth_rate_limit_keys($scope, $identifier);
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    $statement = $database->prepare(
        "DELETE FROM auth_rate_limits WHERE scope = ? AND subject_hash IN ({$placeholders})"
    );
    $statement->execute(array_merge([$scope], $keys));
}
