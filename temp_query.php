<?php
require 'app/bootstrap.php';
global $db;
$rows = $db->fetchAll('SELECT * FROM daily_transactions ORDER BY id DESC LIMIT 5');
print_r($rows);
