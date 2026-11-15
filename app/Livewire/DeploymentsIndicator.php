<?php

namespace App\Livewire;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DeploymentsIndicator extends Component
{
    public bool $expanded = false;

    #[Computed]
    public function deployments()
    {
        $query = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued']);
        if (isPlatformTenant()) {
            // Shared nodes run deployments of every tenant: only show the team's own applications.
            $query->whereIn('application_id', Application::ownedByCurrentTeam()->pluck('id'));
        } else {
            $query->whereIn('server_id', Server::ownedByCurrentTeam()->get()->pluck('id'));
        }

        return $query
            ->orderBy('id')
            ->get([
                'id',
                'application_id',
                'application_name',
                'deployment_url',
                'pull_request_id',
                'server_name',
                'server_id',
                'status',
            ]);
    }

    #[Computed]
    public function deploymentCount()
    {
        return $this->deployments->count();
    }

    public function toggleExpanded()
    {
        $this->expanded = ! $this->expanded;
    }

    public function render()
    {
        return view('livewire.deployments-indicator');
    }
}
