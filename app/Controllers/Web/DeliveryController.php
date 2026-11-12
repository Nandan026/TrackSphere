<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Controllers\Web\Concerns\InteractsWithTenant;
use App\Services\Delivery\DeliveryService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Last-mile delivery: dispatch desk, delivery agent task list, delivery
 * attempts and proof of delivery.
 *
 * - Dispatchers / managers (delivery.assign) see the whole desk and can act on
 *   any shipment.
 * - Delivery agents (delivery.update_status only) see just their own tasks and
 *   can only act on shipments assigned to their linked user account.
 */
class DeliveryController extends BaseController
{
    use InteractsWithTenant;

    protected string $module = 'delivery';

    private DeliveryService $service;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);

        $this->service = new DeliveryService();
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    /**
     * Dispatch desk: shipments at the destination hub / out for delivery.
     */
    public function index(): string|RedirectResponse
    {
        if (! user_can('delivery.assign')) {
            return redirect()->to(site_url('delivery/tasks'));
        }

        $orgId = $this->orgId();
        $db    = \Config\Database::connect();
        $view  = $this->cleanInput($this->request->getGet('view'), 20);
        $q     = $this->cleanInput($this->request->getGet('q'), 60);

        $builder = $db->table('shipments s')
            ->select('s.id, s.tracking_number, s.current_status, s.payment_mode, s.cod_amount, s.chargeable_weight_kg,'
                . ' s.expected_delivery_at, s.updated_at, c.name AS customer_name, cp.name AS courier_name,'
                . ' ra.contact_name AS receiver_name, ra.contact_phone AS receiver_phone, ra.address_line1 AS receiver_address,'
                . ' ra.city AS receiver_city, ra.postal_code AS receiver_postal_code,'
                . ' a.id AS assignment_id, a.status AS assignment_status, a.assigned_at, ag.full_name AS agent_name, ag.id AS agent_id,'
                . ' (SELECT COUNT(*) FROM delivery_attempts t WHERE t.shipment_id = s.id) AS attempts', false)
            ->join('customers c', 'c.id = s.customer_id', 'left')
            ->join('courier_partners cp', 'cp.id = s.courier_partner_id', 'left')
            ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
            ->join('delivery_assignments a', "a.id = (SELECT MAX(a2.id) FROM delivery_assignments a2 WHERE a2.shipment_id = s.id AND a2.status IN ('assigned','accepted'))", 'left', false)
            ->join('delivery_agents ag', 'ag.id = a.delivery_agent_id', 'left')
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->whereIn('s.current_status', ['reached_destination_hub', 'out_for_delivery']);

        if ($view === 'hub') {
            $builder->where('s.current_status', 'reached_destination_hub');
        } elseif ($view === 'out') {
            $builder->where('s.current_status', 'out_for_delivery');
        } elseif ($view === 'unassigned') {
            $builder->where('a.id', null);
        }
        if ($q !== '') {
            $builder->groupStart()
                ->like('s.tracking_number', $q)
                ->orLike('ra.contact_name', $q)
                ->orLike('ra.city', $q)
                ->orLike('ag.full_name', $q)
                ->groupEnd();
        }

        $rows = $builder->orderBy('s.updated_at', 'ASC')->limit(100)->get()->getResultArray();

        // KPI strip (unfiltered)
        $kpi = $db->table('shipments s')
            ->select("SUM(s.current_status = 'reached_destination_hub') AS at_hub, SUM(s.current_status = 'out_for_delivery') AS out_for_delivery,"
                . " SUM(NOT EXISTS (SELECT 1 FROM delivery_assignments x WHERE x.shipment_id = s.id AND x.status IN ('assigned','accepted'))) AS unassigned,"
                . " SUM(s.current_status = 'delivery_failed') AS failed", false)
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->whereIn('s.current_status', ['reached_destination_hub', 'out_for_delivery', 'delivery_failed'])
            ->get()->getRowArray();

        $recentAttempts = $db->table('delivery_attempts t')
            ->select('t.*, s.tracking_number, ag.full_name AS agent_name')
            ->join('shipments s', 's.id = t.shipment_id')
            ->join('delivery_assignments da', 'da.id = t.delivery_assignment_id', 'left')
            ->join('delivery_agents ag', 'ag.id = da.delivery_agent_id', 'left')
            ->where('s.organization_id', $orgId)
            ->orderBy('t.attempted_at', 'DESC')
            ->limit(6)
            ->get()->getResultArray();

        return $this->renderPage('delivery/desk', [
            'title'          => 'Delivery Desk',
            'rows'           => $rows,
            'agents'         => $this->service->activeAgents($orgId),
            'kpi'            => [
                'at_hub'           => (int) ($kpi['at_hub'] ?? 0),
                'out_for_delivery' => (int) ($kpi['out_for_delivery'] ?? 0),
                'unassigned'       => (int) ($kpi['unassigned'] ?? 0),
                'failed'           => (int) ($kpi['failed'] ?? 0),
            ],
            'recentAttempts' => $recentAttempts,
            'view'           => $view,
            'q'              => $q,
            'reasons'        => DeliveryService::failureReasons(),
        ]);
    }

    /**
     * Agent task list (own tasks for agents; any agent via ?agent_id= for dispatchers).
     */
    public function tasks(): string
    {
        $orgId    = $this->orgId();
        $db       = \Config\Database::connect();
        $canAssign = user_can('delivery.assign');

        $agents  = $this->service->activeAgents($orgId);
        $ownAgent = $this->service->agentForUser($orgId, (int) $this->userId());

        $agentId = null;
        if ($canAssign) {
            $requested = (int) $this->request->getGet('agent_id');
            $agentId   = $requested > 0 ? $requested : null;
        } else {
            $agentId = $ownAgent !== null ? (int) $ownAgent['id'] : 0; // 0 => matches nothing
        }

        $build = function (array $statuses) use ($db, $orgId, $agentId) {
            $builder = $db->table('delivery_assignments a')
                ->select('a.id AS assignment_id, a.status AS assignment_status, a.assigned_at, a.completed_at, a.notes,'
                    . ' s.id AS shipment_id, s.tracking_number, s.current_status, s.payment_mode, s.cod_amount, s.total_packages,'
                    . ' s.chargeable_weight_kg, s.special_instructions, c.name AS customer_name,'
                    . ' ra.contact_name AS receiver_name, ra.contact_phone AS receiver_phone, ra.address_line1, ra.address_line2,'
                    . ' ra.landmark, ra.city, ra.state, ra.postal_code, ag.full_name AS agent_name, ag.id AS agent_id,'
                    . ' (SELECT COUNT(*) FROM delivery_attempts t WHERE t.shipment_id = s.id) AS attempts', false)
                ->join('shipments s', 's.id = a.shipment_id')
                ->join('customers c', 'c.id = s.customer_id', 'left')
                ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
                ->join('delivery_agents ag', 'ag.id = a.delivery_agent_id', 'left')
                ->where('a.organization_id', $orgId)
                ->where('s.deleted_at', null)
                ->whereIn('a.status', $statuses);

            if ($agentId !== null) {
                $builder->where('a.delivery_agent_id', $agentId);
            }

            return $builder;
        };

        $active = $build(DeliveryService::ACTIVE_ASSIGNMENT)->orderBy('a.assigned_at', 'ASC')->limit(100)->get()->getResultArray();
        $recent = $build(['completed', 'failed'])->orderBy('a.completed_at', 'DESC')->limit(8)->get()->getResultArray();

        return $this->renderPage('delivery/tasks', [
            'title'     => 'Delivery Tasks',
            'tasks'     => $active,
            'recent'    => $recent,
            'agents'    => $agents,
            'agentId'   => $agentId,
            'ownAgent'  => $ownAgent,
            'canAssign' => $canAssign,
            'reasons'   => DeliveryService::failureReasons(),
        ]);
    }

    /**
     * Proof-of-delivery form (or the saved POD, read-only).
     */
    public function pod(int $shipmentId): string|RedirectResponse
    {
        $orgId = $this->orgId();
        $db    = \Config\Database::connect();

        $shipment = $db->table('shipments s')
            ->select('s.*, c.name AS customer_name, ra.contact_name AS receiver_name, ra.contact_phone AS receiver_phone,'
                . ' ra.address_line1, ra.address_line2, ra.landmark, ra.city, ra.state, ra.postal_code')
            ->join('customers c', 'c.id = s.customer_id', 'left')
            ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
            ->where('s.id', $shipmentId)
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->get()->getRowArray();

        if ($shipment === null) {
            throw PageNotFoundException::forPageNotFound('Shipment not found.');
        }

        $pod        = $db->table('proof_of_deliveries')->where('shipment_id', $shipmentId)->get()->getRowArray();
        $assignment = $this->service->activeAssignment($orgId, $shipmentId);

        $restrict = $this->restrictUserId();
        if ($pod === null) {
            if ($shipment['current_status'] !== 'out_for_delivery') {
                $this->flash('error', 'Proof of delivery can only be captured while the shipment is Out for Delivery.');

                return redirect()->to(site_url('shipments/' . $shipmentId));
            }
            if ($restrict !== null && ($assignment === null || (int) ($assignment['agent_user_id'] ?? 0) !== $restrict)) {
                $this->flash('error', 'This shipment is not assigned to you.');

                return redirect()->to(site_url('delivery/tasks'));
            }
        }

        return $this->renderPage('delivery/pod', [
            'title'       => 'Proof of Delivery',
            'shipment'    => $shipment,
            'pod'         => $pod,
            'assignment'  => $assignment,
            'relations'   => DeliveryService::receiverRelations(),
            'methods'     => DeliveryService::verificationMethods(),
        ]);
    }

    // ------------------------------------------------------------------
    // Actions (POST)
    // ------------------------------------------------------------------

    public function assign(): RedirectResponse
    {
        $result = $this->service->assign(
            $this->orgId(),
            (int) $this->request->getPost('shipment_id'),
            (int) $this->request->getPost('delivery_agent_id'),
            $this->userId(),
            $this->request->getPost('notes'),
            (bool) $this->request->getPost('dispatch_now')
        );

        return $this->respond($result, 'delivery');
    }

    public function markOutForDelivery(int $shipmentId): RedirectResponse
    {
        $result = $this->service->markOutForDelivery($this->orgId(), $shipmentId, $this->userId(), $this->restrictUserId());

        return $this->respond($result, $this->returnUrl($shipmentId));
    }

    public function recordAttempt(int $shipmentId): RedirectResponse
    {
        $outcome = (string) $this->request->getPost('outcome');

        $result = $this->service->recordAttempt(
            $this->orgId(),
            $shipmentId,
            $this->userId(),
            $outcome,
            $this->request->getPost('failure_reason'),
            $this->request->getPost('remarks'),
            (bool) $this->request->getPost('create_ndr'),
            $this->restrictUserId()
        );

        if ($result['success'] && ($result['outcome'] ?? '') === 'delivered') {
            $this->flash('success', $result['message']);

            return redirect()->to(site_url('delivery/' . $shipmentId . '/pod'));
        }

        return $this->respond($result, $this->returnUrl($shipmentId));
    }

    public function submitPod(int $shipmentId): RedirectResponse
    {
        $result = $this->service->submitPod(
            $this->orgId(),
            $shipmentId,
            $this->userId(),
            [
                'received_by'            => $this->request->getPost('received_by'),
                'receiver_relation'      => $this->request->getPost('receiver_relation'),
                'verification_method'    => $this->request->getPost('verification_method'),
                'verification_reference' => $this->request->getPost('verification_reference'),
                'notes'                  => $this->request->getPost('notes'),
                'cod_collected'          => $this->request->getPost('cod_collected'),
            ],
            $this->restrictUserId()
        );

        if (! $result['success']) {
            return $this->respond($result, 'delivery/' . $shipmentId . '/pod');
        }

        $this->flash('success', $result['message']);

        return redirect()->to(site_url('shipments/' . $shipmentId));
    }

    // ------------------------------------------------------------------

    /**
     * Agents (without delivery.assign) may only act on their own assignments.
     */
    private function restrictUserId(): ?int
    {
        return user_can('delivery.assign') ? null : $this->userId();
    }

    /**
     * Where to send the user after an action, based on a whitelisted hidden field.
     */
    private function returnUrl(int $shipmentId): string
    {
        return match ((string) $this->request->getPost('return_to')) {
            'tasks'    => 'delivery/tasks',
            'shipment' => 'shipments/' . $shipmentId,
            default    => user_can('delivery.assign') ? 'delivery' : 'delivery/tasks',
        };
    }
}
