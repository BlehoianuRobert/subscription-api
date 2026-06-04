<?php

declare(strict_types=1);

namespace App;

use Ramsey\Uuid\Uuid;

class Audit
{
    public static function record(
        string $subscriptionId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        string $source,
        string $message,
        ?string $externalEventId = null,
        ?array $metadata = null
    ): void {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            INSERT INTO audit_events
                (id, subscription_id, event_type, from_status, to_status,
                 source, external_event_id, message, metadata, created_at)
            VALUES
                (:id, :subscription_id, :event_type, :from_status, :to_status,
                 :source, :external_event_id, :message, :metadata, :created_at)
        ");

        $stmt->execute([
            ':id'                => Uuid::uuid4()->toString(),
            ':subscription_id'   => $subscriptionId,
            ':event_type'        => $eventType,
            ':from_status'       => $fromStatus,
            ':to_status'         => $toStatus,
            ':source'            => $source,
            ':external_event_id' => $externalEventId,
            ':message'           => $message,
            ':metadata'          => $metadata ? json_encode($metadata) : null,
            ':created_at'        => now(),
        ]);
    }
}