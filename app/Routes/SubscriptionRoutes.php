<?php

declare(strict_types=1);

namespace App\Routes;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Ramsey\Uuid\Uuid;
use App\Database;
use App\Audit;

class SubscriptionRoutes
{
    public static function register(App $app): void
    {
        // POST /subscriptions
        $app->post('/subscriptions', function (Request $request, Response $response) {
            $body   = $request->getParsedBody() ?? [];
            $userId = trim((string)($body['user_id'] ?? ''));

            if ($userId === '') {
                $response->getBody()->write(json_encode(jsonError('missing_field', 'user_id')));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $tenantId = $request->getHeaderLine('X-Tenant-Id') ?: null;

            $now         = now();
            $trialEndsAt = date('Y-m-d\TH:i:s\Z', strtotime($now) + (7 * 24 * 60 * 60));
            $id          = 'sub_' . Uuid::uuid4()->toString();

            $db   = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO subscriptions
                    (id, user_id, tenant_id, status, trial_starts_at, trial_ends_at, created_at, updated_at)
                VALUES
                    (:id, :user_id, :tenant_id, 'trialing', :trial_starts_at, :trial_ends_at, :created_at, :updated_at)
            ");
            $stmt->execute([
                ':id'              => $id,
                ':user_id'         => $userId,
                ':tenant_id'       => $tenantId,
                ':trial_starts_at' => $now,
                ':trial_ends_at'   => $trialEndsAt,
                ':created_at'      => $now,
                ':updated_at'      => $now,
            ]);

            Audit::record($id, 'subscription.created', null, 'trialing', 'api', 'Subscription created and trial started.');

            $sub = $db->query("SELECT * FROM subscriptions WHERE id = " . $db->quote($id))->fetch();

            $response->getBody()->write(json_encode($sub));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        });

        // GET /subscriptions/{id}
        $app->get('/subscriptions/{id}', function (Request $request, Response $response, array $args) {
            $db  = Database::getConnection();
            $sub = $db->query("SELECT * FROM subscriptions WHERE id = " . $db->quote($args['id']))->fetch();

            if (!$sub) {
                $response->getBody()->write(json_encode(jsonError('subscription_not_found')));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $response->getBody()->write(json_encode($sub));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        });

        // POST /subscriptions/{id}/cancel
        $app->post('/subscriptions/{id}/cancel', function (Request $request, Response $response, array $args) {
            $db  = Database::getConnection();
            $sub = $db->query("SELECT * FROM subscriptions WHERE id = " . $db->quote($args['id']))->fetch();

            if (!$sub) {
                $response->getBody()->write(json_encode(jsonError('subscription_not_found')));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            // Already cancelled — return as-is, no duplicate audit event
            if ($sub['status'] === 'cancelled') {
                $response->getBody()->write(json_encode($sub));
                return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
            }

            $oldStatus = $sub['status'];
            $now       = now();

            $db->prepare("
                UPDATE subscriptions
                SET status = 'cancelled', cancelled_at = :cancelled_at, updated_at = :updated_at
                WHERE id = :id
            ")->execute([
                ':cancelled_at' => $now,
                ':updated_at'   => $now,
                ':id'           => $sub['id'],
            ]);

            Audit::record($sub['id'], 'subscription.cancelled', $oldStatus, 'cancelled', 'api', 'User cancelled the subscription.');

            $updated = $db->query("SELECT * FROM subscriptions WHERE id = " . $db->quote($sub['id']))->fetch();

            $response->getBody()->write(json_encode($updated));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        });

        // GET /subscriptions/{id}/history
        $app->get('/subscriptions/{id}/history', function (Request $request, Response $response, array $args) {
            $db  = Database::getConnection();
            $sub = $db->query("SELECT id FROM subscriptions WHERE id = " . $db->quote($args['id']))->fetch();

            if (!$sub) {
                $response->getBody()->write(json_encode(jsonError('subscription_not_found')));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $events = $db->query("
                SELECT event_type, from_status, to_status, source, external_event_id, message, metadata, created_at
                FROM audit_events
                WHERE subscription_id = " . $db->quote($args['id']) . "
                ORDER BY created_at ASC
            ")->fetchAll();

            $response->getBody()->write(json_encode($events));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        });
    }
}