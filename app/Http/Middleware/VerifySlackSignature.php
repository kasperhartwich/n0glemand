<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifySlackSignature
{
    public function __construct(protected ConfigRepository $config) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');
        $secret = (string) $this->config->get('services.slack.signing_secret', '');

        if ($timestamp === null || $signature === null || $secret === '') {
            return $this->reject('missing_headers_or_secret');
        }

        $tolerance = (int) $this->config->get('services.slack.signature_tolerance', 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->reject('stale_timestamp');
        }

        $body = $request->getContent();
        $basestring = "v0:{$timestamp}:{$body}";
        $expected = 'v0='.hash_hmac('sha256', $basestring, $secret);

        if (! hash_equals($expected, (string) $signature)) {
            return $this->reject('signature_mismatch');
        }

        return $next($request);
    }

    protected function reject(string $reason): Response
    {
        Log::warning('slack.signature.invalid', ['reason' => $reason]);

        return response()->json(['error' => 'invalid_signature'], 401);
    }
}
