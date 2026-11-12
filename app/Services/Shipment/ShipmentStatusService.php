<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use CodeIgniter\Database\BaseConnection;

/**
 * Single entry point for changing shipments.current_status.
 * Validates the transition, updates the shipment and appends a
 * shipment_events row atomically.
 */
class ShipmentStatusService
{
    public const STATUS_DRAFT                   = 'draft';
    public const STATUS_READY_TO_SHIP           = 'ready_to_ship';
    public const STATUS_PICKUP_SCHEDULED        = 'pickup_scheduled';
    public const STATUS_PICKUP_FAILED           = 'pickup_failed';
    public const STATUS_PICKED_UP               = 'picked_up';
    public const STATUS_IN_TRANSIT              = 'in_transit';
    public const STATUS_REACHED_DESTINATION_HUB = 'reached_destination_hub';
    public const STATUS_OUT_FOR_DELIVERY        = 'out_for_delivery';
    public const STATUS_DELIVERED               = 'delivered';
    public const STATUS_DELIVERY_FAILED         = 'delivery_failed';
    public const STATUS_RTO_INITIATED           = 'rto_initiated';
    public const STATUS_RTO_IN_TRANSIT          = 'rto_in_transit';
    public const STATUS_RTO_DELIVERED           = 'rto_delivered';
    public const STATUS_CANCELLED               = 'cancelled';

    /**
     * current status => allowed next statuses
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'draft'                   => ['ready_to_ship', 'cancelled'],
        'ready_to_ship'           => ['pickup_scheduled', 'cancelled'],
        'pickup_scheduled'        => ['picked_up', 'pickup_failed'],
        'pickup_failed'           => [],
        'picked_up'               => ['in_transit'],
        'in_transit'              => ['reached_destination_hub'],
        'reached_destination_hub' => ['out_for_delivery'],
        'out_for_delivery'        => ['delivered', 'delivery_failed'],
        'delivery_failed'         => ['out_for_delivery', 'rto_initiated'],
        'rto_initiated'           => ['rto_in_transit'],
        'rto_in_transit'          => ['rto_delivered'],
        'rto_delivered'           => [],
        'delivered'               => [],
        'cancelled'               => [],
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * @return array<string, list<string>>
     */
    public function allowedTransitions(): array
    {
        return self::TRANSITIONS;
    }

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /**
     * @return list<string>
     */
    public function nextStatuses(string $currentStatus): array
    {
        return self::TRANSITIONS[$currentStatus] ?? [];
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Moves a shipment to a new status.
     *
     * @param int|null $organizationId Tenant scope; defaults to the logged-in user's organization when available.
     *
     * @return array{success: bool, message: string, from?: string, to?: string, event_id?: int}
     */
    public function transition(
        int $shipmentId,
        string $newStatus,
        string $title = '',
        ?string $description = null,
        ?int $userId = null,
        ?string $location = null,
        ?int $organizationId = null,
        string $eventSource = 'system',
        bool $visibleToCustomer = true
    ): array {
        if (! array_key_exists($newStatus, self::TRANSITIONS)) {
            return ['success' => false, 'message' => "Unknown status '{$newStatus}'."];
        }

        $organizationId ??= function_exists('current_org_id') ? current_org_id() : null;
        $userId        ??= function_exists('current_user') ? (current_user()['id'] ?? null) : null;

        $this->db->transStart();

        $builder = $this->db->table('shipments')
            ->select('id, current_status, organization_id')
            ->where('id', $shipmentId)
            ->where('deleted_at', null);
        if ($organizationId !== null) {
            $builder->where('organization_id', $organizationId);
        }

        // Row lock prevents two concurrent transitions from the same state.
        $sql      = $builder->getCompiledSelect() . ' FOR UPDATE';
        $shipment = $this->db->query($sql)->getRowArray();

        if ($shipment === null) {
            $this->db->transComplete();

            return ['success' => false, 'message' => 'Shipment not found.'];
        }

        $from = (string) $shipment['current_status'];

        if (! $this->canTransition($from, $newStatus)) {
            $this->db->transComplete();
            $allowed = $this->nextStatuses($from);

            return [
                'success' => false,
                'message' => "Cannot change status from '{$from}' to '{$newStatus}'."
                    . ($allowed === [] ? ' This is a final status.' : ' Allowed: ' . implode(', ', $allowed) . '.'),
                'from' => $from,
                'to'   => $newStatus,
            ];
        }

        $now    = gmdate('Y-m-d H:i:s');
        $update = ['current_status' => $newStatus, 'updated_at' => $now];

        if ($newStatus === self::STATUS_PICKED_UP) {
            $update['shipped_at'] = $now;
        } elseif ($newStatus === self::STATUS_DELIVERED) {
            $update['delivered_at'] = $now;
        } elseif ($newStatus === self::STATUS_CANCELLED) {
            $update['cancelled_at'] = $now;
        }

        $this->db->table('shipments')->where('id', $shipmentId)->update($update);

        $this->db->table('shipment_events')->insert([
            'shipment_id'         => $shipmentId,
            'status_code'         => $newStatus,
            'title'               => $title !== '' ? mb_substr($title, 0, 150) : $this->defaultTitle($newStatus),
            'description'         => $description !== null ? mb_substr($description, 0, 500) : null,
            'event_location'      => $location !== null ? mb_substr($location, 0, 180) : null,
            'event_source'        => $eventSource,
            'visible_to_customer' => $visibleToCustomer ? 1 : 0,
            'occurred_at'         => $now,
            'created_by'          => $userId,
            'created_at'          => $now,
        ]);
        $eventId = (int) $this->db->insertID();

        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            return ['success' => false, 'message' => 'Could not update shipment status. Please try again.'];
        }

        return [
            'success'  => true,
            'message'  => 'Shipment status updated.',
            'from'     => $from,
            'to'       => $newStatus,
            'event_id' => $eventId,
        ];
    }

    public function defaultTitle(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}
