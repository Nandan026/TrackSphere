<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\PackageModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Standard package types (dimensions + default weight).
 * Route permission: shipments.update
 */
class PackagesController extends MasterDataController
{
    protected string $module = 'packages';

    private const PATH = 'packages';

    public function index(): string
    {
        [$q, $status] = $this->listFilters();

        $model = new PackageModel();
        $model->forOrg()
            ->search($q, ['packages.package_name', 'packages.package_code'])
            ->withStatus($status)
            ->orderBy('packages.package_name', 'ASC');

        $packages = $model->paginate($this->perPage);

        return $this->renderPage('packages/index', [
            'title'    => 'Packages',
            'packages' => $packages,
            'pager'    => $model->pager,
            'q'        => $q,
            'status'   => $status,
        ]);
    }

    public function create(): string
    {
        return $this->renderPage('packages/form', [
            'title'   => 'Add Package',
            'mode'    => 'create',
            'package' => [],
        ]);
    }

    public function store(): RedirectResponse
    {
        $orgId = $this->orgId();
        $data  = $this->collect();
        $model = new PackageModel();

        if (! $this->validateData($data, $this->rules())) {
            return $this->validationFailed();
        }

        if ($this->valueTaken($model, 'package_code', (string) $data['package_code'])) {
            return $this->backWithErrors(['package_code' => 'This package code is already in use.']);
        }

        try {
            if (! $model->insert($data + ['organization_id' => $orgId])) {
                throw new \RuntimeException('Package could not be saved.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Package create failed: ' . $e->getMessage());

            return $this->backWithErrors('The package could not be saved. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Package "' . $data['package_name'] . '" created.');
    }

    public function edit(int $id): string
    {
        return $this->renderPage('packages/form', [
            'title'   => 'Edit Package',
            'mode'    => 'edit',
            'package' => $this->ownedRow(new PackageModel(), $id),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $this->ownedRow(new PackageModel(), $id);
        $data  = $this->collect();
        $model = new PackageModel();

        if (! $this->validateData($data, $this->rules())) {
            return $this->validationFailed();
        }

        if ($this->valueTaken($model, 'package_code', (string) $data['package_code'], $id)) {
            return $this->backWithErrors(['package_code' => 'This package code is already in use.']);
        }

        try {
            if (! $model->update($id, $data)) {
                throw new \RuntimeException('Package could not be updated.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Package update failed: ' . $e->getMessage());

            return $this->backWithErrors('The package could not be updated. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Package "' . $data['package_name'] . '" updated.');
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->changeStatus(new PackageModel(), $id, 'inactive', self::PATH, 'package');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->changeStatus(new PackageModel(), $id, 'active', self::PATH, 'package');
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        return [
            'package_name'      => $this->input('package_name'),
            'package_code'      => strtoupper($this->input('package_code')),
            'length_cm'         => $this->inputOrNull('length_cm'),
            'width_cm'          => $this->inputOrNull('width_cm'),
            'height_cm'         => $this->inputOrNull('height_cm'),
            'default_weight_kg' => $this->inputOrNull('default_weight_kg'),
            'status'            => $this->inputStatus(),
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private function rules(): array
    {
        $dim = 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[99999999]';

        return [
            'package_name'      => ['label' => 'Package name', 'rules' => 'required|min_length[2]|max_length[100]'],
            'package_code'      => ['label' => 'Package code', 'rules' => 'required|max_length[40]|regex_match[/^[A-Z0-9_\-]+$/]'],
            'length_cm'         => ['label' => 'Length (cm)', 'rules' => $dim],
            'width_cm'          => ['label' => 'Width (cm)', 'rules' => $dim],
            'height_cm'         => ['label' => 'Height (cm)', 'rules' => $dim],
            'default_weight_kg' => ['label' => 'Default weight (kg)', 'rules' => $dim],
            'status'            => ['label' => 'Status', 'rules' => 'required|in_list[active,inactive]'],
        ];
    }
}
