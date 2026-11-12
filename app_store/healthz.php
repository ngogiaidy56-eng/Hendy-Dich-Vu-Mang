<?php
header('Content-Type: application/json; charset=utf-8');
try { require_once __DIR__.'/includes/db.php'; db()->query('SELECT 1'); echo json_encode(['ok'=>true,'database'=>'connected']); }
catch (Throwable $e) { http_response_code(503); echo json_encode(['ok'=>false,'database'=>'unavailable']); }