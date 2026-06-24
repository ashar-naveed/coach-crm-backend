<?php

require_once 'config/database.php';

$db = Database::getConnection();

echo "Database connected successfully.";
