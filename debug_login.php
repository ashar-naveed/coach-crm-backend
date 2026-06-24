<?php
require_once 'config/database.php';
require_once 'utils/response.php';

$raw = file_get_contents('php://input');
echo "Raw input: [" . $raw . "]<br>";

$data = json_decode($raw, true);
echo "Decoded: ";
var_dump($data);