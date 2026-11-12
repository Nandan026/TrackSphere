<?php
/**
 * Login page.
 *
 * Controller should pass: $pageTitle (optional).
 * POSTs to /login with fields: email, password, remember (optional).
 * Validation errors are read from flashdata `errors` / `error` by partials/flash.
 */
?>
<?= $this->extend('layouts/auth') ?>

<?= $this->section('content') ?>
<h2 class="ts-display">Welcome back</h2>
<p class="lead-sm">Sign in to manage shipments, pickups and deliveries.</p>

<form action="<?= site_url('login') ?>" method="post" novalidate data-ts-once autocomplete="on">
    <?= csrf_field() ?>

    <div class="mb-3">
        <label for="email" class="form-label">Email address</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= esc(old('email')) ?>" placeholder="you@company.com"
                   autocomplete="username" required autofocus>
        </div>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password"
                   placeholder="Enter your password" autocomplete="current-password" required>
            <button type="button" class="btn btn-outline-secondary" data-ts-toggle-password="#password"
                    aria-label="Show or hide password" style="border-color:var(--ts-slate-200)">
                <i class="bi bi-eye"></i>
            </button>
        </div>
    </div>

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
            <label class="form-check-label small text-muted" for="remember">Keep me signed in</label>
        </div>
        <span class="small text-muted">Ask your org admin to reset your password</span>
    </div>

    <button type="submit" class="btn btn-accent btn-lg w-100">
        Sign in <i class="bi bi-arrow-right ms-1"></i>
    </button>
</form>

<div class="ts-demo">
    <div class="ts-demo__title"><i class="bi bi-key"></i> Demo credentials</div>
    <div>Email: <code>admin@demo.tracksphere.local</code></div>
    <div class="mt-1">Password: <code>Admin@123</code></div>
    <button type="button" class="ts-demo__fill" data-ts-fill
            data-email="admin@demo.tracksphere.local" data-password="Admin@123">
        <i class="bi bi-magic"></i> Fill these for me
    </button>
</div>
<?= $this->endSection() ?>
