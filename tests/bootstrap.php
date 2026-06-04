<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/helpers.php';

use App\Database;

// Ensure the schema exists in the test database
Database::initialize();