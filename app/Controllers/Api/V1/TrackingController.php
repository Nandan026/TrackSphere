<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Tracking\PublicTrackingService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PUBLIC tracking (no authentication): GET /api/v1/tracking/{tracking_number}
 *
 * Returns only customer-safe data (see PublicTrackingService): status, route cities
 * and customer-visible events. Rate limited per IP.
 */
class TrackingController extends BaseApiController
{
    private const MAX_LOOKUPS_PER_MINUTE = 30;

    public function show(string $trackingNumber = ''): ResponseInterface
    {
        if (! service('throttler')->check('api_track_' . md5($this->request->getIPAddress()), self::MAX_LOOKUPS_PER_MINUTE, MINUTE)) {
            return api_error('Too many requests. Please try again in a minute.', [], 429)->setHeader('Retry-After', '60');
        }

        $service = new PublicTrackingService();
        $number  = $service->normalize(rawurldecode($trackingNumber));

        if ($number === null) {
            return api_error('That does not look like a valid tracking number.', [], 422);
        }

        $result = $service->find($number);
        if ($result === null) {
            return api_error('No shipment found for this tracking number.', [], 404);
        }

        $s = $result['shipment'];

        return api_success([
            'tracking_number'         => $s['tracking_number'],
            'courier_tracking_number' => $s['courier_tracking_number'],
            'status'                  => $s['current_status'],
            'courier'                 => $s['courier_name'],
            'payment_mode'            => $s['payment_mode'],
            'origin'                  => ['city' => $s['origin_city'], 'state' => $s['origin_state']],
            'destination'             => ['city' => $s['destination_city'], 'state' => $s['destination_state']],
            'expected_delivery_at'    => $this->iso($s['expected_delivery_at']),
            'shipped_at'              => $this->iso($s['shipped_at']),
            'delivered_at'            => $this->iso($s['delivered_at']),
            'events'                  => array_map(fn (array $e): array => [
                'status'      => $e['status'],
                'title'       => $e['title'],
                'description' => $e['description'],
                'location'    => $e['location'],
                'occurred_at' => $this->iso($e['event_time']),
            ], $result['events']),
        ]);
    }

    private function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $t = strtotime($value . ' UTC');

        return $t === false ? $value : gmdate('Y-m-d\TH:i:s\Z', $t);
    }
}
