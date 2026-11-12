<?php
/**
 * Delivery agent create / edit form.
 *
 * @var string                     $mode
 * @var array<string, mixed>       $agent
 * @var list<array<string, mixed>> $users
 * @var list<string>               $vehicleTypes
 */
$isEdit = $mode === 'edit';
$action = $isEdit ? site_url('agents/' . (int) $agent['id']) : site_url('agents');
$v      = static fn (string $key, string $default = ''): string => (string) old($key, $agent[$key] ?? $default);
?>
<?= $this->extend('layouts/main') ?>

<?= $this->section('content') ?>

<div class="ts-page-head">
    <div>
        <h2><?= $isEdit ? 'Edit agent' : 'Add agent' ?></h2>
        <p><?= $isEdit ? esc($agent['agent_code']) . ' · ' . esc($agent['full_name']) : 'Register a delivery agent.' ?></p>
    </div>
    <a href="<?= site_url('agents') ?>" class="btn btn-soft"><i class="bi bi-arrow-left me-1"></i> Back to agents</a>
</div>

<form action="<?= $action ?>" method="post" data-ts-once novalidate>
    <?= csrf_field() ?>

    <section class="ts-card mb-3">
        <div class="ts-card__head"><h3 class="ts-card__title"><i class="bi bi-person-badge"></i> Agent details</h3></div>
        <div class="ts-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="full_name" class="form-label">Full name *</label>
                    <input type="text" name="full_name" id="full_name" class="form-control" maxlength="150" required value="<?= esc($v('full_name'), 'attr') ?>">
                </div>
                <div class="col-md-3">
                    <label for="agent_code" class="form-label">Agent code<?= $isEdit ? ' *' : '' ?></label>
                    <input type="text" name="agent_code" id="agent_code" class="form-control ts-mono text-uppercase" maxlength="50"
                           value="<?= esc($v('agent_code'), 'attr') ?>" <?= $isEdit ? 'required' : 'placeholder="Auto-generated if empty"' ?>>
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="active" <?= $v('status', 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $v('status', 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="phone" class="form-label">Phone *</label>
                    <input type="text" name="phone" id="phone" class="form-control" maxlength="30" required value="<?= esc($v('phone'), 'attr') ?>">
                </div>
                <div class="col-md-4">
                    <label for="vehicle_type" class="form-label">Vehicle type</label>
                    <input type="text" name="vehicle_type" id="vehicle_type" class="form-control" maxlength="50" list="vehicleTypes" value="<?= esc($v('vehicle_type'), 'attr') ?>">
                    <datalist id="vehicleTypes">
                        <?php foreach ($vehicleTypes as $type): ?>
                            <option value="<?= esc($type, 'attr') ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="col-md-4">
                    <label for="vehicle_number" class="form-label">Vehicle number</label>
                    <input type="text" name="vehicle_number" id="vehicle_number" class="form-control ts-mono text-uppercase" maxlength="30" value="<?= esc($v('vehicle_number'), 'attr') ?>">
                </div>
                <div class="col-md-6">
                    <label for="user_id" class="form-label">Linked user account</label>
                    <select name="user_id" id="user_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= $v('user_id') === (string) $u['id'] ? 'selected' : '' ?>>
                                <?= esc(trim($u['first_name'] . ' ' . ($u['last_name'] ?? '')) . ' (' . $u['email'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Lets the agent sign in and update delivery outcomes.</div>
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex gap-2 justify-content-end">
        <a href="<?= site_url('agents') ?>" class="btn btn-outline-primary">Cancel</a>
        <button type="submit" class="btn btn-accent"><i class="bi bi-check2 me-1"></i> <?= $isEdit ? 'Save changes' : 'Create agent' ?></button>
    </div>
</form>

<?= $this->endSection() ?>
