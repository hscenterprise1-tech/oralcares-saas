<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response.
 *
 * - `X-Content-Type-Options: nosniff`: an uploaded file is served as the
 *   type it was stored as, never re-guessed into HTML or script.
 * - `X-Frame-Options: SAMEORIGIN` plus CSP `frame-ancestors 'self'`: other
 *   sites cannot frame the portal to click-jack a clinician.
 * - `Referrer-Policy: strict-origin-when-cross-origin`: URLs carrying a
 *   patient id or a one-time token never leak in full to another site.
 * - `Permissions-Policy`: camera, microphone, geolocation and payment are
 *   off, since nothing in the portal uses them.
 * - `Strict-Transport-Security`, only on HTTPS in production: browsers
 *   refuse plain HTTP for a year. Sending it on a local HTTP dev server
 *   would pin localhost to HTTPS in the developer's browser.
 *
 * A full Content-Security-Policy (script and style sources) is deliberately
 * NOT sent yet. Vite's dev server, Inertia's inline page payload and inline
 * `<style>` blocks need nonce support first, and a CSP that blocks the app is
 * worse than none. It is tracked as a P1 item in the compliance register.
 * The only CSP directive sent is `frame-ancestors`, which cannot break
 * rendering.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        }

        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
