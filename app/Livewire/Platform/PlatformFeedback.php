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
            $this->component->dispatch('error', $e->title, implode(' ', $e->violations) ?: $e->title);
            $stopPropagation();

            return;
        }
        if ($e instanceof TenantInputRejected) {
            // The validation feature keeps the errors for the form fields; this shows them all.
            $this->component->dispatch('error', __('Not allowed on the platform'), implode(' ', $e->validator->errors()->all()));
        }
    }
}
