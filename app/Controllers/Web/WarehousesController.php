<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\WarehouseModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Warehouses / hubs.
 * Route permission: pickups.manage
 */
class WarehousesController extends MasterDataController
{
    protected string $module = 'warehouses';

    private const PATH = 'warehouses';

    public function index(): string
    {
        [$q, $status] = $this->listFilters();

        $model = new WarehouseModel();
        $model->forOrg()
            ->withAddress()
            ->search($q, ['warehouses.name', 'warehouses.warehouse_code', 'a.city', 'a.postal_code'])
            ->withStatus($status)
            ->orderBy('warehouses.id', 'DESC');

        $warehouses = $model->paginate($this->perPage);

        return $this->renderPage('warehouses/index', [
            'title'      => 'Warehouses',
            'warehouses' => $warehouses,
            'pager'      => $model->pager,
            'q'          => $q,
            'status'     => $status,
        ]);
    }

    public function create(): string
    {
        return $this->renderPage('warehouses/form', [
            'title'          => 'Add Warehouse',
            'mode'           => 'create',
            'warehouse'      => [],
            'addr'           => [],
            'addressOptions' => $this->addressOptions(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $orgId = $this->orgId();
        $data  = $this->collect();
        $model = new WarehouseModel();

        if (! $this->validateData($data, $this->rules(false))) {
            return $this->validationFailed();
        }

        if ($data['warehouse_code'] === null) {
            $data['warehouse_code'] = $this->generateCode($model, 'warehouse_code', 'WH-');
        } elseif ($this->valueTaken($model, 'warehouse_code', $data['warehouse_code'])) {
            return $this->backWithErrors(['warehouse_code' => 'This warehouse code is already in use.']);
        }

        $db = $this->db();
        $db->transBegin();

        try {
            [$addressId, $errors] = $this->resolveAddress($orgId, 'warehouse', null);
            if ($addressId === null) {
                $db->transRollback();

                return $this->backWithErrors($errors);
            }

            if (! $model->insert($data + ['organization_id' => $orgId, 'address_id' => $addressId])) {
                throw new \RuntimeException('Warehouse could not be saved.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Warehouse create failed: ' . $e->getMessage());

            return $this->backWithErrors('The warehouse could not be saved. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Warehouse "' . $data['name'] . '" created.');
    }

    public function edit(int $id): string
    {
        $warehouse = $this->ownedRow(new WarehouseModel(), $id);

        return $this->renderPage('warehouses/form', [
            'title'          => 'Edit Warehouse',
            'mode'           => 'edit',
            'warehouse'      => $warehouse,
            'addr'           => $this->findAddress((int) $warehouse['address_id']) ?? [],
            'addressOptions' => $this->addressOptions(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $orgId     = $this->orgId();
        $warehouse = $this->ownedRow(new WarehouseModel(), $id);
        $data      = $this->collect();
        $model     = new WarehouseModel();

        if (! $this->validateData($data, $this->rules(true))) {
            return $this->validationFailed();
        }

        if ($this->valueTaken($model, 'warehouse_code', (string) $data['warehouse_code'], $id)) {
            return $this->backWithErrors(['warehouse_code' => 'This warehouse code is already in use.']);
        }

        $db = $this->db();
        $db->transBegin();

        try {
            [$addressId, $errors] = $this->resolveAddress($orgId, 'warehouse', $warehouse);
            if ($addressId === null) {
                $db->transRollback();

                return $this->backWithErrors($errors);
            }

            if (! $model->update($id, $data + ['address_id' => $addressId])) {
                throw new \RuntimeException('Warehouse could not be updated.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Warehouse update failed: ' . $e->getMessage());

            return $this->backWithErrors('The warehouse could not be updated. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Warehouse "' . $data['name'] . '" updated.');
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->changeStatus(new WarehouseModel(), $id, 'inactive', self::PATH, 'warehouse');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->changeStatus(new WarehouseModel(), $id, 'active', self::PATH, 'warehouse');
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $code = strtoupper($this->input('warehouse_code'));

        return [
            'name'           => $this->input('name'),
            'warehouse_code' => $code === '' ? null : $code,
            'status'         => $this->inputStatus(),
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private function rules(bool $codeRequired): array
    {
        return [
            'name'           => ['label' => 'Name', 'rules' => 'required|min_length[2]|max_length[150]'],
            'warehouse_code' => ['label' => 'Warehouse code', 'rules' => ($codeRequired ? 'required' : 'permit_empty') . '|max_length[50]|regex_match[/^[A-Z0-9_\-]+$/]'],
            'status'         => ['label' => 'Status', 'rules' => 'required|in_list[active,inactive]'],
        ];
    }
}
