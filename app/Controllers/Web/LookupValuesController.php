<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Models\LookupValueModel;
use App\Services\Catalog\StatusCatalogService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Lookup values (lookup_values): platform-wide dropdown options such as
 * failure reasons, vehicle types or return reasons.
 *
 * Route permission: users.manage (see Config\Routes). The table has no
 * organization_id, so changes apply to every organization.
 * "Delete" deactivates the row so stored values keep resolving their label.
 */
class LookupValuesController extends BaseController
{
    protected string $module = 'lookup_values';

    private const PATH     = 'lookup-values';
    private const PER_PAGE = 25;

    public function index(): string
    {
        $category = $this->query('category');
        $status   = $this->query('status');
        $q        = mb_substr($this->query('q'), 0, 100);

        $model = new LookupValueModel();
        if ($category !== '') {
            $model->forCategory($category);
        }
        if (in_array($status, ['active', 'inactive'], true)) {
            $model->withStatus($status);
        } else {
            $status = '';
        }
        $model->search($q, ['lookup_values.code', 'lookup_values.label', 'lookup_values.meaning'])
            ->orderBy('lookup_values.category', 'ASC')
            ->orderBy('lookup_values.sort_order', 'ASC')
            ->orderBy('lookup_values.id', 'ASC');

        $rows = $model->paginate(self::PER_PAGE);

        return $this->renderPage('lookup_values/index', [
            'title'      => 'Lookup Values',
            'rows'       => $rows,
            'pager'      => $model->pager,
            'categories' => $this->categoryChoices(),
            'filter'     => ['category' => $category, 'status' => $status, 'q' => $q],
        ]);
    }

    public function create(): string
    {
        $category = $this->query('category');

        return $this->renderPage('lookup_values/form', $this->formData('Add Lookup Value', 'create', [
            'category'   => $category,
            'sort_order' => $this->nextSortOrder($category),
            'status'     => 'active',
        ]));
    }

    public function store(): RedirectResponse
    {
        $data  = $this->collect(true);
        $model = new LookupValueModel();

        if ($this->exists($data['category'], $data['code'])) {
            return $this->backWithErrors(['code' => 'This code already exists in category "' . $data['category'] . '".']);
        }

        if (! $model->insert($data)) {
            return $this->backWithErrors($model->errors() ?: 'The value could not be saved. Please try again.');
        }

        (new StatusCatalogService())->clearCache();

        return $this->done($data['category'], 'Value "' . $data['label'] . '" added to ' . $data['category'] . '.');
    }

    public function edit(int $id): string
    {
        return $this->renderPage('lookup_values/form', $this->formData('Edit Lookup Value', 'edit', $this->row($id)));
    }

    public function update(int $id): RedirectResponse
    {
        $row   = $this->row($id);
        $data  = $this->collect(false);
        $model = new LookupValueModel();

        // category + code are stored on shipments/cases, so they are never changed here.
        if (! $model->update($id, $data)) {
            return $this->backWithErrors($model->errors() ?: 'The value could not be updated. Please try again.');
        }

        (new StatusCatalogService())->clearCache();

        return $this->done((string) $row['category'], 'Value "' . $data['label'] . '" updated.');
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

        if (($row['status'] ?? '') !== $status && ! (new LookupValueModel())->update($id, ['status' => $status])) {
            return $this->done((string) $row['category'], 'Could not update the value. Please try again.', 'error');
        }

        (new StatusCatalogService())->clearCache();

        return $this->done((string) $row['category'], $status === 'active' ? 'Value activated.' : 'Value deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = (new LookupValueModel())->find($id);

        if (! is_array($row)) {
            throw PageNotFoundException::forPageNotFound('The requested value was not found.');
        }

        return $row;
    }

    private function exists(string $category, string $code): bool
    {
        return (new LookupValueModel())->forCategory($category)->where('lookup_values.code', $code)->countAllResults() > 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function formData(string $title, string $mode, array $row): array
    {
        return [
            'title'      => $title,
            'mode'       => $mode,
            'row'        => $row,
            'categories' => $this->categoryChoices(),
        ];
    }

    /**
     * @return list<string>
     */
    private function categoryChoices(): array
    {
        $categories = LookupValueModel::KNOWN_CATEGORIES;

        try {
            $categories = array_merge($categories, (new LookupValueModel())->categories());
        } catch (\Throwable $e) {
            log_message('warning', 'Could not list lookup categories: ' . $e->getMessage());
        }

        $categories = array_values(array_unique($categories));
        sort($categories);

        return $categories;
    }

    private function nextSortOrder(string $category): int
    {
        if ($category === '') {
            return 10;
        }

        $row = (new LookupValueModel())->selectMax('sort_order', 'max_order')->forCategory($category)->first();

        return (int) ($row['max_order'] ?? 0) + 10;
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(bool $withKeys): array
    {
        $meaning = $this->post('meaning');

        $data = [
            'label'      => $this->post('label'),
            'meaning'    => $meaning === '' ? null : $meaning,
            'sort_order' => (int) $this->post('sort_order'),
            'status'     => strtolower($this->post('status')) === 'inactive' ? 'inactive' : 'active',
        ];

        if ($withKeys) {
            $data['category'] = strtolower($this->post('category'));
            $data['code']     = strtolower($this->post('code'));
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

    private function done(string $category, string $message, string $type = 'success'): RedirectResponse
    {
        $url = self::PATH . ($category !== '' ? '?category=' . rawurlencode($category) : '');

        return redirect()->to(site_url($url))->with($type, $message);
    }
}
