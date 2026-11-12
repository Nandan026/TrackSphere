<?php

declare(strict_types=1);

/**
 * Display formatting helpers (money, dates, status badges).
 * Badge helpers return HTML-safe strings using Bootstrap 5 classes.
 */

if (! function_exists('format_money')) {
    /**
     * Formats an amount, using Indian digit grouping for INR (1,23,456.00).
     */
    function format_money($amount, string $currency = 'INR', int $decimals = 2): string
    {
        $amount  = (float) $amount;
        $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
        $symbol  = $symbols[strtoupper($currency)] ?? (strtoupper($currency) . ' ');

        $negative = $amount < 0;
        $fixed    = number_format(abs($amount), $decimals, '.', '');
        [$int, $frac] = array_pad(explode('.', $fixed), 2, '');

        if (strtoupper($currency) === 'INR' && strlen($int) > 3) {
            $last3 = substr($int, -3);
            $rest  = substr($int, 0, -3);
            $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $int   = $rest . ',' . $last3;
        } else {
            $int = number_format((float) $int, 0, '.', ',');
        }

        return ($negative ? '-' : '') . $symbol . $int . ($decimals > 0 ? '.' . $frac : '');
    }
}

if (! function_exists('format_datetime')) {
    /**
     * Converts a stored UTC datetime to the viewer's/org's timezone for display.
     *
     * @param string|\DateTimeInterface|null $value
     */
    function format_datetime($value, string $format = 'd M Y, h:i A', ?string $timezone = null): string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return '—';
        }

        try {
            $timezone ??= (string) (current_user()['timezone'] ?? 'Asia/Kolkata');

            $date = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)
                : new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));

            return $date->setTimezone(new \DateTimeZone($timezone))->format($format);
        } catch (\Throwable $e) {
            return (string) ($value instanceof \DateTimeInterface ? $value->format($format) : $value);
        }
    }
}

if (! function_exists('format_status_badge')) {
    /**
     * Renders a generic status as a Bootstrap badge (shipment, invoice, NDR, etc.).
     *
     * When $module is given (e.g. 'shipments', 'invoices', 'ndr'), the label,
     * colour and tooltip come from the status glossary (status_definitions).
     * Without a module, or when the glossary has no entry, the built-in map is used.
     */
    function format_status_badge(?string $status, ?string $module = null): string
    {
        $status = strtolower(trim((string) $status));
        if ($status === '') {
            return '<span class="badge text-bg-secondary">—</span>';
        }

        if ($module !== null && $module !== '') {
            try {
                $definition = (new \App\Services\Catalog\StatusCatalogService())->getDefinition($module, $status);
            } catch (\Throwable $e) {
                $definition = null;
            }

            if (is_array($definition)) {
                $allowed = ['success', 'info', 'primary', 'warning', 'danger', 'secondary', 'dark'];
                $class   = in_array($definition['badge_class'] ?? '', $allowed, true) ? $definition['badge_class'] : 'secondary';
                $title   = trim((string) ($definition['meaning'] ?? ''));

                return '<span class="badge text-bg-' . $class . '"'
                    . ($title !== '' ? ' title="' . esc($title, 'attr') . '"' : '')
                    . '>' . esc((string) $definition['label']) . '</span>';
            }
        }

        $map = [
            // success
            'active' => 'success', 'delivered' => 'success', 'paid' => 'success', 'resolved' => 'success',
            'completed' => 'success', 'remitted' => 'success', 'approved' => 'success', 'received' => 'success',
            'rto_delivered' => 'success', 'sent' => 'success',
            // info / progress
            'in_transit' => 'info', 'reached_destination_hub' => 'info', 'picked_up' => 'info',
            'out_for_delivery' => 'primary', 'assigned' => 'info', 'accepted' => 'info',
            'rto_in_transit' => 'info', 'scheduled' => 'info',
            // pending / waiting
            'draft' => 'secondary', 'ready_to_ship' => 'primary', 'pickup_scheduled' => 'warning',
            'requested' => 'warning', 'pending' => 'warning', 'open' => 'warning', 'partial' => 'warning',
            'inactive' => 'secondary', 'processing' => 'warning',
            // danger
            'cancelled' => 'dark', 'pickup_failed' => 'danger', 'delivery_failed' => 'danger',
            'rto_initiated' => 'danger', 'failed' => 'danger', 'overdue' => 'danger',
            'rejected' => 'danger', 'suspended' => 'danger', 'blocked' => 'danger',
        ];

        $class = $map[$status] ?? 'secondary';
        $label = ucwords(str_replace('_', ' ', $status));

        return '<span class="badge text-bg-' . $class . '">' . esc($label) . '</span>';
    }
}

if (! function_exists('tracking_badge')) {
    /**
     * Renders a tracking number as a monospaced badge, optionally linking to public tracking.
     */
    function tracking_badge(?string $trackingNumber, bool $link = true): string
    {
        $trackingNumber = trim((string) $trackingNumber);
        if ($trackingNumber === '') {
            return '<span class="badge text-bg-light border text-muted">Not assigned</span>';
        }

        $safe  = esc($trackingNumber);
        $badge = '<span class="badge text-bg-light border font-monospace">' . $safe . '</span>';

        if (! $link) {
            return $badge;
        }

        return '<a href="' . esc(site_url('track/' . rawurlencode($trackingNumber)), 'attr')
            . '" class="text-decoration-none" title="Track shipment">' . $badge . '</a>';
    }
}
