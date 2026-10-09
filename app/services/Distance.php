<?php
namespace App\services;
final class Distance {
    public static function label(?array $retailer,array $supplier): string {
        $lat=$retailer['lat']??null;$lng=$retailer['lng']??null;
        if($lat===null || $lng===null || $supplier['lat']===null || $supplier['lng']===null)return 'Distance unavailable';
        if(env('MAP_PROVIDER','none')==='mapbox' && env('MAP_API_KEY')!==''){
            $coords=$supplier['lng'].','.$supplier['lat'].';'.$lng.','.$lat;
            $key=hash('sha256',$coords);$_SESSION['route_cache']??=[];
            $cache=$_SESSION['route_cache'][$key]??null;
            if($cache && $cache['expires']>time())return $cache['label'];
            // Coordinates and API token go only to the selected provider, never browser markup or logs.
            $curl=curl_init('https://api.mapbox.com/directions/v5/mapbox/driving/'.$coords.'?overview=false&access_token='.rawurlencode(env('MAP_API_KEY')));
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_CONNECTTIMEOUT=>2]);$raw=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
            if($raw!==false && $code===200){$data=json_decode($raw,true);if(isset($data['routes'][0]['distance'])){$label=number_format($data['routes'][0]['distance']/1000,1).' km estimated driving route';$_SESSION['route_cache'][$key]=['label'=>$label,'expires'=>time()+900];return $label;}}
        }
        $km=Pricing::distance((float)$lat,(float)$lng,(float)$supplier['lat'],(float)$supplier['lng']);
        return number_format($km,1).' km straight-line estimate';
    }
}
