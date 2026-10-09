<?php
namespace App\services;
use App\Models\DB;
use App\middleware\Auth;
final class Orders {
    public static function next(string $state,string $method): array { return match($state) { 'pending'=>['accepted'], 'accepted'=>['preparing'], 'preparing'=>[$method==='pickup'?'ready_for_pickup':'shipped'], 'ready_for_pickup','shipped'=>['delivered'], default=>[] }; }
    public static function get(int $id): ?array { return DB::one('SELECT o.*,c.retailer_id,c.status payment_status,c.mode,c.currency,c.address_snapshot,c.created_at,c.fee_basis,c.fee_bps,c.tax_bps,b.name supplier FROM supplier_orders o JOIN checkouts c ON c.id=o.checkout_id JOIN businesses b ON b.user_id=o.supplier_id WHERE o.id=?',[$id]); }
    public static function transition(int $id,string $status,array $user): void {
        $db=DB::connection();$db->beginTransaction();try {
            $o=DB::one('SELECT o.*,c.retailer_id,c.status payment_status FROM supplier_orders o JOIN checkouts c ON c.id=o.checkout_id WHERE o.id=? FOR UPDATE',[$id]);
            if(!$o)throw new \RuntimeException('Order not found.',404);Auth::order($o,$user);
            if(!in_array($user['role'],['wholesaler','admin'],true) || !in_array($status,self::next($o['status'],$o['fulfillment']),true))throw new \RuntimeException('This status transition is not permitted.');
            if(!in_array($o['payment_status'],['paid','demo_paid','partially_refunded'],true))throw new \RuntimeException('Payment must be confirmed before fulfillment.');
            if(DB::one('SELECT id FROM refunds WHERE order_id=? AND status NOT IN ("failed","canceled")',[$id]))throw new \RuntimeException('This order has a pending or completed refund and cannot be fulfilled.');
            DB::query('UPDATE supplier_orders SET status=? WHERE id=?',[$status,$id]);DB::query('INSERT INTO order_status_history(order_id,actor_id,status) VALUES(?,?,?)',[$id,$user['id'],$status]);$db->commit();
        }catch(\Throwable $e){$db->rollBack();throw $e;}
    }
    public static function refund(int $id,int $actor): void {
        $o=self::get($id);if(!$o)throw new \RuntimeException('Order not found.');
        if(in_array($o['status'],['shipped','delivered'],true)) throw new \RuntimeException('Shipped or delivered returns require manual review; automatic restocking is disabled.');
        if(!in_array($o['payment_status'],['paid','demo_paid','partially_refunded'],true))throw new \RuntimeException('Only confirmed payments can be refunded.');
        if(DB::one('SELECT id FROM payouts WHERE order_id=? AND status="sent"',[$id]))throw new \RuntimeException('Reverse the connected-account transfer in Stripe before refunding this order.');
        DB::query('INSERT IGNORE INTO refunds(order_id,amount,merchandise,delivery,tax,fee) VALUES(?,?,?,?,?,?)',[$id,$o['total'],$o['subtotal'],$o['delivery'],$o['tax'],$o['fee']]);
        $r=DB::one('SELECT * FROM refunds WHERE order_id=?',[$id]);if($r['status']==='succeeded')return;
        if($o['mode']==='stripe') {
            $p=DB::one('SELECT * FROM payments WHERE checkout_id=?',[$o['checkout_id']]);
            $result=Payment::api('refunds',['payment_intent'=>$p['payment_intent'],'amount'=>$o['total']],'refund-order-'.$id);
            DB::query('UPDATE refunds SET provider_ref=?,status=? WHERE order_id=?',[$result['id'],$result['status'],$id]);
            if($result['status']==='succeeded') self::completeRefund($id);
        } else self::completeRefund($id);
        DB::audit($actor,'refund_requested','order:'.$id);
    }
    public static function completeRefund(int $id): void {
        $db=DB::connection();$db->beginTransaction();try {
            $o=DB::one('SELECT * FROM supplier_orders WHERE id=? FOR UPDATE',[$id]);
            if(!$o)throw new \RuntimeException('Order missing.');
            DB::one('SELECT id FROM checkouts WHERE id=? FOR UPDATE',[$o['checkout_id']]);
            $r=DB::one('SELECT * FROM refunds WHERE order_id=? FOR UPDATE',[$id]);
            if(!$r || $o['status']==='cancelled'){$db->commit();return;}
            foreach(DB::all('SELECT * FROM order_items WHERE order_id=? ORDER BY offer_id',[$id]) as $i)DB::query('UPDATE offers SET stock=stock+? WHERE id=?',[$i['quantity'],$i['offer_id']]);
            DB::query('UPDATE refunds SET status="succeeded" WHERE order_id=?',[$id]);DB::query('UPDATE supplier_orders SET status="cancelled" WHERE id=?',[$id]);
            DB::query('INSERT INTO order_status_history(order_id,status) VALUES(?,"cancelled")',[$id]);
            $sum=(int)DB::one('SELECT COALESCE(SUM(r.amount),0) n FROM refunds r JOIN supplier_orders o ON o.id=r.order_id WHERE o.checkout_id=? AND r.status="succeeded"',[$o['checkout_id']])['n'];
            $c=DB::one('SELECT total FROM checkouts WHERE id=?',[$o['checkout_id']]);
            $state=$sum===(int)$c['total']?'refunded':'partially_refunded';DB::query('UPDATE checkouts SET status=? WHERE id=?',[$state,$o['checkout_id']]);DB::query('UPDATE payments SET status=? WHERE checkout_id=?',[$state,$o['checkout_id']]);$db->commit();
        }catch(\Throwable $e){$db->rollBack();throw $e;}
    }
    public static function payout(int $id,int $actor): void {
        $o=self::get($id);if(!$o || $o['mode']!=='stripe' || $o['payment_status']!=='paid' || $o['status']!=='delivered')throw new \RuntimeException('Payouts require a delivered, Stripe-paid order.');
        if(DB::one('SELECT id FROM refunds WHERE order_id=?',[$id]))throw new \RuntimeException('Refunded orders cannot be paid out.');
        $f=DB::one('SELECT stripe_account FROM fulfillment_settings WHERE supplier_id=?',[$o['supplier_id']]);if(!preg_match('/^acct_[A-Za-z0-9]+$/',$f['stripe_account']??''))throw new \RuntimeException('Configure an authorized Stripe Connect test account for this supplier first.');
        $amount=(int)$o['subtotal']+(int)$o['delivery'];
        DB::query('INSERT IGNORE INTO payouts(order_id,amount) VALUES(?,?)',[$id,$amount]);
        if(DB::one('SELECT status FROM payouts WHERE order_id=?',[$id])['status']==='sent')return;
        $p=DB::one('SELECT payment_intent FROM payments WHERE checkout_id=?',[$o['checkout_id']]);
        $r=Payment::api('transfers',['amount'=>$amount,'currency'=>strtolower($o['currency']),'destination'=>$f['stripe_account'],'transfer_group'=>'MY_STORE_'.$o['checkout_id']],'payout-order-'.$id);
        DB::query('UPDATE payouts SET provider_ref=?,status="sent" WHERE order_id=?',[$r['id'],$id]);DB::audit($actor,'test_payout','order:'.$id);
    }
}
