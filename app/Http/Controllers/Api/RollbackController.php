<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationDeploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Visus\Cuid2\Cuid2;

/**
 * POST /api/v1/rollback (wiki 5, "Rollback automático"): redeploys an application with the image
 * built by a previous deployment, without rebuilding it.
 *
 * Body: {"uuid": "<application uuid>"} rolls back to the previous successful commit;
 * "commit" or "deployment_uuid" pick a specific one.
 */
class RollbackController extends Controller
{
    private const COMMIT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,127}$/';

    public function __invoke(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $validated = $request->validate([
            'uuid' => ['required', 'string', 'max:64'],
            'commit' => ['nullable', 'string', 'regex:'.self::COMMIT_PATTERN],
            'deployment_uuid' => ['nullable', 'string', 'max:64'],
        ]);
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $validated['uuid'])->first();
        if (! $application) {
            return response()->json(['message' => 'Application not found.'], 404);
        }
        try {
            $this->authorize('deploy', $application);
        } catch (AuthorizationException) {
            return response()->json(['message' => 'Unauthorized to deploy this application.'], 403);
        }

        $commit = $this->targetCommit($application, $validated['commit'] ?? null, $validated['deployment_uuid'] ?? null);
        if (! $commit) {
            return response()->json(['message' => 'No previous successful deployment to roll back to.'], 422);
        }

        $deploymentUuid = (string) new Cuid2;
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            commit: $commit,
            is_api: true,
            rollback: true,
        );
        audit_log('application.rollback', $application, ['commit' => $commit, 'deployment_uuid' => $result['deployment_uuid'] ?? $deploymentUuid]);

        return response()->json([
            'message' => "Rollback of {$application->name} to {$commit} queued.",
            'deployment_uuid' => (string) ($result['deployment_uuid'] ?? $deploymentUuid),
            'commit' => $commit,
        ]);
    }

    private function targetCommit(Application $application, ?string $commit, ?string $deploymentUuid): ?string
    {
        if ($commit) {
            return $commit;
        }
        $finished = ApplicationDeploymentQueue::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->whereNotNull('commit')
            ->where('commit', '!=', 'HEAD')
            ->orderByDesc('id');

        if ($deploymentUuid) {
            return $finished->where('deployment_uuid', $deploymentUuid)->value('commit');
        }

        return $finished->limit(50)->pluck('commit')->unique()->values()->get(1);
    }
}
