<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Controllers\Web\Concerns\InteractsWithTenant;
use App\Models\ReturnRequestModel;
use App\Services\Return\ReturnService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Return requests: list, create from a delivered shipment, ops decisions
 * (approve with optional reverse-shipment stub / reject / mark received).
 * Decisions additionally require shipments.update so merchants can only raise requests.
 */
class ReturnsController extends BaseController
{
    use InteractsWithTenant;

    protected string $module = 'returns';

    private const STATUSES = ['requested', 'approved', 'rejected', 'received'];

    public function index(): string
    {
        $orgId  = $this->orgId();
        $status = $this->cleanInput($this->request->getGet('status'), 20);
        $q      = $this->cleanInput($this->request->getGet('q'), 60);

        if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
            $status = '';
        }

        $model = new ReturnRequestModel();
        $model->forOrg($orgId)
            ->select('return_requests.*, s.tracking_number, s.current_status AS shipment_status, s.declared_value,'
                . ' c.name AS customer_name, ra.contact_name AS receiver_name, ra.city AS receiver_city,'
                . ' rs.tracking_number AS reverse_tracking, rs.current_status AS reverse_status,'
                . ' ur.first_name AS requester_first, ur.last_name AS requester_last')
            ->join('shipments s', 's.id = return_requests.shipment_id', 'left')
            ->join('customers c', 'c.id = s.customer_id', 'left')
            ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
            ->join('shipments rs', 'rs.id = return_requests.reverse_shipment_id', 'left')
            ->join('users ur', 'ur.id = return_requests.requested_by', 'left');

        if ($status !== '') {
            $model->where('return_requests.status', $status);
        }

        $model->search($q, ['return_requests.return_number', 's.tracking_number', 'c.name', 'ra.contact_name'])
            ->orderBy('return_requests.requested_at', 'DESC')
            ->orderBy('return_requests.id', 'DESC');

        $returns = $model->paginate(12);

        $counts = [];
        $rows   = \Config\Database::connect()->table('return_requests')
            ->select('status, COUNT(*) AS total')
            ->where('organization_id', $orgId)
            ->groupBy('status')
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $this->renderPage('returns/index', [
            'title'     => 'Returns',
            'returns'   => $returns,
            'pager'     => $model->pager,
            'status'    => $status,
            'q'         => $q,
            'counts'    => $counts,
            'statuses'  => self::STATUSES,
            'reasons'   => ReturnService::reasons(),
            'canDecide' => user_can('shipments.update'),
        ]);
    }

    public function create(): string
    {
        $orgId = $this->orgId();

        return $this->renderPage('returns/create', [
            'title'     => 'New Return Request',
            'shipments' => (new ReturnService())->returnableShipments($orgId),
            'reasons'   => ReturnService::reasons(),
            'preset'    => (int) $this->request->getGet('shipment_id'),
        ]);
    }

    public function store(): RedirectResponse
    {
        $result = (new ReturnService())->create(
            $this->orgId(),
            (int) $this->request->getPost('shipment_id'),
            $this->userId(),
            (string) $this->request->getPost('reason_code'),
            $this->request->getPost('reason_details')
        );

        return $this->respond($result, 'returns', 'returns/create');
    }

    public function approve(int $id): RedirectResponse
    {
        $result = (new ReturnService())->approve(
            $this->orgId(),
            $id,
            $this->userId(),
            (bool) $this->request->getPost('create_reverse')
        );

        return $this->respond($result, 'returns');
    }

    public function reject(int $id): RedirectResponse
    {
        return $this->respond(
            (new ReturnService())->reject($this->orgId(), $id, $this->userId(), $this->request->getPost('note')),
            'returns'
        );
    }

    public function received(int $id): RedirectResponse
    {
        return $this->respond((new ReturnService())->markReceived($this->orgId(), $id, $this->userId()), 'returns');
    }
}
