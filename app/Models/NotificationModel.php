<?php

declare(strict_types=1);

namespace App\Models;

class NotificationModel extends BaseModel
{
    protected $table         = 'notifications';
    protected $primaryKey    = 'id';
    protected $updatedField  = '';
    protected $allowedFields = [
        'organization_id', 'user_id', 'shipment_id', 'channel', 'recipient', 'subject',
        'message', 'status', 'sent_at', 'failure_reason',
    ];

    /**
     * @return $this
     */
    public function forUser(int $userId)
    {
        $this->where($this->table . '.user_id', $userId);

        return $this;
    }

    public function unreadCount(int $userId): int
    {
        return $this->builder()
            ->where('user_id', $userId)
            ->where('channel', 'database')
            ->where('status !=', 'read')
            ->countAllResults();
    }
}
