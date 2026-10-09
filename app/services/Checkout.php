<?php
namespace App\services;
use App\Models\DB;
final class Checkout {
    public static function cart(int $user): array { return DB::all('SELECT ci.*,o.supplier_id,o.product_id,o.price,o.pack_size,o.unit,o.min_qty,o.increment_qty,o.stock,o.archived,p.name,p.sku,p.category_id,p.color,b.name supplier,b.status supplier_status,f.pickup,f.delivery,f.delivery_fee,f.delivery_radius,f.lat,f.lng,f.pickup_instructions,f.lead_time FROM cart_items ci JOIN carts c ON c.id=ci.cart_id JOIN offers o ON o.id=ci.offer_id JOIN products p ON p.id=o.product_id JOIN businesses b ON b.user_id=o.supplier_id JOIN fulfillment_settings f ON f.supplier_id=o.supplier_id WHERE c.retailer_id=? ORDER BY o.supplier_id,o.id',[$user]); }
    public static function add(int $user,int $offer,int $qty,bool $replace=false): void {
        $o=DB::one('SELECT o.* FROM offers o JOIN businesses b ON b.user_id=o.supplier_id WHERE o.id=? AND o.archived=0 AND b.status="approved"',[$offer]);
        if(!$o) throw new \RuntimeException('This offer is unavailable.');
        DB::query('INSERT IGNORE INTO carts(retailer_id) VALUES(?)',[$user]);
        $cart=DB::one('SELECT id FROM carts WHERE retailer_id=?',[$user])['id'];
        $old=DB::one('SELECT quantity FROM cart_items WHERE cart_id=? AND offer_id=?',[$cart,$offer]);
        $quantity=$qty+($replace?0:(int)($old['quantity']??0)); Pricing::quantity($o,$quantity);
        DB::query('INSERT INTO cart_items(cart_id,offer_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=?',[$cart,$offer,$quantity,$quantity]);
    }
    public static function expire(): void {
        $ids=DB::all('SELECT id FROM checkouts WHERE status="pending" AND expires_at<NOW()');
        foreach($ids as $row) self::release((int)$row['id'],'expired');
    }
    public static function release(int $id,string $status,bool $providerConfirmed=false): void {
        $payment=DB::one('SELECT p.*,c.status checkout_status FROM payments p JOIN checkouts c ON c.id=p.checkout_id WHERE p.checkout_id=?',[$id]);
        if(!$providerConfirmed && $payment && $payment['checkout_status']==='pending' && $payment['provider']==='stripe' && $payment['provider_session'] && !Payment::cancelSession($id))return;
        $db=DB::connection(); $db->beginTransaction();
        try {
            $c=DB::one('SELECT * FROM checkouts WHERE id=? FOR UPDATE',[$id]);
            if(!$c || $c['status']!=='pending') { $db->commit(); return; }
            foreach(DB::all('SELECT * FROM inventory_reservations WHERE checkout_id=? AND status="reserved" ORDER BY offer_id FOR UPDATE',[$id]) as $r) DB::query('UPDATE offers SET stock=stock+? WHERE id=?',[$r['quantity'],$r['offer_id']]);
            DB::query('UPDATE inventory_reservations SET status="released" WHERE checkout_id=? AND status="reserved"',[$id]);
            DB::query('UPDATE checkouts SET status=? WHERE id=?',[$status,$id]);
            DB::query('UPDATE payments SET status=? WHERE checkout_id=?',[$status,$id]);
            DB::query('UPDATE supplier_orders SET status="cancelled" WHERE checkout_id=?',[$id]);
            DB::query('INSERT INTO order_status_history(order_id,status) SELECT id,"cancelled" FROM supplier_orders WHERE checkout_id=?',[$id]); $db->commit();
        } catch(\Throwable $e) { $db->rollBack(); throw $e; }
    }
    public static function create(int $user,array $methods,int $addressId,string $key): int {
        self::expire();
        $existing=DB::one('SELECT id FROM checkouts WHERE request_key=? AND retailer_id=?',[$key,$user]); if($existing) return (int)$existing['id'];
        $db=DB::connection(); $db->beginTransaction();
        try {
            // Lock the cart, then offers in ascending ID order. All stock writers use these same offer locks.
            $cart=DB::one('SELECT * FROM carts WHERE retailer_id=? FOR UPDATE',[$user]);
            $existing=DB::one('SELECT id FROM checkouts WHERE request_key=? AND retailer_id=?',[$key,$user]);if($existing){$db->commit();return (int)$existing['id'];}
            if(!$cart) throw new \RuntimeException('Your cart is empty.');
            $items=self::cart($user); if(!$items) throw new \RuntimeException('Your cart is empty.');
            $ids=array_column($items,'offer_id'); sort($ids,SORT_NUMERIC);
            $locked=[]; foreach($ids as $id) $locked[$id]=DB::one('SELECT o.*,b.status supplier_status FROM offers o JOIN businesses b ON b.user_id=o.supplier_id WHERE o.id=? FOR UPDATE',[$id]);
            $address=$addressId ? DB::one('SELECT * FROM addresses WHERE id=? AND user_id=?',[$addressId,$user]) : null;
            $groups=[]; $subtotal=0; $delivery=0;
            foreach($items as &$item) {
                $o=$locked[$item['offer_id']];
                if($o['archived'] || $o['supplier_status']!=='approved') throw new \RuntimeException('A supplier offer is no longer available.');
                Pricing::quantity($o,(int)$item['quantity']);
                $item['price']=Pricing::price($o,(int)$item['quantity'],DB::all('SELECT * FROM price_tiers WHERE offer_id=? ORDER BY min_qty',[$o['id']]));
                $item['pack_size']=$o['pack_size']; $item['unit']=$o['unit'];
                $item['total']=$item['price']*(int)$item['quantity'];
                $groups[$o['supplier_id']][]=$item; $subtotal+=$item['total'];
            } unset($item);
            $groupFees=[];
            foreach($groups as $supplier=>$rows) {
                $f=DB::one('SELECT * FROM fulfillment_settings WHERE supplier_id=? FOR UPDATE',[$supplier]); $method=$methods[$supplier]??'';
                if(!in_array($method,['delivery','pickup'],true) || !$f[$method]) throw new \RuntimeException('Choose an available fulfillment option for every supplier.');
                if($method==='delivery') {
                    if(!$address) throw new \RuntimeException('Select a saved delivery address.');
                    if($f['delivery_fee']===null) throw new \RuntimeException('Delivery is quote-only for this supplier. Choose pickup or contact them.');
                    if($f['delivery_radius']!==null) {
                        $distance=Pricing::distance($f['lat']===null?null:(float)$f['lat'],$f['lng']===null?null:(float)$f['lng'],$address['lat']===null?null:(float)$address['lat'],$address['lng']===null?null:(float)$address['lng']);
                        if($distance===null || $distance>(float)$f['delivery_radius']) throw new \RuntimeException('Address is outside the supplier delivery radius or coordinates are missing.');
                    }
                }
                $groupFees[$supplier]=$method==='delivery'?(int)$f['delivery_fee']:0; $delivery+=$groupFees[$supplier];
            }
            $feeRate=(int)DB::setting('buyer_fee_bps','50'); $taxRate=(int)DB::setting('tax_bps','0');
            if($subtotal+$delivery>(int)config('checkout_max_minor'))throw new \RuntimeException('This checkout exceeds the marketplace transaction limit. Split it into smaller purchases.');
            $basis=DB::setting('fee_basis','merchandise_after_discounts');
            // Round each supplier's fee/tax half-up; parent totals are the sum of supplier snapshots.
            $fee=0;$tax=0; foreach($groups as $supplier=>$rows) { $sub=array_sum(array_column($rows,'total'));$t=Pricing::totals($sub,$groupFees[$supplier],$feeRate,$taxRate,$basis); $fee+=$t['fee']; $tax+=$t['tax']; }
            $mode=Payment::mode();
            DB::query('INSERT INTO checkouts(retailer_id,currency,mode,subtotal,delivery,tax,fee,total,fee_bps,tax_bps,address_snapshot,request_key,fee_basis,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[$user,DB::setting('currency','USD'),$mode,$subtotal,$delivery,$tax,$fee,$subtotal+$delivery+$tax+$fee,$feeRate,$taxRate,$address?json_encode($address):null,$key,$basis]);
            $id=DB::id();
            foreach($groups as $supplier=>$rows) {
                $b=DB::one('SELECT name,city FROM businesses WHERE user_id=?',[$supplier]); $f=DB::one('SELECT * FROM fulfillment_settings WHERE supplier_id=?',[$supplier]);
                $t=Pricing::totals(array_sum(array_column($rows,'total')),$groupFees[$supplier],$feeRate,$taxRate,$basis);
                DB::query('INSERT INTO supplier_orders(checkout_id,supplier_id,supplier_snapshot,fulfillment,instructions,subtotal,delivery,tax,fee,total) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$supplier,json_encode($b),$methods[$supplier],$methods[$supplier]==='pickup'?$f['pickup_instructions']:$f['lead_time'],$t['subtotal'],$t['delivery'],$t['tax'],$t['fee'],$t['total']]);
                $order=DB::id(); DB::query('INSERT INTO order_status_history(order_id,actor_id,status) VALUES(?,?,"pending")',[$order,$user]);
                foreach($rows as $row) {
                    DB::query('INSERT INTO order_items(order_id,offer_id,product_id,category_id,name,sku,pack_size,unit,quantity,price,total) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$order,$row['offer_id'],$row['product_id'],$row['category_id'],$row['name'],$row['sku'],$row['pack_size'],$row['unit'],$row['quantity'],$row['price'],$row['total']]);
                    if(DB::query('UPDATE offers SET stock=stock-? WHERE id=? AND stock>=?',[$row['quantity'],$row['offer_id'],$row['quantity']])->rowCount()!==1) throw new \RuntimeException('Stock changed. Please review your cart.');
                    DB::query('INSERT INTO inventory_reservations(checkout_id,offer_id,quantity,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[$id,$row['offer_id'],$row['quantity']]);
                }
            }
            DB::query('INSERT INTO payments(checkout_id,provider,amount) VALUES(?,?,?)',[$id,$mode,$subtotal+$delivery+$tax+$fee]);
            DB::query('DELETE FROM cart_items WHERE cart_id=?',[$cart['id']]); $db->commit(); return $id;
        } catch(\Throwable $e) { $db->rollBack(); throw $e; }
    }
    public static function confirm(int $id,string $provider,string $event,?string $intent=null): void {
        $db=DB::connection();$db->beginTransaction();
        try {
            $c=DB::one('SELECT * FROM checkouts WHERE id=? FOR UPDATE',[$id]);
            if(!$c || $c['mode']!==$provider) throw new \RuntimeException('Payment mode mismatch.');
            if(DB::one('SELECT event_id FROM webhook_events WHERE event_id=?',[$event])) {$db->commit();return;}
            if(in_array($c['status'],['paid','demo_paid','partially_refunded','refunded'],true)) {DB::query('INSERT INTO webhook_events(event_id,type) VALUES(?,"duplicate_confirmation")',[$event]);$db->commit();return;}
            if($c['status']!=='pending' || ($provider==='demo' && strtotime($c['expires_at'])<time())) throw new \RuntimeException('Payment arrived after the reservation was released. Manual refund/reconciliation is required.');
            $status=$provider==='demo'?'demo_paid':'paid';
            DB::query('UPDATE checkouts SET status=? WHERE id=?',[$status,$id]);
            DB::query('UPDATE payments SET status=?,payment_intent=? WHERE checkout_id=?',[$status,$intent,$id]);
            DB::query('UPDATE inventory_reservations SET status="consumed" WHERE checkout_id=? AND status="reserved"',[$id]);
            DB::query('INSERT INTO webhook_events(event_id,type) VALUES(?,?)',[$event,$status]); $db->commit();
        }catch(\Throwable $e){$db->rollBack();throw $e;}
    }
}
