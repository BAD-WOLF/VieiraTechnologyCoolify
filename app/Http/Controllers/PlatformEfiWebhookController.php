<?php

namespace App\Http\Controllers;

use App\Services\Platform\Efi\EfiSettings;
use App\Services\Platform\PlatformBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Efí notifications. The URL carries a secret token (Efí is told not to ask for our client
 * certificate) and nothing in a notification is trusted: the charges it names are read again
 * from the Efí APIs.
 *
 * - Pix of the account key: Efí posts {"pix": [...]} to "{url}/pix" (also on registration).
 * - Boletos: Efí posts a "notification" token to "{url}/charges" whenever a boleto changes.
 */
class PlatformEfiWebhookController extends Controller
{
    public function pix(Request $request, string $token): JsonResponse|Response
    {
        if (! $this->accepts($token)) {
            return response('Not Found', 404, ['Content-Type' => 'text/plain']);
        }
        // The test notification of the registration must get a 2XX, whatever it carries.
        $payload = json_decode((string) $request->getContent(), true);

        return response()->json(['received' => is_array($payload) ? PlatformBilling::handleWebhook($payload) : 0]);
    }

    public function charges(Request $request, string $token): JsonResponse|Response
    {
        if (! $this->accepts($token)) {
            return response('Not Found', 404, ['Content-Type' => 'text/plain']);
        }
        $notification = (string) $request->input('notification', '');
        if (! preg_match('/^[A-Za-z0-9\-]{8,100}$/', $notification)) {
            return response()->json(['message' => 'Invalid notification.'], 400);
        }

        return response()->json(['received' => PlatformBilling::handleChargeNotification($notification)]);
    }

    private function accepts(string $token): bool
    {
        $expected = (string) EfiSettings::value('webhook_token');

        return isPlatform() && PlatformBilling::enabled() && $expected !== '' && hash_equals($expected, $token);
    }
}
