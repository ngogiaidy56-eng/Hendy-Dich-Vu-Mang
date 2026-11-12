<?php
declare(strict_types=1);

namespace Hendy;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $url = Env::get('DATABASE_URL');
        if ($url === null) {
            throw new RuntimeException('Thiếu DATABASE_URL. Hãy cấu hình PostgreSQL trong môi trường chạy.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['postgres', 'postgresql'], true)
            || empty($parts['host'])
            || empty($parts['path'])) {
            throw new RuntimeException('DATABASE_URL không đúng định dạng PostgreSQL URL.');
        }

        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? 5432);
        $database = rawurldecode(ltrim((string) $parts['path'], '/'));
        $username = rawurldecode((string) ($parts['user'] ?? ''));
        $password = rawurldecode((string) ($parts['pass'] ?? ''));

        if ($port < 1 || $port > 65535
            || !preg_match('/\A[A-Za-z0-9.-]+\z/D', $host)
            || !preg_match('/\A[A-Za-z0-9_.-]+\z/D', $database)
            || $username === '') {
            throw new RuntimeException('Thông tin host, port hoặc database trong DATABASE_URL không hợp lệ.');
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $sslMode = Env::bool('DATABASE_SSL', true)
            ? (string) ($query['sslmode'] ?? 'require')
            : 'disable';
        $allowedSslModes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
        if (!in_array($sslMode, $allowedSslModes, true)) {
            $sslMode = 'require';
        }

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $database, $sslMode);

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
                PDO::ATTR_PERSISTENT => false,
            ]);
            self::$connection->exec("SET TIME ZONE 'UTC'");
        } catch (PDOException $error) {
            throw new RuntimeException('Không thể kết nối PostgreSQL. Hãy kiểm tra DATABASE_URL và quyền truy cập.', 0, $error);
        }

        return self::$connection;
    }

    public static function run(string $sql, array $parameters = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    public static function one(string $sql, array $parameters = []): ?array
    {
        $row = self::run($sql, $parameters)->fetch();
        return is_array($row) ? $row : null;
    }

    public static function all(string $sql, array $parameters = []): array
    {
        return self::run($sql, $parameters)->fetchAll();
    }

    public static function value(string $sql, array $parameters = []): mixed
    {
        $value = self::run($sql, $parameters)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public static function migrate(): void
    {
        $schema = dirname(__DIR__) . '/database/schema.sql';
        if (!is_file($schema) || !is_readable($schema)) {
            throw new RuntimeException('Không tìm thấy database/schema.sql.');
        }

        $pdo = self::connection();
        $lockKey = 'hendy-bot-cybertech-schema';
        self::run('SELECT pg_advisory_lock(hashtext(?))', [$lockKey]);
        try {
            $pdo->exec((string) file_get_contents($schema));
        } finally {
            self::run('SELECT pg_advisory_unlock(hashtext(?))', [$lockKey]);
        }
    }

    public static function check(): bool
    {
        return (int) self::value('SELECT 1') === 1;
    }

    public static function close(): void
    {
        self::$connection = null;
    }
}
