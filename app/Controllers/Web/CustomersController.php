<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\AddressModel;
use App\Models\CustomerModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Customers (+ optional primary address captured on the same form).
 * Route permission: customers.manage
 */
class CustomersController extends MasterDataController
{
    protected string $module = 'customers';

    private const TYPES         = ['business', 'individual'];
    private const ADDRESS_TYPES = ['billing', 'pickup', 'delivery', 'other'];

    /**
     * Customer type codes: lookup_values (customer_type) with the constant as fallback.
     *
     * @return list<string>
     */
    private static function types(): array
    {
        return array_keys(lookup_options('customer_type', array_combine(self::TYPES, self::TYPES)));
    }

    /**
     * Address type codes: lookup_values (address_type) with the constant as fallback.
     *
     * @return list<string>
     */
    private static function addressTypes(): array
    {
        return array_keys(lookup_options('address_type', array_combine(self::ADDRESS_TYPES, self::ADDRESS_TYPES)));
    }

    public function index(): string
    {
        [$q, $status] = $this->listFilters();

        $model = new CustomerModel();
        $model->forOrg()
            ->select('customers.*, (SELECT a.city FROM addresses a WHERE a.customer_id = customers.id'
                . ' ORDER BY a.is_default DESC, a.id ASC LIMIT 1) AS primary_city', false)
            ->search($q, ['customers.name', 'customers.customer_code', 'customers.phone', 'customers.email', 'customers.contact_person'])
            ->withStatus($status)
            ->orderBy('customers.id', 'DESC');

        $customers = $model->paginate($this->perPage);

        return $this->renderPage('customers/index', [
            'title'     => 'Customers',
            'customers' => $customers,
            'pager'     => $model->pager,
            'q'         => $q,
            'status'    => $status,
        ]);
    }

    public function create(): string
    {
        return $this->renderPage('customers/form', [
            'title'     => 'Add Customer',
            'mode'      => 'create',
            'customer'  => [],
            'addresses' => [],
            'types'     => self::types(),
            'addrTypes' => self::addressTypes(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $orgId = $this->orgId();
        $data  = $this->collect();
        $code  = strtoupper($this->input('customer_code'));

        $rules = $this->customerRules() + [
            'customer_code' => ['label' => 'Customer code', 'rules' => 'permit_empty|max_length[50]|regex_match[/^[A-Z0-9_\-]+$/]'],
        ];
        $withAddress = $this->addressSubmitted();
        if ($withAddress) {
            $rules += $this->addressRules(false);
        }

        $post = $data + ['customer_code' => $code] + $this->addressPost();
        if (! $this->validateData($post, $rules)) {
            return $this->validationFailed();
        }

        $model = new CustomerModel();

        if ($code !== '' && $this->valueTaken($model, 'customer_code', $code)) {
            return $this->backWithErrors(['customer_code' => 'This customer code is already in use.']);
        }
        if ($code === '') {
            $code = $model->nextCode($orgId);
        }

        $db = $this->db();
        $db->transBegin();

        try {
            $customerId = $model->insert($data + [
                'organization_id' => $orgId,
                'customer_code'   => $code,
            ], true);

            if (! $customerId) {
                throw new \RuntimeException('Customer could not be saved.');
            }

            if ($withAddress) {
                $this->insertAddress(
                    $orgId,
                    $this->addressInput(),
                    $this->addressType(),
                    (int) $customerId,
                    true,
                    ['contact_name' => $data['contact_person'] ?? $data['name'], 'contact_phone' => $data['phone']]
                );
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Customer create failed: ' . $e->getMessage());

            return $this->backWithErrors('The customer could not be saved. Please try again.');
        }

        return $this->redirectTo('customers', 'success', 'Customer ' . $code . ' created.');
    }

    public function edit(int $id): string
    {
        $customer = $this->ownedRow(new CustomerModel(), $id);

        return $this->renderPage('customers/form', [
            'title'     => 'Edit Customer',
            'mode'      => 'edit',
            'customer'  => $customer,
            'addresses' => $this->customerAddresses($id),
            'types'     => self::types(),
            'addrTypes' => self::addressTypes(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $orgId    = $this->orgId();
        $model    = new CustomerModel();
        $customer = $this->ownedRow($model, $id);
        $data     = $this->collect();

        $rules       = $this->customerRules();
        $withAddress = $this->addressSubmitted();
        if ($withAddress) {
            $rules += $this->addressRules(false);
        }

        if (! $this->validateData($data + $this->addressPost(), $rules)) {
            return $this->validationFailed();
        }

        $db = $this->db();
        $db->transBegin();

        try {
            $model = new CustomerModel();
            if (! $model->update($id, $data)) {
                throw new \RuntimeException('Customer could not be updated.');
            }

            if ($withAddress) {
                $hasAddress = count($this->customerAddresses($id)) > 0;
                $this->insertAddress(
                    $orgId,
                    $this->addressInput(),
                    $this->addressType(),
                    $id,
                    ! $hasAddress,
                    ['contact_name' => $data['contact_person'] ?? $data['name'], 'contact_phone' => $data['phone']]
                );
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Customer update failed: ' . $e->getMessage());

            return $this->backWithErrors('The customer could not be updated. Please try again.');
        }

        return $this->redirectTo('customers', 'success', 'Customer ' . $customer['customer_code'] . ' updated.');
    }

    /** Soft-disable: customers keep their shipment / invoice history. */
    public function delete(int $id): RedirectResponse
    {
        return $this->changeStatus(new CustomerModel(), $id, 'inactive', 'customers', 'customer');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->changeStatus(new CustomerModel(), $id, 'active', 'customers', 'customer');
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, string|null>
     */
    private function collect(): array
    {
        $type = strtolower($this->input('customer_type'));

        return [
            'customer_type'  => in_array($type, self::types(), true) ? $type : 'business',
            'name'           => $this->input('name'),
            'contact_person' => $this->inputOrNull('contact_person'),
            'email'          => $this->inputOrNull('email') !== null ? strtolower((string) $this->inputOrNull('email')) : null,
            'phone'          => $this->input('phone'),
            'gstin'          => $this->inputOrNull('gstin') !== null ? strtoupper((string) $this->inputOrNull('gstin')) : null,
            'status'         => $this->inputStatus(),
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private function customerRules(): array
    {
        return [
            'customer_type'  => ['label' => 'Customer type', 'rules' => 'required|in_list[' . implode(',', self::types()) . ']'],
            'name'           => ['label' => 'Name', 'rules' => 'required|min_length[2]|max_length[180]'],
            'contact_person' => ['label' => 'Contact person', 'rules' => 'permit_empty|max_length[150]'],
            'email'          => ['label' => 'Email', 'rules' => 'permit_empty|valid_email|max_length[190]'],
            'phone'          => ['label' => 'Phone', 'rules' => 'required|max_length[30]|regex_match[/^[0-9+\-\s()]{6,30}$/]'],
            'gstin'          => ['label' => 'GSTIN', 'rules' => 'permit_empty|exact_length[15]|regex_match[/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/]'],
            'status'         => ['label' => 'Status', 'rules' => 'required|in_list[active,inactive]'],
        ];
    }

    /**
     * Inline address request values (addr_*), keyed like the form fields.
     *
     * @return array<string, string>
     */
    private function addressPost(): array
    {
        $post = [];
        foreach (array_keys(self::ADDRESS_MAP) as $field) {
            $post[$field] = $this->input($field);
        }

        return $post;
    }

    private function addressType(): string
    {
        $type = strtolower($this->input('addr_type'));

        return in_array($type, self::addressTypes(), true) ? $type : 'billing';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customerAddresses(int $customerId): array
    {
        return (new AddressModel())
            ->forOrg()
            ->forCustomer($customerId)
            ->orderBy('addresses.is_default', 'DESC')
            ->orderBy('addresses.id', 'ASC')
            ->findAll();
    }
}
