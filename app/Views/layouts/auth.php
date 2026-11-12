<?php
/**
 * TrackSphere guest layout (login, password reset).
 *
 * View data: $pageTitle (string, optional).
 * Child views fill the `content` section.
 */
$pageTitle = $pageTitle ?? 'Sign in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0F4C5C">
    <title><?= esc($pageTitle) ?> · TrackSphere</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&amp;family=Plus+Jakarta+Sans:wght@500;600;700;800&amp;display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/tracksphere.css') ?>" rel="stylesheet">
    <?= $this->renderSection('styles') ?>
</head>
<body>
<div class="ts-auth">
    <div class="ts-auth__wrap">
        <div class="ts-auth__brand">
            <span class="ts-logo ts-logo--lg"><i class="bi bi-globe-central-south-asia"></i></span>
            <h1>Track<span>Sphere</span></h1>
            <p>Shipment &amp; last-mile delivery management</p>
        </div>

        <div class="ts-auth__card">
            <?= $this->include('partials/flash') ?>
            <?= $this->renderSection('content') ?>
        </div>

        <div class="ts-auth__foot">
            <i class="bi bi-search me-1"></i>Have a tracking number?
            <a href="<?= site_url('track') ?>">Track a parcel</a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= base_url('assets/js/tracksphere.js') ?>"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
