<?php
declare(strict_types=1);
if(session_status()!==PHP_SESSION_ACTIVE){$secure=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Lax']);session_start();}
function db():PDO{static $p=null;if($p)return $p;$h=getenv('DB_HOST')?:'127.0.0.1';$port=getenv('DB_PORT')?:'3306';$n=getenv('DB_NAME')?:'app_store';$p=new PDO("mysql:host=$h;port=$port;dbname=$n;charset=utf8mb4",getenv('DB_USER')?:'root',getenv('DB_PASS')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);return $p;}
function e(?string $s):string{return htmlspecialchars($s??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf_token():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function csrf_check():void{if(!hash_equals((string)($_SESSION['csrf']??''),(string)($_POST['csrf']??''))){http_response_code(419);exit('Phiên hết hạn, tải lại trang.');}}
function current_user():?array{return $_SESSION['user']??null;}
function require_login():void{if(!current_user()){header('Location: login.php');exit;}}
function flash(string $m):void{$_SESSION['flash']=$m;}
function show_flash():void{if(isset($_SESSION['flash'])){echo '<p class="alert">'.e($_SESSION['flash']).'</p>';unset($_SESSION['flash']);}}