<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\InvoiceModel;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Invoices: creation (line items, tax, discount, totals), status updates and reads.
 *
 * Every method is tenant-scoped (organization id is an argument, resolved by the
 * controller from the logged-in user) and returns a plain result array:
 * ['success' => bool, 'message' => string, ...].
 *
 * Status flow: draft -> sent -> paid   (draft may also be paid directly).
 */
class InvoiceService
{
    public const STATUSES = ['draft', 'sent', 'paid'];

    public const MAX_LINES = 50;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null invoice + customer columns
     */
    public function find(int $orgId, int $invoiceId): ?array
    {
        return $this->db->table('invoices i')
            ->select('i.*, c.name AS customer_name, c.customer_code, c.email AS customer_email,'
                . ' c.phone AS customer_phone, c.gstin AS customer_gstin,'
                . ' u.first_name AS creator_first, u.last_name AS creator_last')
            ->join('customers c', 'c.id = i.customer_id', 'left')
            ->join('users u', 'u.id = i.created_by', 'left')
            ->where('i.id', $invoiceId)
            ->where('i.organization_id', $orgId)
            ->get()->getRowArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $orgId, int $invoiceId): array
    {
        return $this->db->table('invoice_items it')
            ->select('it.*, s.tracking_number')
            ->join('invoices i', 'i.id = it.invoice_id')
            ->join('shipments s', 's.id = it.shipment_id', 'left')
            ->where('it.invoice_id', $invoiceId)
            ->where('i.organization_id', $orgId)
            ->orderBy('it.id')
            ->get()->getResultArray();
    }

    /**
     * Active customers for the invoice form.
     *
     * @return list<array<string, mixed>>
     */
    public function customers(int $orgId): array
    {
        return $this->db->table('customers')
            ->select('id, customer_code, name')
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->orderBy('name')
            ->get()->getResultArray();
    }

    /**
     * Billable shipments (not cancelled, not already on an invoice) with their freight charge.
     *
     * @return list<array<string, mixed>>
     */
    public function billableShipments(int $orgId, int $limit = 300): array
    {
        return $this->db->table('shipments s')
            ->select('s.id, s.customer_id, s.tracking_number, s.shipping_charge, s.current_status')
            ->where('s.organization_id', $orgId)
            ->where('s.deleted_at', null)
            ->where('s.current_status !=', 'cancelled')
            ->where('NOT EXISTS (SELECT 1 FROM invoice_items ii JOIN invoices iv ON iv.id = ii.invoice_id'
                . " WHERE ii.shipment_id = s.id AND iv.organization_id = s.organization_id)", null, false)
            ->orderBy('s.created_at', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    /**
     * Invoice counts per status for the list chips.
     *
     * @return array<string, int>
     */
    public function statusCounts(int $orgId): array
    {
        $rows = $this->db->table('invoices')
            ->select('status, COUNT(*) AS total')
            ->where('organization_id', $orgId)
            ->groupBy('status')
            ->get()->getResultArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    /**
     * Creates a draft invoice with its line items in one transaction.
     *
     * Per line: net = qty * unit_price, tax = net * tax% / 100.
     * subtotal = sum(net), tax_amount = sum(tax),
     * total = subtotal + tax - discount.
     *
     * @param array<string, mixed> $input customer_id, invoice_date, due_date, discount_amount, items[]
     *
     * @return array{success: bool, message: string, errors?: array<string, string>, invoice_id?: int, invoice_number?: string}
     */
    public function create(int $orgId, ?int $userId, array $input): array
    {
        $errors = [];

        $customer = $this->db->table('customers')
            ->where('id', (int) ($input['customer_id'] ?? 0))
            ->where('organization_id', $orgId)
            ->where('status', 'active')
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($customer === null) {
            $errors['customer_id'] = 'Please select an active customer.';
        }

        $invoiceDate = $this->validDate((string) ($input['invoice_date'] ?? '')) ?? gmdate('Y-m-d');
        if (! empty($input['invoice_date']) && $this->validDate((string) $input['invoice_date']) === null) {
            $errors['invoice_date'] = 'Enter a valid invoice date.';
        }

        $dueDate = null;
        if (! empty($input['due_date'])) {
            $dueDate = $this->validDate((string) $input['due_date']);
            if ($dueDate === null) {
                $errors['due_date'] = 'Enter a valid due date.';
            } elseif ($dueDate < $invoiceDate) {
                $errors['due_date'] = 'The due date cannot be before the invoice date.';
            }
        }

        $discount = $this->number($input['discount_amount'] ?? 0) ?? 0.0;
        if ($discount < 0 || $discount > 1000000000) {
            $errors['discount_amount'] = 'Discount must be zero or more.';
            $discount                  = 0.0;
        }

        [$lines, $subtotal, $tax] = $this->validateLines((array) ($input['items'] ?? []), $orgId, (int) ($customer['id'] ?? 0), $errors);

        $subtotal = round($subtotal, 2);
        $tax      = round($tax, 2);
        $discount = round($discount, 2);

        if (! isset($errors['discount_amount']) && $discount > round($subtotal + $tax, 2)) {
            $errors['discount_amount'] = 'The discount cannot exceed the invoice amount.';
        }

        if ($errors !== []) {
            return ['success' => false, 'message' => 'Please correct the highlighted fields.', 'errors' => $errors];
        }

        $total = round($subtotal + $tax - $discount, 2);
        $now   = gmdate('Y-m-d H:i:s');

        $this->db->transBegin();

        try {
            $number = (new InvoiceModel())->nextInvoiceNumber($orgId);

            $this->db->table('invoices')->insert([
                'organization_id' => $orgId,
                'customer_id'     => (int) $customer['id'],
                'invoice_number'  => $number,
                'invoice_date'    => $invoiceDate,
                'due_date'        => $dueDate,
                'subtotal'        => $subtotal,
                'tax_amount'      => $tax,
                'discount_amount' => $discount,
                'total_amount'    => $total,
                'paid_amount'     => 0,
                'balance_amount'  => $total,
                'status'          => 'draft',
                'created_by'      => $userId,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $invoiceId = (int) $this->db->insertID();

            foreach ($lines as $line) {
                $this->db->table('invoice_items')->insert($line + ['invoice_id' => $invoiceId, 'created_at' => $now]);
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            log_message('error', 'invoice create failed: {message}', ['message' => $e->getMessage()]);

            return ['success' => false, 'message' => 'The invoice could not be created. Please try again.'];
        }

        return [
            'success'        => true,
            'message'        => 'Invoice ' . $number . ' created as draft.',
            'invoice_id'     => $invoiceId,
            'invoice_number' => $number,
        ];
    }

    // ------------------------------------------------------------------
    // Status updates
    // ------------------------------------------------------------------

    /**
     * draft -> sent
     *
     * @return array{success: bool, message: string}
     */
    public function markSent(int $orgId, int $invoiceId): array
    {
        $invoice = $this->find($orgId, $invoiceId);
        if ($invoice === null) {
            return ['success' => false, 'message' => 'Invoice not found.'];
        }
        if ($invoice['status'] !== 'draft') {
            return ['success' => false, 'message' => 'Only draft invoices can be marked as sent.'];
        }

        $this->db->table('invoices')
            ->where('id', $invoiceId)
            ->where('organization_id', $orgId)
            ->where('status', 'draft')
            ->update(['status' => 'sent', 'updated_at' => gmdate('Y-m-d H:i:s')]);

        if ($this->db->affectedRows() < 1) {
            return ['success' => false, 'message' => 'The invoice status changed meanwhile. Reload and try again.'];
        }

        return ['success' => true, 'message' => 'Invoice ' . $invoice['invoice_number'] . ' marked as sent.'];
    }

    /**
     * draft | sent -> paid (full payment: paid = total, balance = 0).
     *
     * @return array{success: bool, message: string}
     */
    public function markPaid(int $orgId, int $invoiceId): array
    {
        $invoice = $this->find($orgId, $invoiceId);
        if ($invoice === null) {
            return ['success' => false, 'message' => 'Invoice not found.'];
        }
        if (! in_array($invoice['status'], ['draft', 'sent'], true)) {
            return ['success' => false, 'message' => $invoice['status'] === 'paid'
                ? 'This invoice is already paid.'
                : 'This invoice cannot be marked as paid.'];
        }

        $this->db->table('invoices')
            ->where('id', $invoiceId)
            ->where('organization_id', $orgId)
            ->whereIn('status', ['draft', 'sent'])
            ->update([
                'status'         => 'paid',
                'paid_amount'    => $invoice['total_amount'],
                'balance_amount' => 0,
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ]);

        if ($this->db->affectedRows() < 1) {
            return ['success' => false, 'message' => 'The invoice status changed meanwhile. Reload and try again.'];
        }

        return ['success' => true, 'message' => 'Invoice ' . $invoice['invoice_number'] . ' marked as paid.'];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<int|string, mixed> $rows
     * @param array<string, string>    $errors
     *
     * @return array{0: list<array<string, mixed>>, 1: float, 2: float} lines, subtotal, tax
     */
    private function validateLines(array $rows, int $orgId, int $customerId, array &$errors): array
    {
        $lines    = [];
        $subtotal = 0.0;
        $tax      = 0.0;
        $used     = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));
            $qtyRaw      = $row['quantity'] ?? '';
            $priceRaw    = $row['unit_price'] ?? '';
            $taxRaw      = $row['tax_percent'] ?? '';
            $shipmentRaw = (int) ($row['shipment_id'] ?? 0);

            if ($description === '' && ($priceRaw === '' || $priceRaw === null) && $shipmentRaw === 0) {
                continue; // blank row
            }

            $position = count($lines) + 1;
            $key      = 'items.' . $index;
            $before   = count($errors);

            if ($description === '' || mb_strlen($description) > 255) {
                $errors[$key . '.description'] = 'Line ' . $position . ': description is required (max 255 characters).';
            }

            $qty = $this->number($qtyRaw === '' ? 1 : $qtyRaw);
            if ($qty === null || $qty <= 0 || $qty > 1000000) {
                $errors[$key . '.quantity'] = 'Line ' . $position . ': quantity must be greater than zero.';
            }

            $price = $this->number($priceRaw);
            if ($price === null || $price < 0 || $price > 100000000) {
                $errors[$key . '.unit_price'] = 'Line ' . $position . ': unit price must be zero or more.';
            }

            $taxPercent = $this->number($taxRaw === '' ? 0 : $taxRaw);
            if ($taxPercent === null || $taxPercent < 0 || $taxPercent > 100) {
                $errors[$key . '.tax_percent'] = 'Line ' . $position . ': tax must be between 0 and 100 percent.';
            }

            $shipmentId = null;
            if ($shipmentRaw > 0) {
                $shipment = $this->db->table('shipments')->select('id, customer_id')
                    ->where('id', $shipmentRaw)
                    ->where('organization_id', $orgId)
                    ->where('deleted_at', null)
                    ->get()->getRowArray();

                if ($shipment === null) {
                    $errors[$key . '.shipment_id'] = 'Line ' . $position . ': the selected shipment was not found.';
                } elseif ($customerId > 0 && (int) $shipment['customer_id'] !== $customerId) {
                    $errors[$key . '.shipment_id'] = 'Line ' . $position . ': the shipment belongs to a different customer.';
                } elseif (isset($used[$shipmentRaw])) {
                    $errors[$key . '.shipment_id'] = 'Line ' . $position . ': the shipment is already on another line.';
                } else {
                    $shipmentId         = $shipmentRaw;
                    $used[$shipmentRaw] = true;
                }
            }

            if (count($errors) === $before) {
                $net      = round((float) $qty * (float) $price, 2);
                $lineTax  = round($net * (float) $taxPercent / 100, 2);
                $subtotal += $net;
                $tax      += $lineTax;
                $lines[]   = [
                    'shipment_id' => $shipmentId,
                    'description' => $description,
                    'quantity'    => round((float) $qty, 2),
                    'unit_price'  => round((float) $price, 2),
                    'tax_percent' => round((float) $taxPercent, 2),
                    'line_total'  => $net,
                ];
            }
        }

        if ($lines === [] && ! preg_grep('/^items\./', array_keys($errors))) {
            $errors['items'] = 'Add at least one line item.';
        }
        if (count($lines) > self::MAX_LINES) {
            $errors['items'] = 'An invoice can contain at most ' . self::MAX_LINES . ' line items.';
        }

        return [$lines, $subtotal, $tax];
    }

    private function validDate(string $value): ?string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $dt !== false && $dt->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * @param mixed $value
     */
    private function number($value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
