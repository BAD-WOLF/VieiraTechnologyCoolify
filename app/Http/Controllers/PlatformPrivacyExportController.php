<?php

namespace App\Http\Controllers;

use App\Services\Platform\PrivacyExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Complete copy of the personal data of the user, in JSON (LGPD, art. 18, II and V; art. 19, § 3º).
 */
class PlatformPrivacyExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        abort_unless(isPlatform(), 404);
        $user = auth()->user();
        abort_if(is_null($user), 403);
        audit_log('privacy.data.exported', null, [], null, 'user');
        $json = json_encode(PrivacyExport::forUser($user), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response()->streamDownload(fn () => print ($json), 'meus-dados-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json']);
    }
}
