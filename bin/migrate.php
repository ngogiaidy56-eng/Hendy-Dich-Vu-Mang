<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Env.php';
require dirname(__DIR__) . '/app/Database.php';

Hendy\Env::load(dirname(__DIR__) . '/.env');
Hendy\Database::migrate();
echo "Đã cập nhật PostgreSQL schema.\n";
