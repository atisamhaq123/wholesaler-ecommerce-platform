<?php
namespace App\services;
final class CsvAdapter implements SupplierAdapter {
    public function offers(string $path): array {
        $f=fopen($path,'r');$header=fgetcsv($f,0,',','"','');
        $expected=['product_sku','source','source_url','currency','pack_size','unit','price','delivery_fee','updated_at'];
        if($header!==$expected){fclose($f);throw new \RuntimeException('CSV header must match the downloadable template.');}
        $rows=[];while(($values=fgetcsv($f,0,',','"',''))!==false){if(count($values)!==count($header)){fclose($f);throw new \RuntimeException('Malformed CSV row.');}$rows[]=array_combine($header,$values);if(count($rows)>2000){fclose($f);throw new \RuntimeException('Maximum 2,000 rows per import.');}}fclose($f);return $rows;
    }
}
