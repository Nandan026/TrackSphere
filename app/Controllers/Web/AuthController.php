<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Services\Auth\AuthService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Web (session) authentication: login form, login POST, logout.
 */
class AuthController extends BaseController
{
    private const MAX_ATTEMPTS = 5; // per minute, per IP + email

    public function login(): string|RedirectResponse
    {
        if (auth_check()) {
            return redirect()->to(site_url('dashboard'));
        }

        return view('auth/login', [
            'pageTitle' => 'Sign in',
        ]);
    }

    public function attemptLogin(): RedirectResponse
    {
        if (auth_check()) {
            return redirect()->to(site_url('dashboard'));
        }

        $rules = [
            'email'    => 'required|valid_email|max_length[190]',
            'password' => 'required|max_length[255]',
        ];

        if (! $this->validate($rules)) {
            return $this->failLogin('Please enter a valid email and password.', (string) $this->request->getPost('email'));
        }

        $email    = strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');

        // Brute-force protection: limit attempts per IP + email.
        $throttleKey = 'login_' . md5($this->request->getIPAddress() . '|' . $email);
        if (! service('throttler')->check($throttleKey, self::MAX_ATTEMPTS, MINUTE)) {
            return $this->failLogin('Too many login attempts. Please wait a minute and try again.', $email);
        }

        $result = (new AuthService())->login($email, $password);

        if (! $result['success']) {
            return $this->failLogin($result['message'], $email);
        }

        return redirect()->to($this->intendedUrl())
            ->with('success', 'Welcome back, ' . ($result['user']['first_name'] ?? '') . '!');
    }

    public function logout(): RedirectResponse
    {
        (new AuthService())->logout();

        return redirect()->to(site_url('login'));
    }

    /**
     * Redirects back to the login form with an error, keeping only the email
     * (never the password) as old input.
     */
    private function failLogin(string $message, string $email): RedirectResponse
    {
        $this->request->setGlobal('post', ['email' => $email]);

        return redirect()->to(site_url('login'))->withInput()->with('error', $message);
    }

    /**
     * Returns the URL the user originally requested, but only if it is on
     * this site (prevents open redirects). Falls back to the dashboard.
     */
    private function intendedUrl(): string
    {
        $intended = (string) session()->get('intended_url');
        session()->remove('intended_url');

        if ($intended !== '' && str_starts_with($intended, base_url())) {
            return $intended;
        }

        return site_url('dashboard');
    }
}
