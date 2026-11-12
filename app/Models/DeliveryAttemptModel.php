<?php

declare(strict_types=1);

namespace App\Models;

class DeliveryAttemptModel extends BaseModel
{
    protected $table         = 'delivery_attempts';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'shipment_id', 'delivery_assignment_id', 'attempt_number', 'outcome', 'failure_reason',
        'remarks', 'attempted_at', 'latitude', 'longitude',
    ];

    // No organization_id: tenant scope is applied through shipments.
    protected ?string $parentTable = 'shipments';
    protected ?string $parentKey   = 'shipment_id';

    public function nextAttemptNumber(int $shipmentId): int
    {
        $row = $this->builder()
            ->selectMax('attempt_number', 'max_attempt')
            ->where('shipment_id', $shipmentId)
            ->get()->getRowArray();

        return ((int) ($row['max_attempt'] ?? 0)) + 1;
    }
}
