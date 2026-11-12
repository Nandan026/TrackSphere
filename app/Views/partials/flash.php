<?php
/**
 * Session flash messages.
 *
 * Supported flashdata keys: success, error, warning, info, message (alias of info),
 * and `errors` (array of validation messages, field => message or list).
 */
$session = session();

$flashTypes = [
    'success' => ['class' => 'ts-flash--success', 'icon' => 'bi-check-circle-fill',        'sticky' => false],
    'error'   => ['class' => 'ts-flash--error',   'icon' => 'bi-x-octagon-fill',           'sticky' => true],
    'warning' => ['class' => 'ts-flash--warning', 'icon' => 'bi-exclamation-triangle-fill', 'sticky' => false],
    'info'    => ['class' => 'ts-flash--info',    'icon' => 'bi-info-circle-fill',         'sticky' => false],
    'message' => ['class' => 'ts-flash--info',    'icon' => 'bi-info-circle-fill',         'sticky' => false],
];

$validationErrors = $session->getFlashdata('errors');
if (isset($errors) && is_array($errors) && $errors !== [] && ! $validationErrors) {
    $validationErrors = $errors;
}
?>
<?php foreach ($flashTypes as $key => $meta): ?>
    <?php $text = $session->getFlashdata($key); ?>
    <?php if (! empty($text) && is_string($text)): ?>
        <div class="ts-flash <?= esc($meta['class'], 'attr') ?>" role="alert"<?= $meta['sticky'] ? ' data-sticky' : '' ?>>
            <i class="bi <?= esc($meta['icon'], 'attr') ?>"></i>
            <div class="ts-flash__body"><?= esc($text) ?></div>
            <button type="button" class="btn-close" data-ts-dismiss aria-label="Dismiss"></button>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if (! empty($validationErrors) && is_array($validationErrors)): ?>
    <div class="ts-flash ts-flash--error" role="alert" data-sticky>
        <i class="bi bi-x-octagon-fill"></i>
        <div class="ts-flash__body">
            <strong>Please fix the following:</strong>
            <ul>
                <?php foreach ($validationErrors as $field => $messages): ?>
                    <?php foreach ((array) $messages as $message): ?>
                        <li><?= esc($message) ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        </div>
        <button type="button" class="btn-close" data-ts-dismiss aria-label="Dismiss"></button>
    </div>
<?php endif; ?>
