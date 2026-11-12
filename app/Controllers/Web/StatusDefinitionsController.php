<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\StatusDefinitionModel;
use App\Services\Catalog\StatusCatalogService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Status glossary (status_definitions): platform-wide status codes with a
 * plain-English meaning and badge colour.
 *
 * Route permission: users.manage (see Config\Routes). The table has no
 * organization_id, so changes apply to every organization.
 * "Delete" deactivates the row so history keeps resolving its label.
 */
class StatusDefinitionsController extends BaseController
{
    protected string $module = 'status_definitions';

    private const PATH     = 'status-definitions';
    private const PER_PAGE = 25;

    public function index(): string
    {
        $module = $this->query('module');
        $status = $this->query('status');
        $q      = mb_substr($this->query('q'), 0, 100);

        $model = new StatusDefinitionModel();
        if ($module !== '') {
            $model->forModule($module);
        }
        if (in_array($status, ['active', 'inactive'], true)) {
            $model->withStatus($status);
        } else {
            $status = '';
        }
        $model->search($q, ['status_definitions.code', 'status_definitions.label', 'status_definitions.meaning'])
            ->orderBy('status_definitions.module', 'ASC')
            ->orderBy('status_definitions.sort_order', 'ASC')
            ->orderBy('status_definitions.id', 'ASC');

        $rows = $model->paginate(self::PER_PAGE);

        return $this->renderPage('status_definitions/index', [
            'title'   => 'Status Glossary',
            'rows'    => $rows,
            'pager'   => $model->pager,
            'modules' => $this->moduleChoices(),
            'module'  => 'status_definitions',
            'filter'  => ['module' => $module, 'status' => $status, 'q' => $q],
        ]);
    }

    public function create(): string
    {
        $module = $this->query('module');

        return $this->renderPage('status_definitions/form', $this->formData('Add Status', 'create', [
            'module'      => $module,
            'badge_class' => 'secondary',
            'sort_order'  => $this->nextSortOrder($module),
            'is_terminal' => 0,
            'status'      => 'active',
        ]));
    }

    public function store(): RedirectResponse
    {
        $data  = $this->collect(true);
        $model = new StatusDefinitionModel();

        if ($this->exists($data['module'], $data['code'])) {
            return $this->backWithErrors(['code' => 'This code already exists in module "' . $data['module'] . '".']);
        }

        if (! $model->insert($data)) {
            return $this->backWithErrors($model->errors() ?: 'The status could not be saved. Please try again.');
        }

        (new StatusCatalogService())->clearCache();

        return $this->done($data['module'], 'Status "' . $data['label'] . '" added to ' . $data['module'] . '.');
    }

    public function edit(int $id): string
    {
        return $this->renderPage('status_definitions/form', $this->formData('Edit Status', 'edit', $this->row($id)));
    }

    public function update(int $id): RedirectResponse
    {
        $row   = $this->row($id);
        $data  = $this->collect(false);
        $model = new StatusDefinitionModel();

        // module + code identify the status in code/history, so they are never changed here.
        if (! $model->update($id, $data)) {
            return $this->backWithErrors($model->errors() ?: 'The status could not be updated. Please try again.');
        }

        (new StatusCatalogService())->clearCache();

        return $this->done((string) $row['module'], 'Status "' . $data['label'] . '" updated.');
    }

    public function delete(int $id): RedirectResponse
    {
        return $this->changeStatus($id, 'inactive');
    }

    public function activate(int $id): RedirectResponse
    {
        return $this->changeStatus($id, 'active');
    }

    // ------------------------------------------------------------------

    private function changeStatus(int $id, string $status): RedirectResponse
    {
        $row = $this->row($id);

        if (($row['status'] ?? '') !== $status && ! (new StatusDefinitionModel())->update($id, ['status' => $status])) {
            return $this->done((string) $row['module'], 'Could not update the status. Please try again.', 'error');
        }

        (new StatusCatalogService())->clearCache();

        return $this->done((string) $row['module'], $status === 'active' ? 'Status activated.' : 'Status deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = (new StatusDefinitionModel())->find($id);

        if (! is_array($row)) {
            throw PageNotFoundException::forPageNotFound('The requested status was not found.');
        }

        return $row;
    }

    private function exists(string $module, string $code): bool
    {
        return (new StatusDefinitionModel())->forModule($module)->where('status_definitions.code', $code)->countAllResults() > 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function formData(string $title, string $mode, array $row): array
    {
        return [
            'title'        => $title,
            'mode'         => $mode,
            'row'          => $row,
            'modules'      => $this->moduleChoices(),
            'badgeClasses' => StatusDefinitionModel::BADGE_CLASSES,
            'module'       => 'status_definitions',
        ];
    }

    /**
     * @return list<string>
     */
    private function moduleChoices(): array
    {
        $modules = StatusDefinitionModel::KNOWN_MODULES;

        try {
            $modules = array_merge($modules, (new StatusDefinitionModel())->modules());
        } catch (\Throwable $e) {
            log_message('warning', 'Could not list status modules: ' . $e->getMessage());
        }

        $modules = array_values(array_unique($modules));
        sort($modules);

        return $modules;
    }

    private function nextSortOrder(string $module): int
    {
        if ($module === '') {
            return 10;
        }

        $row = (new StatusDefinitionModel())->selectMax('sort_order', 'max_order')->forModule($module)->first();

        return (int) ($row['max_order'] ?? 0) + 10;
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(bool $withKeys): array
    {
        $data = [
            'label'       => $this->post('label'),
            'meaning'     => $this->post('meaning'),
            'badge_class' => strtolower($this->post('badge_class')),
            'sort_order'  => (int) $this->post('sort_order'),
            'is_terminal' => $this->post('is_terminal') === '1' ? 1 : 0,
            'status'      => strtolower($this->post('status')) === 'inactive' ? 'inactive' : 'active',
        ];

        if ($withKeys) {
            $data['module'] = strtolower($this->post('module'));
            $data['code']   = strtolower($this->post('code'));
        }

        return $data;
    }

    private function query(string $key): string
    {
        $value = $this->request->getGet($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function post(string $key): string
    {
        $value = $this->request->getPost($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, string>|string $errors
     */
    private function backWithErrors($errors): RedirectResponse
    {
        return redirect()->back()->withInput()->with('errors', (array) $errors);
    }

    private function done(string $module, string $message, string $type = 'success'): RedirectResponse
    {
        $url = self::PATH . ($module !== '' ? '?module=' . rawurlencode($module) : '');

        return redirect()->to(site_url($url))->with($type, $message);
    }
}
