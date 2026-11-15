<?php

namespace App\Http\Controllers;

use App\Models\ApplicationDeploymentQueue;
use App\Models\PlatformAllocation;
use App\Models\PlatformInvoice;
use App\Models\PlatformTenant;
use App\Models\Server;
use App\Models\UsageEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redis;

/**
 * Prometheus exposition of the control plane (wiki 6: "Plataforma (Coolify / control plane)" metrics).
 * Protected by PLATFORM_METRICS_TOKEN (Authorization: Bearer ...).
 */
class PlatformMetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('platform.metrics_token');
        if (! isPlatform() || $token === '' || ! hash_equals($token, (string) $request->bearerToken())) {
            return response('Not Found', 404, ['Content-Type' => 'text/plain']);
        }

        $lines = [];
        $metric = function (string $name, string $help, string $type, array $samples) use (&$lines) {
            $lines[] = "# HELP {$name} {$help}";
            $lines[] = "# TYPE {$name} {$type}";
            foreach ($samples as [$labels, $value]) {
                $labelString = collect($labels)->map(fn ($v, $k) => $k.'="'.addcslashes((string) $v, "\"\\\n").'"')->implode(',');
                $lines[] = $name.($labelString !== '' ? '{'.$labelString.'}' : '').' '.(is_float($value) ? round($value, 4) : (int) $value);
            }
        };

        $tenants = PlatformTenant::query()->selectRaw("status, coalesce(plan, 'none') as plan, count(*) as total")->groupBy('status', 'plan')->get();
        $metric('platform_tenants', 'Tenants by status and plan.', 'gauge', $tenants->map(fn ($row) => [['status' => $row->status, 'plan' => $row->plan], (int) $row->total])->all());

        $deployments = ApplicationDeploymentQueue::query()->whereIn('status', ['queued', 'in_progress'])->selectRaw('status, count(*) as total')->groupBy('status')->get();
        $metric('platform_deployments_pending', 'Deployments queued or in progress.', 'gauge', $deployments->map(fn ($row) => [['status' => $row->status], (int) $row->total])->all());

        $recent = ApplicationDeploymentQueue::query()->where('created_at', '>=', now()->subHour())->selectRaw('status, count(*) as total')->groupBy('status')->get();
        $metric('platform_deployments_last_hour', 'Deployments created in the last hour by status.', 'gauge', $recent->map(fn ($row) => [['status' => $row->status], (int) $row->total])->all());

        $allocations = PlatformAllocation::query()->selectRaw('team_id, sum(cpus) as cpus, sum(memory_mb) as memory_mb, sum(containers) as containers')->groupBy('team_id')->get();
        $metric('platform_tenant_allocated_cpus', 'vCPU reserved by tenant workloads.', 'gauge', $allocations->map(fn ($row) => [['tenant_id' => $row->team_id], (float) $row->cpus])->all());
        $metric('platform_tenant_allocated_memory_bytes', 'Memory reserved by tenant workloads.', 'gauge', $allocations->map(fn ($row) => [['tenant_id' => $row->team_id], (int) $row->memory_mb * 1024 * 1024])->all());
        $metric('platform_tenant_allocated_containers', 'Containers reserved by tenant workloads.', 'gauge', $allocations->map(fn ($row) => [['tenant_id' => $row->team_id], (int) $row->containers])->all());

        $lastHour = UsageEvent::query()->where('recorded_at', '>=', now()->subHour())->where('metric', '!=', UsageEvent::METRIC_STORAGE_BYTES)
            ->selectRaw('team_id, metric, sum(value) as total')->groupBy('team_id', 'metric')->get();
        $metric('platform_tenant_usage_last_hour', 'Metered usage of the last hour (cpu_seconds, memory_byte_seconds, egress_bytes).', 'gauge', $lastHour->map(fn ($row) => [['tenant_id' => $row->team_id, 'metric' => $row->metric], (float) $row->total])->all());

        $unpaid = PlatformInvoice::query()->whereIn('status', [PlatformInvoice::STATUS_OPEN, PlatformInvoice::STATUS_UNCOLLECTIBLE])->selectRaw('currency, count(*) as total, sum(amount_cents) as amount')->groupBy('currency')->get();
        $metric('platform_unpaid_invoices', 'Open or uncollectible usage invoices.', 'gauge', $unpaid->map(fn ($row) => [['currency' => $row->currency], (int) $row->total])->all());
        $metric('platform_unpaid_amount_cents', 'Amount of open or uncollectible usage invoices.', 'gauge', $unpaid->map(fn ($row) => [['currency' => $row->currency], (int) $row->amount])->all());

        $nodes = Server::query()->where('team_id', 0)->whereNotNull('platform_region_id')->with(['settings', 'platformRegion'])->get();
        $metric('platform_node_reachable', 'Platform node reachability as seen by the control plane.', 'gauge', $nodes->map(fn ($node) => [['node' => $node->name, 'region' => $node->platformRegion?->slug], $node->settings?->is_reachable ? 1 : 0])->all());

        try {
            $queues = [];
            foreach (['high', 'default'] as $queue) {
                $queues[] = [['queue' => $queue], (int) Redis::connection()->llen("queues:{$queue}")];
            }
            $metric('platform_queue_length', 'Jobs waiting in the Horizon queues.', 'gauge', $queues);
        } catch (\Throwable) {
            // Redis unavailable: skip queue metrics.
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }
}
