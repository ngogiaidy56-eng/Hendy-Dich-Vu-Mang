<?php
require_once __DIR__.'/includes/db.php';$_SESSION=[];session_destroy();header('Location: index.php');exit;