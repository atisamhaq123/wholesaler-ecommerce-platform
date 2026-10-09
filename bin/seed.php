<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
use App\Models\DB;
if(DB::one('SELECT id FROM users LIMIT 1')){echo "Seed skipped: database already has users.\n";exit;}
$db=DB::connection();$db->beginTransaction();try{
    $password=password_hash('DemoStore!2026',PASSWORD_DEFAULT);
    $accounts=[['retailer@mystore.test','retailer','Corner & Co. Market','Neighborhood grocery','Brooklyn','A neighborhood market with everyday essentials.','approved'],['wholesaler@mystore.test','wholesaler','Greenfield Wholesale','Food & beverage distributor','Brooklyn','Thoughtfully sourced everyday goods for independent stores. Family owned since 2008.','approved'],['supplier@mystore.test','wholesaler','Northline Supply','General merchandise wholesaler','Queens','Reliable wholesale essentials, flexible pack sizes and friendly service.','approved'],['pending@mystore.test','wholesaler','Borough Goods','Regional distributor','Manhattan','A new supplier waiting for marketplace approval.','pending'],['admin@mystore.test','admin','My Store Operations','Marketplace administrator','New York','Marketplace operations.','approved']];
    foreach($accounts as $a){DB::query('INSERT INTO users(email,password,role,verified_at) VALUES(?,?,?,NOW())',[$a[0],$password,$a[1]]);$id=DB::id();DB::query('INSERT INTO businesses(user_id,name,type,city,description,status,contact,address) VALUES(?,?,?,?,?,?,?,?)',[$id,$a[2],$a[3],$a[4],$a[5],$a[6],'Demo business contact','100 Sample Avenue']);if($a[1]==='wholesaler')DB::query('INSERT INTO fulfillment_settings(supplier_id,delivery_fee,lat,lng,pickup_instructions,lead_time) VALUES(?,?,?,?,?,?)',[$id,$id===2?1200:1800,$id===2?40.6782:40.7282,$id===2?-73.9442:-73.7949,'Demo pickup location: 100 Sample Avenue. Bring your order number.','1–3 business days']);}
    DB::query('INSERT INTO addresses(user_id,label,address,city,lat,lng) VALUES(1,"Main store","45 Demo Street","Brooklyn",40.6800,-73.9500)');
    foreach([['Beverages','beverages'],['Coffee & tea','coffee-tea'],['Pantry staples','pantry'],['Paper & packaging','paper'],['Personal care','personal-care']] as $c)DB::query('INSERT INTO categories(name,slug) VALUES(?,?)',$c);
    $products=[
        [1,'Natural Spring Water','Pure Spring','MS-1001','Refreshingly simple bottled spring water. Canonical product is the same Pure Spring formulation across pack sizes.','green',24,'each',1699],
        [2,'Signature Whole Bean Coffee','The Daily Roast','MS-1002','Rich and balanced medium roast coffee beans. Packed fresh for cafés and independent retailers.','cream',1,'kg',2450],
        [3,'Organic Diced Tomatoes','Fresh Co.','MS-1003','Organic tomatoes, diced and packed at peak ripeness. Same formulation, multiple wholesale case sizes.','rose',4.8,'kg',2190],
        [4,'Everyday Paper Towels','Everyday','MS-1004','Durable paper towels for everyday cleanups. Unit comparison is by roll for this identical roll specification.','blue',12,'each',1875],
        [5,'Gentle Botanical Hand Wash','Kind.','MS-1005','A gentle botanical hand wash for daily use, available in economical wholesale packs.','sage',3,'L',2850],
        [3,'Premium Long Grain Rice','Harvest Pantry','MS-1006','Versatile long grain rice for restaurants, neighborhood shops and busy kitchens.','sand',10,'kg',2299],
        [1,'Sparkling Mineral Water','Pure Spring','MS-1007','Crisp, lightly sparkling mineral water. Ready for the refrigerator or the restaurant table.','blue',12,'each',1890],
        [2,'House Blend Coffee Beans','The Daily Roast','MS-1008','Smooth café house blend with chocolate notes. A dependable crowd favorite.','sand',2,'kg',3990],
        [3,'Classic Tomato Passata','Fresh Co.','MS-1009','Smooth sieved tomatoes for sauces, soups and everyday cooking.','rose',6,'kg',2690],
        [4,'Recycled Paper Napkins','Everyday','MS-1010','Practical recycled napkins, identical sheets in each wholesale pack.','cream',500,'each',1450],
        [5,'Unscented Hand Soap','Kind.','MS-1011','Unscented gentle hand soap for the workplace and home.','sage',5,'L',3490],
        [3,'Everyday Basmati Rice','Harvest Pantry','MS-1012','Aromatic basmati rice with long, fluffy grains. Quality pantry stock.','sand',5,'kg',1950]
    ];
    foreach($products as $row){[$cat,$name,$brand,$sku,$desc,$color,$pack,$unit,$price]=$row;DB::query('INSERT INTO products(category_id,name,brand,sku,description,color) VALUES(?,?,?,?,?,?)',[$cat,$name,$brand,$sku,$desc,$color]);$pid=DB::id();foreach([2,3] as $supplier){$packPrice=$supplier===2?$price:(int)round($price*1.9);$packSize=$supplier===2?$pack:$pack*2;DB::query('INSERT INTO offers(supplier_id,product_id,pack_size,unit,price,min_qty,increment_qty,stock) VALUES(?,?,?,?,?,2,2,?)',[$supplier,$pid,$packSize,$unit,$packPrice,($pid*17+80)]);$oid=DB::id();DB::query('INSERT INTO price_tiers(offer_id,min_qty,price) VALUES(?,12,?),(?,24,?)',[$oid,(int)round($packPrice*.96),$oid,(int)round($packPrice*.92)]);}}
    DB::query('INSERT INTO offers(supplier_id,product_id,pack_size,unit,price,min_qty,stock) VALUES(4,1,24,"each",1500,1,100)');
    DB::query('INSERT INTO external_sources(name,mode) VALUES("Sam’s Club — demonstration","demo"),("Licensed regional feed — demonstration","demo")');
    DB::query('INSERT INTO external_offers(source_id,product_id,price,currency,pack_size,unit,delivery_fee,updated_at) VALUES(1,1,1599,"USD",24,"each",NULL,NOW()),(2,2,2390,"USD",1000,"g",900,NOW())');
    foreach(['currency'=>'USD','buyer_fee_bps'=>'50','tax_bps'=>'0','fee_basis'=>'merchandise_after_discounts'] as $k=>$v)DB::query('INSERT INTO settings(name,value) VALUES(?,?)',[$k,$v]);
    DB::query('INSERT INTO conversations(product_id) VALUES(1)');$cid=DB::id();DB::query('INSERT INTO conversation_participants(conversation_id,user_id) VALUES(?,1),(?,2)',[$cid,$cid]);DB::query('INSERT INTO messages(conversation_id,sender_id,body) VALUES(?,2,?)',[$cid,'Welcome to Greenfield Wholesale! Happy to help with your first order. Ask us about delivery or volume pricing.']);
    $db->commit();echo "Seeded 5 demo accounts, 12 products, 25 offers and sample comparisons.\n";
}catch(Throwable $e){$db->rollBack();throw $e;}
