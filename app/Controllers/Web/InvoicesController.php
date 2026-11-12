<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Controllers\Web\Concerns\InteractsWithTenant;
use App\Models\InvoiceModel;
use App\Services\Billing\InvoiceService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Invoices: list, create (customer + line items + tax), view, mark sent / paid.
 * All routes require billing.manage (see Config\Routes).
 */
class InvoicesController extends BaseController
{
    use InteractsWithTenant;

    protected string $module = 'invoices';

    private InvoiceService $service;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);

        $this->service = new InvoiceService();
    }

    public function index(): string
    {
        $orgId  = $this->orgId();
        $status = $this->cleanInput($this->request->getGet('status'), 20);
        $q      = $this->cleanInput($this->request->getGet('q'), 60);

        if ($status !== '' && ! in_array($status, InvoiceService::STATUSES, true)) {
            $status = '';
        }

        $model = new InvoiceModel();
        $model->forOrg($orgId)
            ->withCustomer()
            ->withStatus($status)
            ->search($q, ['invoices.invoice_number', 'c.name', 'c.customer_code'])
            ->orderBy('invoices.invoice_date', 'DESC')
            ->orderBy('invoices.id', 'DESC');

        $invoices = $model->paginate(12);

        return $this->renderPage('invoices/index', [
            'title'    => 'Invoices',
            'invoices' => $invoices,
            'pager'    => $model->pager,
            'status'   => $status,
            'q'        => $q,
            'counts'   => $this->service->statusCounts($orgId),
            'statuses' => InvoiceService::STATUSES,
            'today'    => $this->today(),
        ]);
    }

    public function create(): string
    {
        $orgId = $this->orgId();
        $today = $this->today();

        return $this->renderPage('invoices/create', [
            'title'     => 'New Invoice',
            'customers' => $this->service->customers($orgId),
            'shipments' => $this->service->billableShipments($orgId),
            'today'     => $today,
            'dueDate'   => gmdate('Y-m-d', strtotime($today . ' +15 days')),
        ]);
    }

    public function store(): RedirectResponse
    {
        $result = $this->service->create($this->orgId(), $this->userId(), (array) $this->request->getPost());

        if (! $result['success']) {
            return $this->respond($result, 'invoices/create');
        }

        return $this->respond($result, 'invoices/' . $result['invoice_id']);
    }

    public function show(int $id): string
    {
        $orgId   = $this->orgId();
        $invoice = $this->service->find($orgId, $id);

        if ($invoice === null) {
            throw PageNotFoundException::forPageNotFound('Invoice not found.');
        }

        return $this->renderPage('invoices/show', [
            'title'   => 'Invoice ' . $invoice['invoice_number'],
            'invoice' => $invoice,
            'items'   => $this->service->items($orgId, $id),
            'today'   => $this->today(),
        ]);
    }

    public function markSent(int $id): RedirectResponse
    {
        return $this->respond($this->service->markSent($this->orgId(), $id), 'invoices/' . $id);
    }

    public function markPaid(int $id): RedirectResponse
    {
        return $this->respond($this->service->markPaid($this->orgId(), $id), 'invoices/' . $id);
    }

    /**
     * Today's date in the organization's timezone (Y-m-d).
     */
    private function today(): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone($this->userTimezone())))->format('Y-m-d');
    }
}
