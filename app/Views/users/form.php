<?php
/**
 * User create / edit form.
 *
 * @var string                     $mode
 * @var array<string, mixed>       $row
 * @var list<array<string, mixed>> $roles
 * @var bool                       $isSelf
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('users/' . (int) $row['id']) : site_url('users');
$v      = static fn (string $key, string $default = ''): string => (string) old($key, $row[$key] ?? $default);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit user' : 'Add user' ?></h2>
        <p><?= $isEdit ? esc($row['email']) : 'Create a login and set its initial password.' ?></p>
    </div>
    <a href="<?= site_url('users') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to users</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate autocomplete="off">
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-person"></i> Profile &amp; access</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="first_name" class="form-label">First name *</label>
                    <input type="text" name="first_name" id="first_name" class="form-control" maxlength="100" required value="<?= esc($v('first_name'), 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label for="last_name" class="form-label">Last name</label>
                    <input type="text" name="last_name" id="last_name" class="form-control" maxlength="100" value="<?= esc($v('last_name'), 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">Email *</label>
                    <input type="email" name="email" id="email" class="form-control" maxlength="190" required value="<?= esc($v('email'), 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" name="phone" id="phone" class="form-control" maxlength="30" value="<?= esc($v('phone'), 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label for="role_id" class="form-label">Role *</label>
                    <select name="role_id" id="role_id" class="form-select" required <?= $isSelf ? 'disabled' : '' ?>>
                        <option value="">— Select a role —</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>" <?= $v('role_id') === (string) $role['id'] ? 'selected' : '' ?>><?= esc($role['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isSelf): ?>
                        <div class="form-text">You cannot change your own role.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select" <?= $isSelf ? 'disabled' : '' ?>>
                        <option value="active" <?= $v('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $v('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <?php if ($isSelf): ?>
                        <div class="form-text">You cannot deactivate your own account.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-key"></i> Password</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="password" class="form-label">Password<?= $isEdit ? '' : ' *' ?></label>
                    <div class="input-group">
                        <input type="password" name="password" id="password" class="form-control" minlength="8" maxlength="72"
                               autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
                        <button type="button" class="btn btn-outline-primary" data-ts-toggle-password="#password" aria-label="Show or hide password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-text">
                        <?= $isEdit ? 'Leave blank to keep the current password. ' : '' ?>At least 8 characters with a letter and a number.
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="password_confirm" class="form-label">Confirm password<?= $isEdit ? '' : ' *' ?></label>
                    <input type="password" name="password_confirm" id="password_confirm" class="form-control" maxlength="72"
                           autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
                </div>
            </div>
            <p class="text-muted small mt-3 mb-0">No email is sent. Share the password with the user securely and ask them to change it after first sign-in.</p>
        </div>
    </section>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= site_url('users') ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Create user' ?></button>
    </div>
</form>

<?= $this->endSection() ?>
