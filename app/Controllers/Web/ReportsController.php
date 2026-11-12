<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Controllers\Web\Concerns\InteractsWithTenant;
use App\Services\Billing\CodRemittanceService;
use App\Services\Report\ReportService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Reports: shipment summary by status, delivery success rate and COD summary
 * for a date range (+ optional courier), with a CSV export of the shipment summary.
 * Requires reports.view (see Config\Routes).
 */
class ReportsController extends BaseController
{
    use InteractsWithTenant;

    protected string $module = 'reports';

    public function index(): string
    {
        $orgId     = $this->orgId();
        $range     = $this->range();
        $courierId = $this->courierId();
        $service   = new ReportService();

        $summary = $service->shipmentSummary($orgId, $range, $courierId);

        return $this->renderPage('reports/index', [
            'title'       => 'Reports',
            'range'       => $range,
            'courierId'   => (int) $courierId,
            'couriers'    => (new CodRemittanceService())->couriers($orgId),
            'summary'     => $summary,
            'performance' => $service->deliveryPerformance($orgId, $range, $courierId),
            'cod'         => $service->codSummary($orgId, $range, $courierId),
            'rangeError'  => $range['error'],
        ]);
    }

    public function exportShipmentSummary(): ResponseInterface
    {
        $range = $this->range();
        $csv   = (new ReportService())->shipmentSummaryCsv($this->orgId(), $range, $this->courierId());

        $filename = 'shipment-summary_' . $range['from'] . '_to_' . $range['to'] . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody("\xEF\xBB\xBF" . $csv); // UTF-8 BOM so Excel opens it correctly
    }

    /**
     * @return array{from: string, to: string, start_utc: string, end_utc: string, error: string|null}
     */
    private function range(): array
    {
        return ReportService::resolveRange(
            $this->cleanInput($this->request->getGet('from'), 10),
            $this->cleanInput($this->request->getGet('to'), 10),
            $this->userTimezone()
        );
    }

    private function courierId(): ?int
    {
        $id = (int) $this->request->getGet('courier_id');

        return $id > 0 ? $id : null;
    }
}
