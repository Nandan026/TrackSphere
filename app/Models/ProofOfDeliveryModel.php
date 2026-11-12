<?php

declare(strict_types=1);

namespace App\Models;

class ProofOfDeliveryModel extends BaseModel
{
    protected $table         = 'proof_of_deliveries';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'shipment_id', 'delivery_attempt_id', 'received_by', 'receiver_relation',
        'verification_method', 'verification_reference', 'photo_path', 'signature_path',
        'notes', 'delivered_at',
    ];

    // No organization_id: tenant scope is applied through shipments.
    protected ?string $parentTable = 'shipments';
    protected ?string $parentKey   = 'shipment_id';

    /**
     * @return array<string, mixed>|object|null
     */
    public function findByShipment(int $shipmentId)
    {
        return $this->where('proof_of_deliveries.shipment_id', $shipmentId)->first();
    }
}
