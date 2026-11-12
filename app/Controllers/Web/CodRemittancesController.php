<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Controllers\Web\Concerns\InteractsWithTenant;
use App\Models\CodRemittanceModel;
use App\Services\Billing\CodRemittanceService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * COD remittances: list batches, create a batch for a courier + period
 * (amounts entered manually, with a helper that sums delivered COD shipments),
 * and mark a batch remitted. All routes require billing.manage.
 */
class CodRemittancesController extends BaseController
{
    use InteractsWithTenant;

    protected string $module = 'cod_remittances';

    private CodRemittanceService $service;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);

        $this->service = new CodRemittanceService();
    }

    public function index(): string
    {
        $orgId     = $this->orgId();
        $status    = $this->cleanInput($this->request->getGet('status'), 20);
        $courierId = (int) $this->request->getGet('courier_id');

        if ($status !== '' && ! in_array($status, CodRemittanceService::STATUSES, true)) {
            $status = '';
        }

        $model = new CodRemittanceModel();
        $model->forOrg($orgId)
            ->select('cod_remittances.*, cp.name AS courier_name')
            ->join('courier_partners cp', 'cp.id = cod_remittances.courier_partner_id', 'left')
            ->withStatus($status);

        if ($courierId > 0) {
            $model->where('cod_remittances.courier_partner_id', $courierId);
        }

        $model->orderBy('cod_remittances.id', 'DESC');

        $remittances = $model->paginate(12);

        $totals = \Config\Database::connect()->table('cod_remittances')
            ->select("COALESCE(SUM(CASE WHEN status = 'pending' THEN net_amount ELSE 0 END), 0) AS pending_net,"
                . " COALESCE(SUM(CASE WHEN status = 'remitted' THEN net_amount ELSE 0 END), 0) AS remitted_net", false)
            ->where('organization_id', $orgId)
            ->get()->getRowArray() ?? [];

        return $this->renderPage('cod_remittances/index', [
            'title'       => 'COD Remittances',
            'remittances' => $remittances,
            'pager'       => $model->pager,
            'status'      => $status,
            'courierId'   => $courierId,
            'couriers'    => $this->service->couriers($orgId),
            'counts'      => $this->service->statusCounts($orgId),
            'statuses'    => CodRemittanceService::STATUSES,
            'pendingNet'  => (float) ($totals['pending_net'] ?? 0),
            'remittedNet' => (float) ($totals['remitted_net'] ?? 0),
        ]);
    }

    public function create(): string
    {
        $today = (new \DateTimeImmutable('today', new \DateTimeZone($this->userTimezone())));

        return $this->renderPage('cod_remittances/create', [
            'title'       => 'New COD Remittance',
            'couriers'    => $this->service->couriers($this->orgId()),
            'periodStart' => $today->modify('-6 days')->format('Y-m-d'),
            'periodEnd'   => $today->format('Y-m-d'),
        ]);
    }

    public function store(): RedirectResponse
    {
        $result = $this->service->create($this->orgId(), (array) $this->request->getPost());

        return $this->respond($result, 'cod-remittances', 'cod-remittances/create');
    }

    public function markRemitted(int $id): RedirectResponse
    {
        return $this->respond(
            $this->service->markRemitted($this->orgId(), $id, $this->request->getPost('reference_number')),
            'cod-remittances'
        );
    }

    /**
     * JSON helper for the create form: delivered COD totals for a courier + period.
     */
    public function suggest(): ResponseInterface
    {
        $result = $this->service->suggest(
            $this->orgId(),
            (int) $this->request->getGet('courier_id'),
            (string) $this->request->getGet('from'),
            (string) $this->request->getGet('to'),
            $this->userTimezone()
        );

        return $this->response->setStatusCode($result['success'] ? 200 : 422)->setJSON($result);
    }
}
