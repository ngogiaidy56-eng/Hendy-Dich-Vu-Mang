<?php
require_once __DIR__.'/db.php';
function require_admin():void{require_login();if((current_user()['role']??'')!=='admin'){http_response_code(403);exit('Không có quyền quản trị.');}}