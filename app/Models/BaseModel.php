<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Base model for all TrackSphere tables.
 *
 * Multi-tenancy: call ->forOrg() (defaults to the logged-in user's organization)
 * before any read/update/delete that is driven by user input. Tables without
 * their own organization_id declare $parentTable/$parentKey so the scope is
 * applied through the parent row (e.g. shipment_events -> shipments).
 */
abstract class BaseModel extends Model
{
    protected $returnType     = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields  = true;
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $dateFormat     = 'datetime';
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';
    protected $deletedField   = 'deleted_at';

    /** Column holding the tenant id on this table. */
    protected string $orgColumn = 'organization_id';

    /** When true, rows with a NULL organization (system/global rows) are also visible to every org. */
    protected bool $allowGlobalRows = false;

    /** For child tables with no organization_id: parent table + FK column on this table. */
    protected ?string $parentTable = null;
    protected ?string $parentKey   = null;

    /**
     * Restricts the query to one organization. If no organization can be
     * resolved, the query matches nothing (fail closed).
     *
     * @return $this
     */
    public function forOrg(?int $organizationId = null)
    {
        $organizationId ??= function_exists('current_org_id') ? current_org_id() : null;

        if ($organizationId === null) {
            $this->where('1 = 0', null, false);

            return $this;
        }

        if ($this->parentTable !== null && $this->parentKey !== null) {
            $this->select($this->table . '.*')
                ->join($this->parentTable, $this->parentTable . '.id = ' . $this->table . '.' . $this->parentKey)
                ->where($this->parentTable . '.' . $this->orgColumn, $organizationId);

            return $this;
        }

        $column = $this->table . '.' . $this->orgColumn;

        if ($this->allowGlobalRows) {
            $this->groupStart()
                ->where($column, $organizationId)
                ->orWhere($column, null)
                ->groupEnd();
        } else {
            $this->where($column, $organizationId);
        }

        return $this;
    }

    /**
     * Finds one row by id inside the organization scope.
     *
     * @return array<string, mixed>|object|null
     */
    public function findForOrg(int $id, ?int $organizationId = null)
    {
        return $this->forOrg($organizationId)->where($this->table . '.' . $this->primaryKey, $id)->first();
    }

    /**
     * Applies a free-text LIKE search over the given columns.
     *
     * @param list<string> $columns
     *
     * @return $this
     */
    public function search(?string $term, array $columns)
    {
        $term = trim((string) $term);
        if ($term === '' || $columns === []) {
            return $this;
        }

        $this->groupStart();
        foreach ($columns as $i => $column) {
            $i === 0 ? $this->like($column, $term) : $this->orLike($column, $term);
        }
        $this->groupEnd();

        return $this;
    }

    /**
     * @return $this
     */
    public function withStatus(?string $status)
    {
        if ($status !== null && $status !== '') {
            $this->where($this->table . '.status', $status);
        }

        return $this;
    }
}
