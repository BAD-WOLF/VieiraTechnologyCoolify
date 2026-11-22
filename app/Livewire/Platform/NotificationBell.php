<?php

namespace App\Livewire\Platform;

use App\Models\PlatformNotification;
use App\Services\Platform\PlatformInbox;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The bell at the top right of the dashboard: announcements of the owner and events of the
 * account, with the unread count. The list is read when the bell is opened; a new notification
 * refreshes the count right away through the websocket.
 */
class NotificationBell extends Component
{
    public bool $loaded = false;

    /**
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $listeners = ['echo:platform-notifications,PlatformNotificationPublished' => '$refresh'];
        $teamId = currentTeam()?->id;
        if (! is_null($teamId)) {
            $listeners["echo-private:team.{$teamId},PlatformNotificationPublished"] = '$refresh';
        }

        return $listeners;
    }

    public function load(): void
    {
        $this->loaded = true;
    }

    public function markRead(string $uuid): void
    {
        $notification = $this->notification($uuid);
        PlatformInbox::markRead(auth()->user(), $notification);
    }

    /**
     * Opens the link of a notification, marking it read.
     */
    public function follow(string $uuid): void
    {
        $notification = $this->notification($uuid);
        PlatformInbox::markRead(auth()->user(), $notification);
        if (filled($notification->action_url)) {
            $this->redirect($notification->action_url);
        }
    }

    public function markAllRead(): void
    {
        abort_unless(auth()->check(), 403);
        PlatformInbox::markAllRead(auth()->user(), currentTeam());
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.platform.notification-bell', [
            'unread' => $user ? PlatformInbox::unreadCount($user, currentTeam()) : 0,
            'notifications' => $user && $this->loaded ? PlatformInbox::for($user, currentTeam()) : new Collection,
        ]);
    }

    private function notification(string $uuid): PlatformNotification
    {
        abort_unless(auth()->check(), 403);
        $notification = PlatformInbox::find(auth()->user(), currentTeam(), $uuid);
        abort_if(is_null($notification), 404);

        return $notification;
    }
}
