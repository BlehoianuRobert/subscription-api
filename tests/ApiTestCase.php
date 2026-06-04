<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use App\Database;
use App\Routes\SubscriptionRoutes;
use App\Routes\WebhookRoutes;

abstract class ApiTestCase extends TestCase
{
    protected $app;

    protected function setUp(): void
    {
        // Wipe all rows before each test
        Database::reset();
        Database::initialize();

        $this->app = AppFactory::create();
        $this->app->addBodyParsingMiddleware();
        $this->app->addErrorMiddleware(false, false, false);
        SubscriptionRoutes::register($this->app);
        WebhookRoutes::register($this->app);
    }

    protected function post(string $uri, array $body = [], array $headers = []): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', $uri);
        $request = $request->withHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $stream  = (new StreamFactory())->createStream(json_encode($body));
        $request = $request->withBody($stream)->withParsedBody($body);

        $response = $this->app->handle($request);

        return [
            'status' => $response->getStatusCode(),
            'body'   => json_decode((string)$response->getBody(), true),
        ];
    }

    protected function get(string $uri): array
    {
        $request  = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        $response = $this->app->handle($request);

        return [
            'status' => $response->getStatusCode(),
            'body'   => json_decode((string)$response->getBody(), true),
        ];
    }

    protected function createSubscription(string $userId = 'user_123'): array
    {
        $res = $this->post('/subscriptions', ['user_id' => $userId]);
        return $res['body'];
    }

    protected function sendWebhook(string $subscriptionId, string $type, string $eventId, float $amount = 9.99): array
    {
        return $this->post('/webhooks/billing', [
            'event_id'        => $eventId,
            'subscription_id' => $subscriptionId,
            'type'            => $type,
            'timestamp'       => now(),
            'amount'          => $amount,
        ]);
    }
}