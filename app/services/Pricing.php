<?php
namespace App\services;
final class Pricing {
    public static function roundRate(int $minor,int $basisPoints): int { return intdiv($minor*$basisPoints+5000,10000); }
    public static function decimal(string $value): int { if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/',$value)) throw new \RuntimeException('Enter an amount with up to two decimal places.'); [$whole,$fraction]=array_pad(explode('.',$value),2,''); return (int)$whole*100+(int)str_pad($fraction,2,'0'); }
    public static function unit(int $price,float $pack,string $unit): ?array {
        $factors=['each'=>['each',1], 'g'=>['kg',0.001], 'kg'=>['kg',1], 'ml'=>['L',0.001], 'L'=>['L',1], 'lb'=>['kg',0.45359237], 'oz'=>['kg',0.028349523125]];
        if ($pack<=0 || !isset($factors[$unit])) return null;
        [$base,$factor]=$factors[$unit]; return ['unit'=>$base,'price'=>$price/($pack*$factor)];
    }
    public static function price(array $offer,int $quantity,array $tiers): int { $price=(int)$offer['price']; foreach($tiers as $tier) if ($quantity >= (int)$tier['min_qty']) $price=min($price,(int)$tier['price']); return $price; }
    public static function quantity(array $offer,int $qty): void { if ($qty<(int)$offer['min_qty'] || $qty%(int)$offer['increment_qty']!==0 || $qty>(int)$offer['stock']) throw new \RuntimeException('Quantity must meet the minimum, increment, and available stock for this offer.'); }
    public static function totals(int $subtotal,int $delivery,int $feeRate,int $taxRate,string $basis='merchandise_after_discounts'): array { $fee=self::roundRate($subtotal+($basis==='merchandise_plus_delivery'?$delivery:0),$feeRate); $tax=self::roundRate($subtotal,$taxRate); return ['subtotal'=>$subtotal,'delivery'=>$delivery,'fee'=>$fee,'tax'=>$tax,'total'=>$subtotal+$delivery+$fee+$tax]; }
    public static function distance(?float $a,?float $b,?float $c,?float $d): ?float { if ($a===null || $b===null || $c===null || $d===null) return null; $lat=deg2rad($c-$a); $lon=deg2rad($d-$b); return 6371*2*asin(min(1,sqrt(sin($lat/2)**2+cos(deg2rad($a))*cos(deg2rad($c))*sin($lon/2)**2))); }
}
