<?php

/**
 * PatientOutreachService — the central orchestrator.
 *
 * Every outreach message in the system passes through this service:
 *
 *   sweep(concernKey?)
 *     → collects candidates from concern.findCandidates()
 *     → dedups against unresolved module_outreach_messages rows
 *     → for each remaining candidate:
 *         → checks master opt-out
 *         → checks per-concern opt-out
 *         → checks rate limit
 *         → checks quiet hours (SMS only)
 *         → picks a channel via preference walk
 *         → dispatches via the chosen ChannelDispatcher
 *         → writes a row to module_outreach_messages
 *     → returns {sent, skipped, considered}
 *
 *   lookupByPhone(phone)
 *     → finds the most-recent pending message for this phone, returns
 *       enough linkage for the agent layer to act on the inbound reply
 *
 *   handleReply(messageId, replyText)
 *     → delegates to the concern's handleReply()
 *     → updates resolution + downstream action
 *
 *   expirePending()
 *     → flips stale pending messages to no_response
 *
 * Concerns and channel dispatchers are registered via Symfony events
 * fired lazily on first sweep, so this service has no compile-time
 * dependency on any specific concern or channel — adding a new
 * concern or channel doesn't require editing this file.
 *
 * @package OpenEMR\Modules\Outreach\Services
 */

namespace OpenEMR\Modules\Outreach\Services;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\Outreach\Bootstrap;
use OpenEMR\Modules\Outreach\Events\OutreachConcernRegistryEvent;
use OpenEMR\Modules\Outreach\GlobalConfig;
use OpenEMR\Modules\Outreach\OutreachConcern;

class PatientOutreachService
{
    private const TABLE_MESSAGES   = 'module_outreach_messages';
    private const TABLE_CONFIG     = 'module_outreach_concerns_config';
    private const TABLE_PREFS      = 'module_outreach_patient_prefs';
    private const TABLE_RATE_LIMIT = 'module_outreach_rate_limits';

    private SystemLogger $logger;
    private GlobalConfig $config;
    private Bootstrap $bootstrap;

    /** @var array<string, OutreachConcern>|null lazy-loaded concern registry */
    private ?array $concerns = null;

    /** @var array<string, OutreachChannelDispatcher>|null lazy-loaded channel registry */
    private ?array $channels = null;

    public function __construct(Bootstrap $bootstrap)
    {
        $this->bootstrap = $bootstrap;
        $this->config    = $bootstrap->getGlobalConfig();
        $this->logger    = new SystemLogger();
    }

    /**
     * Static factory — convenience for callers that don't already hold
     * the Bootstrap instance. Builds a Bootstrap on the global event
     * dispatcher (process-wide, set up by OpenEMR's kernel) so concern
     * listeners registered at module-load time still respond.
     *
     * Use this from other modules that want to delegate to the central
     * outreach service without a hard dependency on this module's
     * construction details:
     *
     *   $outreach = PatientOutreachService::create();
     *   $outreach->sweep('appt_confirmation');
     */
    public static function create(): self
    {
        global $kernel;
        if (!$kernel) {
            throw new \RuntimeException(
                'PatientOutreachService::create requires OpenEMR $kernel — call from within an HTTP request lifecycle.'
            );
        }
        $bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel);
        return new self($bootstrap);
    }

    // -------------------------------------------------------------------
    // Sweep — top-level dispatcher
    // -------------------------------------------------------------------

    /**
     * Run a sweep across one or all registered concerns.
     *
     * @param string|null $concernKey null = all enabled concerns
     * @param int $limitPerConcern max sends per concern (caps batch size)
     * @param array $options per-call override bag passed to each
     *        concern's findCandidates(). Concern-specific keys
     *        (e.g. 'min_hours_ahead' for appt_confirmation) — concerns
     *        that don't recognize a key ignore it.
     * @return array {sent_count, skipped_count, considered, per_concern: [...]}
     */
    public function sweep(?string $concernKey = null, int $limitPerConcern = 50, array $options = []): array
    {
        if (!$this->config->isEnabled()) {
            return [
                'success' => false,
                'error'   => 'Outreach is disabled (oe_outreach_enabled global is OFF).',
            ];
        }

        $registry = $this->getConcernRegistry();
        $targets  = $concernKey ? array_intersect_key($registry, [$concernKey => true]) : $registry;
        if (empty($targets)) {
            return [
                'success' => false,
                'error'   => $concernKey
                    ? "No registered concern '$concernKey'"
                    : 'No concerns registered',
            ];
        }

        $perConcern = [];
        $totalSent = 0;
        $totalSkipped = 0;
        $totalConsidered = 0;

        foreach ($targets as $key => $concern) {
            if (!$this->isConcernEnabled($key)) {
                $perConcern[$key] = [
                    'enabled' => false,
                    'considered' => 0, 'sent_count' => 0, 'skipped_count' => 0,
                ];
                continue;
            }

            $result = $this->sweepConcern($concern, $limitPerConcern, $options);
            $perConcern[$key] = $result;
            $totalSent       += $result['sent_count'];
            $totalSkipped    += $result['skipped_count'];
            $totalConsidered += $result['considered'];
        }

        return [
            'success'         => true,
            'considered'      => $totalConsidered,
            'sent_count'      => $totalSent,
            'skipped_count'   => $totalSkipped,
            'per_concern'     => $perConcern,
        ];
    }

    /**
     * Run a single concern's sweep. Iterates candidates, applies cross-
     * cutting filters, dispatches, writes audit rows. Per-candidate
     * failures are isolated — one bad row doesn't kill the rest.
     */
    private function sweepConcern(OutreachConcern $concern, int $limit, array $options = []): array
    {
        $key = $concern->getKey();
        $sent = [];
        $skipped = [];
        $considered = 0;

        try {
            $candidates = $concern->findCandidates($options);
        } catch (\Throwable $e) {
            $this->logger->error("OUTREACH: findCandidates failed for $key", ['error' => $e->getMessage()]);
            return [
                'considered' => 0, 'sent_count' => 0, 'skipped_count' => 0,
                'error' => $e->getMessage(),
            ];
        }

        foreach ($candidates as $candidate) {
            if ($considered >= $limit) {
                break;
            }
            $considered++;

            // Resolve patient details once per candidate.
            $patientId = (int) ($candidate['patient_id'] ?? 0);
            if ($patientId <= 0) {
                $skipped[] = ['reason' => 'missing_patient_id', 'candidate' => $candidate];
                continue;
            }
            $patient = $this->getPatient($patientId);
            if (empty($patient)) {
                $skipped[] = ['reason' => 'patient_not_found', 'patient_id' => $patientId];
                continue;
            }

            // Cross-cutting filters: opt-out → rate limit → quiet hours.
            if ($this->isPatientOptedOut($patientId, $key)) {
                $skipped[] = ['reason' => 'patient_opted_out', 'patient_id' => $patientId];
                continue;
            }
            if ($this->isRateLimited($patientId, $key)) {
                $skipped[] = ['reason' => 'rate_limited', 'patient_id' => $patientId];
                continue;
            }

            // Dedup: skip if there's already an unresolved message for
            // the same (concern_type, concern_subtype, reference).
            // Concerns should ideally pre-filter, but the service-layer
            // check is the safety net. concern_subtype is the
            // distinguishing slot for cadence concerns — appointment
            // reminders at 7d/3d/1d/0d are 4 separate sends per
            // appointment, each tracked independently via subtype.
            $refType = $candidate['reference_type'] ?? null;
            $refId   = isset($candidate['reference_id']) ? (int) $candidate['reference_id'] : null;
            $subtype = $candidate['concern_subtype'] ?? null;
            if ($refType && $refId && $this->hasUnresolvedMessage($key, $refType, $refId, $subtype)) {
                $skipped[] = [
                    'reason' => 'already_pending',
                    'patient_id' => $patientId,
                    'reference' => "$refType/$refId",
                    'concern_subtype' => $subtype,
                ];
                continue;
            }

            // Build message + dispatch. Per-call prompt override
            // bypasses the concern's templating — useful for staff
            // ad-hoc sends with custom wording, or when a concern's
            // default doesn't fit the situation. The override lives
            // on the candidate dict so it flows through sweep AND
            // sendOne paths uniformly.
            try {
                $messageText = isset($candidate['prompt_override']) && $candidate['prompt_override'] !== ''
                    ? (string) $candidate['prompt_override']
                    : $concern->buildMessage($candidate);
            } catch (\Throwable $e) {
                $skipped[] = ['reason' => 'build_message_exception', 'error' => $e->getMessage()];
                continue;
            }

            $channelOrder = $this->resolveChannelOrder($patientId, $key);
            $dispatchResult = $this->dispatchMessage(
                $patient, $messageText, $channelOrder, $key, $concern, $candidate
            );

            if (!empty($dispatchResult['success'])) {
                $sent[] = $dispatchResult;
            } else {
                $skipped[] = $dispatchResult + ['reason' => $dispatchResult['error_kind'] ?? 'dispatch_failed'];
            }
        }

        return [
            'considered'    => $considered,
            'sent_count'    => count($sent),
            'skipped_count' => count($skipped),
            'sent'          => $sent,
            'skipped'       => $skipped,
        ];
    }

    /**
     * Dispatch one message for one specific reference, on demand.
     *
     * Equivalent to staff clicking "send confirmation now" on a
     * specific appointment instead of waiting for the next sweep.
     * Applies the same cross-cutting filters (opt-out, rate-limit,
     * dedup) that sweep applies — sendOne is a shortcut, NOT a
     * back door around the platform's safeguards.
     *
     * Overrides accepted:
     *   - prompt_override: string — custom message text, bypasses
     *     concern.buildMessage()
     *   - expires_after_hours: int — overrides the concern default
     *   - skip_dedup: bool — true to dispatch even when an unresolved
     *     row already exists for the same (concern, reference). Use
     *     sparingly — exposed for staff "resend" workflows.
     *
     * Returns the same shape as a sweep's `sent[]` / `skipped[]`
     * entries plus a top-level success flag for convenience.
     *
     * @param string $concernKey
     * @param string $referenceType
     * @param mixed  $referenceId
     * @param array  $overrides
     */
    public function sendOne(
        string $concernKey,
        string $referenceType,
        $referenceId,
        array $overrides = []
    ): array {
        if (!$this->config->isEnabled()) {
            return [
                'success' => false,
                'error'   => 'Outreach is disabled (oe_outreach_enabled global is OFF).',
            ];
        }

        $registry = $this->getConcernRegistry();
        $concern  = $registry[$concernKey] ?? null;
        if ($concern === null) {
            return [
                'success' => false,
                'error'   => "No registered concern '$concernKey'",
            ];
        }
        if (!$this->isConcernEnabled($concernKey)) {
            return [
                'success' => false,
                'error'   => "Concern '$concernKey' is disabled in concerns_config",
            ];
        }

        $candidate = $concern->findCandidateByReference($referenceType, $referenceId);
        if ($candidate === null) {
            return [
                'success' => false,
                'error'   => "Concern '$concernKey' has no candidate for $referenceType/$referenceId",
                'reason'  => 'candidate_not_found',
            ];
        }

        // Bake the overrides into the candidate dict so the dispatch
        // path picks them up the same way it picks up sweep overrides.
        if (isset($overrides['prompt_override']) && $overrides['prompt_override'] !== '') {
            $candidate['prompt_override'] = (string) $overrides['prompt_override'];
        }
        if (isset($overrides['expires_after_hours'])) {
            $candidate['expires_after_hours'] = (int) $overrides['expires_after_hours'];
        }

        $patientId = (int) ($candidate['patient_id'] ?? 0);
        if ($patientId <= 0) {
            return [
                'success' => false,
                'error'   => 'Concern returned a candidate without patient_id',
                'reason'  => 'invalid_candidate',
            ];
        }
        $patient = $this->getPatient($patientId);
        if (empty($patient)) {
            return [
                'success' => false,
                'error'   => "Patient $patientId not found",
                'reason'  => 'patient_not_found',
            ];
        }

        // Cross-cutting filters — same as sweepConcern. sendOne does
        // NOT bypass them by default; staff needs to explicitly opt-in
        // via `skip_dedup` for resend scenarios.
        if ($this->isPatientOptedOut($patientId, $concernKey)) {
            return [
                'success' => false,
                'error'   => 'Patient is opted out',
                'reason'  => 'patient_opted_out',
                'patient_id' => $patientId,
            ];
        }
        if ($this->isRateLimited($patientId, $concernKey)) {
            return [
                'success' => false,
                'error'   => 'Patient has hit the daily rate limit',
                'reason'  => 'rate_limited',
                'patient_id' => $patientId,
            ];
        }

        $skipDedup = !empty($overrides['skip_dedup']);
        $refType   = (string) ($candidate['reference_type'] ?? $referenceType);
        $refId     = (int) ($candidate['reference_id'] ?? (is_numeric($referenceId) ? $referenceId : 0));
        $subtype   = $candidate['concern_subtype'] ?? null;
        if (!$skipDedup && $refType && $refId
            && $this->hasUnresolvedMessage($concernKey, $refType, $refId, $subtype)) {
            return [
                'success' => false,
                'error'   => "An unresolved $concernKey message already exists for $refType/$refId",
                'reason'  => 'already_pending',
                'patient_id' => $patientId,
                'reference'  => "$refType/$refId",
            ];
        }

        // Build text + dispatch via the existing path so audit row,
        // rate-limit increment, and channel walk all happen the same
        // way they do for sweeps.
        try {
            $messageText = isset($candidate['prompt_override']) && $candidate['prompt_override'] !== ''
                ? (string) $candidate['prompt_override']
                : $concern->buildMessage($candidate);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => 'buildMessage threw: ' . $e->getMessage(),
                'reason'  => 'build_message_exception',
            ];
        }

        $channelOrder = $this->resolveChannelOrder($patientId, $concernKey);
        $result = $this->dispatchMessage(
            $patient, $messageText, $channelOrder, $concernKey, $concern, $candidate
        );
        $result['success'] = !empty($result['success']);
        return $result;
    }

    /**
     * Walk the channel-preference list, attempt dispatch via the first
     * one that can reach the patient + write the audit row regardless
     * of outcome (sent/failed/skipped/dry_run).
     */
    private function dispatchMessage(
        array $patient,
        string $messageText,
        array $channelOrder,
        string $concernKey,
        OutreachConcern $concern,
        array $candidate
    ): array {
        $registry = $this->getChannelRegistry();
        $context = $candidate['meta'] ?? [];

        $dryRun = $this->config->isDryRun();
        $expiresHours = $candidate['expires_after_hours']
            ?? $this->getConcernExpiresHours($concernKey)
            ?? $concern->defaultExpiresAfterHours();
        $expiresAt = $expiresHours > 0
            ? date('Y-m-d H:i:s', strtotime("+$expiresHours hours"))
            : null;

        $messageUuidString = $this->generateMessageUuid();

        // Try each channel in order. First one that says canDispatch=true
        // gets to attempt; we record the outcome of that one.
        foreach ($channelOrder as $channelId) {
            $dispatcher = $registry[$channelId] ?? null;
            if (!$dispatcher || !$dispatcher->canDispatch($patient)) {
                continue;
            }

            // Dry run: write a tracking row marked dry_run, no actual send.
            if ($dryRun) {
                return $this->writeMessageRow(
                    $messageUuidString, $concernKey, $concern, $candidate,
                    $patient, $channelId, $messageText, $expiresAt,
                    'dry_run', null, []
                );
            }

            // Real dispatch.
            $result = $dispatcher->dispatch($patient, $messageText, $context);
            $status = !empty($result['success']) ? 'sent' : 'failed';
            $detail = !empty($result['success']) ? null : ($result['error'] ?? 'unknown');

            $row = $this->writeMessageRow(
                $messageUuidString, $concernKey, $concern, $candidate,
                $patient, $channelId, $messageText, $expiresAt,
                $status, $detail, $result
            );
            $row['success'] = !empty($result['success']);
            $row['error']      = $row['success'] ? null : $detail;
            $row['error_kind'] = $row['success'] ? null : 'channel_dispatch_failed';
            return $row;
        }

        // No channel could dispatch. Write a 'skipped' row so the audit
        // trail shows we tried but the patient had no reachable channel.
        $row = $this->writeMessageRow(
            $messageUuidString, $concernKey, $concern, $candidate,
            $patient, 'none', $messageText, $expiresAt,
            'skipped', 'no_channel_can_dispatch', []
        );
        $row['success']    = false;
        $row['error']      = 'No channel can reach this patient';
        $row['error_kind'] = 'no_channel_available';
        return $row;
    }

    private function writeMessageRow(
        string $uuid, string $concernKey, OutreachConcern $concern, array $candidate,
        array $patient, string $channel, string $messageText, ?string $expiresAt,
        string $dispatchStatus, ?string $dispatchResult, array $resultRaw
    ): array {
        $patientUuid = $this->safeUuidToString($patient['uuid'] ?? null);
        $refType = $candidate['reference_type'] ?? null;
        $refId   = isset($candidate['reference_id']) ? (int) $candidate['reference_id'] : null;
        $sentAt  = in_array($dispatchStatus, ['sent', 'dry_run'], true) ? date('Y-m-d H:i:s') : null;
        $meta    = json_encode($candidate['meta'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES);

        sqlStatement(
            "INSERT INTO " . self::TABLE_MESSAGES . " (
                uuid, concern_type, concern_subtype,
                patient_id, patient_uuid, patient_phone, patient_email,
                reference_type, reference_id,
                channel, prompt_text, meta, expected_response_kind,
                dispatch_status, dispatch_result, sent_at, expires_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $uuid, $concernKey,
                $candidate['concern_subtype'] ?? null,
                (int) $patient['pid'], $patientUuid,
                $patient['phone_cell'] ?: ($patient['phone_home'] ?? null),
                $patient['email'] ?? null,
                $refType, $refId,
                $channel, $messageText, $meta, $concern->expectedResponseKind(),
                $dispatchStatus, $dispatchResult, $sentAt, $expiresAt,
            ]
        );
        $messageId = (int) sqlQuery("SELECT LAST_INSERT_ID() AS id")['id'];

        // Bump the rate-limit counter for sent / dry_run only.
        if (in_array($dispatchStatus, ['sent', 'dry_run'], true)) {
            $this->incrementRateLimit((int) $patient['pid'], $concernKey);
        }

        return [
            'message_id' => $messageId,
            'message_uuid' => $uuid,
            'concern_type' => $concernKey,
            'patient_id' => (int) $patient['pid'],
            'channel' => $channel,
            'dispatch_status' => $dispatchStatus,
            'sent_at' => $sentAt,
            'expires_at' => $expiresAt,
            'reference_type' => $refType,
            'reference_id' => $refId,
        ];
    }

    // -------------------------------------------------------------------
    // Inbound correlation + reply handling
    // -------------------------------------------------------------------

    /**
     * Find the most-recent pending message for a phone number. Used by
     * the GV-side triage layer when an inbound Y/N reply needs to map
     * back to (concern, patient, reference). Returns null if no match.
     */
    public function lookupByPhone(string $patientPhone): ?array
    {
        if (trim($patientPhone) === '') {
            return null;
        }

        $row = sqlQuery(
            "SELECT id, uuid, concern_type, patient_id, patient_uuid,
                    patient_phone, reference_type, reference_id,
                    sent_at, expires_at, prompt_text, expected_response_kind
               FROM " . self::TABLE_MESSAGES . "
              WHERE patient_phone = ? AND resolution IS NULL
                AND dispatch_status IN ('sent','dry_run')
                AND (expires_at IS NULL OR expires_at > NOW())
           ORDER BY sent_at DESC LIMIT 1",
            [$patientPhone]
        );

        if (empty($row)) {
            // Digits-only fallback — accommodates format variants like
            // '+1 (704) 555-0100' vs '7045550100' across patient_data.
            $digits = preg_replace('/\D/', '', $patientPhone);
            if (strlen($digits) >= 10) {
                $tail = substr($digits, -10);
                $row = sqlQuery(
                    "SELECT id, uuid, concern_type, patient_id, patient_uuid,
                            patient_phone, reference_type, reference_id,
                            sent_at, expires_at, prompt_text, expected_response_kind
                       FROM " . self::TABLE_MESSAGES . "
                      WHERE REGEXP_REPLACE(patient_phone, '[^0-9]', '') LIKE CONCAT('%', ?)
                        AND resolution IS NULL
                        AND dispatch_status IN ('sent','dry_run')
                        AND (expires_at IS NULL OR expires_at > NOW())
                   ORDER BY sent_at DESC LIMIT 1",
                    [$tail]
                );
            }
        }

        if (empty($row)) {
            return null;
        }

        return [
            'message_id'             => (int) $row['id'],
            'message_uuid'           => (string) $row['uuid'],
            'concern_type'           => (string) $row['concern_type'],
            'patient_id'             => (int) $row['patient_id'],
            'patient_uuid'           => (string) ($row['patient_uuid'] ?? ''),
            'patient_phone'          => (string) $row['patient_phone'],
            'reference_type'         => (string) ($row['reference_type'] ?? ''),
            'reference_id'           => isset($row['reference_id']) ? (int) $row['reference_id'] : null,
            'sent_at'                => (string) $row['sent_at'],
            'expires_at'             => (string) ($row['expires_at'] ?? ''),
            'prompt_text'            => (string) ($row['prompt_text'] ?? ''),
            'expected_response_kind' => (string) $row['expected_response_kind'],
        ];
    }

    /**
     * Apply an inbound reply to the message, delegating to the
     * concern's handleReply hook for any downstream action. Marks the
     * message resolved so subsequent lookupByPhone calls skip it.
     */
    public function handleReply(int $messageId, string $replyText): array
    {
        $row = sqlQuery(
            "SELECT id, concern_type, resolution FROM " . self::TABLE_MESSAGES . " WHERE id = ?",
            [$messageId]
        );
        if (empty($row)) {
            return ['success' => false, 'error' => "Message $messageId not found"];
        }
        if (!empty($row['resolution'])) {
            return [
                'success' => false,
                'error' => "Message $messageId already resolved as '{$row['resolution']}'",
                'already_resolved' => true,
            ];
        }

        $concern = $this->getConcernRegistry()[$row['concern_type']] ?? null;
        if (!$concern) {
            return [
                'success' => false,
                'error' => "Concern '{$row['concern_type']}' not registered",
            ];
        }

        $handlerResult = $concern->handleReply($messageId, $replyText) ?? [
            'success' => true, 'resolution' => 'noted',
        ];

        // Mark resolved in the messages table regardless of handler outcome
        // — the audit trail captures what happened. If the handler failed,
        // resolution carries the failure detail.
        sqlStatement(
            "UPDATE " . self::TABLE_MESSAGES . "
                SET resolution = ?, resolution_reply = ?, resolved_at = NOW()
              WHERE id = ?",
            [
                $handlerResult['resolution'] ?? 'unknown',
                substr($replyText, 0, 1000),
                $messageId,
            ]
        );

        return $handlerResult + [
            'message_id' => $messageId,
            'concern_type' => (string) $row['concern_type'],
        ];
    }

    /**
     * Bulk-flip stale pending messages to no_response so staff queries
     * surface non-responders.
     */
    public function expirePending(int $limit = 200): array
    {
        $now = date('Y-m-d H:i:s');
        $rows = sqlStatement(
            "SELECT id, concern_type, patient_id, patient_phone, sent_at, expires_at
               FROM " . self::TABLE_MESSAGES . "
              WHERE resolution IS NULL
                AND dispatch_status IN ('sent','dry_run')
                AND expires_at IS NOT NULL
                AND expires_at < ?
           ORDER BY expires_at ASC LIMIT " . (int) $limit,
            [$now]
        );

        $expired = [];
        $ids = [];
        while ($row = sqlFetchArray($rows)) {
            $expired[] = [
                'message_id' => (int) $row['id'],
                'concern_type' => (string) $row['concern_type'],
                'patient_id' => (int) $row['patient_id'],
                'patient_phone' => (string) ($row['patient_phone'] ?? ''),
                'sent_at' => (string) $row['sent_at'],
                'expires_at' => (string) $row['expires_at'],
            ];
            $ids[] = (int) $row['id'];
        }
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            sqlStatement(
                "UPDATE " . self::TABLE_MESSAGES . "
                    SET resolution = 'no_response', resolved_at = NOW()
                  WHERE id IN ($placeholders) AND resolution IS NULL",
                $ids
            );
        }

        return [
            'success' => true,
            'window_now' => $now,
            'expired_count' => count($expired),
            'expired' => $expired,
        ];
    }

    // -------------------------------------------------------------------
    // Registry resolution (lazy)
    // -------------------------------------------------------------------

    /** @return array<string, OutreachConcern> */
    public function getConcernRegistry(): array
    {
        if ($this->concerns !== null) {
            return $this->concerns;
        }
        $event = $this->bootstrap->dispatchConcernRegistry();
        $this->concerns = $event->getConcerns();
        return $this->concerns;
    }

    /** @return array<string, OutreachChannelDispatcher> */
    public function getChannelRegistry(): array
    {
        if ($this->channels !== null) {
            return $this->channels;
        }
        // Auto-register the three default channel dispatchers shipped with
        // this module. Modules that ship their own dispatcher (e.g. a
        // future Twilio Voice channel) call addChannel() to register —
        // last-write-wins on key collision.
        $this->channels = [];
        try {
            $this->addChannel(new Channels\SmsChannelDispatcher());
            $this->addChannel(new Channels\EmailChannelDispatcher());
            $this->addChannel(new Channels\PushChannelDispatcher());
        } catch (\Throwable $e) {
            $this->logger->error("OUTREACH: default channel dispatcher registration failed", [
                'error' => $e->getMessage(),
            ]);
        }
        return $this->channels;
    }

    public function addChannel(OutreachChannelDispatcher $dispatcher): self
    {
        $registry = $this->getChannelRegistry();
        $registry[$dispatcher->getChannel()] = $dispatcher;
        $this->channels = $registry;
        return $this;
    }

    // -------------------------------------------------------------------
    // Filters: opt-out, rate limit, quiet hours, dedup
    // -------------------------------------------------------------------

    public function isPatientOptedOut(int $patientId, string $concernKey): bool
    {
        $row = sqlQuery(
            "SELECT master_opt_out, concern_opt_outs FROM " . self::TABLE_PREFS . "
              WHERE patient_id = ? LIMIT 1",
            [$patientId]
        );
        if (empty($row)) {
            return false;
        }
        if ((int) $row['master_opt_out'] === 1) {
            return true;
        }
        $concernOptOuts = $row['concern_opt_outs'] ? json_decode($row['concern_opt_outs'], true) : [];
        if (is_array($concernOptOuts) && in_array($concernKey, $concernOptOuts, true)) {
            return true;
        }
        return false;
    }

    public function isRateLimited(int $patientId, string $concernKey): bool
    {
        $today = date('Y-m-d');
        $row = sqlQuery(
            "SELECT COALESCE(SUM(count),0) AS total FROM " . self::TABLE_RATE_LIMIT . "
              WHERE patient_id = ? AND bucket_date = ?",
            [$patientId, $today]
        );
        $total = (int) ($row['total'] ?? 0);
        $limit = $this->config->getDefaultRateLimit();
        return $limit > 0 && $total >= $limit;
    }

    private function incrementRateLimit(int $patientId, string $concernKey): void
    {
        sqlStatement(
            "INSERT INTO " . self::TABLE_RATE_LIMIT . "
                (patient_id, bucket_date, concern_type, count)
             VALUES (?, CURDATE(), ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1",
            [$patientId, $concernKey]
        );
    }

    /**
     * Optionally narrow the dedup by concern_subtype. Cadence concerns
     * (e.g. appointment_reminder at 7d/3d/1d/0d) emit multiple sends
     * per reference and need each subtype tracked independently — when
     * subtype is provided, this method matches WITH it; when null, it
     * matches subtype-agnostic (the original behavior).
     */
    private function hasUnresolvedMessage(string $concernKey, string $refType, int $refId, ?string $subtype = null): bool
    {
        if ($subtype !== null && $subtype !== '') {
            $row = sqlQuery(
                "SELECT id FROM " . self::TABLE_MESSAGES . "
                  WHERE concern_type = ? AND reference_type = ? AND reference_id = ?
                    AND concern_subtype = ?
                    AND resolution IS NULL
                    AND dispatch_status IN ('sent','dry_run','pending')
                  LIMIT 1",
                [$concernKey, $refType, $refId, $subtype]
            );
        } else {
            $row = sqlQuery(
                "SELECT id FROM " . self::TABLE_MESSAGES . "
                  WHERE concern_type = ? AND reference_type = ? AND reference_id = ?
                    AND resolution IS NULL
                    AND dispatch_status IN ('sent','dry_run','pending')
                  LIMIT 1",
                [$concernKey, $refType, $refId]
            );
        }
        return !empty($row);
    }

    // -------------------------------------------------------------------
    // Channel preference resolution
    // -------------------------------------------------------------------

    /**
     * Build the channel-preference list for this (patient, concern):
     *   1. patient's own preference (if any)
     *   2. concern's configured preference (if any)
     *   3. global default
     */
    private function resolveChannelOrder(int $patientId, string $concernKey): array
    {
        $patientPref = sqlQuery(
            "SELECT channel_preference FROM " . self::TABLE_PREFS . " WHERE patient_id = ? LIMIT 1",
            [$patientId]
        );
        if (!empty($patientPref['channel_preference'])) {
            return $this->splitChannelList($patientPref['channel_preference']);
        }

        $concernPref = sqlQuery(
            "SELECT channel_preference FROM " . self::TABLE_CONFIG . " WHERE concern_type = ? LIMIT 1",
            [$concernKey]
        );
        if (!empty($concernPref['channel_preference'])) {
            return $this->splitChannelList($concernPref['channel_preference']);
        }

        return $this->config->getDefaultChannels();
    }

    private function splitChannelList(string $csv): array
    {
        $parts = array_map('trim', explode(',', $csv));
        return array_values(array_filter(array_map('strtolower', $parts)));
    }

    private function isConcernEnabled(string $concernKey): bool
    {
        $row = sqlQuery(
            "SELECT enabled FROM " . self::TABLE_CONFIG . " WHERE concern_type = ? LIMIT 1",
            [$concernKey]
        );
        if (empty($row)) {
            // No config row = concern uses code defaults = enabled.
            return true;
        }
        return (int) $row['enabled'] === 1;
    }

    private function getConcernExpiresHours(string $concernKey): ?int
    {
        $row = sqlQuery(
            "SELECT expires_after_hours FROM " . self::TABLE_CONFIG . " WHERE concern_type = ? LIMIT 1",
            [$concernKey]
        );
        return !empty($row['expires_after_hours']) ? (int) $row['expires_after_hours'] : null;
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function getPatient(int $patientId): ?array
    {
        $row = sqlQuery(
            "SELECT pid, uuid, fname, lname, phone_cell, phone_home, email
               FROM patient_data WHERE pid = ? LIMIT 1",
            [$patientId]
        );
        return $row ?: null;
    }

    private function safeUuidToString($uuid): ?string
    {
        if (empty($uuid)) return null;
        try {
            return UuidRegistry::uuidToString($uuid);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function generateMessageUuid(): string
    {
        $bin = (new UuidRegistry(['table_name' => self::TABLE_MESSAGES]))->createUuid();
        return UuidRegistry::uuidToString($bin);
    }
}
