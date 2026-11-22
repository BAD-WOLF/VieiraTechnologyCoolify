<?php

namespace App\Livewire\Platform;

use App\Models\PlatformNotification;
use App\Models\Team;
use App\Services\Platform\PlanCatalog;
use App\Services\Platform\PlatformInbox;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Operator console, "Announcements" tab: the owner writes to the users of the platform (news,
 * promotions, updates, maintenance), to everyone, the customers, the team of the platform, the
 * customers of a plan or one customer, now or at a date, until an optional end. They show up in
 * the bell at the top of the dashboard.
 */
class AdminNotifications extends Component
{
    public string $kind = PlatformNotification::KIND_NEWS;

    public string $title = '';

    public string $body = '';

    public string $actionUrl = '';

    public string $actionLabel = '';

    public string $audience = PlatformNotification::AUDIENCE_ALL;

    public string $plan = '';

    public string $teamId = '';

    /** Local date and time of the instance (datetime-local); empty publishes now. */
    public string $publishAt = '';

    public string $expiresAt = '';

    public function mount(): void
    {
        abort_unless($this->canView(), 403);
    }

    public function publish(): void
    {
        abort_unless($this->canView(), 403);
        if (! $this->canEdit()) {
            $this->dispatch('error', __('Only the owner of the root team can send announcements.'));

            return;
        }
        $this->validate($this->rules(), [], $this->attributeNames());
        $timezone = $this->timezone();
        $publishedAt = filled($this->publishAt) ? Carbon::parse($this->publishAt, $timezone)->utc() : now();
        $expiresAt = filled($this->expiresAt) ? Carbon::parse($this->expiresAt, $timezone)->utc() : null;
        if (! is_null($expiresAt) && $expiresAt->lessThanOrEqualTo($publishedAt->copy()->max(now()))) {
            $this->addError('expiresAt', __('The end must be after the publication.'));

            return;
        }
        $reach = PlatformInbox::reach($this->audience, $this->plan ?: null, $this->teamId !== '' ? (int) $this->teamId : null);
        $notification = PlatformInbox::announce([
            'kind' => $this->kind,
            'title' => trim($this->title),
            'body' => trim($this->body) ?: null,
            'action_url' => trim($this->actionUrl) ?: null,
            'action_label' => trim($this->actionUrl) !== '' ? (trim($this->actionLabel) ?: null) : null,
            'audience' => $this->audience,
            'plan' => $this->audience === PlatformNotification::AUDIENCE_PLAN ? $this->plan : null,
            'team_id' => $this->audience === PlatformNotification::AUDIENCE_TEAM ? (int) $this->teamId : null,
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
        ], auth()->user());
        $this->reset('title', 'body', 'actionUrl', 'actionLabel', 'publishAt', 'expiresAt');
        $this->dispatch('success', $notification->published_at->isFuture()
            ? __('Announcement scheduled for :date.', ['date' => $notification->published_at->timezone($timezone)->format('d/m/Y H:i')])
            : __('Announcement sent.'), trans_choice('{0} Nobody receives it yet.|{1} It reaches :count user.|[2,*] It reaches :count users.', $reach, ['count' => $reach]));
    }

    public function end(string $uuid): void
    {
        if (! $this->authorizeChange()) {
            return;
        }
        $notification = $this->announcement($uuid);
        $notification->update(['expires_at' => now()]);
        audit_log('platform.notification.ended', $notification, ['title' => $notification->title], 0);
        $this->dispatch('success', __('Announcement ended: it left the bell of the users.'));
    }

    public function delete(string $uuid): void
    {
        if (! $this->authorizeChange()) {
            return;
        }
        $notification = $this->announcement($uuid);
        audit_log('platform.notification.deleted', null, ['title' => $notification->title, 'uuid' => $notification->uuid], 0);
        $notification->delete();
        $this->dispatch('success', __('Announcement deleted.'));
    }

    public function render()
    {
        abort_unless($this->canView(), 403);
        $teamName = ctype_digit($this->teamId) && (int) $this->teamId !== 0 ? Team::query()->whereKey((int) $this->teamId)->value('name') : null;

        return view('livewire.platform.admin-notifications', [
            'announcements' => PlatformNotification::query()->whereIn('kind', PlatformNotification::ANNOUNCEMENT_KINDS)
                ->with('team:id,name')->withCount('reads')->latest('published_at')->latest('id')->limit(50)->get(),
            'plans' => collect(PlanCatalog::all())->mapWithKeys(fn (array $plan, string $slug) => [$slug => $plan['name'] ?? $slug])->all(),
            'reach' => PlatformInbox::reach($this->audience, $this->plan ?: null, ctype_digit($this->teamId) ? (int) $this->teamId : null),
            'teamName' => $teamName,
            'canEdit' => $this->canEdit(),
            'timezone' => $this->timezone(),
        ]);
    }

    public static function audienceLabel(PlatformNotification $notification): string
    {
        return match ($notification->audience) {
            PlatformNotification::AUDIENCE_CUSTOMERS => __('Customers'),
            PlatformNotification::AUDIENCE_OPERATORS => __('Team of the platform'),
            PlatformNotification::AUDIENCE_PLAN => __('Customers of the plan :plan', ['plan' => PlanCatalog::get($notification->plan)['name'] ?? $notification->plan]),
            PlatformNotification::AUDIENCE_TEAM => __('Customer :name', ['name' => $notification->team?->name ?? '#'.$notification->team_id]),
            default => __('Everyone'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(PlatformNotification::ANNOUNCEMENT_KINDS)],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:2000'],
            'actionUrl' => ['nullable', 'url:http,https', 'max:500'],
            'actionLabel' => ['nullable', 'string', 'max:40'],
            'audience' => ['required', Rule::in(PlatformNotification::AUDIENCES)],
            'plan' => ['required_if:audience,plan', 'nullable', Rule::in(array_keys(PlanCatalog::all()))],
            'teamId' => ['required_if:audience,team', 'nullable', 'integer', 'min:1', Rule::exists('teams', 'id')],
            'publishAt' => ['nullable', 'date'],
            'expiresAt' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attributeNames(): array
    {
        return [
            'kind' => __('type'), 'title' => __('title'), 'body' => __('message'), 'actionUrl' => __('link'),
            'actionLabel' => __('text of the link'), 'audience' => __('audience'), 'plan' => __('plan'),
            'teamId' => __('customer'), 'publishAt' => __('publication'), 'expiresAt' => __('end'),
        ];
    }

    private function announcement(string $uuid): PlatformNotification
    {
        $notification = PlatformNotification::query()->where('uuid', $uuid)->whereIn('kind', PlatformNotification::ANNOUNCEMENT_KINDS)->first();
        abort_if(is_null($notification), 404);

        return $notification;
    }

    private function authorizeChange(): bool
    {
        abort_unless($this->canView(), 403);
        if (! $this->canEdit()) {
            $this->dispatch('error', __('Only the owner of the root team can send announcements.'));

            return false;
        }

        return true;
    }

    private function timezone(): string
    {
        $timezone = (string) data_get(instanceSettings(), 'instance_timezone', config('app.timezone'));

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : (string) config('app.timezone');
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
