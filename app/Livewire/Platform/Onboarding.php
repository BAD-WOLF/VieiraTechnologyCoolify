<?php

namespace App\Livewire\Platform;

use App\Actions\Platform\ProvisionTenant;
use App\Models\PlatformRegion;
use App\Models\Project;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Tenant onboarding: pick a region, get an isolated network on the region nodes, create the first project.
 */
class Onboarding extends Component
{
    public ?int $regionId = null;

    #[Validate(['required', 'string', 'min:2', 'max:100', 'regex:/^[a-zA-Z0-9\s\-_.:\/()]+$/'])]
    public string $projectName = 'My first project';

    public function mount()
    {
        if (! isPlatformTenant()) {
            return redirect()->route('onboarding');
        }
        $tenant = platformTenant();
        if ($tenant->isBlocked() && $tenant->status !== \App\Models\PlatformTenant::STATUS_LIMITED) {
            return redirect()->route('subscription.index');
        }
        $this->regionId = $tenant->platform_region_id ?? PlatformRegion::defaultRegion()?->id;
    }

    public function finish()
    {
        try {
            if (! auth()->user()->isAdmin()) {
                $this->dispatch('error', __('Only team owners and admins can finish the onboarding.'));

                return;
            }
            $this->validate();
            $region = PlatformRegion::query()->where('is_active', true)->findOrFail($this->regionId);
            ProvisionTenant::run(currentTeam(), $region);
            $project = Project::create([
                'name' => $this->projectName,
                'team_id' => currentTeam()->id,
            ]);
            currentTeam()->update(['show_boarding' => false]);
            refreshSession();

            return redirect()->route('project.resource.create', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $project->environments->first()->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function skip()
    {
        currentTeam()->update(['show_boarding' => false]);
        refreshSession();

        return redirect()->route('dashboard');
    }

    public function render()
    {
        return view('livewire.platform.onboarding', [
            'regions' => PlatformRegion::query()->where('is_active', true)->orderBy('name')->get(),
            'tenant' => platformTenant(),
        ]);
    }
}
