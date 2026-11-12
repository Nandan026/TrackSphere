<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use CodeIgniter\Database\BaseConnection;

/**
 * Looks up a shipment for the PUBLIC tracking page. Deliberately returns only
 * customer-safe data: no customer, address lines, phone numbers, charges or
 * internal notes - just status, route cities and customer-visible events.
 */
class PublicTrackingService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Normalizes user input; returns null if it cannot be a tracking number.
     */
    public function normalize(string $input): ?string
    {
        $number = strtoupper(trim($input));

        return preg_match('/^[A-Z0-9-]{6,40}$/', $number) === 1 ? $number : null;
    }

    /**
     * @return array{shipment: array<string, mixed>, events: list<array<string, mixed>>}|null
     */
    public function find(string $trackingNumber): ?array
    {
        $shipment = $this->db->table('shipments s')
            ->select('s.id, s.tracking_number, s.courier_tracking_number, s.current_status, s.payment_mode,'
                . ' s.expected_delivery_at, s.shipped_at, s.delivered_at, s.created_at,'
                . ' cp.name AS courier_name,'
                . ' sa.city AS origin_city, sa.state AS origin_state,'
                . ' ra.city AS destination_city, ra.state AS destination_state')
            ->join('courier_partners cp', 'cp.id = s.courier_partner_id', 'left')
            ->join('addresses sa', 'sa.id = s.sender_address_id', 'left')
            ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
            ->where('s.tracking_number', $trackingNumber)
            ->where('s.deleted_at', null)
            ->get()->getRowArray();

        if ($shipment === null) {
            return null;
        }

        $events = $this->db->table('shipment_events')
            ->select('status_code AS status, title, description, event_location AS location, occurred_at AS event_time')
            ->where('shipment_id', (int) $shipment['id'])
            ->where('visible_to_customer', 1)
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        unset($shipment['id']);
        $shipment['expected_delivery_date'] = $shipment['expected_delivery_at'];

        return ['shipment' => $shipment, 'events' => $events];
    }
}
