<?php
/**
 * Users list.
 *
 * @var list<array<string, mixed>> $users
 * @var object|null                $pager
 * @var string                     $q
 * @var string                     $status
 * @var int                        $selfId
 */
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2>Users</h2>
        <p>People who can sign in to your organization and the role they hold.</p>
    </div>
    <a href="<?= site_url('users/create') ?>" class="btn btn-accent"><i class="bi bi-person-plus me-1"></i> Add User</a>
</div>

<section class="ts-card">
    <form method="get" action="<?= site_url('users') ?>" class="ts-filters">
        <div class="input-group" style="max-width: 360px;">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= esc($q, 'attr') ?>" class="form-control"
                   placeholder="Search name, email, phone, role" aria-label="Search users">
        </div>
        <select name="status" class="form-select" style="max-width: 160px;" aria-label="Status filter">
            <option value="">All statuses</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-soft"><i class="bi bi-funnel me-1"></i> Filter</button>
        <?php if ($q !== '' || $status !== ''): ?>
            <a href="<?= site_url('users') ?>" class="btn btn-outline-primary">Reset</a>
        <?php endif; ?>
    </form>

    <div class="ts-card__body ts-card__body--flush">
        <?php if (empty($users)): ?>
            <div class="ts-empty">
                <i class="bi bi-shield-lock"></i>
                <strong>No users found</strong>
                <?= ($q !== '' || $status !== '') ? 'Try a different search or filter.' : 'Add the first team member.' ?>
            </div>
        <?php else: ?>
            <div class="ts-table-wrap">
                <table class="ts-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Last login</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php $fullName = trim($u['first_name'] . ' ' . ($u['last_name'] ?? '')); ?>
                            <tr>
                                <td>
                                    <span class="ts-cell-strong"><?= esc($fullName) ?></span>
                                    <?php if ((int) $u['id'] === $selfId): ?><span class="ts-badge ts-badge--info">You</span><?php endif; ?>
                                    <span class="ts-cell-sub"><?= esc($u['email']) ?></span>
                                </td>
                                <td><?= esc($u['phone'] ?? '—') ?></td>
                                <td><?= esc($u['role_name'] ?? '—') ?></td>
                                <td><?= esc(format_datetime($u['last_login_at'] ?? null)) ?></td>
                                <td><span class="ts-badge ts-badge--<?= esc($u['status'], 'attr') ?>"><?= esc(ucfirst((string) $u['status'])) ?></span></td>
                                <td class="ts-actions">
                                    <?= view('partials/master_row_actions', [
                                        'base' => 'users', 'id' => $u['id'], 'status' => $u['status'],
                                        'label' => 'user', 'name' => $fullName,
                                        'canToggle' => (int) $u['id'] !== $selfId,
                                    ]) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= view('partials/pagination', ['pager' => $pager]) ?>
        <?php endif; ?>
    </div>
</section>

<?= $this->endSection() ?>
