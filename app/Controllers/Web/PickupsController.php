<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Controllers\Web\Concerns\InteractsWithTenant;
use App\Models\PickupRequestModel;
use App\Services\Shipment\ShipmentService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Pickup request desk: list, cancel and complete (-> shipment picked_up).
 * New pickups are booked from the shipment page.
 */
class PickupsController extends BaseController
{
    use InteractsWithTenant;

    protected string $module = 'pickups';

    private const STATUSES = ['requested', 'scheduled', 'completed', 'cancelled', 'failed'];

    public function index(): string
    {
        $orgId  = $this->orgId();
        $status = $this->cleanInput($this->request->getGet('status'), 30);
        $q      = $this->cleanInput($this->request->getGet('q'), 60);
        $date   = $this->cleanInput($this->request->getGet('date'), 10);

        if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
            $status = '';
        }
        if ($date !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = '';
        }

        $model = new PickupRequestModel();
        $model->forOrg($orgId)
            ->select('pickup_requests.*, s.tracking_number, s.current_status AS shipment_status, pl.name AS pickup_location_name,'
                . ' pa.city AS pickup_city, ra.city AS receiver_city, cp.name AS courier_name, c.name AS customer_name')
            ->join('shipments s', 's.id = pickup_requests.shipment_id', 'left')
            ->join('pickup_locations pl', 'pl.id = pickup_requests.pickup_location_id', 'left')
            ->join('addresses pa', 'pa.id = pl.address_id', 'left')
            ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
            ->join('courier_partners cp', 'cp.id = s.courier_partner_id', 'left')
            ->join('customers c', 'c.id = s.customer_id', 'left');

        if ($status !== '') {
            $model->where('pickup_requests.status', $status);
        }
        if ($date !== '') {
            $model->where('pickup_requests.requested_date', $date);
        }
        $model->search($q, ['s.tracking_number', 'pickup_requests.courier_reference', 'c.name', 'pl.name'])
            ->orderBy('pickup_requests.requested_date', 'DESC')
            ->orderBy('pickup_requests.id', 'DESC');

        $pickups = $model->paginate(15);

        $db = \Config\Database::connect();

        $counts = [];
        $rows   = $db->table('pickup_requests')
            ->select('status, COUNT(*) AS total')
            ->where('organization_id', $orgId)
            ->groupBy('status')
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        // Shipments packed and waiting for a pickup booking.
        $awaiting = $db->table('shipments s')
            ->select('s.id, s.tracking_number, s.created_at, c.name AS customer_name, ra.city AS receiver_city, cp.name AS courier_name')
            ->join('customers c', 'c.id = s.customer_id', 'left')
            ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
            ->join('courier_partners cp', 'cp.id = s.courier_partner_id', 'left')
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->where('s.current_status', 'ready_to_ship')
            ->orderBy('s.created_at', 'DESC')
            ->limit(10)
            ->get()->getResultArray();

        return $this->renderPage('pickups/index', [
            'title'    => 'Pickups',
            'pickups'  => $pickups,
            'pager'    => $model->pager,
            'status'   => $status,
            'q'        => $q,
            'date'     => $date,
            'counts'   => $counts,
            'statuses' => self::STATUSES,
            'awaiting' => $awaiting,
            'canManage' => user_can('shipments.update') || user_can('pickups.manage'),
        ]);
    }

    public function cancel(int $id): RedirectResponse
    {
        return $this->respond((new ShipmentService())->cancelPickup($this->orgId(), $id, $this->userId()), 'pickups');
    }

    public function complete(int $id): RedirectResponse
    {
        return $this->respond((new ShipmentService())->completePickup($this->orgId(), $id, $this->userId()), 'pickups');
    }
}
