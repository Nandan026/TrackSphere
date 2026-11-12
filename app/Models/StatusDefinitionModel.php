<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Platform-wide glossary of status codes (status_definitions).
 * Not tenant-scoped: there is no organization_id on this table.
 */
class StatusDefinitionModel extends BaseModel
{
    public const BADGE_CLASSES = ['success', 'info', 'primary', 'warning', 'danger', 'secondary', 'dark'];

    /** Status groups the application knows about (more can be typed in the form). */
    public const KNOWN_MODULES = [
        'shipments', 'pickups', 'delivery', 'ndr', 'returns',
        'invoices', 'cod_remittances', 'record',
    ];

    protected $table         = 'status_definitions';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'module', 'code', 'label', 'meaning', 'badge_class', 'sort_order', 'is_terminal', 'status',
    ];

    protected $validationRules = [
        'module'      => 'required|max_length[40]|regex_match[/^[a-z0-9_]+$/]',
        'code'        => 'required|max_length[40]|regex_match[/^[a-z0-9_]+$/]',
        'label'       => 'required|min_length[2]|max_length[100]',
        'meaning'     => 'required|min_length[3]|max_length[500]',
        'badge_class' => 'required|in_list[success,info,primary,warning,danger,secondary,dark]',
        'sort_order'  => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[100000]',
        'is_terminal' => 'permit_empty|in_list[0,1]',
        'status'      => 'required|in_list[active,inactive]',
    ];

    protected $validationMessages = [
        'module' => ['regex_match' => 'Module may only contain lowercase letters, numbers and underscores.'],
        'code'   => ['regex_match' => 'Code may only contain lowercase letters, numbers and underscores.'],
    ];

    /**
     * @return $this
     */
    public function forModule(string $module)
    {
        $this->where($this->table . '.module', $module);

        return $this;
    }

    /**
     * @return $this
     */
    public function activeOnly()
    {
        $this->where($this->table . '.status', 'active');

        return $this;
    }

    /**
     * Active codes of a module as code => label, in display order.
     *
     * @return array<string, string>
     */
    public function optionsForModule(string $module): array
    {
        $options = [];
        foreach ($this->definitionsForModule($module) as $row) {
            $options[(string) $row['code']] = (string) $row['label'];
        }

        return $options;
    }

    /**
     * Full active rows of a module ordered by sort_order.
     *
     * @return list<array<string, mixed>>
     */
    public function definitionsForModule(string $module, bool $activeOnly = true): array
    {
        $this->forModule($module);
        if ($activeOnly) {
            $this->activeOnly();
        }

        return $this->orderBy($this->table . '.sort_order', 'ASC')
            ->orderBy($this->table . '.id', 'ASC')
            ->findAll();
    }

    /**
     * Distinct module keys that currently have definitions.
     *
     * @return list<string>
     */
    public function modules(): array
    {
        $rows = $this->builder()->select('module')->distinct()->orderBy('module', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $r): string => (string) $r['module'], $rows);
    }
}
