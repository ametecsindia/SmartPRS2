<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PunchIngestService;
use App\Services\SmarteptWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/webhooks/smartept/{slug}
 *
 * The receiving half of SmartEPT's OutboundPusher. Authenticated by
 * App\Http\Middleware\SmarteptSignature: by the time we are here the HMAC has
 * matched and tenant_id/company_id are on $request->attributes, off the
 * endpoint row. Nothing identity-bearing is read out of the body.
 *
 * The response follows the same contract as the SBB ingest endpoint: a verdict
 * PER PUNCH, never a bare count. SmartEPT's pusher is fire-and-forget and
 * records only the status line, so the detail is for the operator reading logs
 * and for anyone testing the endpoint by hand — but the rule holds either way,
 * because a 200 that quietly dropped a punch is the failure mode this whole
 * path exists to avoid.
 */
class SmarteptWebhookController extends Controller
{
    public function receive(Request $request)
    {
        $tenantId = (int) $request->attributes->get('tenant_id');
        $endpointId = (int) $request->attributes->get('smartept_endpoint_id');
        $subscribed = (array) $request->attributes->get('smartept_events', SmarteptWebhook::EVENTS);

        $payload = json_decode((string) $request->getContent(), true);
        if (! is_array($payload)) {
            self::record($endpointId, null, 'REJECTED body was not JSON', 0, 0);

            return response()->json([
                'error' => ['code' => 'WEBHOOK_422', 'message' => 'Body was not valid JSON.'],
            ], 422);
        }

        // The body is authoritative; the header is a hint the sender also sets.
        // Disagreement means something rewrote one of them in transit — say so
        // rather than silently trusting whichever we happened to read first.
        $event = is_string($payload['event'] ?? null) ? trim($payload['event']) : '';
        $headerEvent = trim((string) $request->header(SmarteptWebhook::EVENT_HEADER, ''));
        if ($event === '') {
            $event = $headerEvent;
        } elseif ($headerEvent !== '' && $headerEvent !== $event) {
            self::record($endpointId, $event, 'REJECTED event header/body mismatch', 0, 0);

            return response()->json([
                'error' => [
                    'code' => 'WEBHOOK_422',
                    'message' => 'X-SmartEPT-Event ("'.$headerEvent.'") does not match the body event ("'.$event.'").',
                ],
            ], 422);
        }

        // Not subscribed, or an event this receiver does not implement: 202, not
        // an error. SmartEPT pushes every subscribed event to every target and
        // logs a non-2xx as a failure the operator then has to chase; an event
        // we deliberately ignore is not a failure.
        if (! in_array($event, SmarteptWebhook::EVENTS, true) || ! in_array($event, $subscribed, true)) {
            self::record($endpointId, $event ?: null, 'IGNORED not subscribed', 0, 0);

            return response()->json([
                'ok' => true,
                'ignored' => true,
                'event' => $event ?: null,
                'message' => 'This endpoint does not accept "'.($event ?: 'unnamed').'".',
            ], 202);
        }

        $translated = SmarteptWebhook::punchesFor($event, $payload);
        $punches = $translated['punches'];

        if ($punches === []) {
            self::record($endpointId, $event, 'OK nothing to store', 0, 0);

            return response()->json([
                'ok' => true,
                'event' => $event,
                'batch' => ['received' => 0, 'accepted' => 0, 'duplicates' => 0, 'pending' => 0, 'rejected' => 0, 'skipped' => $translated['skipped']],
                'results' => [],
                'message' => $translated['note'] ?? 'Nothing in this payload needed storing.',
            ]);
        }

        // A daily push for a large company expands past MAX_BATCH (two punches
        // per employee). Chunk it rather than refusing it — the sender cannot
        // split the day for us, and a rejected 422 would just lose the day.
        $counts = ['received' => 0, 'accepted' => 0, 'duplicates' => 0, 'pending' => 0, 'rejected' => 0];
        $results = [];

        foreach (array_chunk($punches, PunchIngestService::MAX_BATCH) as $chunk) {
            $out = PunchIngestService::ingest(
                $chunk,
                $tenantId,
                $request->attributes->get('company_id'),
                'smartept:'.$request->attributes->get('smartept_endpoint_name', ''),
                SmarteptWebhook::SOURCE
            );

            foreach ($counts as $k => $_) {
                $counts[$k] += $out['batch'][$k] ?? 0;
            }
            $results = array_merge($results, $out['results']);
        }

        $counts['skipped'] = $translated['skipped'];

        Log::info('smartept.webhook.received', [
            'endpoint' => $endpointId,
            'tenant' => $tenantId,
            'event' => $event,
            'smartept_company_id' => $payload['company_id'] ?? null,
        ] + $counts);

        self::record(
            $endpointId,
            $event,
            'OK '.$counts['accepted'].' accepted, '.$counts['duplicates'].' duplicate, '
                .$counts['pending'].' pending, '.$counts['rejected'].' rejected',
            $counts['accepted'],
            $counts['rejected']
        );

        return response()->json([
            'ok' => true,
            'event' => $event,
            'batch' => $counts,
            'results' => $results,
        ]);
    }

    /**
     * Stamp delivery health on the endpoint row.
     *
     * Telemetry only. It must never turn a stored punch into a failed response,
     * so every failure here is swallowed — the punches are already durable by
     * the time this runs.
     */
    private static function record(int $endpointId, ?string $event, string $status, int $accepted, int $rejected): void
    {
        if ($endpointId <= 0) {
            return;
        }

        try {
            DB::table('smartept_webhook_endpoints')->where('id', $endpointId)->update([
                'last_received_at' => now(),
                'last_event' => $event !== null ? mb_substr($event, 0, 64) : null,
                'last_status' => mb_substr($status, 0, 191),
                'received_count' => DB::raw('received_count + 1'),
                'accepted_count' => DB::raw('accepted_count + '.$accepted),
                'rejected_count' => DB::raw('rejected_count + '.$rejected),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
