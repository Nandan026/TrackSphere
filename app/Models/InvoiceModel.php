<?php

declare(strict_types=1);

namespace App\Models;

class InvoiceModel extends BaseModel
{
    protected $table         = 'invoices';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'organization_id', 'customer_id', 'invoice_number', 'invoice_date', 'due_date',
        'subtotal', 'tax_amount', 'discount_amount', 'total_amount', 'paid_amount',
        'balance_amount', 'status', 'created_by',
    ];

    /**
     * @return $this
     */
    public function withCustomer()
    {
        $this->select('invoices.*, c.name AS customer_name, c.customer_code')
            ->join('customers c', 'c.id = invoices.customer_id', 'left');

        return $this;
    }

    public function nextInvoiceNumber(int $organizationId): string
    {
        $prefix = 'INV-' . gmdate('Ym') . '-';

        $row = $this->builder()
            ->select('invoice_number')
            ->where('organization_id', $organizationId)
            ->like('invoice_number', $prefix, 'after')
            ->orderBy('invoice_number', 'DESC')
            ->get(1)->getRowArray();

        $next = $row ? ((int) substr($row['invoice_number'], strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
