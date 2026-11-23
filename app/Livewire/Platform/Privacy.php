<?php

namespace App\Livewire\Platform;

use App\Models\PrivacyDeletedAccount;
use App\Models\PrivacyRequest;
use App\Services\Platform\AccountDeletion;
use App\Services\Platform\Privacy as PrivacyService;
use App\Services\Platform\PrivacyExport;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Privacy and data: the page of the data subject (LGPD, arts. 18 to 20). A summary of the data at
 * once, a complete copy, where to correct it, the consent to promotions, with whom it is shared,
 * requests answered in 15 days and the deletion of the account.
 */
class Privacy extends Component
{
    public bool $marketing = false;

    public string $requestType = 'access';

    public string $requestMessage = '';

    public string $deletePassword = '';

    public string $deleteConfirmation = '';

    public function mount(): void
    {
        abort_unless(isPlatform(), 404);
        $this->marketing = PrivacyService::wantsMarketing(auth()->user());
        $type = (string) request()->query('pedido', '');
        if (in_array($type, PrivacyRequest::TYPES, true)) {
            $this->requestType = $type;
        }
    }

    public function updatedMarketing(bool $value): void
    {
        PrivacyService::setMarketing(auth()->user(), $value, 'privacy');
        $this->dispatch('success', $value ? __('You will receive promotions and news.') : __('You will no longer receive promotions and news.'));
    }

    public function openRequest(): void
    {
        $this->validate([
            'requestType' => ['required', 'in:'.implode(',', PrivacyRequest::TYPES)],
            'requestMessage' => ['nullable', 'string', 'max:2000', $this->requestType === 'other' ? 'required' : 'nullable'],
        ], [], ['requestType' => __('Request'), 'requestMessage' => __('Details')]);
        $request = PrivacyService::openRequest(auth()->user(), $this->requestType, $this->requestMessage);
        $this->reset('requestMessage');
        $this->dispatch('success', __('Request sent.'), __('You will receive the answer by e-mail by :date.', ['date' => $request->due_at->format('d/m/Y')]));
    }

    public function deleteAccount()
    {
        $user = auth()->user();
        $this->validate([
            'deletePassword' => ['required', 'string'],
            'deleteConfirmation' => ['required', 'string'],
        ], [], ['deletePassword' => __('Password'), 'deleteConfirmation' => __('Confirmation')]);
        if (! Hash::check($this->deletePassword, (string) $user->password)) {
            throw ValidationException::withMessages(['deletePassword' => __('Wrong password.')]);
        }
        if (mb_strtoupper(trim($this->deleteConfirmation)) !== mb_strtoupper(__('DELETE'))) {
            throw ValidationException::withMessages(['deleteConfirmation' => __('Type :word to confirm.', ['word' => __('DELETE')])]);
        }
        try {
            AccountDeletion::request($user, PrivacyDeletedAccount::BY_SELF, $user);
        } catch (\RuntimeException $e) {
            $this->dispatch('error', __('The account can not be deleted yet'), e($e->getMessage()));

            return null;
        }
        auth()->guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login');
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.platform.privacy', [
            'summary' => PrivacyExport::summary($user),
            'processors' => PrivacyService::processors(),
            'requests' => PrivacyRequest::query()->where('user_id', $user->id)->latest('id')->limit(20)->get(),
            'types' => PrivacyRequest::TYPES,
            'preview' => AccountDeletion::preview($user),
            'contact' => PrivacyService::contactEmail(),
            'officer' => PrivacyService::officer(),
            'fiscalYears' => AccountDeletion::fiscalYears(),
            'auditMonths' => AccountDeletion::accessRecordMonths(),
        ]);
    }
}
