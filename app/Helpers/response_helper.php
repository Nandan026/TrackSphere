<?php

declare(strict_types=1);

/**
 * Standard JSON envelope helpers for the REST API.
 */

if (! function_exists('api_success')) {
    /**
     * @param mixed                $data
     * @param array<string, mixed> $meta
     */
    function api_success($data = null, string $message = 'OK', int $code = 200, array $meta = []): \CodeIgniter\HTTP\ResponseInterface
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return service('response')
            ->setStatusCode($code)
            ->setJSON($payload);
    }
}

if (! function_exists('api_error')) {
    /**
     * @param array<string, mixed>|list<string> $errors
     */
    function api_error(string $message = 'Request failed', array $errors = [], int $code = 400): \CodeIgniter\HTTP\ResponseInterface
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ];

        return service('response')
            ->setStatusCode($code)
            ->setJSON($payload);
    }
}
