<?php

declare(strict_types=1);

namespace App\Models;

class NdrCaseModel extends BaseModel
{
    protected $table         = 'ndr_cases';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'organization_id', 'shipment_id', 'delivery_attempt_id', 'case_number', 'reason_code',
        'customer_response', 'resolution', 'next_attempt_at', 'status', 'remarks',
        'resolved_by', 'resolved_at',
    ];

    /**
     * @return $this
     */
    public function withShipment()
    {
        $this->select('ndr_cases.*, s.tracking_number, s.current_status AS shipment_status')
            ->join('shipments s', 's.id = ndr_cases.shipment_id', 'left');

        return $this;
    }

    public function nextCaseNumber(int $organizationId): string
    {
        do {
            $number = 'NDR-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $exists = $this->builder()
                ->where('organization_id', $organizationId)
                ->where('case_number', $number)
                ->countAllResults() > 0;
        } while ($exists);

        return $number;
    }
}
