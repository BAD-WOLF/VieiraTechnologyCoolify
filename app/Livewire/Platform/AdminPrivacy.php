<?php

namespace App\Livewire\Platform;

use App\Models\PrivacyDeletedAccount;
use App\Models\PrivacyIncident;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\Platform\AccountDeletion;
use App\Services\Platform\Privacy;
use App\Services\Platform\PrivacyIncidents;
use App\Services\Platform\ProcessingRecord;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Privacy (LGPD) tab of the operator console: the requests of the data subjects and their 15-day
 * deadline, the register and communication of security incidents, new versions of the privacy
 * policy, deleted accounts and the record of the processing operations. Admins of the root team
 * read it; only the owner acts.
 */
class AdminPrivacy extends Component
{
    public ?int $answeringId = null;

    public string $answer = '';

    public string $policyChanges = '';

    public string $deleteEmail = '';

    public string $deleteReason = '';

    public ?int $editingIncidentId = null;

    /** @var array<string, mixed> */
    public array $incident = [];

    public ?int $communicatingId = null;

    public string $communicateAudience = 'all';

    public ?int $anpdIncidentId = null;

    public string $anpdDate = '';

    public string $anpdProtocol = '';

    public function mount(): void
    {
        abort_unless($this->canView(), 403);
        $this->resetIncident();
    }

    public function startAnswer(int $id): void
    {
        $this->answeringId = PrivacyRequest::query()->findOrFail($id)->id;
        $this->answer = '';
    }

    public function sendAnswer(): void
    {
        if (! $this->authorizeOwner()) {
            return;
        }
        $this->validate(['answer' => ['required', 'string', 'max:5000']], [], ['answer' => __('Your answer')]);
        $request = PrivacyRequest::query()->findOrFail($this->answeringId);
        Privacy::answerRequest($request, $this->answer, auth()->user());
        $this->reset('answeringId', 'answer');
        $this->dispatch('success', __('Answer sent to :email.', ['email' => $request->email]));
    }

    public function publishPolicy(): void
    {
        if (! $this->authorizeOwner()) {
            return;
        }
        $this->validate(['policyChanges' => ['required', 'string', 'max:2000']], [], ['policyChanges' => __('What changed')]);
        $version = Privacy::publishPolicy($this->policyChanges, auth()->user());
        $this->reset('policyChanges');
        $this->dispatch('success', __('Version :version published.', ['version' => $version]), __('Every user is told in the bell and accepts it on the next visit.'));
    }

    public function deleteAccount(): void
    {
        if (! $this->authorizeOwner()) {
            return;
        }
        $this->validate([
            'deleteEmail' => ['required', 'email'],
            'deleteReason' => ['required', 'string', 'max:500'],
        ], [], ['deleteEmail' => __('E-mail of the account'), 'deleteReason' => __('Reason')]);
        $user = User::query()->where('email', strtolower(trim($this->deleteEmail)))->first();
        if (is_null($user)) {
            $this->addError('deleteEmail', __('No account with this e-mail.'));

            return;
        }
        if ($user->id === auth()->id()) {
            $this->addError('deleteEmail', __('Delete your own account in Privacy and data.'));

            return;
        }
        try {
            AccountDeletion::request($user, PrivacyDeletedAccount::BY_OWNER, auth()->user(), $this->deleteReason);
        } catch (\RuntimeException $e) {
            $this->dispatch('error', __('The account can not be deleted'), e($e->getMessage()));

            return;
        }
        $this->reset('deleteEmail', 'deleteReason');
        $this->dispatch('success', __('Deleting the account of :email.', ['email' => $user->email]), __('The user was logged out and receives an e-mail with what was deleted and what is kept.'));
    }

    public function retryDeletion(int $id): void
    {
        if (! $this->authorizeOwner()) {
            return;
        }
        AccountDeletion::retry(PrivacyDeletedAccount::query()->findOrFail($id));
        $this->dispatch('success', __('Trying the deletion again.'));
    }

    public function editIncident(int $id): void
    {
        $incident = PrivacyIncident::query()->findOrFail($id);
        $this->editingIncidentId = $incident->id;
        $timezone = $this->timezone();
        $this->incident = [
            'title' => $incident->title,
            'known_at' => $incident->known_at->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'occurred_at' => $incident->occurred_at?->setTimezone($timezone)->format('Y-m-d\TH:i') ?? '',
            'description' => $incident->description,
            'data_categories' => $incident->data_categories,
            'affected_count' => (string) ($incident->affected_count ?? ''),
            'relevant_risk' => $incident->relevant_risk,
            'risks' => (string) $incident->risks,
            'security_measures' => (string) $incident->security_measures,
            'mitigation' => (string) $incident->mitigation,
            'recommendations' => (string) $incident->recommendations,
            'delay_reason' => (string) $incident->delay_reason,
        ];
    }

    public function saveIncident(): void
    {
        if (! $this->authorizeOwner()) {
            return;
        }
        $this->validate([
            'incident.title' => ['required', 'string', 'max:160'],
            'incident.known_at' => ['required', 'date'],
            'incident.occurred_at' => ['nullable', 'date'],
            'incident.description' => ['required', 'string', 'max:5000'],
            'incident.data_categories' => ['required', 'string', 'max:2000'],
            'incident.affected_count' => ['nullable', 'integer', 'min:0'],
            'incident.relevant_risk' => ['boolean'],
            'incident.risks' => ['nullable', 'string', 'max:5000'],
            'incident.security_measures' => ['nullable', 'string', 'max:5000'],
            'incident.mitigation' => ['nullable', 'string', 'max:5000'],
            'incident.recommendations' => ['nullable', 'string', 'max:5000'],
            'incident.delay_reason' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'incident.title' => __('Title'),
            'incident.known_at' => __('When you learned of it'),
            'incident.description' => __('What happened'),
            'incident.data_categories' => __('Personal data involved'),
        ]);
        $timezone = $this->timezone();
        $values = [
            'title' => $this->incident['title'],
            'known_at' => Carbon::parse($this->incident['known_at'], $timezone)->utc(),
            'occurred_at' => filled($this->incident['occurred_at'] ?? null) ? Carbon::parse($this->incident['occurred_at'], $timezone)->utc() : null,
            'description' => $this->incident['description'],
            'data_categories' => $this->incident['data_categories'],
            'affected_count' => filled($this->incident['affected_count'] ?? null) ? (int) $this->incident['affected_count'] : null,
            'relevant_risk' => (bool) ($this->incident['relevant_risk'] ?? false),
            'risks' => $this->incident['risks'] ?: null,
            'security_measures' => $this->incident['security_measures'] ?: null,
            'mitigation' => $this->incident['mitigation'] ?: null,
            'recommendations' => $this->incident['recommendations'] ?: null,
            'delay_reason' => $this->incident['delay_reason'] ?: null,
        ];
        $incident = $this->editingIncidentId
            ? tap(PrivacyIncident::query()->findOrFail($this->editingIncidentId))->update($values)
            : PrivacyIncident::query()->create($values + ['created_by' => auth()->id()]);
        audit_log('privacy.incident.saved', $incident, ['relevant_risk' => $incident->relevant_risk], 0);
        $this->resetIncident();
        $this->dispatch('success', __('Incident recorded.'), $incident->relevant_risk
            ? __('Communicate the ANPD and the data subjects by :date.', ['date' => $incident->communicationDeadline()->setTimezone($timezone)->format('d/m/Y')])
            : null);
    }

    public function cancelIncident(): void
    {
        $this->resetIncident();
    }

    public function communicate(int $id): void
    {
        if (! $this->authorizeOwner()) {
            return;
        }
        $this->validate(['communicateAudience' => ['required', 'in:'.implode(',', PrivacyIncident::AUDIENCES)]]);
        $count = PrivacyIncidents::communicate(PrivacyIncident::query()->findOrFail($id), $this->communicateAudience, auth()->user());
        $this->reset('communicatingId');
        $this->dispatch('success', trans_choice('{0} Nobody to tell.|{1} :count user was told by e-mail and in the bell.|[2,*] :count users were told by e-mail and in the bell.', $count, ['count' => $count]));
    }

    public function recordAnpd(): void
    {
        if (! $this->authorizeOwner()) {
            return;
        }
        $this->validate([
            'anpdDate' => ['required', 'date'],
            'anpdProtocol' => ['nullable', 'string', 'max:100'],
        ], [], ['anpdDate' => __('Date'), 'anpdProtocol' => __('Protocol')]);
        $incident = PrivacyIncident::query()->findOrFail($this->anpdIncidentId);
        $incident->update([
            'anpd_communicated_at' => Carbon::parse($this->anpdDate, $this->timezone())->utc(),
            'anpd_protocol' => $this->anpdProtocol ?: null,
        ]);
        audit_log('privacy.incident.anpd', $incident, ['protocol' => $incident->anpd_protocol], 0);
        $this->reset('anpdIncidentId', 'anpdDate', 'anpdProtocol');
        $this->dispatch('success', __('Communication to the ANPD recorded.'));
    }

    public function downloadRecord(): StreamedResponse
    {
        abort_unless($this->canView(), 403);
        $csv = ProcessingRecord::csv();

        return response()->streamDownload(fn () => print ($csv), 'registro-das-operacoes-de-tratamento.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        return view('livewire.platform.admin-privacy', [
            'canEdit' => $this->canEdit(),
            'missing' => Privacy::missingIdentification(),
            'version' => Privacy::policyVersion(),
            'publishedAt' => Privacy::policyPublishedAt(),
            'requests' => PrivacyRequest::query()->orderByRaw("case when status = 'open' then 0 else 1 end")->orderBy('due_at')->latest('id')->limit(100)->get(),
            'openRequests' => PrivacyRequest::query()->where('status', PrivacyRequest::STATUS_OPEN)->count(),
            'overdueRequests' => PrivacyRequest::query()->where('status', PrivacyRequest::STATUS_OPEN)->where('due_at', '<', now())->count(),
            'incidents' => PrivacyIncident::query()->latest('known_at')->limit(50)->get(),
            'deletions' => PrivacyDeletedAccount::query()->latest('id')->limit(50)->get(),
            'operations' => ProcessingRecord::operations(),
            'timezone' => $this->timezone(),
        ]);
    }

    private function resetIncident(): void
    {
        $this->editingIncidentId = null;
        $this->incident = [
            'title' => '', 'known_at' => now($this->timezone())->format('Y-m-d\TH:i'), 'occurred_at' => '', 'description' => '',
            'data_categories' => '', 'affected_count' => '', 'relevant_risk' => false, 'risks' => '', 'security_measures' => '',
            'mitigation' => '', 'recommendations' => '', 'delay_reason' => '',
        ];
    }

    private function timezone(): string
    {
        return (string) (instanceSettings()->instance_timezone ?: config('app.timezone', 'UTC'));
    }

    private function authorizeOwner(): bool
    {
        abort_unless($this->canView(), 403);
        if (! $this->canEdit()) {
            $this->dispatch('error', __('Only the owner of the root team can do this.'));

            return false;
        }

        return true;
    }

    private function canView(): bool
    {
        return isPlatform() && auth()->check() && (int) currentTeam()?->id === 0 && auth()->user()->isInstanceAdmin();
    }

    private function canEdit(): bool
    {
        return $this->canView() && (bool) auth()->user()?->isOwner();
    }
}
