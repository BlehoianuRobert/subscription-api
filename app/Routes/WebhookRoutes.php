<?php

declare(strict_types=1);

namespace App\Routes;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use App\Database;
use App\Audit;
use App\StateMachine;

class WebhookRoutes
{
    private const ALLOWED_TYPES = ['payment.succeeded', 'payment.failed'];

    public static function register(App $app): void
    {
        // POST /webhooks/billing
        $app->post('/webhooks/billing', function (Request $request, Response $response) {
            $body = $request->getParsedBody() ?? [];

            $eventId        = trim((string)($body['event_id'] ?? ''));
            $subscriptionId = trim((string)($body['subscription_id'] ?? ''));
            $eventType      = trim((string)($body['type'] ?? ''));
            $timestamp      = trim((string)($body['timestamp'] ?? ''));
            $amount         = $body['amount'] ?? null;

            // Validation
            if ($eventId === '') {
                $response->getBody()->write(json_encode(jsonError('missing_field', 'event_id')));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            if ($subscriptionId === '') {
                $response->getBody()->write(json_encode(jsonError('missing_field', 'subscription_id')));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            if (!in_array($eventType, self::ALLOWED_TYPES, true)) {
                $response->getBody()->write(json_encode(jsonError('invalid_event_type')));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            if ($timestamp === '' || strtotime($timestamp) === false) {
                $response->getBody()->write(json_encode(jsonError('missing_field', 'timestamp')));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }
            if ($amount === null || !is_numeric($amount) || (float)$amount < 0) {
                $response->getBody()->write(json_encode(jsonError('missing_field', 'amount')));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $db  = Database::getConnection();
            $now = now();

            // Idempotency check — duplicate event_id
            $existing = $db->query("SELECT event_id FROM billing_events WHERE event_id = " . $db->quote($eventId))->fetch();
            if ($existing) {
                $response->getBody()->write(json_encode(['status' => 'duplicate_ignored']));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            }

            // Find subscription
            $sub = $db->query("SELECT * FROM subscriptions WHERE id = " . $db->quote($subscriptionId))->fetch();

            if (!$sub) {
                $db->prepare("
                    INSERT INTO billing_events
                        (event_id, subscription_id, event_type, carrier_timestamp, amount, received_at, processed_result)
                    VALUES
                        (:event_id, :subscription_id, :event_type, :carrier_timestamp, :amount, :received_at, 'unknown_subscription')
                ")->execute([
                    ':event_id'          => $eventId,
                    ':subscription_id'   => $subscriptionId,
                    ':event_type'        => $eventType,
                    ':carrier_timestamp' => $timestamp,
                    ':amount'            => (float)$amount,
                    ':received_at'       => $now,
                ]);

                $response->getBody()->write(json_encode(jsonError('subscription_not_found')));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            // Apply state transition
            $event = [
                'event_id'   => $eventId,
                'event_type' => $eventType,
                'timestamp'  => $timestamp,
                'amount'     => (float)$amount,
            ];

            $result = StateMachine::applyBillingEvent($sub, $event);

            // Save billing event
            $db->prepare("
                INSERT INTO billing_events
                    (event_id, subscription_id, event_type, carrier_timestamp, amount, received_at, processed_result)
                VALUES
                    (:event_id, :subscription_id, :event_type, :carrier_timestamp, :amount, :received_at, :processed_result)
            ")->execute([
                ':event_id'          => $eventId,
                ':subscription_id'   => $subscriptionId,
                ':event_type'        => $eventType,
                ':carrier_timestamp' => $timestamp,
                ':amount'            => (float)$amount,
                ':received_at'       => $now,
                ':processed_result'  => $result['audit_type'],
            ]);

            // Update subscription if state changed
            if ($result['changed'] && !empty($result['updates'])) {
                $sets   = [];
                $params = [':id' => $sub['id']];
                foreach ($result['updates'] as $col => $val) {
                    $sets[]          = "$col = :$col";
                    $params[":$col"] = $val;
                }
                $db->prepare("UPDATE subscriptions SET " . implode(', ', $sets) . " WHERE id = :id")
                    ->execute($params);
            }

            // Write audit event
            Audit::record(
                $subscriptionId,
                $result['audit_type'],
                $result['old_status'] ?? $sub['status'],
                $result['new_status'] ?? $sub['status'],
                'webhook',
                $result['message'],
                $eventId,
                ['amount' => (float)$amount, 'carrier_timestamp' => $timestamp]
            );

            $response->getBody()->write(json_encode(['status' => 'ok', 'result' => $result['audit_type']]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        });
    }
}