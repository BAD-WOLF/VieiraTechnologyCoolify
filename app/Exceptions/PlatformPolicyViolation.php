<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Raised when a tenant action or configuration violates the platform isolation or plan policy.
 * The message is safe to show to the tenant.
 */
class PlatformPolicyViolation extends NonReportableException
{
    /**
     * Short heading of the refusal ("Plan limit reached"...).
     */
    public string $title;

    /**
     * @param  array<int, string>  $violations
     */
    public function __construct(public array $violations = [], string $prefix = 'Blocked by platform policy')
    {
        // Shown to the customer (pages, notifications, API): fixed texts are translated here.
        $violations = array_values(array_unique(array_filter(array_map(fn ($violation) => is_string($violation) ? __($violation) : $violation, $violations))));
        $this->violations = $violations;
        $prefix = __($prefix);
        $this->title = $prefix;
        $message = $prefix.(count($violations) > 0 ? ': '.implode(' | ', $violations) : '.');
        parent::__construct($message);
    }

    public static function single(string $violation, string $prefix = 'Blocked by platform policy'): self
    {
        return new self([$violation], $prefix);
    }

    /**
     * API clients get a 422 with the list of violations; other requests use the default rendering.
     */
    public function render(Request $request): JsonResponse|false
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return false;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'violations' => $this->violations,
        ], 422);
    }
}
