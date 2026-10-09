<?php
namespace App\services;
final class Imports {
    public static function csv(string $path,int $actor): int {
        $rows=(new CsvAdapter)->offers($path);$db=\App\Models\DB::connection();$db->beginTransaction();try {
            foreach($rows as $row){
                $p=\App\Models\DB::one('SELECT id FROM products WHERE sku=?',[$row['product_sku']]);
                if(!$p || !preg_match('/^[A-Z]{3}$/',$row['currency']) || !Pricing::unit(1,(float)$row['pack_size'],$row['unit']) || strlen($row['source'])<2 || strlen($row['source'])>100 || ($row['source_url'] && !preg_match('~^https?://~',$row['source_url'])))throw new \RuntimeException('Invalid product, currency, pack, unit, source or URL in import.');
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$row['updated_at']);if(!$date || $date->format('Y-m-d H:i:s')!==$row['updated_at'] || $date->getTimestamp()>time())throw new \RuntimeException('Use a valid past UTC timestamp: YYYY-MM-DD HH:MM:SS.');
                \App\Models\DB::query('INSERT IGNORE INTO external_sources(name,mode) VALUES(?,"import")',[$row['source']]);
                $source=\App\Models\DB::one('SELECT id FROM external_sources WHERE name=?',[$row['source']])['id'];
                if(\App\Models\DB::one('SELECT mode FROM external_sources WHERE id=?',[$source])['mode']!=='import')throw new \RuntimeException('Choose a distinct import source name; demonstration sources cannot be overwritten.');
                \App\Models\DB::query('INSERT INTO external_offers(source_id,product_id,source_url,currency,pack_size,unit,price,delivery_fee,updated_at) VALUES(?,?,?,?,?,?,?,?,?)',[$source,$p['id'],$row['source_url']?:null,$row['currency'],$row['pack_size'],$row['unit'],Pricing::decimal($row['price']),$row['delivery_fee']===''?null:Pricing::decimal($row['delivery_fee']),$date->format('Y-m-d H:i:s')]);
            }
            \App\Models\DB::audit($actor,'external_csv_import',(string)count($rows).' rows');$db->commit();return count($rows);
        }catch(\Throwable $e){$db->rollBack();throw $e;}
    }
}
