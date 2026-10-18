<?php

namespace App\Http\Controllers\Gateway;

use App\Actions\Gateway\RecordGatewayEvent;
use App\DTOs\GatewayEventData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gateway\GatewayEventRequest;
use Illuminate\Http\JsonResponse;

class GatewayWebhookController extends Controller
{
    /**
     * Persist a signed gateway event and queue it for processing.
     *
     * 202 accepted · 200 duplicate (already recorded) · 401/413/422 do not retry · 5xx retry.
     */
    public function __invoke(GatewayEventRequest $request, RecordGatewayEvent $record): JsonResponse
    {
        /** @var array{eventId: string, type: string, schemaVersion: int, sessionId: string, occurredAt: string, payload: array<string, mixed>} $data */
        $data = $request->validated();

        $event = $record->handle(GatewayEventData::fromArray($data));

        return $event === null
            ? response()->json(['status' => 'duplicate', 'eventId' => $data['eventId']])
            : response()->json(['status' => 'accepted', 'eventId' => $data['eventId']], 202);
    }
}
