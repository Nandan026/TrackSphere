<?php
/**
 * Address chooser used by pickup locations and warehouses.
 * Either pick an existing address, or fill the inline fields (a new address row is created).
 *
 * Expects:
 *   $addressOptions list<array>  existing addresses of the organization
 *   $addr           array        current address values (edit) or []
 *   $isEdit         bool
 */
$isEdit = $isEdit ?? false;
$addr   = $addr ?? [];
$chosen = (string) old('address_id', '');
?>
<div class="mb-3">
    <label for="address_id" class="form-label">Use an existing address</label>
    <select name="address_id" id="address_id" class="form-select">
        <option value="">
            <?= $isEdit ? '— Keep current address (edit details below) —' : '— Enter a new address below —' ?>
        </option>
        <?php foreach ($addressOptions as $opt): ?>
            <option value="<?= (int) $opt['id'] ?>" <?= $chosen === (string) $opt['id'] ? 'selected' : '' ?>>
                <?= esc($opt['contact_name'] . ' — ' . $opt['address_line1'] . ', ' . $opt['city'] . ' ' . $opt['postal_code']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <div class="form-text">
        When an existing address is selected, the fields below are ignored.
        <?= $isEdit ? 'Editing the fields below saves a new address record, so past shipments keep their original address.' : '' ?>
    </div>
</div>

<?= view('partials/address_fields', ['addr' => $addr, 'requireContact' => true]) ?>
