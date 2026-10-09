<?php
namespace App\services;
use App\Models\DB;
final class Accounts {
    public static function limit(string $action,string $identity,int $max=8): void {
        $bucket=hash('sha256',$action.'|'.$identity);
        DB::query('INSERT INTO rate_limits(bucket,attempts,started_at) VALUES(?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(started_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,attempts+1), started_at=IF(started_at < DATE_SUB(NOW(),INTERVAL 15 MINUTE),NOW(),started_at)',[$bucket]);
        if((int)DB::one('SELECT attempts FROM rate_limits WHERE bucket=?',[$bucket])['attempts']>$max) throw new \RuntimeException('Too many attempts. Try again in 15 minutes.',429);
    }
    public static function token(int $uid,string $kind): void {
        $raw=bin2hex(random_bytes(32));
        DB::query('UPDATE tokens SET used_at=NOW() WHERE user_id=? AND kind=? AND used_at IS NULL',[$uid,$kind]);
        DB::query('INSERT INTO tokens(user_id,kind,token_hash,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))',[$uid,$kind,hash('sha256',$raw)]);
        $email=DB::one('SELECT email FROM users WHERE id=?',[$uid])['email'];
        $link=url('/'.($kind==='reset'?'reset':'verify').'?token='.$raw);
        $message="My Store\n".($kind==='reset'?'Reset your password':'Verify your email')." (expires in one hour):\n$link\n";
        if(env('MAIL_MODE','log')==='mail') {
            if(!mail($email,'My Store account',$message,'From: '.env('MAIL_FROM'))) throw new \RuntimeException('Email delivery is unavailable. Please try again.');
        } else {
            if(env('APP_ENV')==='production') throw new \RuntimeException('Production email delivery must be configured.');
            if(!is_dir(ROOT.'/storage/mail')) mkdir(ROOT.'/storage/mail',0700,true);
            file_put_contents(ROOT.'/storage/mail/'.bin2hex(random_bytes(8)).'.txt',"To: $email\n$message",LOCK_EX);
        }
    }
    public static function consume(string $raw,string $kind,?string $password=null): void {
        $db=DB::connection(); $db->beginTransaction();
        try {
            $t=DB::one('SELECT * FROM tokens WHERE token_hash=? AND kind=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[hash('sha256',$raw),$kind]);
            if(!$t) throw new \RuntimeException('This link is invalid or expired.');
            if($kind==='reset') DB::query('UPDATE users SET password=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$t['user_id']]);
            else DB::query('UPDATE users SET verified_at=NOW() WHERE id=?',[$t['user_id']]);
            DB::query('UPDATE tokens SET used_at=NOW() WHERE id=?',[$t['id']]); $db->commit();
        } catch(\Throwable $e) { $db->rollBack(); throw $e; }
    }
    public static function password(string $password): void { if(strlen($password)<12 || strlen($password)>200) throw new \RuntimeException('Use a password between 12 and 200 characters.'); }
}
