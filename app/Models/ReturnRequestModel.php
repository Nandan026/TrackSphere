<?php

declare(strict_types=1);

namespace App\Models;

class ReturnRequestModel extends BaseModel
{
    protected $table         = 'return_requests';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false; // table uses requested_at / approved_at / received_at
    protected $allowedFields = [
        'organization_id', 'shipment_id', 'return_number', 'reason_code', 'reason_details',
        'status', 'reverse_shipment_id', 'requested_by', 'approved_by', 'requested_at',
        'approved_at', 'received_at',
    ];

    /**
     * @return $this
     */
    public function withShipment()
    {
        $this->select('return_requests.*, s.tracking_number, s.current_status AS shipment_status')
            ->join('shipments s', 's.id = return_requests.shipment_id', 'left');

        return $this;
    }

    public function nextReturnNumber(int $organizationId): string
    {
        do {
            $number = 'RET-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $exists = $this->builder()
                ->where('organization_id', $organizationId)
                ->where('return_number', $number)
                ->countAllResults() > 0;
        } while ($exists);

        return $number;
    }
}
