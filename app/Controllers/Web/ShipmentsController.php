<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Controllers\Web\Concerns\InteractsWithTenant;
use App\Models\ShipmentModel;
use App\Services\Shipment\ShipmentService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Shipment list, creation and lifecycle actions (ready, pickup, courier,
 * advance, cancel). All business rules live in ShipmentService /
 * ShipmentStatusService; this controller only handles HTTP concerns.
 */
class ShipmentsController extends BaseController
{
    use InteractsWithTenant;

    protected string $module = 'shipments';

    private ShipmentService $service;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);

        $this->service = new ShipmentService();
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public function index(): string
    {
        $orgId  = $this->orgId();
        $status = $this->cleanInput($this->request->getGet('status'), 40);
        $q      = $this->cleanInput($this->request->getGet('q'), 60);

        if ($status !== '' && ! in_array($status, $this->service->statusService()->statuses(), true)) {
            $status = '';
        }

        $model = new ShipmentModel();
        $model->forOrg($orgId)
            ->withRelations()
            ->withCurrentStatus($status)
            ->search($q, [
                'shipments.tracking_number',
                'shipments.courier_tracking_number',
                'c.name',
                'ra.contact_name',
                'ra.city',
            ])
            ->orderBy('shipments.created_at', 'DESC')
            ->orderBy('shipments.id', 'DESC');

        $shipments = $model->paginate(15);

        return $this->renderPage('shipments/index', [
            'title'     => 'Shipments',
            'shipments' => $shipments,
            'pager'     => $model->pager,
            'status'    => $status,
            'q'         => $q,
            'counts'    => (new ShipmentModel())->statusCounts($orgId),
            'statuses'  => $this->service->statusService()->statuses(),
            'canCreate' => user_can('shipments.create'),
        ]);
    }

    public function create(): string
    {
        $orgId = $this->orgId();
        $db    = \Config\Database::connect();

        $customers = $db->table('customers')
            ->select('id, customer_code, name, phone')
            ->where('organization_id', $orgId)->where('status', 'active')->where('deleted_at', null)
            ->orderBy('name')->get()->getResultArray();

        $locations = $db->table('pickup_locations pl')
            ->select('pl.id, pl.name, pl.location_code, pl.is_default, a.city, a.postal_code')
            ->join('addresses a', 'a.id = pl.address_id', 'left')
            ->where('pl.organization_id', $orgId)->where('pl.status', 'active')
            ->orderBy('pl.is_default', 'DESC')->orderBy('pl.name')
            ->get()->getResultArray();

        $addresses = $db->table('addresses')
            ->select('id, customer_id, address_type, contact_name, contact_phone, address_line1, city, state, postal_code')
            ->where('organization_id', $orgId)
            ->orderBy('city')->orderBy('contact_name')
            ->limit(500)
            ->get()->getResultArray();

        $packages = $db->table('packages')
            ->select('id, package_name, package_code, length_cm, width_cm, height_cm, default_weight_kg')
            ->where('organization_id', $orgId)->where('status', 'active')
            ->orderBy('package_name')->get()->getResultArray();

        return $this->renderPage('shipments/create', [
            'title'      => 'New Shipment',
            'customers'  => $customers,
            'locations'  => $locations,
            'addresses'  => $addresses,
            'packages'   => $packages,
            'couriers'   => $this->service->activeCouriers($orgId),
            'presetCustomer' => (int) $this->request->getGet('customer_id'),
        ]);
    }

    public function store(): RedirectResponse
    {
        $result = $this->service->createShipment($this->request->getPost(), $this->orgId(), $this->userId());

        if (! $result['success']) {
            return $this->respond($result, 'shipments/create');
        }

        return $this->respond($result, 'shipments/' . $result['shipment_id']);
    }

    public function show(int $id): string
    {
        $orgId   = $this->orgId();
        $details = $this->service->details($orgId, $id);

        if ($details === null) {
            throw PageNotFoundException::forPageNotFound('Shipment not found.');
        }

        $actions = $this->service->availableActions($details['shipment'], $details['activePickup']);
        $canManage = user_can('shipments.update');

        $data = $details + [
            'title'      => 'Shipment ' . $details['shipment']['tracking_number'],
            'actions'    => $actions,
            'canManage'  => $canManage,
            'canAssign'  => user_can('delivery.assign'),
            'canDeliver' => user_can('delivery.update_status'),
            'canReturn'  => user_can('returns.manage'),
            'canNdr'     => user_can('ndr.manage'),
            'slots'      => ShipmentService::PICKUP_SLOTS,
            'couriers'   => [],
            'locations'  => [],
        ];

        if ($canManage && ($actions['assign_courier'] || $actions['book_pickup'])) {
            $db = \Config\Database::connect();

            $data['couriers']  = $this->service->activeCouriers($orgId);
            $data['locations'] = $db->table('pickup_locations pl')
                ->select('pl.id, pl.name, pl.location_code, a.city')
                ->join('addresses a', 'a.id = pl.address_id', 'left')
                ->where('pl.organization_id', $orgId)->where('pl.status', 'active')
                ->orderBy('pl.is_default', 'DESC')->orderBy('pl.name')
                ->get()->getResultArray();
        }

        return $this->renderPage('shipments/show', $data);
    }

    // ------------------------------------------------------------------
    // Actions (POST)
    // ------------------------------------------------------------------

    public function markReady(int $id): RedirectResponse
    {
        return $this->respond($this->service->markReady($this->orgId(), $id, $this->userId()), 'shipments/' . $id);
    }

    public function bookPickup(int $id): RedirectResponse
    {
        $result = $this->service->bookPickup(
            $this->orgId(),
            $id,
            $this->userId(),
            [
                'pickup_location_id' => $this->request->getPost('pickup_location_id'),
                'requested_date'     => $this->request->getPost('requested_date'),
                'time_slot'          => $this->request->getPost('time_slot'),
                'courier_partner_id' => $this->request->getPost('courier_partner_id'),
                'remarks'            => $this->request->getPost('remarks'),
            ],
            $this->userTimezone()
        );

        return $this->respond($result, 'shipments/' . $id);
    }

    public function assignCourier(int $id): RedirectResponse
    {
        $result = $this->service->assignCourier(
            $this->orgId(),
            $id,
            $this->userId(),
            (int) $this->request->getPost('courier_partner_id')
        );

        return $this->respond($result, 'shipments/' . $id);
    }

    public function advanceStatus(int $id): RedirectResponse
    {
        $result = $this->service->advance(
            $this->orgId(),
            $id,
            $this->userId(),
            (string) $this->request->getPost('status'),
            $this->request->getPost('location'),
            $this->request->getPost('remarks')
        );

        return $this->respond($result, 'shipments/' . $id);
    }

    public function cancel(int $id): RedirectResponse
    {
        $result = $this->service->cancel(
            $this->orgId(),
            $id,
            $this->userId(),
            $this->request->getPost('reason')
        );

        return $this->respond($result, 'shipments/' . $id);
    }
}
