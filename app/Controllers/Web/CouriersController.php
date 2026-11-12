<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Models\CourierPartnerModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Courier partners (courier_partners table).
 * Route permission: integrations.manage OR billing.manage
 *
 * Platform-wide couriers (organization_id NULL) are listed but read-only.
 */
class CouriersController extends MasterDataController
{
    protected string $module = 'couriers';

    private const PATH       = 'couriers';
    private const INTEGRATIONS = ['simulated', 'api', 'manual'];

    /**
     * Integration type codes: lookup_values (integration_type) with the constant as fallback.
     *
     * @return list<string>
     */
    private static function integrations(): array
    {
        return array_keys(lookup_options('integration_type', array_combine(self::INTEGRATIONS, self::INTEGRATIONS)));
    }

    public function index(): string
    {
        [$q, $status] = $this->listFilters();
        $orgId        = $this->orgId();

        $model = new CourierPartnerModel();
        $model->forOrg()
            ->search($q, ['courier_partners.name', 'courier_partners.code'])
            ->withStatus($status)
            ->orderBy('courier_partners.organization_id IS NULL', 'DESC', false)
            ->orderBy('courier_partners.name', 'ASC');

        $couriers = $model->paginate($this->perPage);

        return $this->renderPage('couriers/index', [
            'title'    => 'Courier Partners',
            'couriers' => $couriers,
            'orgId'    => $orgId,
            'pager'    => $model->pager,
            'q'        => $q,
            'status'   => $status,
        ]);
    }

    public function create(): string
    {
        return $this->renderPage('couriers/form', [
            'title'        => 'Add Courier',
            'mode'         => 'create',
            'courier'      => [],
            'integrations' => self::integrations(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $orgId = $this->orgId();
        $data  = $this->collect();

        if (! $this->validateData($data, $this->rules())) {
            return $this->validationFailed();
        }

        if ($error = $this->extraErrors($data)) {
            return $this->backWithErrors($error);
        }

        $model = new CourierPartnerModel();
        if ($this->valueTaken($model, 'code', (string) $data['code'], null, true)) {
            return $this->backWithErrors(['code' => 'This courier code is already in use.']);
        }

        try {
            if (! $model->insert($data + ['organization_id' => $orgId])) {
                throw new \RuntimeException('Courier could not be saved.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Courier create failed: ' . $e->getMessage());

            return $this->backWithErrors('The courier could not be saved. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Courier "' . $data['name'] . '" created.');
    }

    public function edit(int $id): string
    {
        return $this->renderPage('couriers/form', [
            'title'        => 'Edit Courier',
            'mode'         => 'edit',
            'courier'      => $this->ownedRow(new CourierPartnerModel(), $id),
            'integrations' => self::integrations(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $this->ownedRow(new CourierPartnerModel(), $id);
        $data = $this->collect();

        if (! $this->validateData($data, $this->rules())) {
            return $this->validationFailed();
        }

        if ($error = $this->extraErrors($data)) {
            return $this->backWithErrors($error);
        }

        $model = new CourierPartnerModel();
        if ($this->valueTaken($model, 'code', (string) $data['code'], $id, true)) {
            return $this->backWithErrors(['code' => 'This courier code is already in use.']);
        }

        try {
            if (! $model->update($id, $data)) {
                throw new \RuntimeException('Courier could not be updated.');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Courier update failed: ' . $e->getMessage());

            return $this->backWithErrors('The courier could not be updated. Please try again.');
        }

        return $this->redirectTo(self::PATH, 'success', 'Courier "' . $data['name'] . '" updated.');
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->changeStatus(new CourierPartnerModel(), $id, 'inactive', self::PATH, 'courier');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->changeStatus(new CourierPartnerModel(), $id, 'active', self::PATH, 'courier');
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $type = strtolower($this->input('integration_type'));
        $json = $this->input('configuration');

        return [
            'name'             => $this->input('name'),
            'code'             => strtoupper($this->input('code')),
            'integration_type' => in_array($type, self::integrations(), true) ? $type : 'simulated',
            'api_base_url'     => $this->inputOrNull('api_base_url'),
            'supports_cod'     => $this->inputBool('supports_cod'),
            'supports_reverse' => $this->inputBool('supports_reverse'),
            'status'           => $this->inputStatus(),
            'configuration'    => $json === '' ? null : $json,
        ];
    }

    /**
     * @return array<string, array{label: string, rules: string}>
     */
    private function rules(): array
    {
        return [
            'name'             => ['label' => 'Name', 'rules' => 'required|min_length[2]|max_length[150]'],
            'code'             => ['label' => 'Code', 'rules' => 'required|max_length[50]|regex_match[/^[A-Z0-9_\-]+$/]'],
            'integration_type' => ['label' => 'Integration type', 'rules' => 'required|in_list[' . implode(',', self::integrations()) . ']'],
            'api_base_url'     => ['label' => 'API base URL', 'rules' => 'permit_empty|max_length[255]|valid_url_strict[http,https]'],
            'status'           => ['label' => 'Status', 'rules' => 'required|in_list[active,inactive]'],
            'configuration'    => ['label' => 'Configuration', 'rules' => 'permit_empty|max_length[10000]'],
        ];
    }

    /**
     * Cross-field checks that the rule syntax cannot express.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function extraErrors(array $data): array
    {
        $errors = [];

        if ($data['integration_type'] === 'api' && empty($data['api_base_url'])) {
            $errors['api_base_url'] = 'API base URL is required for API integrations.';
        }

        if ($data['configuration'] !== null) {
            $decoded = json_decode((string) $data['configuration'], true);
            if (! is_array($decoded)) {
                $errors['configuration'] = 'Configuration must be a valid JSON object.';
            }
        }

        return $errors;
    }
}
