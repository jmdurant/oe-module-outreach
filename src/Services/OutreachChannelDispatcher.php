<?php

/**
 * OutreachChannelDispatcher — interface every channel adapter implements.
 *
 * Channel adapters live in src/Services/Channels/ and wrap an existing
 * dispatch path:
 *   • SmsChannelDispatcher   → AppDispatch::getApiService('sms') → Doximity
 *   • EmailChannelDispatcher → MyMailer
 *   • PushChannelDispatcher  → Firebase webhook (existing pattern from
 *                              oe-module-prepayment EncounterWebhookController)
 *
 * The PatientOutreachService picks a channel based on:
 *   1. Per-patient channel preference (module_outreach_patient_prefs)
 *   2. Per-concern channel preference (module_outreach_concerns_config)
 *   3. Practice-default channel order (GlobalConfig.getDefaultChannels)
 *
 * Then walks the chosen order until a dispatcher is willing/able to
 * deliver — first dispatcher that returns success wins. If none can
 * deliver, the message is marked dispatch_status='failed'.
 *
 * @package OpenEMR\Modules\Outreach\Services
 */

namespace OpenEMR\Modules\Outreach\Services;

interface OutreachChannelDispatcher
{
    /**
     * Channel id this dispatcher handles. Lowercase, matches the
     * module_outreach_messages.channel enum: 'sms' | 'email' | 'push'.
     */
    public function getChannel(): string;

    /**
     * Can this dispatcher reach the given patient? Checked before
     * attempting send so the service can fall through to the next
     * channel without burning a tracking row on a doomed attempt.
     *
     * @param array $patient patient_data row (or subset with phone/email/etc.)
     */
    public function canDispatch(array $patient): bool;

    /**
     * Send a message through this channel.
     *
     * @param array $patient patient_data row
     * @param string $messageText body to send
     * @param array $context concern-specific context (subject hints, attachments, etc.)
     * @return array {success: bool, error?: string, message_id?: string, raw?: mixed}
     */
    public function dispatch(array $patient, string $messageText, array $context = []): array;
}
