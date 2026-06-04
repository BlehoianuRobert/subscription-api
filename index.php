<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Slim\Factory\AppFactory;
use App\Database;
use App\Routes\SubscriptionRoutes;
use App\Routes\WebhookRoutes;

// Load .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Initialize database tables
Database::initialize();

$app = AppFactory::create();

$app->addErrorMiddleware(true, true, true);
$app->addBodyParsingMiddleware();

// Register routes
SubscriptionRoutes::register($app);
WebhookRoutes::register($app);

$app->run();