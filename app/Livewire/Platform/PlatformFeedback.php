<?php

namespace App\Livewire\Platform;

use App\Exceptions\PlatformPolicyViolation;
use App\Exceptions\TenantInputRejected;
use Livewire\ComponentHook;

/**
 * Shows platform refusals (plan limits, isolation rules, values a tenant may not save) as a
 * notification of the component that triggered them.
 *
 * Coolify catches errors in most actions, but not while a page is mounted (creating a database or
 * a one-click service from a link happens there): without this the customer gets a server error
 * page, or a validation error stored where no form shows it.
 */
class PlatformFeedback extends ComponentHook
{
    public function exception($e, $stopPropagation): void
    {
        if ($e instanceof PlatformPolicyViolation) {
            $this->component->dispatch('error', $e->title, self::describe($e->violations ?: [$e->title], self::isPlanLimit($e)));
            $stopPropagation();

            return;
        }
        if ($e instanceof TenantInputRejected) {
            // The validation feature keeps the errors for the form fields; this shows them all.
            $this->component->dispatch('error', __('Not allowed on the platform'), self::describe($e->validator->errors()->all()));
        }
    }

    /**
     * One line per reason (escaped: they quote what the customer typed) and, when the plan is
     * what stops it, where to change the plan.
     *
     * @param  array<int, string>  $reasons
     */
    public static function describe(array $reasons, bool $planLimit = false): string
    {
        $lines = array_map(fn (string $reason) => e($reason), array_values(array_unique(array_filter($reasons))));
        if ($planLimit && isPlatformTenant()) {
            $lines[] = '<a class="underline" href="'.e(route('subscription.show')).'">'.e(__('See the plans and the usage of your account')).'</a>';
        }

        return implode('<br>', $lines);
    }

    private static function isPlanLimit(PlatformPolicyViolation $e): bool
    {
        return in_array($e->title, [__('Plan limit reached'), __('Not included in your plan')], true)
            || str_starts_with($e->title, __('Plan quota exceeded (stop another resource, lower its limits or upgrade your plan)'));
    }
}
