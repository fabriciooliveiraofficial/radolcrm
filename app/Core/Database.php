<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            (int) ($config['port'] ?? 3306),
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );

        $timezoneOffset = (new \DateTime('now', new \DateTimeZone(date_default_timezone_get())))->format('P');

        $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '{$timezoneOffset}'",
        ]);

        $this->pdo->exec("SET time_zone = '{$timezoneOffset}'");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $trimmed = ltrim($sql);
        if (!str_starts_with(strtoupper($trimmed), 'INSERT')) {
            $table = $trimmed;
            $fields = array_keys($params);
            $placeholders = array_fill(0, count($fields), '?');
            $sql = sprintf(
                "INSERT INTO %s (`%s`) VALUES (%s)",
                $table,
                implode('`, `', $fields),
                implode(', ', $placeholders)
            );
            $params = array_values($params);
        }

        $this->query($sql, $params);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $values = [];
        foreach ($data as $column => $value) {
            $sets[] = "`{$column}` = ?";
            $values[] = $value;
        }
        $sql = sprintf("UPDATE %s SET %s WHERE %s", $table, implode(', ', $sets), $where);
        return $this->execute($sql, array_merge($values, $whereParams));
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}

