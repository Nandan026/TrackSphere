<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Report\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Reports API (Bearer token, reports.view).
 *
 *   GET /api/v1/reports/shipment-summary?from=YYYY-MM-DD&to=YYYY-MM-DD&courier_id=
 *
 * Defaults to the last 30 days in the organization's timezone.
 */
class ReportsController extends BaseApiController
{
    public function shipmentSummary(): ResponseInterface
    {
        if ($denied = $this->deny('reports.view')) {
            return $denied;
        }

        $orgId     = (int) $this->orgId();
        $courierId = (int) $this->request->getGet('courier_id');
        $courierId = $courierId > 0 ? $courierId : null;

        $range = ReportService::resolveRange(
            mb_substr(trim((string) $this->request->getGet('from')), 0, 10),
            mb_substr(trim((string) $this->request->getGet('to')), 0, 10),
            $this->timezone()
        );

        $service     = new ReportService();
        $summary     = $service->shipmentSummary($orgId, $range, $courierId);
        $performance = $service->deliveryPerformance($orgId, $range, $courierId);
        $cod         = $service->codSummary($orgId, $range, $courierId);

        return api_success([
            'period'      => ['from' => $range['from'], 'to' => $range['to'], 'timezone' => $this->timezone()],
            'courier_id'  => $courierId,
            'total'       => $summary['total'],
            'by_status'   => $summary['rows'],
            'by_courier'  => $summary['by_courier'],
            'delivery'    => [
                'delivered'    => $performance['delivered'],
                'failed'       => $performance['failed'],
                'rto'          => $performance['rto'],
                'in_progress'  => $performance['in_progress'],
                'success_rate' => $performance['success_rate'],
                'on_time_rate' => $performance['on_time_rate'],
            ],
            'cod'         => [
                'shipments' => $cod['shipments'],
                'total'     => $cod['total'],
                'collected' => $cod['collected'],
                'pending'   => $cod['pending'],
                'returned'  => $cod['returned'],
            ],
        ], $range['error'] ?? 'OK');
    }
}
