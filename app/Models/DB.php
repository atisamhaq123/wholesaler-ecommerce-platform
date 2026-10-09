<?php
namespace App\Models;
use PDO;
final class DB {
    private static ?PDO $pdo = null;
    public static function connection(): PDO {
        return self::$pdo ??= new PDO('mysql:host=' . env('DB_HOST','127.0.0.1') . ';port=' . env('DB_PORT','3306') . ';dbname=' . env('DB_DATABASE','my_store') . ';charset=utf8mb4', env('DB_USERNAME','root'), env('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    public static function query(string $sql, array $params = []): \PDOStatement { $s=self::connection()->prepare($sql); $s->execute($params); return $s; }
    public static function all(string $sql,array $params=[]): array { return self::query($sql,$params)->fetchAll(); }
    public static function one(string $sql,array $params=[]): ?array { return self::query($sql,$params)->fetch() ?: null; }
    public static function id(): int { return (int)self::connection()->lastInsertId(); }
    public static function setting(string $key,string $default=''): string { return (string)(self::one('SELECT value FROM settings WHERE name=?',[$key])['value'] ?? $default); }
    public static function audit(int $actor,string $action,string $entity): void { self::query('INSERT INTO audit_logs(actor_id,action,entity) VALUES(?,?,?)',[$actor,$action,$entity]); }
}
