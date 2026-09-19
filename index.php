<?php
session_start();

/* =========================
   E-Commerce Shopping Website
   Beginner-friendly single-file PHP application
   ========================= */

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "ecommerce_db";

$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_errno) {
    die("<h2>Database connection failed</h2><p>Start MySQL in XAMPP and import database.sql into phpMyAdmin.</p><p>Error: " . htmlspecialchars($conn->connect_error) . "</p>");
}
$conn->set_charset("utf8mb4");

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function go($url = "index.php") { header("Location: $url"); exit; }
function flash($type, $msg) { $_SESSION['flash'] = [$type, $msg]; }
function show_flash() {
    if (!empty($_SESSION['flash'])) {
        [$type, $msg] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="alert '.e($type).'">'.e($msg).'</div>';
    }
}
function logged_in() { return isset($_SESSION['user_id']); }
function is_admin() { return ($_SESSION['role'] ?? '') === 'admin'; }
function require_login() { if (!logged_in()) { flash('error','Please login first.'); go('index.php?page=login'); } }
function require_admin() { if (!is_admin()) { flash('error','Admin access required.'); go('index.php'); } }
function post($key, $default='') { return trim($_POST[$key] ?? $default); }
function money($n) { return "₹" . number_format((float)$n, 2); }
function cart_count($conn) {
    if (!logged_in()) return 0;
    $id = (int)$_SESSION['user_id'];
    $r = $conn->query("SELECT COALESCE(SUM(quantity),0) c FROM cart WHERE user_id=$id");
    return (int)($r->fetch_assoc()['c'] ?? 0);
}
function price_after_discount($price, $discount) {
    return max(0, (float)$price - ((float)$price * (float)$discount / 100));
}
function csrf() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function check_csrf() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        die("Invalid request token.");
    }
}
function upload_image($field) {
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
    $allowed = ['jpg','jpeg','png','webp','gif'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true) || $_FILES[$field]['size'] > 3*1024*1024) return '';
    $dir = __DIR__ . "/uploads";
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $name = uniqid("p_", true) . "." . $ext;
    move_uploaded_file($_FILES[$field]['tmp_name'], $dir . "/" . $name);
    return "uploads/" . $name;
}

/* First-run default admin and sample data.
   Login: admin@example.com / admin123 */
$adminEmail = "admin@example.com";
$check = $conn->query("SELECT id FROM users WHERE email='".$conn->real_escape_string($adminEmail)."' LIMIT 1");
if ($check && $check->num_rows === 0) {
    $hash = password_hash("admin123", PASSWORD_DEFAULT);
    $st = $conn->prepare("INSERT INTO users(name,email,password,role,status) VALUES(?,?,?,?,?)");
    $role="admin"; $status="active"; $name="Administrator";
    $st->bind_param("sssss",$name,$adminEmail,$hash,$role,$status); $st->execute(); $st->close();
}
if ((int)$conn->query("SELECT COUNT(*) c FROM categories")->fetch_assoc()['c'] === 0) {
    $cats = [["Electronics","Smart gadgets and electronic accessories"],["Fashion","Clothing and lifestyle products"],["Home & Living","Useful products for your home"],["Accessories","Daily-use accessories"]];
    $st=$conn->prepare("INSERT INTO categories(category_name,description,status) VALUES(?,?,?)");
    foreach($cats as $c){$s="active";$st->bind_param("sss",$c[0],$c[1],$s);$st->execute();}
    $st->close();
}

/* =========================
   ACTIONS
   ========================= */
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();

    if ($action === 'login') {
        $email=post('email'); $password=$_POST['password'] ?? '';
        $st=$conn->prepare("SELECT id,name,email,password,role,status FROM users WHERE email=? LIMIT 1");
        $st->bind_param("s",$email); $st->execute(); $u=$st->get_result()->fetch_assoc(); $st->close();
        if ($u && $u['status']==='blocked') { flash('error','Your account is blocked. Contact administrator.'); go('index.php?page=login'); }
        if ($u && password_verify($password,$u['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']=$u['id']; $_SESSION['name']=$u['name']; $_SESSION['email']=$u['email']; $_SESSION['role']=$u['role'];
            flash('success','Login successful.'); go($u['role']==='admin' ? 'index.php?page=admin' : 'index.php');
        }
        flash('error','Invalid email or password.'); go('index.php?page=login');
    }

    if ($action === 'register') {
        $name=post('name'); $email=post('email'); $mobile=post('mobile'); $password=$_POST['password']??''; $confirm=$_POST['confirm']??'';
        if ($name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<6 || $password!==$confirm) {
            flash('error','Enter valid details. Password must be 6+ characters and match confirm password.'); go('index.php?page=register');
        }
        $st=$conn->prepare("SELECT id FROM users WHERE email=?"); $st->bind_param("s",$email); $st->execute();
        if($st->get_result()->num_rows){$st->close();flash('error','Email already registered.');go('index.php?page=register');}
        $st->close();
        $hash=password_hash($password,PASSWORD_DEFAULT); $role='user';$status='active';
        $st=$conn->prepare("INSERT INTO users(name,email,password,mobile,role,status) VALUES(?,?,?,?,?,?)");
        $st->bind_param("ssssss",$name,$email,$hash,$mobile,$role,$status);$st->execute();$st->close();
        flash('success','Registration successful. Please login.'); go('index.php?page=login');
    }

    if ($action === 'cart_add') {
        require_login(); $pid=(int)($_POST['product_id']??0); $qty=max(1,(int)($_POST['quantity']??1)); $uid=(int)$_SESSION['user_id'];
        $st=$conn->prepare("SELECT id,stock,status FROM products WHERE id=?");$st->bind_param("i",$pid);$st->execute();$p=$st->get_result()->fetch_assoc();$st->close();
        if(!$p || $p['status']!=='active' || $p['stock']<=0){flash('error','Product is unavailable.');go('index.php?page=products');}
        $st=$conn->prepare("SELECT quantity FROM cart WHERE user_id=? AND product_id=?");$st->bind_param("ii",$uid,$pid);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
        $newqty=($row['quantity']??0)+$qty; if($newqty>$p['stock'])$newqty=$p['stock'];
        if($row){$st=$conn->prepare("UPDATE cart SET quantity=? WHERE user_id=? AND product_id=?");$st->bind_param("iii",$newqty,$uid,$pid);}
        else{$st=$conn->prepare("INSERT INTO cart(user_id,product_id,quantity) VALUES(?,?,?)");$st->bind_param("iii",$uid,$pid,$qty);}
        $st->execute();$st->close();flash('success','Product added to cart.');go($_POST['return_to']??'index.php?page=cart');
    }

    if ($action === 'cart_update') {
        require_login(); $uid=(int)$_SESSION['user_id']; $pid=(int)($_POST['product_id']??0); $qty=(int)($_POST['quantity']??1);
        if($qty<=0){$st=$conn->prepare("DELETE FROM cart WHERE user_id=? AND product_id=?");$st->bind_param("ii",$uid,$pid);}
        else{$st=$conn->prepare("UPDATE cart c JOIN products p ON p.id=c.product_id SET c.quantity=LEAST(?,p.stock) WHERE c.user_id=? AND c.product_id=?");$st->bind_param("iii",$qty,$uid,$pid);}
        $st->execute();$st->close();go('index.php?page=cart');
    }

    if ($action === 'coupon_apply') {
        require_login(); $code=strtoupper(post('coupon_code')); $uid=(int)$_SESSION['user_id'];
        $st=$conn->prepare("SELECT * FROM coupons WHERE coupon_code=? AND status='active' LIMIT 1");$st->bind_param("s",$code);$st->execute();$cp=$st->get_result()->fetch_assoc();$st->close();
        $cartTotal=0;
        $r=$conn->query("SELECT c.quantity,p.price,p.discount FROM cart c JOIN products p ON p.id=c.product_id WHERE c.user_id=$uid");
        while($x=$r->fetch_assoc())$cartTotal+=price_after_discount($x['price'],$x['discount'])*$x['quantity'];
        if(!$cp || date('Y-m-d')<$cp['start_date'] || date('Y-m-d')>$cp['expiry_date']){unset($_SESSION['coupon']);flash('error','Invalid or expired coupon.');go('index.php?page=cart');}
        if($cartTotal < $cp['minimum_order']){flash('error','Minimum order for this coupon is '.money($cp['minimum_order']).'.');go('index.php?page=cart');}
        $discount=$cp['discount_type']==='percent' ? $cartTotal*$cp['discount_value']/100 : $cp['discount_value'];
        if($cp['maximum_discount']>0)$discount=min($discount,$cp['maximum_discount']);
        $_SESSION['coupon']=['code'=>$cp['coupon_code'],'discount'=>$discount];
        flash('success','Coupon applied successfully.');go('index.php?page=cart');
    }

    if ($action === 'coupon_remove') { unset($_SESSION['coupon']); flash('success','Coupon removed.'); go('index.php?page=cart'); }

    if ($action === 'checkout') {
        require_login(); $uid=(int)$_SESSION['user_id']; $name=post('shipping_name');$email=post('shipping_email');$mobile=post('shipping_mobile');$address=post('shipping_address');$city=post('shipping_city');$state=post('shipping_state');$pin=post('shipping_pincode');$payment=post('payment_method');
        if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||!$mobile||!$address||!$city||!$state||!$pin||!in_array($payment,['COD','Demo Online'],true)){flash('error','Please complete all checkout fields.');go('index.php?page=checkout');}
        $items=[];$subtotal=0;$r=$conn->query("SELECT c.product_id,c.quantity,p.product_name,p.price,p.discount,p.stock,p.status FROM cart c JOIN products p ON p.id=c.product_id WHERE c.user_id=$uid");
        while($x=$r->fetch_assoc()){if($x['status']!=='active'||$x['stock']<$x['quantity']){flash('error','A cart product is unavailable or out of stock.');go('index.php?page=cart');}$x['unit']=price_after_discount($x['price'],$x['discount']);$x['line']=$x['unit']*$x['quantity'];$subtotal+=$x['line'];$items[]=$x;}
        if(!$items){flash('error','Your cart is empty.');go('index.php?page=cart');}
        $couponCode=$_SESSION['coupon']['code']??'';$couponDiscount=(float)($_SESSION['coupon']['discount']??0);$shipping=$subtotal>=999?0:79;$final=max(0,$subtotal-$couponDiscount+$shipping);
        $conn->begin_transaction();
        try{
            $orderStatus='Pending';$delivered='Not Delivered';$payStatus=$payment==='COD'?'Pending':'Paid';
            $st=$conn->prepare("INSERT INTO orders(user_id,total_amount,coupon_code,discount,shipping_charge,final_amount,payment_method,payment_status,order_status,delivered_status,shipping_name,shipping_email,shipping_mobile,shipping_address,shipping_city,shipping_state,shipping_pincode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param("idsdddsssssssssss",$uid,$subtotal,$couponCode,$couponDiscount,$shipping,$final,$payment,$payStatus,$orderStatus,$delivered,$name,$email,$mobile,$address,$city,$state,$pin);$st->execute();$oid=$conn->insert_id;$st->close();
            $st=$conn->prepare("INSERT INTO order_items(order_id,product_id,product_name,price,quantity,subtotal) VALUES(?,?,?,?,?,?)");
            foreach($items as $x){$st->bind_param("iisdid",$oid,$x['product_id'],$x['product_name'],$x['unit'],$x['quantity'],$x['line']);$st->execute();$conn->query("UPDATE products SET stock=stock-".(int)$x['quantity']." WHERE id=".(int)$x['product_id']);}
            $st->close();
            $transaction=$payment==='COD'?'COD-'.date('YmdHis').'-'.$oid:'DEMO-'.date('YmdHis').'-'.$oid;
            $st=$conn->prepare("INSERT INTO payments(order_id,user_id,payment_method,transaction_id,amount,payment_status) VALUES(?,?,?,?,?,?)");$st->bind_param("iissds",$oid,$uid,$payment,$transaction,$final,$payStatus);$st->execute();$st->close();
            $conn->query("DELETE FROM cart WHERE user_id=$uid");unset($_SESSION['coupon']);$conn->commit();
            $_SESSION['last_order']=$oid;go('index.php?page=success&order='.$oid);
        }catch(Throwable $e){$conn->rollback();flash('error','Order could not be placed.');go('index.php?page=checkout');}
    }

    if ($action === 'contact') {
        $name=post('name');$email=post('email');$mobile=post('mobile');$subject=post('subject');$message=post('message');
        if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||!$subject||!$message){flash('error','Please fill all required contact fields.');go('index.php?page=contact');}
        $st=$conn->prepare("INSERT INTO contact_messages(name,email,mobile,subject,message) VALUES(?,?,?,?,?)");$st->bind_param("sssss",$name,$email,$mobile,$subject,$message);$st->execute();$st->close();flash('success','Your message has been submitted.');go('index.php?page=contact');
    }

    if ($action === 'profile') {
        require_login();$uid=(int)$_SESSION['user_id'];$name=post('name');$mobile=post('mobile');$address=post('address');$city=post('city');$state=post('state');$pin=post('pincode');
        $img=upload_image('profile_image');$sql=$img?"UPDATE users SET name=?,mobile=?,address=?,city=?,state=?,pincode=?,profile_image=? WHERE id=?":"UPDATE users SET name=?,mobile=?,address=?,city=?,state=?,pincode=? WHERE id=?";
        $st=$conn->prepare($sql);if($img)$st->bind_param("sssssssi",$name,$mobile,$address,$city,$state,$pin,$img,$uid);else $st->bind_param("ssssssi",$name,$mobile,$address,$city,$state,$pin,$uid);$st->execute();$st->close();$_SESSION['name']=$name;flash('success','Profile updated.');go('index.php?page=profile');
    }

    if ($action === 'password') {
        require_login();$uid=(int)$_SESSION['user_id'];$cur=$_POST['current_password']??'';$new=$_POST['new_password']??'';$conf=$_POST['confirm_password']??'';
        $st=$conn->prepare("SELECT password FROM users WHERE id=?");$st->bind_param("i",$uid);$st->execute();$u=$st->get_result()->fetch_assoc();$st->close();
        if(!$u||!password_verify($cur,$u['password'])||$new===''||$new!==$conf){flash('error','Current password is incorrect or new passwords do not match.');go('index.php?page=password');}
        $hash=password_hash($new,PASSWORD_DEFAULT);$st=$conn->prepare("UPDATE users SET password=? WHERE id=?");$st->bind_param("si",$hash,$uid);$st->execute();$st->close();flash('success','Password changed successfully.');go('index.php?page=profile');
    }

    /* Admin CRUD */
    if (strpos($action,'admin_')===0) {
        require_admin();
        if($action==='admin_user_status'){ $id=(int)$_POST['id'];$status=post('status');$st=$conn->prepare("UPDATE users SET status=? WHERE id=? AND role!='admin'");$st->bind_param("si",$status,$id);$st->execute();$st->close();go('index.php?page=admin&tab=users');}
        if($action==='admin_user_delete'){ $id=(int)$_POST['id'];$conn->query("DELETE FROM users WHERE id=$id AND role!='admin'");go('index.php?page=admin&tab=users');}
        if($action==='admin_category_save'){
            $id=(int)($_POST['id']??0);$n=post('category_name');$d=post('description');$s=post('status')==='inactive'?'inactive':'active';
            if($id){$st=$conn->prepare("UPDATE categories SET category_name=?,description=?,status=? WHERE id=?");$st->bind_param("sssi",$n,$d,$s,$id);}else{$st=$conn->prepare("INSERT INTO categories(category_name,description,status) VALUES(?,?,?)");$st->bind_param("sss",$n,$d,$s);}
            $st->execute();$st->close();go('index.php?page=admin&tab=categories');
        }
        if($action==='admin_category_delete'){ $id=(int)$_POST['id'];$conn->query("DELETE FROM categories WHERE id=$id");go('index.php?page=admin&tab=categories');}
        if($action==='admin_category_status'){ $id=(int)$_POST['id'];$s=post('status');$st=$conn->prepare("UPDATE categories SET status=? WHERE id=?");$st->bind_param("si",$s,$id);$st->execute();$st->close();go('index.php?page=admin&tab=categories');}
        if($action==='admin_product_save'){
            $id=(int)($_POST['id']??0);$n=post('product_name');$cat=(int)$_POST['category_id'];$desc=post('description');$price=(float)$_POST['price'];$disc=(float)$_POST['discount'];$stock=(int)$_POST['stock'];$sku=post('sku');$status=post('status')==='inactive'?'inactive':'active';$img=upload_image('image');
            $okCat=$conn->query("SELECT id FROM categories WHERE id=$cat AND status='active'")->num_rows;
            if(!$okCat){flash('error','Select an active category.');go('index.php?page=admin&tab=products');}
            if($id){ if($img){$st=$conn->prepare("UPDATE products SET category_id=?,product_name=?,description=?,price=?,discount=?,stock=?,sku=?,image=?,status=? WHERE id=?");$st->bind_param("issddisssi",$cat,$n,$desc,$price,$disc,$stock,$sku,$img,$status,$id);}
                else{$st=$conn->prepare("UPDATE products SET category_id=?,product_name=?,description=?,price=?,discount=?,stock=?,sku=?,status=? WHERE id=?");$st->bind_param("issddissi",$cat,$n,$desc,$price,$disc,$stock,$sku,$status,$id);} $st->execute();$st->close();
            }else{$st=$conn->prepare("INSERT INTO products(category_id,product_name,description,price,discount,stock,sku,image,status) VALUES(?,?,?,?,?,?,?,?,?)");$st->bind_param("issddisss",$cat,$n,$desc,$price,$disc,$stock,$sku,$img,$status);$st->execute();$id=$conn->insert_id;$st->close();}
            if(!empty($_FILES['gallery']['name'][0])){ $dir=__DIR__."/uploads";if(!is_dir($dir))mkdir($dir,0777,true); foreach($_FILES['gallery']['name'] as $k=>$nm){if($_FILES['gallery']['error'][$k]!==UPLOAD_ERR_OK)continue;$ext=strtolower(pathinfo($nm,PATHINFO_EXTENSION));if(!in_array($ext,['jpg','jpeg','png','webp','gif'],true))continue;$new=uniqid("g_",true).".".$ext;move_uploaded_file($_FILES['gallery']['tmp_name'][$k],$dir."/".$new);$path="uploads/".$new;$st=$conn->prepare("INSERT INTO product_images(product_id,image) VALUES(?,?)");$st->bind_param("is",$id,$path);$st->execute();$st->close();}}
            go('index.php?page=admin&tab=products');
        }
        if($action==='admin_product_delete'){ $id=(int)$_POST['id'];$conn->query("DELETE FROM products WHERE id=$id");go('index.php?page=admin&tab=products');}
        if($action==='admin_order_update'){ $id=(int)$_POST['id'];$os=post('order_status');$ps=post('payment_status');$ds=$os==='Delivered'?'Delivered':(post('delivered_status')==='Delivered'?'Delivered':'Not Delivered');$st=$conn->prepare("UPDATE orders SET order_status=?,payment_status=?,delivered_status=? WHERE id=?");$st->bind_param("sssi",$os,$ps,$ds,$id);$st->execute();$st->close();go('index.php?page=admin&tab=orders');}
        if($action==='admin_coupon_save'){
            $id=(int)($_POST['id']??0);$code=strtoupper(post('coupon_code'));$type=post('discount_type');$value=(float)$_POST['discount_value'];$min=(float)$_POST['minimum_order'];$max=(float)$_POST['maximum_discount'];$start=post('start_date');$exp=post('expiry_date');$status=post('status')==='inactive'?'inactive':'active';
            if($id){$st=$conn->prepare("UPDATE coupons SET coupon_code=?,discount_type=?,discount_value=?,minimum_order=?,maximum_discount=?,start_date=?,expiry_date=?,status=? WHERE id=?");$st->bind_param("ssdddsssi",$code,$type,$value,$min,$max,$start,$exp,$status,$id);}
            else{$st=$conn->prepare("INSERT INTO coupons(coupon_code,discount_type,discount_value,minimum_order,maximum_discount,start_date,expiry_date,status) VALUES(?,?,?,?,?,?,?,?)");$st->bind_param("ssdddsss",$code,$type,$value,$min,$max,$start,$exp,$status);}
            $st->execute();$st->close();go('index.php?page=admin&tab=coupons');
        }
        if($action==='admin_coupon_delete'){ $id=(int)$_POST['id'];$conn->query("DELETE FROM coupons WHERE id=$id");go('index.php?page=admin&tab=coupons');}
    }
}

if ($action==='logout') { session_unset();session_destroy();header("Location: index.php");exit;}

/* =========================
   DATA + PAGE
   ========================= */
$page=$_GET['page']??'home';
$tab=$_GET['tab']??'dashboard';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($page==='admin'?'Admin Dashboard':'ShopKart - E-Commerce')?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
  <div class="container nav">
    <a class="brand" href="index.php"><span>SK</span> ShopKart</a>
    <button class="menu-btn" onclick="document.querySelector('.navlinks').classList.toggle('show')">☰</button>
    <nav class="navlinks">
      <a href="index.php">Home</a><a href="index.php?page=products">Products</a><a href="index.php?page=categories">Categories</a><a href="index.php?page=about">About</a><a href="index.php?page=contact">Contact</a>
      <?php if(logged_in()): ?>
        <a href="index.php?page=profile">My Profile</a><a href="index.php?page=cart">🛒 Cart <b class="count"><?=cart_count($conn)?></b></a>
        <?php if(is_admin()): ?><a class="admin-link" href="index.php?page=admin">Admin</a><?php endif; ?>
        <a href="index.php?action=logout">Logout</a>
      <?php else: ?><a href="index.php?page=login">Login</a><a class="btn small" href="index.php?page=register">Register</a><?php endif; ?>
    </nav>
  </div>
</header>
<main>
<div class="container"><?php show_flash(); ?>

<?php if($page==='home'): ?>
<section class="hero">
  <div><span class="eyebrow">SMART SHOPPING • GREAT VALUE</span><h1>Everything you need.<br><strong>One simple shop.</strong></h1><p>Discover quality products, secure checkout, useful offers and fast delivery in a clean shopping experience.</p><div class="actions"><a class="btn" href="index.php?page=products">Shop Now →</a><a class="btn outline" href="index.php?page=categories">Explore Categories</a></div></div>
  <div class="hero-card"><div class="hero-icon">🛍️</div><h3>New Season Offers</h3><p>Save on selected products with active coupons.</p><span class="deal">FREE SHIPPING OVER ₹999</span></div>
</section>
<h2 class="section-title">Popular Categories</h2>
<div class="grid four">
<?php $cats=$conn->query("SELECT * FROM categories WHERE status='active' ORDER BY id LIMIT 4"); while($c=$cats->fetch_assoc()): ?>
<a class="cat-card" href="index.php?page=products&category=<?=$c['id']?>"><div class="cat-icon"><?=match($c['category_name']){'Electronics'=>'⚡','Fashion'=>'👕','Home & Living'=>'🏠',default=>'🎒'}?></div><h3><?=e($c['category_name'])?></h3><p><?=e($c['description'])?></p></a>
<?php endwhile; ?></div>
<h2 class="section-title">Featured Products</h2>
<div class="grid four"><?php $ps=$conn->query("SELECT p.*,c.category_name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.status='active' ORDER BY p.id DESC LIMIT 8"); while($p=$ps->fetch_assoc()): ?>
<div class="product-card"><a href="index.php?page=product&id=<?=$p['id']?>"><div class="pimg"><?php if($p['image']):?><img src="<?=e($p['image'])?>" alt="<?=e($p['product_name'])?>"><?php else:?><span>📦</span><?php endif;?></div></a><div class="pc-body"><small><?=e($p['category_name'])?></small><h3><?=e($p['product_name'])?></h3><div class="rating">★★★★★</div><div class="price"><?=money(price_after_discount($p['price'],$p['discount']))?> <?php if($p['discount']>0):?><del><?=money($p['price'])?></del><em><?=$p['discount']?>% OFF</em><?php endif;?></div><form method="post" action="index.php?action=cart_add"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="return_to" value="index.php"><button class="btn full" <?=!$p['stock']?'disabled':''?>><?= $p['stock']?'Add to Cart':'Out of Stock'?></button></form></div></div>
<?php endwhile;?></div>
<?php elseif($page==='products'): ?>
<div class="page-head"><div><span class="eyebrow">OUR COLLECTION</span><h1>Products</h1></div><form class="search" method="get"><input type="hidden" name="page" value="products"><input name="q" value="<?=e($_GET['q']??'')?>" placeholder="Search products..."><select name="category"><option value="">All categories</option><?php $cs=$conn->query("SELECT * FROM categories WHERE status='active'");while($c=$cs->fetch_assoc()):?><option value="<?=$c['id']?>" <?=($_GET['category']??'')==$c['id']?'selected':''?>><?=e($c['category_name'])?></option><?php endwhile;?></select><select name="sort"><option value="">Latest</option><option value="low" <?=($_GET['sort']??'')==='low'?'selected':''?>>Price Low → High</option><option value="high" <?=($_GET['sort']??'')==='high'?'selected':''?>>Price High → Low</option></select><button class="btn">Search</button></form></div>
<?php $q=$conn->real_escape_string($_GET['q']??'');$cat=(int)($_GET['category']??0);$where="p.status='active'";if($q)$where.=" AND (p.product_name LIKE '%$q%' OR p.description LIKE '%$q%')";if($cat)$where.=" AND p.category_id=$cat";$order=($_GET['sort']??'')==='low'?'p.price*(1-p.discount/100) ASC':(($_GET['sort']??'')==='high'?'p.price*(1-p.discount/100) DESC':'p.id DESC');$ps=$conn->query("SELECT p.*,c.category_name FROM products p JOIN categories c ON c.id=p.category_id WHERE $where ORDER BY $order");?>
<div class="grid four"><?php while($p=$ps->fetch_assoc()):?><div class="product-card"><a href="index.php?page=product&id=<?=$p['id']?>"><div class="pimg"><?php if($p['image']):?><img src="<?=e($p['image'])?>" alt="<?=e($p['product_name'])?>"><?php else:?><span>📦</span><?php endif;?></div></a><div class="pc-body"><small><?=e($p['category_name'])?></small><h3><?=e($p['product_name'])?></h3><div class="rating">★★★★★</div><div class="price"><?=money(price_after_discount($p['price'],$p['discount']))?> <del><?=($p['discount']>0)?money($p['price']):''?></del></div><p class="stock"><?= $p['stock']>0?'In Stock ('.$p['stock'].')':'Out of Stock'?></p><form method="post" action="index.php?action=cart_add"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="hidden" name="return_to" value="index.php?page=products"><button class="btn full" <?=$p['stock']<=0?'disabled':''?>>Add to Cart</button></form></div></div><?php endwhile;?></div>
<?php elseif($page==='product'): ?>
<?php $id=(int)($_GET['id']??0);$st=$conn->prepare("SELECT p.*,c.category_name FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=?");$st->bind_param("i",$id);$st->execute();$p=$st->get_result()->fetch_assoc();$st->close();$imgs=$conn->query("SELECT image FROM product_images WHERE product_id=$id");?>
<?php if(!$p):?><div class="empty"><h2>Product not found</h2></div><?php else:?><div class="detail"><div><div class="detail-img"><?php if($p['image']):?><img src="<?=e($p['image'])?>" alt="<?=e($p['product_name'])?>"><?php else:?><span>📦</span><?php endif;?></div><div class="thumbs"><?php while($im=$imgs->fetch_assoc()):?><img src="<?=e($im['image'])?>" alt="Product image"><?php endwhile;?></div></div><div class="detail-info"><span class="pill"><?=e($p['category_name'])?></span><h1><?=e($p['product_name'])?></h1><div class="rating">★★★★★ <small>4.8/5</small></div><p><?=nl2br(e($p['description']))?></p><div class="big-price"><?=money(price_after_discount($p['price'],$p['discount']))?> <?php if($p['discount']):?><del><?=money($p['price'])?></del><span><?=$p['discount']?>% OFF</span><?php endif;?></div><p><b>SKU:</b> <?=e($p['sku']?:'N/A')?> &nbsp; <b>Stock:</b> <?=$p['stock']?></p><form method="post" action="index.php?action=cart_add" class="buy-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="product_id" value="<?=$p['id']?>"><input type="number" name="quantity" min="1" max="<?=$p['stock']?>" value="1"><button class="btn" <?=$p['stock']<=0?'disabled':''?>>Add to Cart</button><button class="btn dark" name="return_to" value="index.php?page=checkout">Buy Now</button></form><div class="info-box"><b>Product Information</b><p>Quality product • Secure packaging • Stock controlled by admin • Easy reorder from My Orders.</p></div></div></div><?php endif;?>
<?php elseif($page==='categories'): ?>
<h1 class="center-title">Shop by Category</h1><div class="grid four"><?php $cs=$conn->query("SELECT * FROM categories WHERE status='active' ORDER BY id");while($c=$cs->fetch_assoc()):?><a class="cat-card" href="index.php?page=products&category=<?=$c['id']?>"><div class="cat-icon">🛒</div><h2><?=e($c['category_name'])?></h2><p><?=e($c['description'])?></p><span class="btn small">Open Category</span></a><?php endwhile;?></div>
<?php elseif($page==='cart'): require_login(); ?>
<h1>Your Shopping Cart</h1>
<?php $uid=(int)$_SESSION['user_id'];$r=$conn->query("SELECT c.*,p.product_name,p.price,p.discount,p.stock,p.image FROM cart c JOIN products p ON p.id=c.product_id WHERE c.user_id=$uid");$subtotal=0;$items=[];while($x=$r->fetch_assoc()){$x['unit']=price_after_discount($x['price'],$x['discount']);$x['line']=$x['unit']*$x['quantity'];$subtotal+=$x['line'];$items[]=$x;}?>
<?php if(!$items):?><div class="empty"><div>🛒</div><h2>Your cart is empty</h2><a class="btn" href="index.php?page=products">Start Shopping</a></div><?php else:?><div class="cart-layout"><div><?php foreach($items as $x):?><div class="cart-item"><div class="mini-img"><?php if($x['image']):?><img src="<?=e($x['image'])?>"><?php else:?><span>📦</span><?php endif;?></div><div class="cart-name"><h3><?=e($x['product_name'])?></h3><p><?=money($x['unit'])?> each</p></div><form method="post" action="index.php?action=cart_update" class="qty"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="product_id" value="<?=$x['product_id']?>"><input name="quantity" type="number" min="0" max="<?=$x['stock']?>" value="<?=$x['quantity']?>"><button>Update</button></form><strong><?=money($x['line'])?></strong></div><?php endforeach;?></div><aside class="summary"><h2>Order Summary</h2><p>Subtotal <b><?=money($subtotal)?></b></p><?php $cd=(float)($_SESSION['coupon']['discount']??0);?><p>Coupon Discount <b>-<?=money($cd)?></b></p><?php $ship=$subtotal>=999?0:79;?><p>Shipping <b><?=money($ship)?></b></p><hr><h2>Grand Total <b><?=money(max(0,$subtotal-$cd+$ship))?></b></h2><form method="post" action="index.php?action=coupon_apply" class="coupon"><input type="hidden" name="csrf" value="<?=csrf()?>"><input name="coupon_code" placeholder="Coupon code" value="<?=e($_SESSION['coupon']['code']??'')?>"><button class="btn">Apply</button></form><?php if(isset($_SESSION['coupon'])):?><a class="remove-link" href="index.php?action=coupon_remove">Remove coupon</a><?php endif;?><a class="btn full" href="index.php?page=checkout">Proceed to Checkout</a></aside></div><?php endif;?>
<?php elseif($page==='checkout'): require_login(); ?>
<h1>Checkout</h1><?php $uid=(int)$_SESSION['user_id'];$r=$conn->query("SELECT c.quantity,p.product_name,p.price,p.discount FROM cart c JOIN products p ON p.id=c.product_id WHERE c.user_id=$uid");$subtotal=0;$checkoutItems=[];while($x=$r->fetch_assoc()){$x['unit']=price_after_discount($x['price'],$x['discount']);$x['line']=$x['unit']*$x['quantity'];$subtotal+=$x['line'];$checkoutItems[]=$x;}$cd=(float)($_SESSION['coupon']['discount']??0);$ship=$subtotal>=999?0:79;$final=max(0,$subtotal-$cd+$ship);$st=$conn->prepare("SELECT * FROM users WHERE id=?");$st->bind_param("i",$uid);$st->execute();$me=$st->get_result()->fetch_assoc();$st->close();?>
<?php if(!$checkoutItems):?><div class="empty"><h2>Cart is empty.</h2><a class="btn" href="index.php?page=products">Shop Now</a></div><?php else:?><form method="post" action="index.php?action=checkout"><input type="hidden" name="csrf" value="<?=csrf()?>"><div class="checkout"><div class="form-card"><h2>Shipping Details</h2><div class="form-grid"><label>Name<input name="shipping_name" required value="<?=e($me['name'])?>"></label><label>Email<input name="shipping_email" required type="email" value="<?=e($me['email'])?>"></label><label>Mobile<input name="shipping_mobile" required value="<?=e($me['mobile'])?>"></label><label>Address<input name="shipping_address" required value="<?=e($me['address'])?>"></label><label>City<input name="shipping_city" required value="<?=e($me['city'])?>"></label><label>State<input name="shipping_state" required value="<?=e($me['state'])?>"></label><label>Pincode<input name="shipping_pincode" required value="<?=e($me['pincode'])?>"></label><label>Country<input value="India" readonly></label></div><h2>Payment</h2><label class="radio"><input type="radio" name="payment_method" value="COD" checked> Cash on Delivery</label><label class="radio"><input type="radio" name="payment_method" value="Demo Online"> Online Payment / Demo Payment</label><p class="muted">Online payment is simulated for academic/demo use; no real gateway is connected.</p><button class="btn large">Place Order</button></div><aside class="summary"><h2>Order Summary</h2><?php foreach($checkoutItems as $x):?><p><?=e($x['product_name'])?> × <?=$x['quantity']?> <b><?=money($x['line'])?></b></p><?php endforeach;?><hr><p>Subtotal <b><?=money($subtotal)?></b></p><p>Coupon <b>-<?=money($cd)?></b></p><p>Shipping <b><?=money($ship)?></b></p><h2>Total <b><?=money($final)?></b></h2></aside></div></form><?php endif;?>
<?php elseif($page==='success'): require_login();$oid=(int)($_GET['order']??0);$uid=(int)$_SESSION['user_id'];$st=$conn->prepare("SELECT * FROM orders WHERE id=? AND user_id=?");$st->bind_param("ii",$oid,$uid);$st->execute();$o=$st->get_result()->fetch_assoc();$st->close();?>
<?php if(!$o):?><div class="empty"><h2>Order not found.</h2></div><?php else:?><div class="success-page"><div class="success-icon">✓</div><h1><?= $o['payment_method']==='Demo Online'?'Payment Successful':'Order Placed Successfully'?></h1><p>Your order has been saved successfully.</p><div class="success-grid"><div><span>Order ID</span><b>#<?=$o['id']?></b></div><div><span>Payment Status</span><b><?=e($o['payment_status'])?></b></div><div><span>Order Amount</span><b><?=money($o['final_amount'])?></b></div><div><span>Order Date</span><b><?=e($o['created_at'])?></b></div></div><div class="actions center"><a class="btn" href="index.php?page=orders">View Order</a><a class="btn outline" href="index.php?page=products">Continue Shopping</a></div></div><?php endif;?>
<?php elseif($page==='login' || $page==='register'): ?>
<div class="auth-wrap"><div class="auth-card"><div class="brand big"><span>SK</span> ShopKart</div><h1><?=$page==='login'?'Welcome Back':'Create Account'?></h1><p class="muted"><?=$page==='login'?'Login to continue shopping.':'Register a new customer account.'?></p><form method="post" action="index.php?action=<?=$page?>"><input type="hidden" name="csrf" value="<?=csrf()?>"><?php if($page==='register'):?><label>Name<input name="name" required></label><label>Mobile<input name="mobile"></label><?php endif;?><label>Email<input name="email" type="email" required></label><label>Password<input name="password" type="password" required><?php if($page==='login'):?><small>Default admin: admin@example.com / admin123</small><?php endif;?></label><?php if($page==='register'):?><label>Confirm Password<input name="confirm" type="password" required></label><?php endif;?><button class="btn full large"><?=$page==='login'?'Login':'Register'?></button></form><p class="center"><?php if($page==='login'):?>New user? <a href="index.php?page=register">Create account</a><?php else:?>Already registered? <a href="index.php?page=login">Login</a><?php endif;?></p></div></div>
<?php elseif($page==='profile'): require_login();$uid=(int)$_SESSION['user_id'];$st=$conn->prepare("SELECT * FROM users WHERE id=?");$st->bind_param("i",$uid);$st->execute();$u=$st->get_result()->fetch_assoc();$st->close();?>
<h1>My Profile</h1><div class="profile-layout"><div class="profile-card"><div class="avatar"><?php if($u['profile_image']):?><img src="<?=e($u['profile_image'])?>"><?php else:?><?=e(strtoupper(substr($u['name'],0,1)))?><?php endif;?></div><h2><?=e($u['name'])?></h2><p><?=e($u['email'])?></p><a class="btn outline full" href="index.php?page=orders">My Orders</a><a class="btn outline full" href="index.php?page=password">Change Password</a><a class="btn dark full" href="index.php?action=logout">Logout</a></div><div class="form-card"><h2>Edit Profile</h2><form method="post" enctype="multipart/form-data" action="index.php?action=profile"><input type="hidden" name="csrf" value="<?=csrf()?>"><div class="form-grid"><label>Name<input name="name" value="<?=e($u['name'])?>" required></label><label>Email<input value="<?=e($u['email'])?>" readonly></label><label>Mobile<input name="mobile" value="<?=e($u['mobile'])?>"></label><label>Address<input name="address" value="<?=e($u['address'])?>"></label><label>City<input name="city" value="<?=e($u['city'])?>"></label><label>State<input name="state" value="<?=e($u['state'])?>"></label><label>Pincode<input name="pincode" value="<?=e($u['pincode'])?>"></label><label>Profile Image<input type="file" name="profile_image" accept="image/*"></label></div><button class="btn">Update Profile</button></form></div></div>
<?php elseif($page==='password'): require_login(); ?><div class="auth-wrap"><div class="auth-card"><h1>Change Password</h1><form method="post" action="index.php?action=password"><input type="hidden" name="csrf" value="<?=csrf()?>"><label>Current Password<input name="current_password" type="password" required></label><label>New Password<input name="new_password" type="password" required></label><label>Confirm Password<input name="confirm_password" type="password" required></label><button class="btn full">Change Password</button></form></div></div>
<?php elseif($page==='orders'): require_login();$uid=(int)$_SESSION['user_id'];$orders=$conn->query("SELECT * FROM orders WHERE user_id=$uid ORDER BY id DESC");?>
<h1>My Orders</h1><?php if(!$orders->num_rows):?><div class="empty"><h2>No orders yet.</h2><a class="btn" href="index.php?page=products">Shop Now</a></div><?php else:?><div class="table-wrap"><table><thead><tr><th>Order ID</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Delivered</th><th>Actions</th></tr></thead><tbody><?php while($o=$orders->fetch_assoc()):?><tr><td>#<?=$o['id']?></td><td><?=e($o['created_at'])?></td><td><?=money($o['final_amount'])?></td><td><?=e($o['payment_status'])?></td><td><span class="status"><?=e($o['order_status'])?></span></td><td><?=e($o['delivered_status'])?></td><td><a class="btn tiny" href="index.php?page=order&id=<?=$o['id']?>">View</a><a class="btn tiny dark" href="index.php?action=reorder&id=<?=$o['id']?>">Reorder</a></td></tr><?php endwhile;?></tbody></table></div><?php endif;?>
<?php
elseif($action==='reorder'):
    require_login();

    $oid = (int)($_GET['id'] ?? 0);
    $uid = (int)$_SESSION['user_id'];

    $r = $conn->query("
        SELECT 
            oi.product_id,
            oi.quantity,
            p.stock,
            p.status
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = $oid
        AND o.user_id = $uid
    ");

    $added = 0;

    while ($x = $r->fetch_assoc()) {

        if ($x['status'] === 'active' && $x['stock'] > 0) {

            $q = min($x['quantity'], $x['stock']);

            $st = $conn->prepare("
                INSERT INTO cart(user_id, product_id, quantity)
                VALUES(?,?,?)
                ON DUPLICATE KEY UPDATE
                quantity = LEAST(quantity + VALUES(quantity), ?)
            ");

            $st->bind_param(
                "iiii",
                $uid,
                $x['product_id'],
                $q,
                $x['stock']
            );

            $st->execute();
            $st->close();

            $added++;
        }
    }

    flash(
        $added ? 'success' : 'error',
        $added
            ? "$added product(s) added to cart."
            : "Products are no longer available."
    );

    go('index.php?page=cart');

?>
<?php elseif($page==='order'): require_login();$oid=(int)($_GET['id']??0);$uid=(int)$_SESSION['user_id'];$st=$conn->prepare("SELECT * FROM orders WHERE id=? AND user_id=?");$st->bind_param("ii",$oid,$uid);$st->execute();$o=$st->get_result()->fetch_assoc();$st->close();?>
<?php if(!$o):?><div class="empty"><h2>Order not found.</h2></div><?php else:?><h1>Order #<?=$o['id']?></h1><div class="order-box"><p><b>Customer:</b> <?=e($o['shipping_name'])?></p><p><b>Address:</b> <?=e($o['shipping_address'])?>, <?=e($o['shipping_city'])?>, <?=e($o['shipping_state'])?> - <?=e($o['shipping_pincode'])?></p><p><b>Status:</b> <span class="status"><?=e($o['order_status'])?></span> &nbsp; <b>Delivered:</b> <?=e($o['delivered_status'])?></p><div class="table-wrap"><table><tr><th>Product</th><th>Qty</th><th>Subtotal</th></tr><?php $its=$conn->query("SELECT * FROM order_items WHERE order_id=$oid");while($x=$its->fetch_assoc()):?><tr><td><?=e($x['product_name'])?></td><td><?=$x['quantity']?></td><td><?=money($x['subtotal'])?></td></tr><?php endwhile;?></table></div><h2 class="right">Total: <?=money($o['final_amount'])?></h2></div><?php endif;?>
<?php elseif($page==='about'): ?>
<div class="content-page"><span class="eyebrow">ABOUT US</span><h1>Shopping made simple.</h1><p>ShopKart is a PHP + MySQL based academic e-commerce website designed to provide a complete shopping flow from browsing products to checkout and order management.</p><div class="grid four"><div class="info-box"><h3>🎯 Mission</h3><p>Make online shopping simple and accessible.</p></div><div class="info-box"><h3>👁️ Vision</h3><p>Build a reliable, user-friendly digital store.</p></div><div class="info-box"><h3>⭐ Quality</h3><p>Keep product and stock information organized.</p></div><div class="info-box"><h3>🔒 Secure</h3><p>Use sessions, password hashing and prepared SQL.</p></div></div></div>
<?php elseif($page==='contact'): ?>
<div class="checkout"><div class="form-card"><span class="eyebrow">CONTACT US</span><h1>We'd love to hear from you.</h1><form method="post" action="index.php?action=contact"><input type="hidden" name="csrf" value="<?=csrf()?>"><div class="form-grid"><label>Name<input name="name" required></label><label>Email<input name="email" type="email" required></label><label>Mobile<input name="mobile"></label><label>Subject<input name="subject" required></label></div><label>Message<textarea name="message" required></textarea><button class="btn">Send Message</button></form></div><div class="summary"><h2>Store Information</h2><p>📧 support@shopkart.local</p><p>📞 +91 98765 43210</p><p>📍 Ahmedabad, Gujarat, India</p><p>🕘 Mon–Sat: 10:00 AM – 7:00 PM</p></div></div>
<?php elseif($page==='admin'): require_admin(); ?>
<div class="admin-layout"><aside class="sidebar"><h2>ShopKart Admin</h2><a class="<?=$tab==='dashboard'?'active':''?>" href="index.php?page=admin">Dashboard</a><a class="<?=$tab==='users'?'active':''?>" href="index.php?page=admin&tab=users">Users</a><a class="<?=$tab==='categories'?'active':''?>" href="index.php?page=admin&tab=categories">Categories</a><a class="<?=$tab==='products'?'active':''?>" href="index.php?page=admin&tab=products">Products</a><a class="<?=$tab==='orders'?'active':''?>" href="index.php?page=admin&tab=orders">Orders</a><a class="<?=$tab==='coupons'?'active':''?>" href="index.php?page=admin&tab=coupons">Coupons</a><a href="index.php">← Store</a><a href="index.php?action=logout">Logout</a></aside><section class="admin-main"><div class="admin-top"><div><span class="eyebrow">ADMIN PANEL</span><h1><?=ucfirst($tab)?></h1></div><span class="admin-user">👤 <?=e($_SESSION['name'])?></span></div>
<?php if($tab==='dashboard'): $counts=[];$counts['users']=$conn->query("SELECT COUNT(*) c FROM users WHERE role='user'")->fetch_assoc()['c'];$counts['categories']=$conn->query("SELECT COUNT(*) c FROM categories")->fetch_assoc()['c'];$counts['products']=$conn->query("SELECT COUNT(*) c FROM products")->fetch_assoc()['c'];$counts['orders']=$conn->query("SELECT COUNT(*) c FROM orders")->fetch_assoc()['c'];$counts['pending']=$conn->query("SELECT COUNT(*) c FROM orders WHERE order_status NOT IN('Delivered','Cancelled')")->fetch_assoc()['c'];$counts['delivered']=$conn->query("SELECT COUNT(*) c FROM orders WHERE order_status='Delivered'")->fetch_assoc()['c'];$counts['sales']=$conn->query("SELECT COALESCE(SUM(final_amount),0) c FROM orders WHERE payment_status='Paid'")->fetch_assoc()['c'];$counts['coupons']=$conn->query("SELECT COUNT(*) c FROM coupons WHERE status='active' AND CURDATE() BETWEEN start_date AND expiry_date")->fetch_assoc()['c'];?>
<div class="stats"><?php foreach([['users','Users','👥'],['categories','Categories','🗂️'],['products','Products','📦'],['orders','Orders','🧾'],['pending','Pending Orders','⏳'],['delivered','Delivered','✅'],['sales','Total Sales','💰'],['coupons','Active Coupons','🎟️']] as $x):?><div class="stat"><span><?=$x[2]?></span><small><?=e($x[1])?></small><strong><?=$x[0]==='sales'?money($counts[$x[0]]):$counts[$x[0]]?></strong></div><?php endforeach;?></div><div class="admin-note"><h2>Quick Actions</h2><a class="btn" href="index.php?page=admin&tab=products&add=1">＋ Add Product</a><a class="btn outline" href="index.php?page=admin&tab=categories&add=1">＋ Add Category</a><a class="btn outline" href="index.php?page=admin&tab=coupons&add=1">＋ Add Coupon</a></div>
<?php elseif($tab==='users'): ?><div class="table-wrap"><table><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Mobile</th><th>Registered</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php $rs=$conn->query("SELECT * FROM users WHERE role='user' ORDER BY id DESC");while($u=$rs->fetch_assoc()):?><tr><td><?=$u['id']?></td><td><?=e($u['name'])?></td><td><?=e($u['email'])?></td><td><?=e($u['mobile'])?></td><td><?=e($u['created_at'])?></td><td><span class="status"><?=e($u['status'])?></span></td><td><form class="inline" method="post" action="index.php?action=admin_user_status"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=$u['id']?>"><input type="hidden" name="status" value="<?=$u['status']==='blocked'?'active':'blocked'?>"><button class="btn tiny"><?=$u['status']==='blocked'?'Unblock':'Block'?></button></form><form class="inline" method="post" action="index.php?action=admin_user_delete" onsubmit="return confirm('Delete user?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="btn tiny danger">Delete</button></form></td></tr><?php endwhile;?></tbody></table></div>
<?php elseif($tab==='categories'): $edit=(int)($_GET['edit']??0);$ce=$edit?$conn->query("SELECT * FROM categories WHERE id=$edit")->fetch_assoc():null; ?><div class="admin-form"><?php if(isset($_GET['add'])||$ce):?><form method="post" action="index.php?action=admin_category_save"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=e($ce['id']??0)?>"><div class="form-grid"><label>Category Name<input name="category_name" required value="<?=e($ce['category_name']??'')?>"></label><label>Status<select name="status"><option value="active" <?=($ce['status']??'active')==='active'?'selected':''?>>Active / Open</option><option value="inactive" <?=($ce['status']??'')==='inactive'?'selected':''?>>Inactive / Discontinued</option></select></label></div><label>Description<textarea name="description"><?=e($ce['description']??'')?></textarea><button class="btn">Save Category</button></form></div><?php endif;?><div class="table-wrap"><table><tr><th>ID</th><th>Category</th><th>Description</th><th>Status</th><th>Created</th><th>Actions</th></tr><?php $rs=$conn->query("SELECT * FROM categories ORDER BY id DESC");while($c=$rs->fetch_assoc()):?><tr><td><?=$c['id']?></td><td><?=e($c['category_name'])?></td><td><?=e($c['description'])?></td><td><?=e($c['status'])?></td><td><?=e($c['created_at'])?></td><td><a class="btn tiny" href="index.php?page=products&category=<?=$c['id']?>">Open</a><a class="btn tiny" href="index.php?page=admin&tab=categories&edit=<?=$c['id']?>">Edit</a><form class="inline" method="post" action="index.php?action=admin_category_delete" onsubmit="return confirm('Delete category?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn tiny danger">Delete</button></form></td></tr><?php endwhile;?></table></div>
<?php elseif($tab==='products'): $edit=(int)($_GET['edit']??0);$pe=$edit?$conn->query("SELECT * FROM products WHERE id=$edit")->fetch_assoc():null;?><div class="admin-form"><?php if(isset($_GET['add'])||$pe):?><form method="post" enctype="multipart/form-data" action="index.php?action=admin_product_save"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=e($pe['id']??0)?>"><div class="form-grid"><label>Product Name<input name="product_name" required value="<?=e($pe['product_name']??'')?>"></label><label>Category<select name="category_id" required><?php $cs=$conn->query("SELECT * FROM categories WHERE status='active'");while($c=$cs->fetch_assoc()):?><option value="<?=$c['id']?>" <?=($pe['category_id']??0)==$c['id']?'selected':''?>><?=e($c['category_name'])?></option><?php endwhile;?></select></label><label>Price<input name="price" type="number" step="0.01" required value="<?=e($pe['price']??'0')?>"></label><label>Discount %<input name="discount" type="number" step="0.01" value="<?=e($pe['discount']??'0')?>"></label><label>Stock<input name="stock" type="number" required value="<?=e($pe['stock']??'0')?>"></label><label>SKU<input name="sku" value="<?=e($pe['sku']??'')?>"></label><label>Status<select name="status"><option value="active">Active</option><option value="inactive" <?=($pe['status']??'')==='inactive'?'selected':''?>>Inactive</option></select></label><label>Main Image<input type="file" name="image" accept="image/*"></label><label>Multiple Images<input type="file" name="gallery[]" multiple accept="image/*"></label></div><label>Description<textarea name="description" required><?=e($pe['description']??'')?></textarea><button class="btn">Save Product</button></form></div><?php endif;?><div class="table-wrap"><table><tr><th>ID</th><th>Image</th><th>Product</th><th>Category</th><th>Price</th><th>Discount</th><th>Stock</th><th>Status</th><th>Actions</th></tr><?php $rs=$conn->query("SELECT p.*,c.category_name FROM products p JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC");while($p=$rs->fetch_assoc()):?><tr><td><?=$p['id']?></td><td><?php if($p['image']):?><img class="table-img" src="<?=e($p['image'])?>"><?php else:?>📦<?php endif;?></td><td><?=e($p['product_name'])?></td><td><?=e($p['category_name'])?></td><td><?=money($p['price'])?></td><td><?=$p['discount']?>%</td><td><?=$p['stock']?></td><td><?=e($p['status'])?></td><td><a class="btn tiny" href="index.php?page=product&id=<?=$p['id']?>">Open</a><a class="btn tiny" href="index.php?page=admin&tab=products&edit=<?=$p['id']?>">Edit</a><form class="inline" method="post" action="index.php?action=admin_product_delete" onsubmit="return confirm('Delete product?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=$p['id']?>"><button class="btn tiny danger">Delete</button></form></td></tr><?php endwhile;?></table></div>
<?php elseif($tab==='orders'): ?><div class="table-wrap"><table><tr><th>ID</th><th>Customer</th><th>Total</th><th>Payment</th><th>Payment Status</th><th>Order Status</th><th>Delivered</th><th>Date</th><th>Update</th></tr><?php $rs=$conn->query("SELECT * FROM orders ORDER BY id DESC");while($o=$rs->fetch_assoc()):?><tr><td>#<?=$o['id']?></td><td><?=e($o['shipping_name'])?><br><small><?=e($o['shipping_email'])?></small></td><td><?=money($o['final_amount'])?></td><td><?=e($o['payment_method'])?></td><td><?=e($o['payment_status'])?></td><td><?=e($o['order_status'])?></td><td><?=e($o['delivered_status'])?></td><td><?=e($o['created_at'])?></td><td><form method="post" action="index.php?action=admin_order_update"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=$o['id']?>"><select name="order_status"><?php foreach(['Pending','Confirmed','Processing','Shipped','Out for Delivery','Delivered','Cancelled'] as $s):?><option <?= $o['order_status']===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select><select name="payment_status"><option <?= $o['payment_status']==='Pending'?'selected':''?>>Pending</option><option <?= $o['payment_status']==='Paid'?'selected':''?>>Paid</option><option <?= $o['payment_status']==='Failed'?'selected':''?>>Failed</option></select><button class="btn tiny">Update</button></form></td></tr><?php endwhile;?></table></div>
<?php elseif($tab==='coupons'): $edit=(int)($_GET['edit']??0);$cp=$edit?$conn->query("SELECT * FROM coupons WHERE id=$edit")->fetch_assoc():null;?><div class="admin-form"><?php if(isset($_GET['add'])||$cp):?><form method="post" action="index.php?action=admin_coupon_save"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=e($cp['id']??0)?>"><div class="form-grid"><label>Coupon Code<input name="coupon_code" required value="<?=e($cp['coupon_code']??'')?>"></label><label>Discount Type<select name="discount_type"><option value="percent">Percent</option><option value="fixed" <?=($cp['discount_type']??'')==='fixed'?'selected':''?>>Fixed</option></select></label><label>Discount Value<input name="discount_value" type="number" step="0.01" required value="<?=e($cp['discount_value']??'')?>"></label><label>Minimum Order<input name="minimum_order" type="number" step="0.01" value="<?=e($cp['minimum_order']??'0')?>"></label><label>Maximum Discount<input name="maximum_discount" type="number" step="0.01" value="<?=e($cp['maximum_discount']??'0')?>"></label><label>Start Date<input name="start_date" type="date" required value="<?=e($cp['start_date']??date('Y-m-d'))?>"></label><label>Expiry Date<input name="expiry_date" type="date" required value="<?=e($cp['expiry_date']??date('Y-m-d',strtotime('+30 days')))?>"></label><label>Status<select name="status"><option value="active">Active</option><option value="inactive" <?=($cp['status']??'')==='inactive'?'selected':''?>>Inactive</option></select></label></div><button class="btn">Save Coupon</button></form></div><?php endif;?><div class="table-wrap"><table><tr><th>Code</th><th>Type</th><th>Value</th><th>Min</th><th>Max</th><th>Validity</th><th>Status</th><th>Actions</th></tr><?php $rs=$conn->query("SELECT * FROM coupons ORDER BY id DESC");while($c=$rs->fetch_assoc()):?><tr><td><b><?=e($c['coupon_code'])?></b></td><td><?=e($c['discount_type'])?></td><td><?=e($c['discount_value'])?></td><td><?=money($c['minimum_order'])?></td><td><?=money($c['maximum_discount'])?></td><td><?=e($c['start_date'])?> → <?=e($c['expiry_date'])?></td><td><?=e($c['status'])?><?=date('Y-m-d')>$c['expiry_date']?' / Expired':''?></td><td><a class="btn tiny" href="index.php?page=admin&tab=coupons&edit=<?=$c['id']?>">Edit</a><form class="inline" method="post" action="index.php?action=admin_coupon_delete" onsubmit="return confirm('Delete coupon?')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn tiny danger">Delete</button></form></td></tr><?php endwhile;?></table></div><?php endif;?>
</section></div>
<?php else: ?><div class="empty"><h2>Page not found</h2><a class="btn" href="index.php">Go Home</a></div><?php endif; ?>
</div></main>
<footer><div class="container footer-grid"><div><div class="brand"><span>SK</span> ShopKart</div><p>Modern PHP + MySQL e-commerce project for XAMPP.</p></div><div><h4>Shop</h4><a href="index.php?page=products">Products</a><a href="index.php?page=categories">Categories</a></div><div><h4>Support</h4><a href="index.php?page=about">About</a><a href="index.php?page=contact">Contact</a></div><div><h4>Account</h4><a href="index.php?page=profile">My Profile</a><a href="index.php?page=orders">My Orders</a></div></div><div class="copyright">© <?=date('Y')?> ShopKart. Built with HTML, CSS, PHP and MySQL.</div></footer>
</body></html>
