<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\AddressModel;
use App\Models\BaseModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Shared plumbing for the master-data CRUD controllers.
 *
 * Rules every child follows:
 *  - the tenant comes from current_org_id() only, never from request input;
 *  - rows are loaded through the organization scope before being changed;
 *  - "delete" means status = inactive (history stays intact).
 */
abstract class MasterDataController extends BaseController
{
    protected int $perPage = 15;

    /** Statuses accepted by the status selects. */
    protected const STATUSES = ['active', 'inactive'];

    /** Request field => addresses column (inline address form uses the "addr_" prefix). */
    protected const ADDRESS_MAP = [
        'addr_contact_name'  => 'contact_name',
        'addr_contact_phone' => 'contact_phone',
        'addr_line1'         => 'address_line1',
        'addr_line2'         => 'address_line2',
        'addr_landmark'      => 'landmark',
        'addr_city'          => 'city',
        'addr_district'      => 'district',
        'addr_state'         => 'state',
        'addr_postal_code'   => 'postal_code',
        'addr_country_code'  => 'country_code',
    ];

    // ------------------------------------------------------------------
    // Tenant / request helpers
    // ------------------------------------------------------------------

    /**
     * Organization of the signed-in user. Accounts without an organization
     * (platform users) have no tenant data to manage.
     */
    protected function orgId(): int
    {
        $orgId = current_org_id();

        if ($orgId === null) {
            throw PageNotFoundException::forPageNotFound('Your account is not linked to an organization.');
        }

        return $orgId;
    }

    /**
     * Trimmed scalar POST/GET value ('' when missing or not a scalar).
     */
    protected function input(string $key, string $method = 'post'): string
    {
        $value = $method === 'get' ? $this->request->getGet($key) : $this->request->getPost($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Trimmed value or null when empty (for nullable columns).
     */
    protected function inputOrNull(string $key): ?string
    {
        $value = $this->input($key);

        return $value === '' ? null : $value;
    }

    protected function inputBool(string $key): int
    {
        return in_array($this->input($key), ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
    }

    protected function inputStatus(string $key = 'status'): string
    {
        $status = strtolower($this->input($key));

        return in_array($status, self::STATUSES, true) ? $status : 'active';
    }

    /**
     * @param array<string, string>|string $errors
     */
    protected function backWithErrors($errors): RedirectResponse
    {
        return redirect()->back()->withInput()->with('errors', (array) $errors);
    }

    protected function validationFailed(): RedirectResponse
    {
        return $this->backWithErrors($this->validator->getErrors());
    }

    protected function redirectTo(string $path, string $type, string $message): RedirectResponse
    {
        return redirect()->to(site_url($path))->with($type, $message);
    }

    /**
     * Loads a row owned by the current organization or throws a 404.
     * (Global rows with a NULL organization are never returned here, so they
     * cannot be edited from a tenant account.)
     *
     * @return array<string, mixed>
     */
    protected function ownedRow(BaseModel $model, int $id): array
    {
        $table = $model->builder()->getTable();
        $row   = $model->where($table . '.organization_id', $this->orgId())->where($table . '.id', $id)->first();

        if (! is_array($row)) {
            throw PageNotFoundException::forPageNotFound('The requested record was not found.');
        }

        return $row;
    }

    /**
     * True when another row of this organization already uses the value.
     * Courier codes also collide with global (organization-less) rows.
     */
    protected function valueTaken(BaseModel $model, string $column, string $value, ?int $ignoreId = null, bool $withGlobal = false): bool
    {
        $table = $model->builder()->getTable();
        $builder = $model->builder();

        if ($withGlobal) {
            $builder->groupStart()->where($table . '.organization_id', $this->orgId())->orWhere($table . '.organization_id', null)->groupEnd();
        } else {
            $builder->where($table . '.organization_id', $this->orgId());
        }

        $builder->where($table . '.' . $column, $value);

        if ($ignoreId !== null) {
            $builder->where($table . '.id !=', $ignoreId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Next free sequential code such as PL-0003 for this organization.
     */
    protected function generateCode(BaseModel $model, string $column, string $prefix): string
    {
        $table = $model->builder()->getTable();
        $count = $model->builder()->where($table . '.organization_id', $this->orgId())->countAllResults();

        do {
            $count++;
            $code = $prefix . str_pad((string) $count, 4, '0', STR_PAD_LEFT);
        } while ($this->valueTaken($model, $column, $code));

        return $code;
    }

    /**
     * Switches the status of an owned row (used for deactivate / activate).
     */
    protected function changeStatus(BaseModel $model, int $id, string $status, string $listPath, string $label): RedirectResponse
    {
        $row = $this->ownedRow($model, $id);

        if (($row['status'] ?? '') !== $status && ! $model->update($id, ['status' => $status])) {
            return $this->redirectTo($listPath, 'error', 'Could not update the ' . $label . '. Please try again.');
        }

        $message = $status === 'active' ? ucfirst($label) . ' activated.' : ucfirst($label) . ' deactivated.';

        return $this->redirectTo($listPath, 'success', $message);
    }

    /**
     * @return array{0: string, 1: string} [search term, status filter] from the query string
     */
    protected function listFilters(): array
    {
        $q      = mb_substr($this->input('q', 'get'), 0, 100);
        $status = strtolower($this->input('status', 'get'));

        return [$q, in_array($status, self::STATUSES, true) ? $status : ''];
    }

    protected function db(): BaseConnection
    {
        return \Config\Database::connect();
    }

    // ------------------------------------------------------------------
    // Address helpers (inline address create)
    // ------------------------------------------------------------------

    /**
     * Validation rules for the inline address fields.
     *
     * @return array<string, array{label: string, rules: string}>
     */
    protected function addressRules(bool $requireContact = true): array
    {
        $contact = $requireContact ? 'required|' : 'permit_empty|';

        return [
            'addr_contact_name'  => ['label' => 'Address contact name', 'rules' => $contact . 'max_length[150]'],
            'addr_contact_phone' => ['label' => 'Address contact phone', 'rules' => $contact . 'max_length[30]|regex_match[/^[0-9+\-\s()]{6,30}$/]'],
            'addr_line1'         => ['label' => 'Address line 1', 'rules' => 'required|max_length[255]'],
            'addr_line2'         => ['label' => 'Address line 2', 'rules' => 'permit_empty|max_length[255]'],
            'addr_landmark'      => ['label' => 'Landmark', 'rules' => 'permit_empty|max_length[150]'],
            'addr_city'          => ['label' => 'City', 'rules' => 'required|max_length[100]'],
            'addr_district'      => ['label' => 'District', 'rules' => 'permit_empty|max_length[100]'],
            'addr_state'         => ['label' => 'State', 'rules' => 'required|max_length[100]'],
            'addr_postal_code'   => ['label' => 'Postal code', 'rules' => 'required|max_length[15]|regex_match[/^[A-Za-z0-9\- ]{3,15}$/]'],
            'addr_country_code'  => ['label' => 'Country code', 'rules' => 'permit_empty|exact_length[2]|alpha'],
        ];
    }

    /**
     * True when the user typed anything into the core inline address fields.
     */
    protected function addressSubmitted(): bool
    {
        foreach (['addr_line1', 'addr_city', 'addr_state', 'addr_postal_code'] as $field) {
            if ($this->input($field) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Inline address input mapped to addresses columns.
     *
     * @return array<string, string|null>
     */
    protected function addressInput(): array
    {
        $data = [];

        foreach (self::ADDRESS_MAP as $field => $column) {
            $data[$column] = $this->inputOrNull($field);
        }

        $data['country_code'] = strtoupper($data['country_code'] ?? 'IN');

        return $data;
    }

    /**
     * Inserts an address for the organization and returns its id.
     * Call inside a transaction when other rows depend on it.
     *
     * @param array<string, string|null> $data       output of addressInput()
     * @param array<string, string|null> $contactFallback default contact name/phone
     */
    protected function insertAddress(int $orgId, array $data, string $type, ?int $customerId = null, bool $isDefault = false, array $contactFallback = []): int
    {
        $data['contact_name']  ??= $contactFallback['contact_name'] ?? null;
        $data['contact_phone'] ??= $contactFallback['contact_phone'] ?? null;

        $model = new AddressModel();
        $id    = $model->insert($data + [
            'organization_id' => $orgId,
            'customer_id'     => $customerId,
            'address_type'    => $type,
            'is_default'      => $isDefault ? 1 : 0,
        ], true);

        if (! $id) {
            throw new \RuntimeException('Could not save the address.');
        }

        return (int) $id;
    }

    /**
     * Existing addresses of the organization for "use existing address" selects.
     *
     * @return list<array<string, mixed>>
     */
    protected function addressOptions(): array
    {
        return (new AddressModel())
            ->forOrg()
            ->select('addresses.id, addresses.contact_name, addresses.address_line1, addresses.city, addresses.postal_code')
            ->orderBy('addresses.id', 'DESC')
            ->findAll(300);
    }

    /**
     * Address row of this organization or null.
     *
     * @return array<string, mixed>|null
     */
    protected function findAddress(int $addressId): ?array
    {
        $row = (new AddressModel())->findForOrg($addressId);

        return is_array($row) ? $row : null;
    }

    /**
     * Resolves the address of a pickup location / warehouse form.
     *
     * - address_id given  => must belong to the organization, used as is;
     * - address_id empty  => inline fields are required; on edit, the current
     *   address is kept when nothing changed, otherwise a new address row is
     *   created (old shipments keep pointing at the original address).
     *
     * Must run inside a transaction. Returns [addressId, error]; error is a
     * list of messages when the request must be rejected.
     *
     * @param array<string, mixed>|null $currentRow the existing record on edit
     *
     * @return array{0: int|null, 1: array<string, string>}
     */
    protected function resolveAddress(int $orgId, string $type, ?array $currentRow): array
    {
        $selected = $this->input('address_id');

        if ($selected !== '') {
            $address = ctype_digit($selected) ? $this->findAddress((int) $selected) : null;
            if ($address === null) {
                return [null, ['address_id' => 'The selected address is not available.']];
            }

            return [(int) $address['id'], []];
        }

        if (! $this->validate($this->addressRules())) {
            return [null, $this->validator->getErrors()];
        }

        $input = $this->addressInput();

        if ($currentRow !== null && ! empty($currentRow['address_id'])) {
            $current = $this->findAddress((int) $currentRow['address_id']);

            if ($current !== null && $this->addressUnchanged($current, $input)) {
                return [(int) $current['id'], []];
            }
        }

        return [$this->insertAddress($orgId, $input, $type), []];
    }

    /**
     * @param array<string, mixed>       $current
     * @param array<string, string|null> $input
     */
    private function addressUnchanged(array $current, array $input): bool
    {
        foreach ($input as $column => $value) {
            if ((string) ($current[$column] ?? '') !== (string) ($value ?? '')) {
                return false;
            }
        }

        return true;
    }
}
