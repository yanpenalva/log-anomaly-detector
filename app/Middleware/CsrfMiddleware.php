<?php

declare(strict_types=1);

namespace App\Middleware;

use flight\Engine;
use flight\net\Request;

/**
 * Double-submit CSRF guard for browser POSTs. Non-browser clients (no
 * cookies) are untouched; browsers get a csrf_token cookie on safe requests
 * and must echo it in the X-CSRF-Token header on POST.
 */
class CsrfMiddleware
{
    private const COOKIE = 'csrf_token';
    private const HEADER = 'X-CSRF-Token';
    private const TOKEN_BYTES = 16;

    public function __construct(private readonly Engine $app)
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function before(array $params): void
    {
        $request = $this->app->request();

        if ($request->method !== 'POST') {
            $this->issueCookie($request);

            return;
        }

        $cookies = $request->cookies;
        if (!isset($cookies[self::COOKIE])) {
            return;
        }

        $provided = '';

        foreach (Request::headers() as $name => $value) {
            if (strcasecmp($name, self::HEADER) === 0) {
                $provided = (string) $value;
                break;
            }
        }

        if ($provided === ''
            || !hash_equals((string) $cookies[self::COOKIE], $provided)) {
            $this->app->halt(403, (string) json_encode([
                'error' => ['code' => 'csrf_mismatch', 'message' => 'Missing or invalid CSRF token'],
            ]));
        }
    }

    private function issueCookie(Request $request): void
    {
        if (isset($request->cookies[self::COOKIE])) {
            return;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->app->response()->header(
            'Set-Cookie',
            sprintf('%s=%s; Path=/; SameSite=Lax', self::COOKIE, $token)
        );
    }
}
