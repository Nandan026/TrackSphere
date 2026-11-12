<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Platform-wide dropdown values grouped by category (lookup_values).
 * Not tenant-scoped: there is no organization_id on this table.
 */
class LookupValueModel extends BaseModel
{
    /** Categories the application reads (more can be typed in the form). */
    public const KNOWN_CATEGORIES = [
        'payment_mode', 'failure_reason', 'return_reason', 'ndr_customer_response',
        'vehicle_type', 'verification_method', 'receiver_relation', 'integration_type',
        'customer_type', 'address_type', 'delivery_outcome',
    ];

    protected $table         = 'lookup_values';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'category', 'code', 'label', 'meaning', 'sort_order', 'status',
    ];

    protected $validationRules = [
        'category'   => 'required|max_length[60]|regex_match[/^[a-z0-9_]+$/]',
        'code'       => 'required|max_length[60]|regex_match[/^[a-z0-9_]+$/]',
        'label'      => 'required|min_length[1]|max_length[150]',
        'meaning'    => 'permit_empty|max_length[500]',
        'sort_order' => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[100000]',
        'status'     => 'required|in_list[active,inactive]',
    ];

    protected $validationMessages = [
        'category' => ['regex_match' => 'Category may only contain lowercase letters, numbers and underscores.'],
        'code'     => ['regex_match' => 'Code may only contain lowercase letters, numbers and underscores.'],
    ];

    /**
     * @return $this
     */
    public function forCategory(string $category)
    {
        $this->where($this->table . '.category', $category);

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
     * Active codes of a category as code => label, in display order.
     *
     * @return array<string, string>
     */
    public function options(string $category): array
    {
        $options = [];
        foreach ($this->definitions($category) as $row) {
            $options[(string) $row['code']] = (string) $row['label'];
        }

        return $options;
    }

    /**
     * Full rows of a category ordered by sort_order (active only by default).
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(string $category, bool $activeOnly = true): array
    {
        $this->forCategory($category);
        if ($activeOnly) {
            $this->activeOnly();
        }

        return $this->orderBy($this->table . '.sort_order', 'ASC')
            ->orderBy($this->table . '.id', 'ASC')
            ->findAll();
    }

    /**
     * Distinct categories that currently have values.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $rows = $this->builder()->select('category')->distinct()->orderBy('category', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $r): string => (string) $r['category'], $rows);
    }
}
