<?php

namespace App\Livewire\Platform;

use Livewire\Component;

/**
 * Integrations of the team: API, webhooks, log history and application traces.
 */
class Integrations extends Component
{
    public function mount(): void
    {
        if (! isPlatform()) {
            $this->redirectRoute('dashboard');
        }
    }

    public function render()
    {
        return view('livewire.platform.integrations', [
            'apiDocsUrl' => route('platform.openapi'),
            'isTenant' => isPlatformTenant(),
        ]);
    }
}
