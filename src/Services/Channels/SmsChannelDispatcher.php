<?php

/**
 * SmsChannelDispatcher — sends outreach SMS via the practice's
 * configured fax/SMS provider through OpenEMR's AppDispatch factory.
 *
 * Same surface every other SMS sender in this codebase uses
 * (FaxSmsFHIRController, WaitlistNotificationService) — practice
 * configures its provider in Admin > Config > FaxSMS Module
 * (Doximity in the reference deployment via the
 * oe-module-doximity AppDispatch patch). When that config changes,
 * this dispatcher follows automatically — no module change needed.
 *
 * Context fields the dispatcher honors:
 *   - context.sms_body  (string) optional SMS-specific short text;
 *                       falls back to $messageText when absent.
 *                       Useful when the canonical message body
 *                       (used by other channels) is too long or
 *                       too rich for SMS — e.g. prepayment requests
 *                       where the email is rich HTML and the SMS
 *                       is "Practice: $X due. Pay: <link>".
 *   - context.phone_override (string) optional explicit destination
 *                       phone, used when patient.phone_cell /
 *                       phone_home are empty. Required by concerns
 *                       operating without a real patient_data row —
 *                       e.g. pending_referral_follow_up where the
 *                       contact phone comes from the fax intake
 *                       (parent/guardian) before any registration
 *                       has happened. When patient phone IS set,
 *                       phone_override is ignored.
 *
 * @package OpenEMR\Modules\Outreach\Services\Channels
 */

namespace OpenEMR\Modules\Outreach\Services\Channels;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\Outreach\Services\OutreachChannelDispatcher;

class SmsChannelDispatcher implements OutreachChannelDispatcher
{
    private SystemLogger $logger;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    public function getChannel(): string
    {
        return 'sms';
    }

    /**
     * Can reach this patient if they have any phone number on file.
     * Cell preferred over home; either works. canDispatch is checked
     * BEFORE dispatch with the patient row only — phone_override is
     * a context-only signal we don't see here. dispatchMessage's
     * channel walk asks canDispatch first; for no-patient-yet flows,
     * the patient stub carries the override into phone_cell so this
     * still returns true.
     */
    public function canDispatch(array $patient): bool
    {
        $phone = $patient['phone_cell'] ?? $patient['phone_home'] ?? null;
        return !empty($phone);
    }

    public function dispatch(array $patient, string $messageText, array $context = []): array
    {
        // Patient phone takes precedence; context.phone_override is the
        // fallback for concerns operating without a real patient_data
        // row (pending referrals, etc).
        $rawPhone = $patient['phone_cell']
            ?? $patient['phone_home']
            ?? $context['phone_override']
            ?? '';
        $phone = $this->normalizePhone((string) $rawPhone);
        if (empty($phone)) {
            return ['success' => false, 'error' => 'No phone on file'];
        }

        try {
            $appDispatchClass = 'OpenEMR\\Modules\\FaxSMS\\Controller\\AppDispatch';
            if (!class_exists($appDispatchClass)) {
                return [
                    'success' => false,
                    'error' => 'oe-module-faxsms not installed; cannot dispatch SMS',
                ];
            }
            /** @var object|null $client */
            $client = $appDispatchClass::getApiService('sms');
            if (empty($client) || !method_exists($client, 'sendSMS')) {
                return [
                    'success' => false,
                    'error' => 'No SMS provider configured (Admin > Config > FaxSMS Module)',
                ];
            }

            // Provider clients return a string. Doximity-style: message id
            // on success, "Error: ..." on failure. Other providers follow
            // similar conventions.
            //
            // Concerns may pass an SMS-specific short body via
            // context.sms_body (e.g. prepayment_request needs the SMS
            // to be a one-liner with the payment link, not the full
            // rich text used for email). Falls back to $messageText
            // — the canonical body buildMessage() returns — when
            // sms_body isn't provided.
            $body = (string) ($context['sms_body'] ?? $messageText);
            $result = (string) $client->sendSMS($phone, '', $body);
            $isError = stripos($result, 'error') === 0;
            if ($isError) {
                return ['success' => false, 'error' => $result, 'raw' => $result];
            }

            // Doximity returns its PatientMessageThread/<uuid> as the
            // sendSMS result, which is the SAME key inbound replies
            // carry — so message_id IS the join key for inbound
            // correlation. Surface it as thread_id so the platform
            // can persist on module_outreach_messages.external_thread_id.
            // Other providers (Twilio, RingCentral, Clickatell) return
            // their own message id; if it's stable across the
            // outbound/inbound pair we get the same correlation. If
            // not, the platform falls back to phone-number lookup.
            return [
                'success'    => true,
                'message_id' => $result,
                'thread_id'  => $result,
                'raw'        => $result,
            ];
        } catch (\Throwable $e) {
            $this->logger->error("OUTREACH SMS dispatch failed", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * E.164-ish normalization. AppDispatch providers handle formatting
     * variants but stripping cosmetic chars + adding +1 prefix when
     * obviously US gives us a more predictable input shape.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }
        return $phone; // pass through; provider may handle
    }
}
