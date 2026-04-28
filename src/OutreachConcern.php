<?php

/**
 * OutreachConcern — the contract every outreach concern implements.
 *
 * A concern represents one operational reason to message a patient:
 *   • "Confirm your upcoming appointment" (appt_confirmation)
 *   • "Your superbill is ready" (post_visit_superbill)
 *   • "Copay due before your visit" (copay_reminder)
 *   • "Statement issued — please pay or contact billing" (unpaid_statement)
 *   • "Complete your intake forms" (pre_visit_questionnaire)
 *   • etc.
 *
 * Concerns live in their HOME modules (the module that owns the
 * domain knowledge for that concern):
 *   • AppointmentConfirmationConcern → oe-module-online-booking
 *   • PostVisitSuperbillConcern      → oe-module-prepayment
 *   • UnpaidStatementConcern         → oe-module-portal-messaging-v2
 *   • etc.
 *
 * Each module subscribes to OutreachConcernRegistryEvent in its own
 * Bootstrap and calls $event->register(new TheirConcern()). The
 * outreach module never imports concrete concerns directly — the
 * registry pattern keeps cross-module coupling out.
 *
 * Lifecycle of a single message:
 *
 *   PatientOutreachService.sweep(concern_type)
 *     → concern.findCandidates()             [returns candidate list]
 *     → for each candidate:
 *         → concern.buildMessage(candidate)  [returns prompt text]
 *         → service.checkOptOut(patient)
 *         → service.checkRateLimit(patient)
 *         → channel.dispatch(...)
 *         → row inserted into module_outreach_messages
 *
 * On inbound reply:
 *
 *   PatientOutreachService.lookupByPhone(phone)
 *     → finds module_outreach_messages row, identifies concern_type
 *     → concern.handleReply(messageId, replyText)
 *     → concern updates resolution + triggers downstream action
 *
 * @package OpenEMR\Modules\Outreach
 */

namespace OpenEMR\Modules\Outreach;

interface OutreachConcern
{
    /**
     * Stable string id for this concern. Goes into
     * module_outreach_messages.concern_type. Lowercase + underscores.
     * Examples: 'appt_confirmation', 'post_visit_superbill',
     * 'unpaid_statement', 'pre_visit_questionnaire'.
     */
    public function getKey(): string;

    /**
     * Human-readable label for the GUI / agent / logs.
     * Example: 'Appointment confirmation (Y/N)'.
     */
    public function getLabel(): string;

    /**
     * One-line description for the GUI / agent prompt.
     */
    public function getDescription(): string;

    /**
     * Hint about WHEN this concern fires:
     *   'pre_visit'  — fires before an appointment (window before pc_eventDate)
     *   'post_visit' — fires after an encounter is signed
     *   'cadence'    — fires on a recurring schedule (e.g. 7d/21d/45d)
     *   'event'      — fires once on a domain event
     *   'mixed'      — fires under multiple triggers
     *
     * Used for grouping in the GUI + audit reports.
     */
    public function getTimingHint(): string;

    /**
     * Find candidates due for a message right now. Pure read — does
     * not write anything. Returns an iterable of arrays with at least:
     *   - patient_id (int, required)
     *   - reference_type (string, e.g. 'appointment')
     *   - reference_id (int, e.g. appointment id)
     *   - meta (array, concern-specific data passed to buildMessage)
     *
     * The service layer dedups against module_outreach_messages — a
     * candidate that already has an unresolved row for the same
     * (concern_type, reference) is skipped automatically.
     *
     * Concerns SHOULD apply their own time-window / status filters
     * here (only-active-patients, only-future-appointments, etc).
     * The service layer applies the cross-cutting filters
     * (opt-out, rate-limit, quiet-hours).
     *
     * The $options array is a per-call override bag passed through
     * from PatientOutreachService::sweep(). Concerns interpret keys
     * relevant to their domain (e.g. appt_confirmation honors
     * 'min_hours_ahead'/'max_hours_ahead'); irrelevant keys are
     * ignored. Implementations MUST accept any options array without
     * erroring even when they care about none of the keys.
     *
     * @return iterable<int, array{patient_id:int, reference_type?:string, reference_id?:int, meta?:array}>
     */
    public function findCandidates(array $options = []): iterable;

    /**
     * Resolve ONE specific candidate by its reference tuple. Used by
     * PatientOutreachService::sendOne() to dispatch a single message
     * on demand (e.g. staff manually triggers a confirmation send for
     * a specific appointment) without running a sweep.
     *
     * Returns the same candidate-dict shape findCandidates() yields,
     * or null if the reference doesn't resolve (appointment cancelled,
     * patient deleted, etc). Concerns SHOULD apply the same domain
     * filters they apply in findCandidates — sendOne shouldn't be a
     * back door around them.
     *
     * @param string $referenceType e.g. 'appointment'
     * @param mixed  $referenceId   the concern-specific id
     */
    public function findCandidateByReference(string $referenceType, $referenceId): ?array;

    /**
     * Build the prompt text for a single candidate. Receives the
     * candidate dict from findCandidates(); returns the message body
     * the channel dispatcher will send.
     *
     * Concerns SHOULD honor practice-specific template overrides
     * stored in module_outreach_concerns_config.prompt_template
     * when present. The service layer doesn't enforce this; concerns
     * decide whether the override applies.
     */
    public function buildMessage(array $candidate): string;

    /**
     * What KIND of reply the concern expects:
     *   'y_n'        — patient should reply Y or N (confirm/decline)
     *   'free_text'  — patient may reply with anything; concern parses
     *   'no_reply'   — informational only; patient need not respond
     *
     * Goes into module_outreach_messages.expected_response_kind so
     * the inbound triage layer knows what to do with the reply.
     */
    public function expectedResponseKind(): string;

    /**
     * Default expiration window for this concern's messages (in hours).
     * After this elapses without resolution, the service marks the
     * message no_response. Concerns can override per-message via
     * findCandidates' meta; the global default lives in
     * module_outreach_concerns_config.expires_after_hours.
     */
    public function defaultExpiresAfterHours(): int;

    /**
     * Handle an inbound reply mapped to this concern (via
     * lookupByPhone). Receives the message id + the raw reply text;
     * returns a result dict with at least:
     *   - success: bool
     *   - resolution: string (goes into module_outreach_messages.resolution)
     *   - resolution_reply: string (the raw text for audit)
     *   - downstream_action: ?array (e.g. {appointment_id, action: 'confirmed'})
     *
     * Concerns that don't accept replies (expectedResponseKind=no_reply)
     * may return null or {success:true, resolution:'noted'}.
     */
    public function handleReply(int $messageId, string $replyText): ?array;

    /*
     * OPTIONAL HOOK — onExpire(int $messageId, array $row): ?array
     *
     * Concerns that need to take action when a message expires
     * unanswered (e.g. a "final follow-up" rung whose silence IS the
     * trigger to close the case + notify the referring provider) MAY
     * declare this method. The platform calls it from expirePending()
     * BEFORE flipping the row's resolution to 'no_response'.
     *
     * Return shape (when implemented):
     *   - resolution: string — overrides the default 'no_response'
     *     (e.g. 'lost', 'closed_no_contact'). Pass null/omit to
     *     accept the default.
     *   - downstream_action: ?array — audit log of any side effect
     *     (faxes sent, status flips, etc.). Stored opportunistically.
     *
     * Not part of the interface so existing concerns aren't forced to
     * implement a no-op. Platform uses method_exists() to detect.
     * Recommended signature when declared:
     *
     *   public function onExpire(int $messageId, array $row): ?array
     */
}
