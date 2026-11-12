<?php
/**
 * Inline address fields (names are prefixed with "addr_").
 *
 * Expects:
 *   $addr           array   current values keyed by addresses column (may be empty)
 *   $requireContact bool    mark contact name / phone as required (default true)
 *   $addrTypes      array   (optional) when set, renders an address type select (name addr_type)
 */
$addr           = $addr ?? [];
$requireContact = $requireContact ?? true;
$req            = $requireContact ? ' required' : '';
$val            = static fn (string $field, string $column, string $default = ''): string => (string) old($field, $addr[$column] ?? $default);
?>
<div class="row g-3">
    <?php if (! empty($addrTypes)): ?>
        <div class="col-md-4">
            <label for="addr_type" class="form-label">Address type</label>
            <select name="addr_type" id="addr_type" class="form-select">
                <?php foreach ($addrTypes as $type): ?>
                    <option value="<?= esc($type, 'attr') ?>" <?= old('addr_type', 'billing') === $type ? 'selected' : '' ?>><?= esc(ucfirst($type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="w-100 d-none d-md-block m-0 p-0"></div>
    <?php endif; ?>

    <div class="col-md-6">
        <label for="addr_contact_name" class="form-label">Contact name<?= $requireContact ? ' *' : '' ?></label>
        <input type="text" name="addr_contact_name" id="addr_contact_name" class="form-control" maxlength="150"
               value="<?= esc($val('addr_contact_name', 'contact_name'), 'attr') ?>"<?= $req ?>>
    </div>
    <div class="col-md-6">
        <label for="addr_contact_phone" class="form-label">Contact phone<?= $requireContact ? ' *' : '' ?></label>
        <input type="text" name="addr_contact_phone" id="addr_contact_phone" class="form-control" maxlength="30"
               value="<?= esc($val('addr_contact_phone', 'contact_phone'), 'attr') ?>"<?= $req ?>>
    </div>
    <div class="col-12">
        <label for="addr_line1" class="form-label">Address line 1 *</label>
        <input type="text" name="addr_line1" id="addr_line1" class="form-control" maxlength="255"
               value="<?= esc($val('addr_line1', 'address_line1'), 'attr') ?>">
    </div>
    <div class="col-md-8">
        <label for="addr_line2" class="form-label">Address line 2</label>
        <input type="text" name="addr_line2" id="addr_line2" class="form-control" maxlength="255"
               value="<?= esc($val('addr_line2', 'address_line2'), 'attr') ?>">
    </div>
    <div class="col-md-4">
        <label for="addr_landmark" class="form-label">Landmark</label>
        <input type="text" name="addr_landmark" id="addr_landmark" class="form-control" maxlength="150"
               value="<?= esc($val('addr_landmark', 'landmark'), 'attr') ?>">
    </div>
    <div class="col-md-4">
        <label for="addr_city" class="form-label">City *</label>
        <input type="text" name="addr_city" id="addr_city" class="form-control" maxlength="100"
               value="<?= esc($val('addr_city', 'city'), 'attr') ?>">
    </div>
    <div class="col-md-4">
        <label for="addr_district" class="form-label">District</label>
        <input type="text" name="addr_district" id="addr_district" class="form-control" maxlength="100"
               value="<?= esc($val('addr_district', 'district'), 'attr') ?>">
    </div>
    <div class="col-md-4">
        <label for="addr_state" class="form-label">State *</label>
        <input type="text" name="addr_state" id="addr_state" class="form-control" maxlength="100"
               value="<?= esc($val('addr_state', 'state'), 'attr') ?>">
    </div>
    <div class="col-md-4">
        <label for="addr_postal_code" class="form-label">Postal code *</label>
        <input type="text" name="addr_postal_code" id="addr_postal_code" class="form-control ts-mono" maxlength="15"
               value="<?= esc($val('addr_postal_code', 'postal_code'), 'attr') ?>">
    </div>
    <div class="col-md-4">
        <label for="addr_country_code" class="form-label">Country code</label>
        <input type="text" name="addr_country_code" id="addr_country_code" class="form-control text-uppercase" maxlength="2"
               value="<?= esc($val('addr_country_code', 'country_code', 'IN'), 'attr') ?>">
    </div>
</div>
