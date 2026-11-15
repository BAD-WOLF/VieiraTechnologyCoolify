<?php

namespace App\Exceptions;

/**
 * Raised when a tenant action or configuration violates the platform isolation or plan policy.
 * The message is safe to show to the tenant.
 */
class PlatformPolicyViolation extends NonReportableException
{
    /**
     * @param  array<int, string>  $violations
     */
    public function __construct(public array $violations = [], string $prefix = 'Blocked by platform policy')
    {
        $violations = array_values(array_unique(array_filter($violations)));
        $this->violations = $violations;
        $message = $prefix.(count($violations) > 0 ? ': '.implode(' | ', $violations) : '.');
        parent::__construct($message);
    }

    public static function single(string $violation, string $prefix = 'Blocked by platform policy'): self
    {
        return new self([$violation], $prefix);
    }
}
