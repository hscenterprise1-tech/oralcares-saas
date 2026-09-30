<?php

namespace App\Support;

use App\Exceptions\TenantContextMissingException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureClinicEntitled;
use App\Services\ClinicContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\ExceptionResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * How an HTTP error looks inside the Clinic Portal.
 *
 * Registered with `Inertia::handleExceptionsUsing()`. For a request to the
 * Clinic Portal (never the Company side, never a JSON call) a 403, 404, 429,
 * 500 or 503 is rendered as the portal's own error page, and an expired form
 * (419) returns to the sign-in page saying why. Everything else keeps
 * Laravel's normal response.
 *
 * A 5xx never carries its exception message to the browser: the page shows a
 * fixed sentence and the request id to quote to support. With APP_DEBUG on,
 * 5xx keep the framework's debug page so developers still see the trace.
 */
class ClinicPortalErrors
{
    /**
     * The statuses the portal renders itself.
     */
    public const RENDERED = [403, 404, 429, 500, 503];

    public static function handle(ExceptionResponse $response): ExceptionResponse|RedirectResponse|null
    {
        $request = $response->request;
        $status = $response->statusCode();

        if (! self::isClinicPortalRequest($request) || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return null;
        }

        if ($status === 419) {
            return redirect()
                ->route('clinic.login', self::routeParameters($request))
                ->with('message', 'Your session expired. Please sign in again.');
        }

        if (! in_array($status, self::RENDERED, true) || ($status >= 500 && config('app.debug'))) {
            return null;
        }

        $response->render('ClinicPortal/Error', [
            'status' => $status,
            'reason' => self::reason($response),
            'message' => self::message($response),
            'requestId' => $request->attributes->get(AssignRequestId::ATTRIBUTE),
        ]);

        // A URL that matched no route never started a session, so there is
        // no signed-in user or clinic to share; the page renders on its own.
        return $request->hasSession() ? $response->withSharedData() : $response;
    }

    /**
     * A Clinic Portal page: `/clinic/...` in path mode, a clinic subdomain in
     * domain mode.
     */
    public static function isClinicPortalRequest(Request $request): bool
    {
        if (config('app.routing_mode') === 'path') {
            return $request->is('clinic', 'clinic/*');
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = $request->getHost();

        return is_string($appHost)
            && str_ends_with($host, '.'.$appHost)
            && $host !== 'admin.'.$appHost;
    }

    /**
     * @return array<string, string>
     */
    private static function routeParameters(Request $request): array
    {
        $clinic = app(ClinicContext::class)->requestedClinicSlug($request);

        return is_string($clinic) && $clinic !== '' ? ['clinic' => $clinic] : [];
    }

    /**
     * @return 'plan_unavailable'|'no_clinic_context'|null
     */
    private static function reason(ExceptionResponse $response): ?string
    {
        if ($response->exception instanceof TenantContextMissingException) {
            return 'no_clinic_context';
        }

        if ($response->exception instanceof HttpExceptionInterface && $response->exception->getMessage() === EnsureClinicEntitled::REFUSAL) {
            return 'plan_unavailable';
        }

        return null;
    }

    /**
     * The refusal's own sentence for a 403 raised on purpose (`abort(403,
     * '...')`), which is written for the user. Nothing for any other status.
     */
    private static function message(ExceptionResponse $response): ?string
    {
        $exception = $response->exception;

        if ($response->statusCode() !== 403 || ! $exception instanceof HttpExceptionInterface) {
            return null;
        }

        $message = trim($exception->getMessage());

        return $message === '' ? null : $message;
    }
}
