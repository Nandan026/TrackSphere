<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Auth\AuthService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Requires a logged-in web session. Guests are redirected to the login page.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = session()->get(AuthService::SESSION_KEY);

        if (is_array($user) && ! empty($user['id'])) {
            return null;
        }

        // Remember where the user was going (GET only) to return after login.
        if (strtolower($request->getMethod()) === 'get' && ! $request->isAJAX()) {
            session()->set('intended_url', current_url());
        }

        if ($request->isAJAX()) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['success' => false, 'message' => 'Authentication required.', 'errors' => []]);
        }

        return redirect()->to(site_url('login'))->with('error', 'Please sign in to continue.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
