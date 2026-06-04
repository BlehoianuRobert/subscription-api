<?php

declare(strict_types=1);

namespace Tests;

class SubscriptionTest extends ApiTestCase
{
    // 1. Creating a subscription starts in trialing
    public function testCreateSubscriptionStartsInTrialing(): void
    {
        $res = $this->post('/subscriptions', ['user_id' => 'user_1']);

        $this->assertSame(201, $res['status']);
        $this->assertSame('trialing', $res['body']['status']);
        $this->assertSame('user_1', $res['body']['user_id']);
        $this->assertNotEmpty($res['body']['trial_ends_at']);
    }

    // 2. trial_ends_at is approximately 7 days from now
    public function testTrialEndsAtIsSevenDaysFromNow(): void
    {
        $res         = $this->post('/subscriptions', ['user_id' => 'user_1']);
        $trialEndsAt = strtotime($res['body']['trial_ends_at']);
        $expected    = time() + (7 * 24 * 60 * 60);

        $this->assertEqualsWithDelta($expected, $trialEndsAt, 5);
    }

    // 3. GET returns the subscription
    public function testGetSubscription(): void
    {
        $sub = $this->createSubscription();
        $res = $this->get('/subscriptions/' . $sub['id']);

        $this->assertSame(200, $res['status']);
        $this->assertSame($sub['id'], $res['body']['id']);
    }

    // 4. GET returns 404 for unknown ID
    public function testGetSubscriptionNotFound(): void
    {
        $res = $this->get('/subscriptions/sub_does_not_exist');
        $this->assertSame(404, $res['status']);
        $this->assertSame('subscription_not_found', $res['body']['error']);
    }

    // 5. Cancelling moves to cancelled
    public function testCancelSubscription(): void
    {
        $sub = $this->createSubscription();
        $res = $this->post('/subscriptions/' . $sub['id'] . '/cancel');

        $this->assertSame(200, $res['status']);
        $this->assertSame('cancelled', $res['body']['status']);
        $this->assertNotEmpty($res['body']['cancelled_at']);
    }

    // 6. Cancelling is idempotent
    public function testCancelIsIdempotent(): void
    {
        $sub = $this->createSubscription();
        $this->post('/subscriptions/' . $sub['id'] . '/cancel');
        $res = $this->post('/subscriptions/' . $sub['id'] . '/cancel');

        $this->assertSame(200, $res['status']);
        $this->assertSame('cancelled', $res['body']['status']);

        $history      = $this->get('/subscriptions/' . $sub['id'] . '/history');
        $cancelEvents = array_filter($history['body'], fn($e) => $e['event_type'] === 'subscription.cancelled');
        $this->assertCount(1, $cancelEvents);
    }

    // 7. payment.succeeded moves trialing to active
    public function testPaymentSucceededMovesTrialingToActive(): void
    {
        $sub = $this->createSubscription();
        $this->sendWebhook($sub['id'], 'payment.succeeded', 'evt_001');

        $updated = $this->get('/subscriptions/' . $sub['id']);
        $this->assertSame('active', $updated['body']['status']);
    }

    // 8. payment.failed moves active to grace
    public function testPaymentFailedMovesActiveToGrace(): void
    {
        $sub = $this->createSubscription();
        $this->sendWebhook($sub['id'], 'payment.succeeded', 'evt_001');
        $this->sendWebhook($sub['id'], 'payment.failed', 'evt_002');

        $updated = $this->get('/subscriptions/' . $sub['id']);
        $this->assertSame('grace', $updated['body']['status']);
        $this->assertNotEmpty($updated['body']['grace_started_at']);
        $this->assertNotEmpty($updated['body']['grace_ends_at']);
    }

    // 9. payment.succeeded moves grace back to active
    public function testPaymentSucceededMovesGraceToActive(): void
    {
        $sub = $this->createSubscription();
        $this->sendWebhook($sub['id'], 'payment.succeeded', 'evt_001');
        $this->sendWebhook($sub['id'], 'payment.failed', 'evt_002');
        $this->sendWebhook($sub['id'], 'payment.succeeded', 'evt_003');

        $updated = $this->get('/subscriptions/' . $sub['id']);
        $this->assertSame('active', $updated['body']['status']);
        $this->assertNull($updated['body']['grace_started_at']);
        $this->assertNull($updated['body']['grace_ends_at']);
    }

    // 10. Duplicate webhook is ignored
    public function testDuplicateWebhookIsIgnored(): void
    {
        $sub = $this->createSubscription();
        $this->sendWebhook($sub['id'], 'payment.succeeded', 'evt_001');
        $res = $this->sendWebhook($sub['id'], 'payment.succeeded', 'evt_001');

        $this->assertSame(200, $res['status']);
        $this->assertSame('duplicate_ignored', $res['body']['status']);

        $updated = $this->get('/subscriptions/' . $sub['id']);
        $this->assertSame('active', $updated['body']['status']);
    }

    // 11. payment.succeeded after cancel does not reactivate
    public function testPaymentSucceededAfterCancelDoesNotReactivate(): void
    {
        $sub = $this->createSubscription();
        $this->post('/subscriptions/' . $sub['id'] . '/cancel');
        $this->sendWebhook($sub['id'], 'payment.succeeded', 'evt_001');

        $updated = $this->get('/subscriptions/' . $sub['id']);
        $this->assertSame('cancelled', $updated['body']['status']);
    }

    // 12. Cancel then delayed payment — final state is cancelled, audit records both
    public function testCancelThenDelayedPaymentRace(): void
    {
        $sub = $this->createSubscription();
        $this->post('/subscriptions/' . $sub['id'] . '/cancel');
        $this->sendWebhook($sub['id'], 'payment.succeeded', 'evt_001');

        $updated = $this->get('/subscriptions/' . $sub['id']);
        $this->assertSame('cancelled', $updated['body']['status']);

        $history    = $this->get('/subscriptions/' . $sub['id'] . '/history');
        $eventTypes = array_column($history['body'], 'event_type');

        $this->assertContains('subscription.cancelled', $eventTypes);
        $this->assertContains('payment.succeeded.ignored_after_cancel', $eventTypes);
    }

    // 13. Unknown subscription in webhook returns 404
    public function testWebhookUnknownSubscription(): void
    {
        $res = $this->sendWebhook('sub_does_not_exist', 'payment.succeeded', 'evt_001');
        $this->assertSame(404, $res['status']);
        $this->assertSame('subscription_not_found', $res['body']['error']);
    }

    // 14. Invalid webhook type returns 400
    public function testWebhookInvalidTypeReturns400(): void
    {
        $res = $this->post('/webhooks/billing', [
            'event_id'        => 'evt_001',
            'subscription_id' => 'sub_123',
            'type'            => 'payment.unknown',
            'timestamp'       => now(),
            'amount'          => 9.99,
        ]);

        $this->assertSame(400, $res['status']);
        $this->assertSame('invalid_event_type', $res['body']['error']);
    }

    // 15. Missing user_id returns 400
    public function testCreateSubscriptionMissingUserId(): void
    {
        $res = $this->post('/subscriptions', []);
        $this->assertSame(400, $res['status']);
        $this->assertSame('missing_field', $res['body']['error']);
        $this->assertSame('user_id', $res['body']['field']);
    }

    // 16. Audit history is in chronological order
    public function testAuditHistoryIsChronological(): void
    {
        $sub = $this->createSubscription();
        $this->sendWebhook($sub['id'], 'payment.succeeded', 'evt_001');
        $this->post('/subscriptions/' . $sub['id'] . '/cancel');

        $history = $this->get('/subscriptions/' . $sub['id'] . '/history');
        $this->assertSame(200, $history['status']);

        $types = array_column($history['body'], 'event_type');
        $this->assertSame('subscription.created', $types[0]);
    }
}