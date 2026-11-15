<?php

namespace App\Http\Controllers;

use App\Services\Platform\Inter\InterSettings;
use App\Services\Platform\InterBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Banco Inter webhook of the charges (boleto + Pix). The URL carries a secret token; the payload
 * only tells which charges changed, their status is always read again from the Inter API.
 */
class PlatformInterWebhookController extends Controller
{
    public function __invoke(Request $request, string $token): JsonResponse|Response
    {
        $expected = (string) InterSettings::value('webhook_token');
        if (! isPlatform() || ! InterBilling::enabled() || $expected === '' || ! hash_equals($expected, $token)) {
            return response('Not Found', 404, ['Content-Type' => 'text/plain']);
        }
        $payload = json_decode((string) $request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        return response()->json(['received' => InterBilling::handleWebhook($payload)]);
    }
}
