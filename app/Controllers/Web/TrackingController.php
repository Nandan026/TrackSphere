<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Services\Tracking\PublicTrackingService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public shipment tracking (no authentication). Only customer-safe data is
 * exposed - see PublicTrackingService.
 *
 *   GET  /track                      search form
 *   GET  /track?tracking_number=X    search (shareable, used by dashboard quick search)
 *   POST /track                      search form submit -> redirects to /track/X
 *   GET  /track/{number}             result page
 */
class TrackingController extends BaseController
{
    protected string $module = 'tracking';

    private const MAX_LOOKUPS_PER_MINUTE = 30;

    public function index(): string|RedirectResponse|ResponseInterface
    {
        $isPost    = strtolower($this->request->getMethod()) === 'post';
        $submitted = trim((string) ($isPost
            ? $this->request->getPost('tracking_number')
            : $this->request->getGet('tracking_number')));

        if ($submitted === '') {
            if ($isPost) {
                return $this->page('', null, 422, 'Please enter a tracking number.');
            }

            return $this->page('', null);
        }

        if (! $this->allowLookup()) {
            return $this->tooManyRequests();
        }

        $number = (new PublicTrackingService())->normalize($submitted);
        if ($number === null) {
            return $this->page($submitted, null, 422, 'That does not look like a valid tracking number.');
        }

        // Canonical, shareable URL (POST-redirect-GET).
        return redirect()->to(site_url('track/' . rawurlencode($number)));
    }

    public function show(string $trackingNumber = ''): string|ResponseInterface
    {
        if (! $this->allowLookup()) {
            return $this->tooManyRequests();
        }

        $service = new PublicTrackingService();
        $number  = $service->normalize(rawurldecode($trackingNumber));
        $result  = $number !== null ? $service->find($number) : null;

        if ($result === null) {
            return $this->page($number ?? trim(rawurldecode($trackingNumber)), null, 404);
        }

        return $this->page($number, $result);
    }

    /**
     * @param array{shipment: array<string, mixed>, events: list<array<string, mixed>>}|null $result
     */
    private function page(string $number, ?array $result, int $status = 200, ?string $error = null): ResponseInterface
    {
        $html = $this->renderPage('tracking/public', [
            'title'          => 'Track your shipment',
            'trackingNumber' => $number,
            'shipment'       => $result['shipment'] ?? null,
            'events'         => $result['events'] ?? [],
            // partials/flash renders $errors for the current request.
            'errors'         => $error !== null ? ['tracking_number' => $error] : [],
        ]);

        return $this->response->setStatusCode($status)->setBody($html);
    }

    private function allowLookup(): bool
    {
        return service('throttler')->check(
            'track_' . md5($this->request->getIPAddress()),
            self::MAX_LOOKUPS_PER_MINUTE,
            MINUTE
        );
    }

    private function tooManyRequests(): ResponseInterface
    {
        return $this->response->setStatusCode(429)->setBody('Too many requests. Please try again in a minute.');
    }
}
