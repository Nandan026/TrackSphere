<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\AuthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Checks permission codes passed as filter arguments.
 *
 * Route usage:  ['filter' => 'permission:shipments.view']
 *               ['filter' => 'permission:shipments.view,shipments.update']  (user needs ALL)
 *               ['filter' => 'permission:shipments.view|reports.view']       (ANY of the "|" alternatives)
 */
class PermissionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $isApi = $this->isApi($request);

        if (current_user() === null) {
            if ($isApi) {
                return api_error('Authentication required.', [], 401);
            }

            return redirect()->to(site_url('login'))->with('error', 'Please sign in to continue.');
        }

        foreach ((array) $arguments as $argument) {
            $alternatives = array_filter(array_map('trim', explode('|', (string) $argument)));
            if ($alternatives === []) {
                continue;
            }

            $granted = false;
            foreach ($alternatives as $permission) {
                if (user_can($permission)) {
                    $granted = true;
                    break;
                }
            }

            if (! $granted) {
                return $this->forbidden($request, $isApi);
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function isApi(RequestInterface $request): bool
    {
        $path = trim($request->getUri()->getPath(), '/');

        return str_contains('/' . $path, '/api/') || $request->isAJAX();
    }

    private function forbidden(RequestInterface $request, bool $isApi)
    {
        if ($isApi) {
            return api_error('You do not have permission to perform this action.', [], 403);
        }

        $response = service('response')->setStatusCode(403);

        // Prefer the app's 403 view if one exists, else a minimal body.
        if (is_file(APPPATH . 'Views/errors/403.php')) {
            return $response->setBody(view('errors/403'));
        }

        return $response->setBody(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>403 Forbidden</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;padding:4rem">'
            . '<h1>403 &mdash; Access denied</h1><p>You do not have permission to view this page.</p>'
            . '<p><a href="' . esc(site_url('/'), 'attr') . '">Back to home</a></p></body></html>'
        );
    }
}
