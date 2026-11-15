<?php

namespace App\Livewire\Platform;

use App\Services\Platform\TenantLogs;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Log history of the team's applications, services and databases (regional Loki), kept after
 * redeploys and restarts, unlike the live container logs.
 */
class Logs extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'resource')]
    public string $resourceUuid = '';

    #[Url]
    public string $range = '1h';

    public string $filter = '';

    /** @var list<array{time: string, container: string, line: string}> */
    public array $lines = [];

    public ?string $error = null;

    public bool $searched = false;

    public function mount(): void
    {
        if (! isPlatformTenant()) {
            $this->redirectRoute('dashboard', navigate: true);
        }
        if ($this->resourceUuid !== '') {
            $this->search();
        }
    }

    public function search(): void
    {
        $this->validate([
            'resourceUuid' => ['required', 'string', 'max:64'],
            'range' => ['required', 'in:'.implode(',', array_keys(TenantLogs::RANGES))],
            'filter' => ['nullable', 'string', 'max:'.TenantLogs::MAX_FILTER_LENGTH],
        ]);
        $teamId = (int) currentTeam()->id;
        $resource = TenantLogs::findResource($teamId, $this->resourceUuid);
        if (is_null($resource)) {
            $this->lines = [];
            $this->error = __('Resource not found.');

            return;
        }
        $this->authorize('view', $resource);
        $result = TenantLogs::fetch($teamId, $resource, $this->range, $this->filter);
        $this->lines = $result['lines'];
        $this->error = $result['error'];
        $this->searched = true;
    }

    public function render()
    {
        return view('livewire.platform.logs', [
            'resources' => isPlatformTenant() ? TenantLogs::resourceOptions((int) currentTeam()->id) : [],
            'ranges' => array_keys(TenantLogs::RANGES),
        ]);
    }
}
