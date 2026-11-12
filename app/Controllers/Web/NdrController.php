<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Controllers\Web\Concerns\InteractsWithTenant;
use App\Models\NdrCaseModel;
use App\Services\Delivery\DeliveryService;
use App\Services\Ndr\NdrService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Non-delivery report cases: list open/resolved cases and resolve them with
 * a re-attempt or return-to-origin.
 */
class NdrController extends BaseController
{
    use InteractsWithTenant;

    protected string $module = 'ndr';

    public function index(): string
    {
        $orgId  = $this->orgId();
        $status = $this->cleanInput($this->request->getGet('status') ?? 'open', 20);
        $q      = $this->cleanInput($this->request->getGet('q'), 60);

        if (! in_array($status, ['open', 'resolved', 'all'], true)) {
            $status = 'open';
        }

        $model = new NdrCaseModel();
        $model->forOrg($orgId)
            ->select('ndr_cases.*, s.tracking_number, s.current_status AS shipment_status, s.payment_mode, s.cod_amount,'
                . ' ra.contact_name AS receiver_name, ra.contact_phone AS receiver_phone, ra.city AS receiver_city,'
                . ' c.name AS customer_name, ag.full_name AS agent_name,'
                . ' (SELECT COUNT(*) FROM delivery_attempts t WHERE t.shipment_id = s.id) AS attempts,'
                . ' u.first_name AS resolver_first, u.last_name AS resolver_last', false)
            ->join('shipments s', 's.id = ndr_cases.shipment_id', 'left')
            ->join('customers c', 'c.id = s.customer_id', 'left')
            ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
            ->join('delivery_attempts att', 'att.id = ndr_cases.delivery_attempt_id', 'left')
            ->join('delivery_assignments da', 'da.id = att.delivery_assignment_id', 'left')
            ->join('delivery_agents ag', 'ag.id = da.delivery_agent_id', 'left')
            ->join('users u', 'u.id = ndr_cases.resolved_by', 'left');

        if ($status !== 'all') {
            $model->where('ndr_cases.status', $status);
        }

        $model->search($q, ['ndr_cases.case_number', 's.tracking_number', 'ra.contact_name', 'ra.city'])
            ->orderBy('ndr_cases.created_at', 'DESC')
            ->orderBy('ndr_cases.id', 'DESC');

        $cases = $model->paginate(12);

        $counts = ['open' => 0, 'resolved' => 0];
        $rows   = \Config\Database::connect()->table('ndr_cases')
            ->select('status, COUNT(*) AS total')
            ->where('organization_id', $orgId)
            ->groupBy('status')
            ->get()->getResultArray();
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $this->renderPage('ndr/index', [
            'title'     => 'NDR Cases',
            'cases'     => $cases,
            'pager'     => $model->pager,
            'status'    => $status,
            'q'         => $q,
            'counts'    => $counts,
            'agents'    => (new DeliveryService())->activeAgents($orgId),
            'reasons'   => DeliveryService::failureReasons(),
            'responses' => NdrService::customerResponses(),
            'timezone'  => $this->userTimezone(),
        ]);
    }

    public function resolve(int $id): RedirectResponse
    {
        $result = (new NdrService())->resolve(
            $this->orgId(),
            $id,
            $this->userId(),
            [
                'action'            => $this->request->getPost('action'),
                'customer_response' => $this->request->getPost('customer_response'),
                'next_attempt_at'   => $this->request->getPost('next_attempt_at'),
                'delivery_agent_id' => $this->request->getPost('delivery_agent_id'),
                'remarks'           => $this->request->getPost('remarks'),
            ],
            $this->userTimezone()
        );

        return $this->respond($result, 'ndr');
    }
}
