<?php

/**
 * OutreachController — REST surface for oe-module-outreach.
 *
 * Routes (all under /apis/default/fhir/Outreach/...) are registered in
 * Bootstrap::registerOutreachRoutes. The FHIR prefix is the only
 * route-registration slot OpenEMR exposes for custom modules — the
 * endpoints here are NOT FHIR-canonical resources, just operational
 * endpoints living under the FHIR namespace.
 *
 *   GET  /fhir/Outreach/concerns
 *   POST /fhir/Outreach/sweep                  {concern_key?, limit?}
 *   POST /fhir/Outreach/expire-pending         {limit?}
 *   GET  /fhir/Outreach/messages
 *        ?concern_key&patient_id&dispatch_status&resolution&limit&offset
 *   GET  /fhir/Outreach/lookup-by-phone        ?phone=
 *   POST /fhir/Outreach/reply                  {message_id, reply_text}
 *   GET  /fhir/Outreach/preferences            ?patient_id=
 *   PUT  /fhir/Outreach/preferences            {patient_id, ...}
 *
 * Returns are bare JSON {success, ...} — not OperationOutcome — because
 * the agent layer (openemr-mcp) needs structured fields for branching,
 * not a generic FHIR error envelope.
 *
 * @package OpenEMR\Modules\Outreach\Controllers
 */

namespace OpenEMR\Modules\Outreach\Controllers;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\Outreach\Bootstrap;
use OpenEMR\Modules\Outreach\Services\PatientOutreachService;

class OutreachController
{
    private SystemLogger $logger;
    private PatientOutreachService $service;

    public function __construct(Bootstrap $bootstrap)
    {
        $this->logger  = new SystemLogger();
        $this->service = new PatientOutreachService($bootstrap);
    }

    // -------------------------------------------------------------------
    // GET /fhir/Outreach/concerns
    // -------------------------------------------------------------------

    public function listConcerns(HttpRestRequest $request): array
    {
        $concerns = $this->service->getConcernRegistry();
        $out = [];
        foreach ($concerns as $key => $concern) {
            $out[] = [
                'key'                         => (string) $key,
                'label'                       => $concern->getLabel(),
                'description'                 => $concern->getDescription(),
                'timing_hint'                 => $concern->getTimingHint(),
                'expected_response_kind'      => $concern->expectedResponseKind(),
                'default_expires_after_hours' => $concern->defaultExpiresAfterHours(),
                'enabled'                     => $this->isConcernEnabled((string) $key),
            ];
        }
        return [
            'success'  => true,
            'count'    => count($out),
            'concerns' => $out,
            'channels' => array_keys($this->service->getChannelRegistry()),
        ];
    }

    // -------------------------------------------------------------------
    // POST /fhir/Outreach/sweep
    // -------------------------------------------------------------------

    public function sweep(HttpRestRequest $request): array
    {
        $body = $this->readJsonBody();
        $concernKey = isset($body['concern_key']) && $body['concern_key'] !== ''
            ? (string) $body['concern_key']
            : null;
        $limit = isset($body['limit']) ? max(1, (int) $body['limit']) : 50;
        $options = isset($body['options']) && is_array($body['options']) ? $body['options'] : [];

        return $this->service->sweep($concernKey, $limit, $options);
    }

    // -------------------------------------------------------------------
    // POST /fhir/Outreach/send-one
    // -------------------------------------------------------------------

    /**
     * Dispatch a single message for a specific (concern, reference)
     * tuple — staff "send confirmation now" path. Applies the same
     * opt-out / rate-limit / dedup checks as a sweep.
     *
     * Body: {concern_key, reference_type, reference_id,
     *        prompt_override?, expires_after_hours?, skip_dedup?,
     *        meta?: {...}}
     *
     * `meta` is an arbitrary dict of concern-specific context that
     * gets merged into candidate.meta after findCandidateByReference
     * returns — see PatientOutreachService::sendOne for details.
     * AppointmentCancellationConcern uses this to pass
     * cancellation_subtype + refund context.
     */
    public function sendOne(HttpRestRequest $request): array
    {
        $body = $this->readJsonBody();
        $concernKey   = trim((string) ($body['concern_key']   ?? ''));
        $referenceType = trim((string) ($body['reference_type'] ?? ''));
        $referenceId  = $body['reference_id'] ?? null;
        if ($concernKey === '' || $referenceType === '' || $referenceId === null || $referenceId === '') {
            return $this->error('concern_key, reference_type, and reference_id are required', 400);
        }

        $overrides = [];
        if (isset($body['prompt_override'])) {
            $overrides['prompt_override'] = (string) $body['prompt_override'];
        }
        if (isset($body['expires_after_hours'])) {
            $overrides['expires_after_hours'] = (int) $body['expires_after_hours'];
        }
        if (isset($body['skip_dedup'])) {
            $overrides['skip_dedup'] = (bool) $body['skip_dedup'];
        }
        if (isset($body['meta']) && is_array($body['meta'])) {
            $overrides['meta'] = $body['meta'];
        }

        return $this->service->sendOne($concernKey, $referenceType, $referenceId, $overrides);
    }

    // -------------------------------------------------------------------
    // POST /fhir/Outreach/expire-pending
    // -------------------------------------------------------------------

    public function expirePending(HttpRestRequest $request): array
    {
        $body = $this->readJsonBody();
        $limit = isset($body['limit']) ? max(1, (int) $body['limit']) : 200;
        return $this->service->expirePending($limit);
    }

    // -------------------------------------------------------------------
    // GET /fhir/Outreach/messages
    // -------------------------------------------------------------------

    public function listMessages(HttpRestRequest $request): array
    {
        $concernKey      = trim((string) ($_GET['concern_key'] ?? ''));
        $patientId       = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : null;
        $dispatchStatus  = trim((string) ($_GET['dispatch_status'] ?? ''));
        $resolution      = trim((string) ($_GET['resolution'] ?? ''));
        $limit           = max(1, min(500, (int) ($_GET['limit'] ?? 50)));
        $offset          = max(0, (int) ($_GET['offset'] ?? 0));

        $where  = [];
        $params = [];
        if ($concernKey !== '') {
            $where[] = 'concern_type = ?';
            $params[] = $concernKey;
        }
        if ($patientId !== null && $patientId > 0) {
            $where[] = 'patient_id = ?';
            $params[] = $patientId;
        }
        if ($dispatchStatus !== '') {
            $where[] = 'dispatch_status = ?';
            $params[] = $dispatchStatus;
        }
        if ($resolution !== '') {
            // ?resolution=__null__ filters for unresolved (open) messages
            if ($resolution === '__null__') {
                $where[] = 'resolution IS NULL';
            } else {
                $where[] = 'resolution = ?';
                $params[] = $resolution;
            }
        }
        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $rows = sqlStatement(
            "SELECT id, uuid, concern_type, concern_subtype,
                    patient_id, patient_uuid, patient_phone, patient_email,
                    reference_type, reference_id,
                    channel, dispatch_status, dispatch_result,
                    resolution, resolution_reply,
                    sent_at, resolved_at, expires_at, created_at
               FROM module_outreach_messages
               $whereSql
           ORDER BY id DESC
              LIMIT $limit OFFSET $offset",
            $params
        );

        $countRow = sqlQuery(
            "SELECT COUNT(*) AS n FROM module_outreach_messages $whereSql",
            $params
        );

        $messages = [];
        while ($row = sqlFetchArray($rows)) {
            $row['id']           = (int) $row['id'];
            $row['patient_id']   = (int) $row['patient_id'];
            $row['reference_id'] = $row['reference_id'] !== null ? (int) $row['reference_id'] : null;
            $messages[] = $row;
        }

        return [
            'success'    => true,
            'count'      => count($messages),
            'total'      => (int) ($countRow['n'] ?? 0),
            'limit'      => $limit,
            'offset'     => $offset,
            'messages'   => $messages,
        ];
    }

    // -------------------------------------------------------------------
    // GET /fhir/Outreach/lookup-by-phone?phone=...&thread_id=...
    // -------------------------------------------------------------------

    /**
     * Inbound-reply correlation. Either `phone` OR `thread_id` (or
     * both) is required. When both are provided, thread_id wins —
     * it's a strictly stronger join key (1:1 mapping between
     * outbound and inbound) provided by SMS providers like Doximity
     * (PatientMessageThread/<uuid>). Phone is the fallback for
     * providers that don't expose a thread id, or for legacy callers.
     */
    public function lookupByPhone(HttpRestRequest $request): array
    {
        $phone    = trim((string) ($_GET['phone']     ?? ''));
        $threadId = trim((string) ($_GET['thread_id'] ?? ''));
        if ($phone === '' && $threadId === '') {
            return $this->error('Either phone or thread_id query parameter is required', 400);
        }
        $row = $this->service->lookupByPhone($phone, $threadId !== '' ? $threadId : null);
        if ($row === null) {
            return $this->error('No pending outreach message for this phone/thread', 404);
        }
        return ['success' => true] + $row;
    }

    // -------------------------------------------------------------------
    // POST /fhir/Outreach/reply
    // -------------------------------------------------------------------

    public function reply(HttpRestRequest $request): array
    {
        $body      = $this->readJsonBody();
        $messageId = isset($body['message_id']) ? (int) $body['message_id'] : 0;
        $replyText = (string) ($body['reply_text'] ?? '');
        if ($messageId <= 0 || $replyText === '') {
            return $this->error('message_id and reply_text are required', 400);
        }
        return $this->service->handleReply($messageId, $replyText);
    }

    // -------------------------------------------------------------------
    // GET /fhir/Outreach/preferences?patient_id=...
    // -------------------------------------------------------------------

    public function getPreferences(HttpRestRequest $request): array
    {
        $patientId = (int) ($_GET['patient_id'] ?? 0);
        if ($patientId <= 0) {
            return $this->error('patient_id query parameter is required', 400);
        }

        $row = sqlQuery(
            "SELECT patient_id, master_opt_out, channel_preference,
                    concern_opt_outs, quiet_hours_start, quiet_hours_end,
                    updated_at
               FROM module_outreach_patient_prefs
              WHERE patient_id = ? LIMIT 1",
            [$patientId]
        );

        if (empty($row)) {
            // No row = patient is implicitly opted-in with practice defaults.
            return [
                'success'             => true,
                'patient_id'          => $patientId,
                'master_opt_out'      => false,
                'channel_preference'  => null,
                'concern_opt_outs'    => [],
                'quiet_hours_start'   => null,
                'quiet_hours_end'     => null,
                'has_explicit_prefs'  => false,
            ];
        }

        $optOuts = $row['concern_opt_outs']
            ? (json_decode($row['concern_opt_outs'], true) ?: [])
            : [];

        return [
            'success'             => true,
            'patient_id'          => (int) $row['patient_id'],
            'master_opt_out'      => (int) $row['master_opt_out'] === 1,
            'channel_preference'  => $row['channel_preference'] ?: null,
            'concern_opt_outs'    => is_array($optOuts) ? array_values($optOuts) : [],
            'quiet_hours_start'   => $row['quiet_hours_start'] !== null ? (int) $row['quiet_hours_start'] : null,
            'quiet_hours_end'     => $row['quiet_hours_end'] !== null ? (int) $row['quiet_hours_end'] : null,
            'has_explicit_prefs'  => true,
            'updated_at'          => (string) ($row['updated_at'] ?? ''),
        ];
    }

    // -------------------------------------------------------------------
    // PUT /fhir/Outreach/preferences
    // -------------------------------------------------------------------

    public function setPreferences(HttpRestRequest $request): array
    {
        $body = $this->readJsonBody();
        $patientId = (int) ($body['patient_id'] ?? 0);
        if ($patientId <= 0) {
            return $this->error('patient_id is required', 400);
        }

        $existing = sqlQuery(
            "SELECT patient_id, master_opt_out, channel_preference,
                    concern_opt_outs, quiet_hours_start, quiet_hours_end
               FROM module_outreach_patient_prefs
              WHERE patient_id = ? LIMIT 1",
            [$patientId]
        ) ?: [];

        // Merge inbound over existing — fields not present keep current value.
        $masterOptOut = array_key_exists('master_opt_out', $body)
            ? (int) (bool) $body['master_opt_out']
            : (int) ($existing['master_opt_out'] ?? 0);

        $channelPref = array_key_exists('channel_preference', $body)
            ? ($body['channel_preference'] === null ? null : (string) $body['channel_preference'])
            : ($existing['channel_preference'] ?? null);

        $concernOptOuts = array_key_exists('concern_opt_outs', $body)
            ? (is_array($body['concern_opt_outs']) ? $body['concern_opt_outs'] : [])
            : (!empty($existing['concern_opt_outs'])
                ? (json_decode($existing['concern_opt_outs'], true) ?: [])
                : []);
        $concernOptOutsJson = json_encode(array_values($concernOptOuts), JSON_UNESCAPED_SLASHES);

        $quietStart = array_key_exists('quiet_hours_start', $body)
            ? ($body['quiet_hours_start'] === null ? null : max(0, min(23, (int) $body['quiet_hours_start'])))
            : ($existing['quiet_hours_start'] ?? null);

        $quietEnd = array_key_exists('quiet_hours_end', $body)
            ? ($body['quiet_hours_end'] === null ? null : max(0, min(23, (int) $body['quiet_hours_end'])))
            : ($existing['quiet_hours_end'] ?? null);

        if (empty($existing)) {
            sqlStatement(
                "INSERT INTO module_outreach_patient_prefs
                    (patient_id, master_opt_out, channel_preference,
                     concern_opt_outs, quiet_hours_start, quiet_hours_end)
                 VALUES (?,?,?,?,?,?)",
                [
                    $patientId, $masterOptOut, $channelPref,
                    $concernOptOutsJson, $quietStart, $quietEnd,
                ]
            );
        } else {
            sqlStatement(
                "UPDATE module_outreach_patient_prefs
                    SET master_opt_out = ?, channel_preference = ?,
                        concern_opt_outs = ?, quiet_hours_start = ?,
                        quiet_hours_end = ?
                  WHERE patient_id = ?",
                [
                    $masterOptOut, $channelPref,
                    $concernOptOutsJson, $quietStart, $quietEnd,
                    $patientId,
                ]
            );
        }

        return [
            'success'             => true,
            'patient_id'          => $patientId,
            'master_opt_out'      => (bool) $masterOptOut,
            'channel_preference'  => $channelPref,
            'concern_opt_outs'    => array_values($concernOptOuts),
            'quiet_hours_start'   => $quietStart,
            'quiet_hours_end'     => $quietEnd,
        ];
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function isConcernEnabled(string $concernKey): bool
    {
        $row = sqlQuery(
            "SELECT enabled FROM module_outreach_concerns_config
              WHERE concern_type = ? LIMIT 1",
            [$concernKey]
        );
        // No row = code default = enabled.
        return empty($row) ? true : ((int) $row['enabled'] === 1);
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function error(string $message, int $statusCode = 400): array
    {
        http_response_code($statusCode);
        return ['success' => false, 'error' => $message];
    }
}
