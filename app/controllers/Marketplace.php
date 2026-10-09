<?php
namespace App\controllers;
use App\Models\DB;
use App\middleware\Auth;
use App\services\{Pricing,Checkout,Payment,Accounts,Orders,Imports};
final class Marketplace {
    public function get(string $path): void {
        switch($path) {
        case '/': case '/catalog':
            $where=['o.archived=0','b.status="approved"'];$params=[];
            if(trim($_GET['q']??'')){ $where[]='(p.name LIKE ? OR p.brand LIKE ? OR p.sku LIKE ?)';$q='%'.trim($_GET['q']).'%';array_push($params,$q,$q,$q); }
            if(!empty($_GET['category'])){$where[]='p.category_id=?';$params[]=(int)$_GET['category'];}
            if(!empty($_GET['supplier'])){$where[]='o.supplier_id=?';$params[]=(int)$_GET['supplier'];}
            $sort=match($_GET['sort']??''){ 'price'=>'MIN(o.price)', 'name'=>'p.name', default=>'p.id' };
            $page=max(1,(int)($_GET['page']??1));$offset=($page-1)*12;
            $base=' FROM products p JOIN categories c ON c.id=p.category_id JOIN offers o ON o.product_id=p.id JOIN businesses b ON b.user_id=o.supplier_id WHERE '.implode(' AND ',$where);
            $count=(int)DB::one('SELECT COUNT(DISTINCT p.id) n'.$base,$params)['n'];
            $products=DB::all('SELECT p.*,c.name category,MIN(o.price) price,COUNT(o.id) offers,SUM(o.stock) stock'.$base.' GROUP BY p.id,c.name ORDER BY '.$sort.' LIMIT 12 OFFSET '.$offset,$params);
            view('catalog',['title'=>'Discover your next best seller','products'=>$products,'categories'=>DB::all('SELECT * FROM categories ORDER BY name'),'suppliers'=>DB::all('SELECT b.user_id,b.name FROM businesses b JOIN users u ON u.id=b.user_id WHERE u.role="wholesaler" AND b.status="approved"'),'count'=>$count,'page'=>$page]);break;
        case '/product':
            $p=DB::one('SELECT p.*,c.name category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=?',[(int)($_GET['id']??0)]);if(!$p)throw new \RuntimeException('Product not found.',404);
            $offers=DB::all('SELECT o.*,b.name supplier,b.city,f.pickup,f.delivery,f.delivery_fee,f.lat,f.lng,f.lead_time FROM offers o JOIN businesses b ON b.user_id=o.supplier_id JOIN fulfillment_settings f ON f.supplier_id=o.supplier_id WHERE o.product_id=? AND o.archived=0 AND b.status="approved" ORDER BY o.price',[$p['id']]);
            $external=DB::all('SELECT o.*,s.name supplier,s.mode FROM external_offers o JOIN external_sources s ON s.id=o.source_id WHERE o.product_id=? ORDER BY o.price',[$p['id']]);
            view('product',['title'=>$p['name'],'product'=>$p,'offers'=>$offers,'external'=>$external]);break;
        case '/supplier':
            $b=DB::one('SELECT b.user_id,b.name,b.city,b.type,b.description,f.pickup,f.delivery,f.delivery_fee,f.lead_time FROM businesses b JOIN users u ON u.id=b.user_id JOIN fulfillment_settings f ON f.supplier_id=b.user_id WHERE b.user_id=? AND u.role="wholesaler" AND b.status="approved"',[(int)($_GET['id']??0)]);if(!$b)throw new \RuntimeException('Supplier not found.',404);
            view('supplier',['title'=>$b['name'],'supplier'=>$b,'offers'=>DB::all('SELECT o.*,p.name,p.color FROM offers o JOIN products p ON p.id=o.product_id WHERE o.supplier_id=? AND o.archived=0',[$b['user_id']])]);break;
        case '/login':case '/register':case '/forgot':case '/reset':
            view('auth',['title'=>ucfirst(substr($path,1)),'mode'=>substr($path,1)]);break;
        case '/verify': Accounts::consume((string)($_GET['token']??''),'verify');flash('Email verified. You can now place orders.');redirect('/login');
        case '/dashboard':
            $u=Auth::require();if($u['role']==='admin')redirect('/admin');
            $filter=$u['role']==='retailer'?'c.retailer_id=?':'o.supplier_id=?';
            $orders=DB::all('SELECT o.*,c.status payment_status,c.created_at,b.name supplier FROM supplier_orders o JOIN checkouts c ON c.id=o.checkout_id JOIN businesses b ON b.user_id=o.supplier_id WHERE '.$filter.' ORDER BY o.id DESC LIMIT 6',[$u['id']]);
            $stats=DB::one('SELECT COUNT(*) orders,COALESCE(SUM(IF(c.status IN ("paid","demo_paid","partially_refunded"),o.total-COALESCE(r.amount,0),0)),0) total FROM supplier_orders o JOIN checkouts c ON c.id=o.checkout_id LEFT JOIN refunds r ON r.order_id=o.id AND r.status="succeeded" WHERE '.$filter,[$u['id']]);
            view('dashboard',['title'=>'Your business, at a glance','orders'=>$orders,'stats'=>$stats]);break;
        case '/cart': $u=Auth::require('retailer');view('cart',['title'=>'Your wholesale cart','items'=>Checkout::cart((int)$u['id']),'addresses'=>DB::all('SELECT * FROM addresses WHERE user_id=?',[$u['id']])]);break;
        case '/checkout':
            $u=Auth::require('retailer');Checkout::expire();$c=DB::one('SELECT * FROM checkouts WHERE id=? AND retailer_id=?',[(int)($_GET['id']??0),$u['id']]);if(!$c)throw new \RuntimeException('Checkout not found.',404);
            view('checkout',['title'=>'Checkout #'.$c['id'],'checkout'=>$c,'orders'=>DB::all('SELECT * FROM supplier_orders WHERE checkout_id=?',[$c['id']])]);break;
        case '/orders':
            $u=Auth::require();$where=$u['role']==='admin'?'1=1':($u['role']==='retailer'?'c.retailer_id=?':'o.supplier_id=?');$params=$u['role']==='admin'?[]:[$u['id']];
            $page=max(1,(int)($_GET['page']??1));$count=(int)DB::one('SELECT COUNT(*) n FROM supplier_orders o JOIN checkouts c ON c.id=o.checkout_id WHERE '.$where,$params)['n'];
            $orders=DB::all('SELECT o.*,c.status payment_status,c.created_at,b.name supplier FROM supplier_orders o JOIN checkouts c ON c.id=o.checkout_id JOIN businesses b ON b.user_id=o.supplier_id WHERE '.$where.' ORDER BY o.id DESC LIMIT 20 OFFSET '.(($page-1)*20),$params);
            view('orders',['title'=>'Orders','orders'=>$orders,'count'=>$count,'page'=>$page]);break;
        case '/order':case '/invoice':
            $u=Auth::require();$o=Orders::get((int)($_GET['id']??0));if(!$o)throw new \RuntimeException('Order not found.',404);Auth::order($o,$u);
            view('order',['title'=>'Order #'.str_pad($o['id'],5,'0',STR_PAD_LEFT),'order'=>$o,'items'=>DB::all('SELECT * FROM order_items WHERE order_id=?',[$o['id']]),'history'=>DB::all('SELECT * FROM order_status_history WHERE order_id=? ORDER BY id',[$o['id']]),'refund'=>DB::one('SELECT * FROM refunds WHERE order_id=?',[$o['id']]),'invoice'=>$path==='/invoice']);break;
        case '/expenses':case '/expenses/export': $this->expenses($path);break;
        case '/messages':case '/messages/poll': $this->messages($path);break;
        case '/profile':
            $u=Auth::require();view('profile',['title'=>'Business & account settings','business'=>DB::one('SELECT * FROM businesses WHERE user_id=?',[$u['id']]),'addresses'=>DB::all('SELECT * FROM addresses WHERE user_id=?',[$u['id']]),'fulfillment'=>DB::one('SELECT * FROM fulfillment_settings WHERE supplier_id=?',[$u['id']])]);break;
        case '/listings':
            $u=Auth::require('wholesaler');view('listings',['title'=>'Your product listings','offers'=>DB::all('SELECT o.*,p.name,p.sku FROM offers o JOIN products p ON p.id=o.product_id WHERE o.supplier_id=? ORDER BY o.id DESC',[$u['id']])]);break;
        case '/listing/edit':
            $u=Auth::require('wholesaler');$offer=empty($_GET['id'])?null:DB::one('SELECT * FROM offers WHERE id=? AND supplier_id=?',[(int)$_GET['id'],$u['id']]);if(!empty($_GET['id'])&&!$offer)throw new \RuntimeException('Listing not found.',404);
            view('listing',['title'=>$offer?'Edit listing':'New listing','offer'=>$offer,'products'=>DB::all('SELECT * FROM products ORDER BY name'),'tiers'=>$offer?DB::all('SELECT * FROM price_tiers WHERE offer_id=? ORDER BY min_qty',[$offer['id']]):[]]);break;
        case '/admin':case '/admin/users':case '/admin/catalog':case '/admin/imports':case '/admin/settings':case '/admin/audit':case '/admin/payments':
            Auth::require('admin');view('admin',['title'=>'Marketplace administration','section'=>substr($path,7)?:'overview']);break;
        case '/admin/import-template':
            Auth::require('admin');header('Content-Type: text/csv');header('Content-Disposition: attachment; filename="comparison-import.csv"');echo "product_sku,source,source_url,currency,pack_size,unit,price,delivery_fee,updated_at\nMS-1001,Authorized supplier feed,https://example.com/product,USD,24,each,18.50,,2026-01-01 12:00:00\n";break;
        case '/media':
            $row=DB::one('SELECT i.* FROM offer_images i JOIN offers o ON o.id=i.offer_id JOIN businesses b ON b.user_id=o.supplier_id WHERE i.id=? AND o.archived=0 AND b.status="approved"',[(int)($_GET['id']??0)]);if(!$row)throw new \RuntimeException('Image not found.',404);header('Content-Type: '.$row['mime']);readfile(ROOT.'/storage/uploads/'.$row['filename']);break;
        default:throw new \RuntimeException('Page not found.',404);
        }
    }
    public function post(string $path): void {
        Auth::csrf();
        switch($path) {
        case '/login':
            $email=strtolower(trim($_POST['email']??''));Accounts::limit('login_ip',$_SERVER['REMOTE_ADDR']??'',40);Accounts::limit('login',($_SERVER['REMOTE_ADDR']??'').'|'.$email);
            $u=DB::one('SELECT * FROM users WHERE email=? AND active=1',[$email]);if(!$u || !password_verify($_POST['password']??'',$u['password']))throw new \RuntimeException('Email or password is incorrect.');
            session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));redirect('/dashboard');
        case '/logout': $_SESSION=[];session_regenerate_id(true);flash('You have signed out.');redirect('/');
        case '/register':
            Accounts::limit('register',$_SERVER['REMOTE_ADDR']??'');$email=strtolower(trim($_POST['email']??''));$role=$_POST['role']??'';Accounts::password($_POST['password']??'');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL) || !in_array($role,['retailer','wholesaler'],true))throw new \RuntimeException('Provide a valid email and business role.');$name=$this->text('name',150);
            if(DB::one('SELECT id FROM users WHERE email=?',[$email]))throw new \RuntimeException('Unable to register this email. Try signing in or resetting your password.');
            $db=DB::connection();$db->beginTransaction();try{DB::query('INSERT INTO users(email,password,role) VALUES(?,?,?)',[$email,password_hash($_POST['password'],PASSWORD_DEFAULT),$role]);$id=DB::id();DB::query('INSERT INTO businesses(user_id,name,type,status) VALUES(?,?,?,?)',[$id,$name,$this->text('type',100),$role==='retailer'?'approved':'pending']);if($role==='wholesaler')DB::query('INSERT INTO fulfillment_settings(supplier_id) VALUES(?)',[$id]);$db->commit();}catch(\Throwable $e){$db->rollBack();throw $e;}
            Accounts::token($id,'verify');flash('Account created. Check your email to verify it. Suppliers also need administrator approval.');redirect('/login');
        case '/forgot':
            Accounts::limit('reset',$_SERVER['REMOTE_ADDR']??'',5);$u=DB::one('SELECT id FROM users WHERE email=? AND active=1',[strtolower(trim($_POST['email']??''))]);if($u)Accounts::token((int)$u['id'],'reset');flash('If the account exists, a reset link has been sent.');redirect('/forgot');
        case '/reset': Accounts::password($_POST['password']??'');Accounts::consume((string)($_POST['token']??''),'reset',$_POST['password']);flash('Password updated. Sign in with your new password.');redirect('/login');
        case '/verify/resend':$u=Auth::require();Accounts::limit('verify',(string)$u['id'],3);Accounts::token((int)$u['id'],'verify');flash('Verification email sent.');redirect('/profile');
        case '/cart/add':case '/cart/update':
            $u=Auth::require('retailer');Checkout::add((int)$u['id'],(int)($_POST['offer_id']??0),(int)($_POST['quantity']??0),$path==='/cart/update');flash('Cart updated.');redirect('/cart');
        case '/cart/remove': $u=Auth::require('retailer');DB::query('DELETE ci FROM cart_items ci JOIN carts c ON c.id=ci.cart_id WHERE ci.id=? AND c.retailer_id=?',[(int)($_POST['item_id']??0),$u['id']]);flash('Item removed.');redirect('/cart');
        case '/checkout/create':
            $u=Auth::require('retailer');if(!$u['verified_at'])throw new \RuntimeException('Verify your email before checking out.');
            $key=(string)($_POST['request_key']??'');if(!preg_match('/^[a-f0-9]{64}$/',$key))throw new \RuntimeException('Invalid checkout request.');$id=Checkout::create((int)$u['id'],(array)($_POST['fulfillment']??[]),(int)($_POST['address_id']??0),$key);redirect('/checkout?id='.$id);
        case '/checkout/pay':case '/checkout/demo':case '/checkout/cancel':
            $u=Auth::require('retailer');$id=(int)($_POST['id']??0);$c=DB::one('SELECT * FROM checkouts WHERE id=? AND retailer_id=?',[$id,$u['id']]);if(!$c)throw new \RuntimeException('Checkout not found.',404);
            if($path==='/checkout/cancel'){Checkout::release($id,'cancelled');flash('Checkout cancelled; inventory released.');}
            elseif($path==='/checkout/demo'){if($c['mode']!=='demo')throw new \RuntimeException('Demo confirmation is unavailable for Stripe checkouts.');Checkout::confirm($id,'demo','demo-'.$id);flash('Demo transaction confirmed. No money was charged.');}
            else {header('Location: '.Payment::session($id),true,303);exit;}
            redirect('/checkout?id='.$id);
        case '/order/status':$u=Auth::require('wholesaler','admin');Orders::transition((int)$_POST['id'],(string)$_POST['status'],$u);flash('Fulfillment status updated.');redirect('/order?id='.(int)$_POST['id']);
        case '/order/notes':$u=Auth::require('retailer');$o=Orders::get((int)$_POST['id']);if(!$o)throw new \RuntimeException('Order not found.');Auth::order($o,$u);DB::query('UPDATE supplier_orders SET notes=? WHERE id=?',[$this->text('notes',2000,false),$o['id']]);flash('Purchase note saved.');redirect('/order?id='.$o['id']);
        case '/messages/start':case '/messages/send':$this->sendMessage($path);break;
        case '/profile/save':case '/address/save':case '/address/delete':case '/fulfillment/save':case '/password/save':$this->saveProfile($path);break;
        case '/listing/save':case '/listing/archive': $this->saveListing($path);break;
        case '/admin/user':case '/admin/category':case '/admin/product':case '/admin/import':case '/admin/external/delete':case '/admin/setting':case '/admin/refund':case '/admin/payout':case '/admin/listing':case '/admin/connect':$this->admin($path);break;
        default:throw new \RuntimeException('Action not found.',404);
        }
    }
    private function text(string $key,int $max,bool $required=true): string { $s=trim((string)($_POST[$key]??''));if(($required && $s==='') || mb_strlen($s)>$max)throw new \RuntimeException('Provide a valid '.$key.' (maximum '.$max.' characters).');return $s; }
    private function coordinates(): array { $lat=trim($_POST['lat']??'');$lng=trim($_POST['lng']??'');if(($lat==='' && $lng!== '') || ($lng==='' && $lat!==''))throw new \RuntimeException('Provide both coordinates or leave both empty.');if($lat!=='' && (!is_numeric($lat)||!is_numeric($lng)||abs((float)$lat)>90||abs((float)$lng)>180))throw new \RuntimeException('Invalid coordinates.');return [$lat===''?null:$lat,$lng===''?null:$lng]; }
    private function saveProfile(string $path): void {
        $u=Auth::require();
        if($path==='/profile/save'){DB::query('UPDATE businesses SET name=?,type=?,contact=?,phone=?,address=?,city=?,description=? WHERE user_id=?',[$this->text('name',150),$this->text('type',100),$this->text('contact',100,false),$this->text('phone',40,false),$this->text('address',1000,false),$this->text('city',100),$this->text('description',2000,false),$u['id']]);}
        elseif($path==='/address/save') {Auth::require('retailer');[$lat,$lng]=$this->coordinates();DB::query('INSERT INTO addresses(user_id,label,address,city,lat,lng) VALUES(?,?,?,?,?,?)',[$u['id'],$this->text('label',100),$this->text('address',1000),$this->text('city',100),$lat,$lng]);}
        elseif($path==='/address/delete'){Auth::require('retailer');DB::query('DELETE FROM addresses WHERE id=? AND user_id=?',[(int)$_POST['id'],$u['id']]);}
        elseif($path==='/password/save'){if(!password_verify($_POST['current_password']??'',$u['password']))throw new \RuntimeException('Current password is incorrect.');Accounts::password($_POST['password']??'');DB::query('UPDATE users SET password=? WHERE id=?',[password_hash($_POST['password'],PASSWORD_DEFAULT),$u['id']]);session_regenerate_id(true);}
        else {Auth::require('wholesaler');[$lat,$lng]=$this->coordinates();$radius=$this->text('delivery_radius',8,false);if($radius!=='' && (!is_numeric($radius)||(float)$radius<=0))throw new \RuntimeException('Delivery radius must be positive.');DB::query('UPDATE fulfillment_settings SET pickup=?,delivery=?,delivery_fee=?,delivery_radius=?,lat=?,lng=?,pickup_instructions=?,lead_time=? WHERE supplier_id=?',[isset($_POST['pickup'])?1:0,isset($_POST['delivery'])?1:0,$_POST['delivery_fee']===''?null:Pricing::decimal($_POST['delivery_fee']),$radius?:null,$lat,$lng,$this->text('pickup_instructions',1000,false),$this->text('lead_time',100),$u['id']]);}
        flash('Settings saved.');redirect('/profile');
    }
    private function saveListing(string $path): void {
        $u=Auth::require('wholesaler');$id=(int)($_POST['id']??0);$old=$id?DB::one('SELECT * FROM offers WHERE id=? AND supplier_id=?',[$id,$u['id']]):null;if($id&&!$old)throw new \RuntimeException('Listing not found.',404);
        if($path==='/listing/archive'){DB::query('UPDATE offers SET archived=? WHERE id=?',[$old['archived']?0:1,$id]);flash('Listing availability updated.');redirect('/listings');}
        $product=(int)$_POST['product_id'];$pack=(float)$_POST['pack_size'];$unit=$_POST['unit'];$min=(int)$_POST['min_qty'];$inc=(int)$_POST['increment_qty'];$stock=(int)$_POST['stock'];$price=Pricing::decimal($_POST['price']);
        if(!DB::one('SELECT id FROM products WHERE id=?',[$product]) || !Pricing::unit($price,$pack,$unit) || $min<1 || $inc<1 || $stock<0 || $min%$inc!==0 || $stock>1000000)throw new \RuntimeException('Check product, pack, minimum, increment and inventory. Minimum must be a multiple of the increment.');
        $tiers=[];foreach(explode("\n",trim($_POST['tiers']??'')) as $line){if(!trim($line))continue;$v=explode(':',trim($line));if(count($v)!==2 || !ctype_digit($v[0]) || (int)$v[0]<$min || (int)$v[0]%$inc!==0)throw new \RuntimeException('Use quantity:price for each tier.');$tierPrice=Pricing::decimal($v[1]);if($tierPrice>$price)throw new \RuntimeException('Tier prices must not exceed the base price.');$tiers[(int)$v[0]]=$tierPrice;}
        $image=null;
        if(!empty($_FILES['image']['name'])){
            $file=$_FILES['image'];if($file['error']!==UPLOAD_ERR_OK || $file['size']>4*1024*1024 || !is_uploaded_file($file['tmp_name']))throw new \RuntimeException('Image upload failed. Maximum size is 4 MB.');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$size=getimagesize($file['tmp_name']);if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!$size||$size[0]*$size[1]>20000000)throw new \RuntimeException('Use JPEG, PNG or WebP images up to 20 megapixels.');
            // Decode/re-encode to strip metadata and appended payloads. Private uploads are served through /media.
            $img=match($mime){'image/jpeg'=>imagecreatefromjpeg($file['tmp_name']),'image/png'=>imagecreatefrompng($file['tmp_name']),default=>imagecreatefromwebp($file['tmp_name'])};if(!$img)throw new \RuntimeException('Invalid image.');
            if(!is_dir(ROOT.'/storage/uploads'))mkdir(ROOT.'/storage/uploads',0700,true);$filename=bin2hex(random_bytes(20)).'.webp';imagewebp($img,ROOT.'/storage/uploads/'.$filename,85);$image=$filename;
        }
        $db=DB::connection();$db->beginTransaction();try{
            if($id){DB::one('SELECT id FROM offers WHERE id=? FOR UPDATE',[$id]);$reserved=(int)DB::one('SELECT COALESCE(SUM(quantity),0) n FROM inventory_reservations WHERE offer_id=? AND status="reserved"',[$id])['n'];if($reserved>0)throw new \RuntimeException('This listing has active checkout reservations. Wait for confirmation or expiration before editing.');DB::query('UPDATE offers SET product_id=?,pack_size=?,unit=?,price=?,min_qty=?,increment_qty=?,stock=? WHERE id=?',[$product,$pack,$unit,$price,$min,$inc,$stock,$id]);}
            else{DB::query('INSERT INTO offers(supplier_id,product_id,pack_size,unit,price,min_qty,increment_qty,stock) VALUES(?,?,?,?,?,?,?,?)',[$u['id'],$product,$pack,$unit,$price,$min,$inc,$stock]);$id=DB::id();}
            DB::query('DELETE FROM price_tiers WHERE offer_id=?',[$id]);foreach($tiers as $qty=>$tier)DB::query('INSERT INTO price_tiers(offer_id,min_qty,price) VALUES(?,?,?)',[$id,$qty,$tier]);if($image)DB::query('INSERT INTO offer_images(offer_id,filename,mime) VALUES(?,?,"image/webp")',[$id,$image]);$db->commit();
        }catch(\Throwable $e){$db->rollBack();if($image)unlink(ROOT.'/storage/uploads/'.$image);throw $e;}
        flash('Listing saved.');redirect('/listings');
    }
    private function messages(string $path): void {
        $u=Auth::require('retailer','wholesaler');$id=(int)($_GET['id']??0);
        $conversations=DB::all('SELECT c.*,b.name other_name,(SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.sender_id<>? AND (mine.last_read_at IS NULL OR m.created_at>mine.last_read_at)) unread FROM conversations c JOIN conversation_participants mine ON mine.conversation_id=c.id AND mine.user_id=? JOIN conversation_participants other ON other.conversation_id=c.id AND other.user_id<>mine.user_id JOIN businesses b ON b.user_id=other.user_id ORDER BY c.id DESC',[$u['id'],$u['id']]);
        if(!$id && $conversations)$id=(int)$conversations[0]['id'];$messages=[];$conversation=null;$page=max(1,(int)($_GET['page']??1));
        if($id){Auth::conversation($id,(int)$u['id']);$conversation=DB::one('SELECT c.*,p.name product_name FROM conversations c LEFT JOIN products p ON p.id=c.product_id WHERE c.id=?',[$id]);$messages=array_reverse(DB::all('SELECT m.*,b.name sender FROM messages m JOIN businesses b ON b.user_id=m.sender_id WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 40 OFFSET '.(($page-1)*40),[$id]));DB::query('UPDATE conversation_participants SET last_read_at=NOW(6) WHERE conversation_id=? AND user_id=?',[$id,$u['id']]);}
        if($path==='/messages/poll'){header('Content-Type: application/json');echo json_encode(['messages'=>$messages]);return;}
        view('messages',['title'=>'Messages','conversations'=>$conversations,'messages'=>$messages,'conversation'=>$conversation,'id'=>$id,'page'=>$page]);
    }
    private function sendMessage(string $path): void {
        $u=Auth::require('retailer','wholesaler');Accounts::limit('message',(string)$u['id'],100);$body=$this->text('body',4000);
        if($path==='/messages/start'){
            Auth::require('retailer');$supplier=(int)($_POST['supplier_id']??0);$p=(int)($_POST['product_id']??0);$order=(int)($_POST['order_id']??0);
            if(!DB::one('SELECT b.user_id FROM businesses b JOIN users u ON u.id=b.user_id WHERE b.user_id=? AND u.role="wholesaler" AND b.status="approved"',[$supplier]))throw new \RuntimeException('Supplier unavailable.');
            if($p&&!DB::one('SELECT id FROM offers WHERE product_id=? AND supplier_id=? AND archived=0',[$p,$supplier]))throw new \RuntimeException('Product reference is invalid.');
            if($order){$o=Orders::get($order);if(!$o || (int)$o['supplier_id']!==$supplier)throw new \RuntimeException('Order reference is invalid.');Auth::order($o,$u);}
            $db=DB::connection();$db->beginTransaction();try{DB::query('INSERT INTO conversations(product_id,order_id) VALUES(?,?)',[$p?:null,$order?:null]);$id=DB::id();foreach([$u['id'],$supplier] as $participant)DB::query('INSERT INTO conversation_participants(conversation_id,user_id) VALUES(?,?)',[$id,$participant]);DB::query('INSERT INTO messages(conversation_id,sender_id,body) VALUES(?,?,?)',[$id,$u['id'],$body]);$db->commit();}catch(\Throwable $e){$db->rollBack();throw $e;}
        }else{$id=(int)$_POST['id'];Auth::conversation($id,(int)$u['id']);DB::query('INSERT INTO messages(conversation_id,sender_id,body) VALUES(?,?,?)',[$id,$u['id'],$body]);}
        redirect('/messages?id='.$id);
    }
    private function expenses(string $path): void {
        $u=Auth::require('retailer');$from=$_GET['from']??date('Y-m-01');$to=$_GET['to']??date('Y-m-d');foreach([$from,$to] as $date){$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed || $parsed->format('Y-m-d')!==$date)throw new \RuntimeException('Invalid date range.');}
        $where='c.retailer_id=? AND c.created_at>=? AND c.created_at<DATE_ADD(?,INTERVAL 1 DAY)';$params=[$u['id'],$from,$to];
        if(!empty($_GET['supplier'])){$where.=' AND o.supplier_id=?';$params[]=(int)$_GET['supplier'];}
        if(!empty($_GET['status'])){$where.=' AND c.status=?';$params[]=$_GET['status'];}
        if(!empty($_GET['category'])){$where.=' AND EXISTS(SELECT 1 FROM order_items i WHERE i.order_id=o.id AND i.category_id=?)';$params[]=(int)$_GET['category'];}
        $rows=DB::all('SELECT o.*,c.status payment_status,c.mode,c.currency,c.created_at,b.name supplier,COALESCE(r.amount,0) refunded,COALESCE(r.merchandise,0) refunded_merchandise,COALESCE(r.delivery,0) refunded_delivery,COALESCE(r.tax,0) refunded_tax,COALESCE(r.fee,0) refunded_fee FROM supplier_orders o JOIN checkouts c ON c.id=o.checkout_id JOIN businesses b ON b.user_id=o.supplier_id LEFT JOIN refunds r ON r.order_id=o.id AND r.status="succeeded" WHERE '.$where.' ORDER BY o.id DESC',$params);
        if($path==='/expenses/export'){header('Content-Type: text/csv');header('Content-Disposition: attachment; filename="my-store-expenses.csv"');$f=fopen('php://output','w');fputcsv($f,['Order','Date','Supplier','Currency','Mode','Payment status','Merchandise minor','Delivery minor','Tax minor','Buyer fee minor','Refund minor','Net minor','Note'],',','"','');foreach($rows as $r){$safe=fn($s)=>preg_match('/^[=+@\-\t\r]/',(string)$s)?"'".$s:$s;fputcsv($f,[$r['id'],$r['created_at'],$safe($r['supplier']),$r['currency'],$r['mode']==='demo'?'demo - no real payment':'stripe test',$r['payment_status'],$r['subtotal'],$r['delivery'],$r['tax'],$r['fee'],$r['refunded'],$r['total']-$r['refunded'],$safe($r['notes'])],',','"','');}fclose($f);return;}
        view('expenses',['title'=>'Purchase history & expenses','rows'=>$rows,'from'=>$from,'to'=>$to,'categories'=>DB::all('SELECT * FROM categories'),'suppliers'=>DB::all('SELECT DISTINCT b.user_id,b.name FROM businesses b JOIN supplier_orders o ON o.supplier_id=b.user_id JOIN checkouts c ON c.id=o.checkout_id WHERE c.retailer_id=?',[$u['id']])]);
    }
    private function admin(string $path): void {
        $u=Auth::require('admin');$id=(int)($_POST['id']??0);$destination='/admin';
        switch($path){
        case '/admin/user':
            $target=DB::one('SELECT * FROM users WHERE id=?',[$id]);if(!$target || $target['role']==='admin')throw new \RuntimeException('Administrator accounts cannot be modified here.');
            $status=$_POST['status']??'';if(!in_array($status,['pending','approved','suspended'],true))throw new \RuntimeException('Invalid approval status.');DB::query('UPDATE businesses SET status=? WHERE user_id=?',[$status,$id]);DB::query('UPDATE users SET active=? WHERE id=?',[isset($_POST['active'])?1:0,$id]);$destination='/admin/users';break;
        case '/admin/category': $name=$this->text('name',100);$slug=strtolower(preg_replace('/[^a-zA-Z0-9]+/','-',$name));if($id)DB::query('UPDATE categories SET name=?,slug=? WHERE id=?',[$name,$slug,$id]);else DB::query('INSERT INTO categories(name,slug) VALUES(?,?)',[$name,$slug]);$destination='/admin/catalog';break;
        case '/admin/product':
            $category=(int)$_POST['category_id'];if(!DB::one('SELECT id FROM categories WHERE id=?',[$category]))throw new \RuntimeException('Invalid category.');$values=[$category,$this->text('name',150),$this->text('brand',100),$this->text('sku',100),$this->text('gtin',30,false),$this->text('description',3000,false)];if($id){$values[]=$id;DB::query('UPDATE products SET category_id=?,name=?,brand=?,sku=?,gtin=?,description=? WHERE id=?',$values);}else DB::query('INSERT INTO products(category_id,name,brand,sku,gtin,description) VALUES(?,?,?,?,?,?)',$values);$destination='/admin/catalog';break;
        case '/admin/import':if(empty($_FILES['csv']) || $_FILES['csv']['error']!==0 || $_FILES['csv']['size']>2*1024*1024 || !is_uploaded_file($_FILES['csv']['tmp_name']))throw new \RuntimeException('Upload a CSV up to 2 MB.');$n=Imports::csv($_FILES['csv']['tmp_name'],(int)$u['id']);flash("Imported $n comparison offers.");redirect('/admin/imports');
        case '/admin/external/delete':DB::query('DELETE FROM external_offers WHERE id=?',[$id]);$destination='/admin/imports';break;
        case '/admin/setting':
            $currency=strtoupper($this->text('currency',3));$fee=(int)$_POST['buyer_fee_bps'];$tax=(int)$_POST['tax_bps'];if(!in_array($currency,['USD','EUR','GBP','CAD','AUD','PKR'],true)||$fee<0||$fee>1000||$tax<0||$tax>5000)throw new \RuntimeException('Check currency, buyer fee (0–1000 bps) and tax (0–5000 bps).');
            if($currency!==DB::setting('currency','USD') && DB::one('SELECT id FROM checkouts LIMIT 1'))throw new \RuntimeException('Currency cannot change after checkout history exists. Use a separate marketplace database.');
            $basis=$_POST['fee_basis']??'merchandise_after_discounts';if(!in_array($basis,['merchandise_after_discounts','merchandise_plus_delivery'],true))throw new \RuntimeException('Invalid buyer fee basis.');
            foreach(['currency'=>$currency,'buyer_fee_bps'=>$fee,'tax_bps'=>$tax,'fee_basis'=>$basis] as $key=>$val)DB::query('INSERT INTO settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=?',[$key,(string)$val,(string)$val]);$destination='/admin/settings';break;
        case '/admin/refund':Orders::refund($id,(int)$u['id']);flash('Refund requested. Demo refunds complete immediately; provider refunds follow Stripe confirmation.');redirect('/order?id='.$id);
        case '/admin/payout':Orders::payout($id,(int)$u['id']);flash('Stripe Connect test transfer sent.');redirect('/order?id='.$id);
        case '/admin/listing':DB::query('UPDATE offers SET archived=1 WHERE id=?',[$id]);$destination='/admin/catalog';break;
        case '/admin/connect':$account=$this->text('stripe_account',100,false);if($account!==''&&!preg_match('/^acct_[A-Za-z0-9]+$/',$account))throw new \RuntimeException('Invalid connected account ID.');DB::query('UPDATE fulfillment_settings SET stripe_account=? WHERE supplier_id=?',[$account?:null,$id]);$destination='/admin/users';break;
        }
        DB::audit((int)$u['id'],substr($path,7),'id:'.$id);flash('Marketplace updated.');redirect($destination);
    }
}
