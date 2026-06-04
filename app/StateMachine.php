<?php

declare(strict_types=1);

namespace App;

class StateMachine
{
    public static function applyBillingEvent(array $subscription, array $event): array
    {
        if ($event['event_type'] === 'payment.succeeded') {
            return self::applyPaymentSucceeded($subscription);
        }

        if ($event['event_type'] === 'payment.failed') {
            return self::applyPaymentFailed($subscription);
        }

        return [
            'changed'    => false,
            'audit_type' => 'webhook.unknown_type',
            'message'    => 'Unknown event type received.',
            'updates'    => [],
        ];
    }

    private static function applyPaymentSucceeded(array $sub): array
    {
        $now = now();

        if ($sub['status'] === 'cancelled') {
            return [
                'changed'    => false,
                'audit_type' => 'payment.succeeded.ignored_after_cancel',
                'message'    => 'Webhook received after cancellation; stored but ignored.',
                'updates'    => [],
            ];
        }

        if ($sub['status'] === 'trialing') {
            return [
                'changed'    => true,
                'old_status' => 'trialing',
                'new_status' => 'active',
                'audit_type' => 'subscription.activated',
                'message'    => 'Trial converted to active after successful payment.',
                'updates'    => [
                    'status'     => 'active',
                    'updated_at' => $now,
                ],
            ];
        }

        if ($sub['status'] === 'active') {
            return [
                'changed'    => false,
                'audit_type' => 'payment.succeeded.renewal',
                'message'    => 'Renewal payment succeeded; subscription stays active.',
                'updates'    => [],
            ];
        }

        if ($sub['status'] === 'grace') {
            return [
                'changed'    => true,
                'old_status' => 'grace',
                'new_status' => 'active',
                'audit_type' => 'subscription.recovered',
                'message'    => 'Payment recovered from grace; subscription reactivated.',
                'updates'    => [
                    'status'           => 'active',
                    'grace_started_at' => null,
                    'grace_ends_at'    => null,
                    'updated_at'       => $now,
                ],
            ];
        }

        return [
            'changed'    => false,
            'audit_type' => 'payment.succeeded.noop',
            'message'    => 'No transition applied.',
            'updates'    => [],
        ];
    }

    private static function applyPaymentFailed(array $sub): array
    {
        $now = now();

        if ($sub['status'] === 'cancelled') {
            return [
                'changed'    => false,
                'audit_type' => 'payment.failed.ignored_after_cancel',
                'message'    => 'Failed payment webhook after cancellation; ignored.',
                'updates'    => [],
            ];
        }

        if ($sub['status'] === 'trialing') {
            if ($now < $sub['trial_ends_at']) {
                return [
                    'changed'    => false,
                    'audit_type' => 'payment.failed.during_trial',
                    'message'    => 'Payment failed but trial still active; no state change.',
                    'updates'    => [],
                ];
            }

            $graceEndsAt = date('Y-m-d\TH:i:s\Z', strtotime($now) + (3 * 24 * 60 * 60));
            return [
                'changed'    => true,
                'old_status' => 'trialing',
                'new_status' => 'grace',
                'audit_type' => 'subscription.entered_grace',
                'message'    => 'Trial expired and payment failed; entered grace period.',
                'updates'    => [
                    'status'           => 'grace',
                    'grace_started_at' => $now,
                    'grace_ends_at'    => $graceEndsAt,
                    'updated_at'       => $now,
                ],
            ];
        }

        if ($sub['status'] === 'active') {
            $graceEndsAt = date('Y-m-d\TH:i:s\Z', strtotime($now) + (3 * 24 * 60 * 60));
            return [
                'changed'    => true,
                'old_status' => 'active',
                'new_status' => 'grace',
                'audit_type' => 'subscription.entered_grace',
                'message'    => 'Payment failed; subscription entered grace period.',
                'updates'    => [
                    'status'           => 'grace',
                    'grace_started_at' => $now,
                    'grace_ends_at'    => $graceEndsAt,
                    'updated_at'       => $now,
                ],
            ];
        }

        if ($sub['status'] === 'grace') {
            return [
                'changed'    => false,
                'audit_type' => 'payment.failed.in_grace',
                'message'    => 'Repeated payment failure during grace; grace period unchanged.',
                'updates'    => [],
            ];
        }

        return [
            'changed'    => false,
            'audit_type' => 'payment.failed.noop',
            'message'    => 'No transition applied.',
            'updates'    => [],
        ];
    }
}