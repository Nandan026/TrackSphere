<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Append-only audit trail.
 */
class AuditLogModel extends BaseModel
{
    protected $table         = 'audit_logs';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'organization_id', 'user_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    /**
     * Records an action for the current user/request context.
     *
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function record(string $action, string $entityType, ?int $entityId = null, ?array $old = null, ?array $new = null): bool
    {
        $request = service('request');
        $user    = function_exists('current_user') ? current_user() : null;

        return (bool) $this->insert([
            'organization_id' => $user['organization_id'] ?? null,
            'user_id'         => $user['id'] ?? null,
            'action'          => $action,
            'entity_type'     => $entityType,
            'entity_id'       => $entityId,
            'old_values'      => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            'new_values'      => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'ip_address'      => method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null,
            'user_agent'      => method_exists($request, 'getUserAgent') ? mb_substr((string) $request->getUserAgent(), 0, 500) : null,
        ]);
    }
}
