<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\ShipmentModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Dashboard with KPIs. Every query is scoped to the logged-in user's
 * organization (never to a request parameter) and sections are only
 * computed when the user holds the matching permission.
 */
class DashboardController extends BaseController
{
    protected string $module = 'dashboard';

    public function index(): string
    {
        $orgId = current_org_id();
        $db    = \Config\Database::connect();

        $data = [
            'title'    => 'Dashboard',
            'kpis'     => ['open_ndr' => 0],
            'statuses' => [],
            'recentShipments' => [],
            'events'   => [],
        ];

        if ($orgId === null) {
            // Platform-level users without an organization have no tenant data.
            return $this->renderPage('dashboard/index', $data);
        }

        if (user_can('shipments.view')) {
            $data = array_merge($data, $this->shipmentSection($db, $orgId));
        }

        if (user_can('pickups.manage')) {
            $data['kpis']['pending_pickups'] = $db->table('pickup_requests')
                ->where('organization_id', $orgId)
                ->whereIn('status', ['requested', 'scheduled'])
                ->countAllResults();
        }

        if (user_can('ndr.manage') || user_can('shipments.view')) {
            $data['kpis']['open_ndr'] = $db->table('ndr_cases')
                ->where('organization_id', $orgId)
                ->where('status', 'open')
                ->countAllResults();
        }

        if (user_can('returns.manage')) {
            $data['kpis']['return_requests'] = $db->table('return_requests')
                ->where('organization_id', $orgId)
                ->where('status', 'requested')
                ->countAllResults();
        }

        if (user_can('delivery.assign') || user_can('delivery.update_status')) {
            $data['kpis']['active_agents'] = $db->table('delivery_agents')
                ->where('organization_id', $orgId)
                ->where('status', 'active')
                ->countAllResults();
        }

        if (user_can('billing.manage')) {
            $data['kpis']['draft_invoices'] = $db->table('invoices')
                ->where('organization_id', $orgId)
                ->where('status', 'draft')
                ->countAllResults();
        }

        return $this->renderPage('dashboard/index', $data);
    }

    /**
     * @return array{kpis: array<string, mixed>, statuses: array<string, int>, recentShipments: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function shipmentSection(BaseConnection $db, int $orgId): array
    {
        $statuses = (new ShipmentModel())->statusCounts($orgId);

        // "Today" in the organization's timezone, converted to UTC for querying.
        $tz         = new \DateTimeZone((string) (current_user()['timezone'] ?? 'Asia/Kolkata'));
        $todayStart = (new \DateTimeImmutable('today', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $base = static fn () => $db->table('shipments')
            ->where('organization_id', $orgId)
            ->where('deleted_at', null);

        $inTransit = ['picked_up', 'in_transit', 'reached_destination_hub'];

        $kpis = [
            'total_shipments'   => array_sum($statuses),
            'created_today'     => $base()->where('created_at >=', $todayStart)->countAllResults(),
            'in_transit'        => array_sum(array_intersect_key($statuses, array_flip($inTransit))),
            'out_for_delivery'  => $statuses['out_for_delivery'] ?? 0,
            'delivered'         => $statuses['delivered'] ?? 0,
            'delivered_today'   => $base()->where('delivered_at >=', $todayStart)->countAllResults(),
            'delivery_failed'   => $statuses['delivery_failed'] ?? 0,
            'cod_to_collect'    => (float) ($base()->selectSum('cod_amount', 'total')
                ->where('payment_mode', 'cod')
                ->whereNotIn('current_status', ['draft', 'cancelled', 'delivered', 'rto_delivered'])
                ->get()->getRow('total') ?? 0),
            'cod_collected'     => (float) ($base()->selectSum('cod_amount', 'total')
                ->where('payment_mode', 'cod')
                ->where('current_status', 'delivered')
                ->get()->getRow('total') ?? 0),
        ];

        $recent = $db->table('shipments s')
            ->select('s.id, s.tracking_number, s.current_status, s.payment_mode, s.created_at,'
                . ' c.name AS customer_name, ra.contact_name AS receiver_name, ra.city AS destination_city,'
                . ' cp.name AS courier_name')
            ->join('customers c', 'c.id = s.customer_id', 'left')
            ->join('addresses ra', 'ra.id = s.receiver_address_id', 'left')
            ->join('courier_partners cp', 'cp.id = s.courier_partner_id', 'left')
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->orderBy('s.created_at', 'DESC')
            ->limit(8)
            ->get()->getResultArray();

        $events = $db->table('shipment_events e')
            ->select('e.title, e.status_code, e.event_location, e.occurred_at, s.tracking_number')
            ->join('shipments s', 's.id = e.shipment_id')
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->orderBy('e.occurred_at', 'DESC')
            ->limit(8)
            ->get()->getResultArray();

        return ['kpis' => $kpis, 'statuses' => $statuses, 'recentShipments' => $recent, 'events' => $events];
    }
}
