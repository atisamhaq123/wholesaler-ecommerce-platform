<?php
namespace App\services;
use App\Models\DB;
final class Payment {
    public static function mode(): string { if(env('PAYMENT_MODE','demo')==='demo' || env('STRIPE_SECRET_KEY')==='') return 'demo'; if(!str_starts_with(env('STRIPE_SECRET_KEY'),'sk_test_')) throw new \RuntimeException('Only Stripe test keys are supported by this MVP.'); return 'stripe'; }
    public static function api(string $path,array $params,string $key,string $method='POST'): array {
        if(self::mode()!=='stripe') throw new \RuntimeException('Stripe test mode is not configured.');
        $curl=curl_init('https://api.stripe.com/v1/'.$path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>env('STRIPE_SECRET_KEY').':',CURLOPT_HTTPHEADER=>['Idempotency-Key: '.$key],CURLOPT_TIMEOUT=>25]);
        if($method==='POST')curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($params)]);
        $raw=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        if($raw===false || $code>=400) throw new \RuntimeException('Payment provider unavailable. Retry shortly; no payment has been confirmed.');
        $result=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return $result;
    }
    public static function session(int $id): string {
        // Stripe requires an expiry at least 30 minutes after session creation.
        // Extend only the first payment attempt to 31 minutes; retries use the same expiry and idempotency key.
        $db=DB::connection();$db->beginTransaction();try {
            $locked=DB::one('SELECT c.*,p.provider_session FROM checkouts c JOIN payments p ON p.checkout_id=c.id WHERE c.id=? FOR UPDATE',[$id]);
            if(!$locked || $locked['mode']!=='stripe' || $locked['status']!=='pending' || strtotime($locked['expires_at'])<=time())throw new \RuntimeException('This checkout is no longer payable.');
            if($locked['provider_session']===null){DB::query('UPDATE checkouts SET expires_at=DATE_ADD(NOW(),INTERVAL 31 MINUTE) WHERE id=?',[$id]);DB::query('UPDATE inventory_reservations SET expires_at=DATE_ADD(NOW(),INTERVAL 31 MINUTE) WHERE checkout_id=? AND status="reserved"',[$id]);}
            $db->commit();
        }catch(\Throwable $e){$db->rollBack();throw $e;}
        $c=DB::one('SELECT * FROM checkouts WHERE id=?',[$id]);
        if(!$c || $c['mode']!=='stripe' || $c['status']!=='pending' || strtotime($c['expires_at'])<=time()) throw new \RuntimeException('This checkout is no longer payable.');
        $params=['mode'=>'payment','payment_method_types'=>['card'],'success_url'=>url('/checkout?id='.$id.'&returned=1'),'cancel_url'=>url('/checkout?id='.$id.'&cancelled=1'),'expires_at'=>strtotime($c['expires_at']),'metadata'=>['checkout_id'=>$id],'payment_intent_data'=>['transfer_group'=>'MY_STORE_'.$id,'metadata'=>['checkout_id'=>$id]],'line_items'=>[['price_data'=>['currency'=>strtolower($c['currency']),'unit_amount'=>$c['total'],'product_data'=>['name'=>'My Store checkout #'.$id.' (including tax, delivery and buyer fee)']],'quantity'=>1]]];
        // Persist the exact request before networking so an uncertain response can be retried safely.
        $request=DB::one('SELECT request_payload FROM payments WHERE checkout_id=?',[$id]);
        if($request['request_payload'])$params=json_decode($request['request_payload'],true,512,JSON_THROW_ON_ERROR);
        else DB::query('UPDATE payments SET request_payload=? WHERE checkout_id=? AND request_payload IS NULL',[json_encode($params),$id]);
        $params=json_decode(DB::one('SELECT request_payload FROM payments WHERE checkout_id=?',[$id])['request_payload'],true,512,JSON_THROW_ON_ERROR);
        $r=self::api('checkout/sessions',$params,'checkout-'.$id);
        DB::query('UPDATE payments SET provider_session=? WHERE checkout_id=?',[$r['id'],$id]); return $r['url'];
    }
    public static function cancelSession(int $id): bool {
        $p=DB::one('SELECT p.*,c.currency FROM payments p JOIN checkouts c ON c.id=p.checkout_id WHERE p.checkout_id=?',[$id]);
        if(!$p || !$p['provider_session'])return true;
        $session=self::api('checkout/sessions/'.rawurlencode($p['provider_session']),[],'retrieve-'.$id,'GET');
        if(($session['payment_status']??'')==='paid'){
            if((int)$session['amount_total']!==(int)$p['amount'] || strtoupper($session['currency'])!==$p['currency'])throw new \RuntimeException('Provider confirmation amount mismatch.');
            Checkout::confirm($id,'stripe','reconcile-'.$p['provider_session'],$session['payment_intent']);return false;
        }
        if($session['status']==='open')self::api('checkout/sessions/'.rawurlencode($p['provider_session']).'/expire',[],'expire-'.$id);
        return true;
    }
    public static function signature(string $body,string $header,string $secret,?int $now=null): bool {
        if($secret==='') return false; $parts=[];foreach(explode(',',$header) as $p){$pair=explode('=',$p,2);if(count($pair)===2)$parts[$pair[0]][]=$pair[1];}
        $t=(int)($parts['t'][0]??0);if(abs(($now??time())-$t)>300)return false;
        foreach($parts['v1']??[] as $sig) if(hash_equals(hash_hmac('sha256',$t.'.'.$body,$secret),$sig))return true;return false;
    }
    public static function webhook(string $body,string $signature): void {
        if(!self::signature($body,$signature,env('STRIPE_WEBHOOK_SECRET'))) throw new \RuntimeException('Invalid webhook signature.',400);
        $event=json_decode($body,true,512,JSON_THROW_ON_ERROR);$object=$event['data']['object'];$type=$event['type'];
        if(DB::one('SELECT event_id FROM webhook_events WHERE event_id=?',[$event['id']]))return;
        if(in_array($type,['checkout.session.completed','checkout.session.async_payment_succeeded'],true)) {
            if(($object['payment_status']??'')!=='paid')return;
            $p=DB::one('SELECT p.*,c.currency FROM payments p JOIN checkouts c ON c.id=p.checkout_id WHERE p.provider_session=? AND p.provider="stripe"',[$object['id']]);
            if(!$p || (int)$object['amount_total']!==(int)$p['amount'] || strtoupper($object['currency'])!==$p['currency']) throw new \RuntimeException('Payment does not match checkout.',400);
            Checkout::confirm((int)$p['checkout_id'],'stripe',$event['id'],$object['payment_intent']);
        } elseif(in_array($type,['checkout.session.expired','checkout.session.async_payment_failed'],true)) {
            $p=DB::one('SELECT * FROM payments WHERE provider_session=?',[$object['id']]);if($p)Checkout::release((int)$p['checkout_id'],$type==='checkout.session.expired'?'expired':'failed',true);
            DB::query('INSERT IGNORE INTO webhook_events(event_id,type) VALUES(?,?)',[$event['id'],$type]);
        } elseif(in_array($type,['refund.updated','refund.created','refund.failed'],true)) {
            $r=DB::one('SELECT * FROM refunds WHERE provider_ref=?',[$object['id']]);if($r && $object['status']==='succeeded') Orders::completeRefund((int)$r['order_id']);
            elseif($r)DB::query('UPDATE refunds SET status=? WHERE id=?',[$object['status'],$r['id']]);
            DB::query('INSERT IGNORE INTO webhook_events(event_id,type) VALUES(?,?)',[$event['id'],$type]);
        }
    }
}
