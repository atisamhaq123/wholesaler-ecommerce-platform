<?php
namespace App\middleware;
use App\Models\DB;
final class Auth {
    public static function user(): ?array { return empty($_SESSION['uid']) ? null : DB::one('SELECT u.*,b.name business_name,b.status business_status FROM users u JOIN businesses b ON b.user_id=u.id WHERE u.id=? AND u.active=1',[$_SESSION['uid']]); }
    public static function require(string ...$roles): array { $u=self::user(); if (!$u) redirect('/login'); if ($roles && !in_array($u['role'],$roles,true)) throw new \RuntimeException('Access denied.',403); return $u; }
    public static function csrf(): void { if (!isset($_POST['csrf'],$_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],(string)$_POST['csrf'])) throw new \RuntimeException('Your session expired. Refresh the page and try again.',419); }
    public static function order(array $order,array $user): void { if ($user['role'] !== 'admin' && !($user['role']==='retailer' && (int)$order['retailer_id']===(int)$user['id']) && !($user['role']==='wholesaler' && (int)$order['supplier_id']===(int)$user['id'])) throw new \RuntimeException('Access denied.',403); }
    public static function conversation(int $id,int $user): void { if (!DB::one('SELECT id FROM conversation_participants WHERE conversation_id=? AND user_id=?',[$id,$user])) throw new \RuntimeException('Access denied.',403); }
}
