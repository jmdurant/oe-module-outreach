<?php

/**
 * PushChannelDispatcher — fires Firebase push notifications via the
 * webhook pattern oe-module-prepayment's EncounterWebhookController
 * already uses.
 *
 * The Firebase function (e.g. `processSuperbillReady`,
 * `processPatientReminder`) on the receiving end consumes the
 * payload and pushes through FCM to the patient's device(s)
 * registered against their patient_uuid. We don't talk to FCM
 * directly — the webhook is the indirection that lets the practice
 * roll the FCM key without touching this code.
 *
 * Context fields the dispatcher honors:
 *   - context.event_type   (string) override for payload.event_type
 *   - context.deep_link    (string) URL to open in the app on tap
 *   - context.priority     ('normal'|'high') Firebase priority hint
 *   - context.webhook_url  (string) override for the practice's
 *                          configured FIREBASE_OUTREACH_WEBHOOK
 *   - context.push_payload (array) full payload override — when
 *                          provided, replaces the dispatcher's
 *                          generic shape and ships verbatim. Useful
 *                          for concerns that need to mirror an
 *                          existing Firebase function's input
 *                          contract (e.g. unpaid_statement_reminder
 *                          mirroring the legacy paymentBalances shape).
 *                          patient_id / patient_uuid are auto-merged
 *                          if not in the override.
 *
 * @package OpenEMR\Modules\Outreach\Services\Channels
 */

namespace OpenEMR\Modules\Outreach\Services\Channels;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\Outreach\Services\OutreachChannelDispatcher;

class PushChannelDispatcher implements OutreachChannelDispatcher
{
    private SystemLogger $logger;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    public function getChannel(): string
    {
        return 'push';
    }

    /**
     * Push needs (a) a webhook URL configured AND (b) a patient_uuid
     * the FCM dispatcher can map to a device token. Without either,
     * we can't deliver and shouldn't burn a tracking row trying.
     */
    public function canDispatch(array $patient): bool
    {
        if (empty($this->getWebhookUrl())) {
            return false;
        }
        return !empty($patient['uuid']);
    }

    public function dispatch(array $patient, string $messageText, array $context = []): array
    {
        $webhookUrl = (string) ($context['webhook_url'] ?? $this->getWebhookUrl());
        if (empty($webhookUrl)) {
            return [
                'success' => false,
                'error' => 'FIREBASE_OUTREACH_WEBHOOK env not configured',
            ];
        }

        $patientUuidString = $this->safeUuidToString($patient['uuid'] ?? null);
        if (empty($patientUuidString)) {
            return ['success' => false, 'error' => 'No patient UUID available for push routing'];
        }

        // Concern-supplied push_payload wins — concerns that need to
        // mirror an existing Firebase function's input contract pass
        // a full payload via candidate.meta.push_payload. We merge in
        // the patient routing fields if they're not already there and
        // ship verbatim. Otherwise fall back to the generic shape.
        if (!empty($context['push_payload']) && is_array($context['push_payload'])) {
            $payload = $context['push_payload'];
            $payload['patient_id']   = $payload['patient_id']   ?? (int) ($patient['pid'] ?? 0);
            $payload['patient_uuid'] = $payload['patient_uuid'] ?? $patientUuidString;
            $payload['timestamp']    = $payload['timestamp']    ?? date('c');
        } else {
            $payload = [
                'event_type' => (string) ($context['event_type'] ?? 'patient_outreach'),
                'patient_id' => (int) ($patient['pid'] ?? 0),
                'patient_uuid' => $patientUuidString,
                'patient_name' => trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? '')),
                'message' => $messageText,
                'priority' => (string) ($context['priority'] ?? 'normal'),
                'deep_link' => (string) ($context['deep_link'] ?? ''),
                'meta' => $context,
                'timestamp' => date('c'),
            ];
        }

        $secret = (string) (getenv('FIREBASE_WEBHOOK_SECRET') ?: '');
        $headers = ['Content-Type: application/json'];
        if (!empty($secret)) {
            $headers[] = 'X-Webhook-Secret: ' . $secret;
        }

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $body = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $http >= 400) {
            return [
                'success' => false,
                'error' => $err ?: "webhook returned HTTP $http",
                'http_status' => $http,
            ];
        }
        return [
            'success' => true,
            'http_status' => $http,
            'raw' => $body,
        ];
    }

    private function getWebhookUrl(): string
    {
        // Practices override per-environment with FIREBASE_OUTREACH_WEBHOOK.
        // Fallback to the same Firebase function shape the prepayment
        // module uses so a practice that already has Firebase wired
        // doesn't need additional config.
        return (string) (
            getenv('FIREBASE_OUTREACH_WEBHOOK')
            ?: getenv('FIREBASE_SUPERBILL_WEBHOOK')
            ?: ''
        );
    }

    private function safeUuidToString($uuid): ?string
    {
        if (empty($uuid)) return null;
        try {
            return \OpenEMR\Common\Uuid\UuidRegistry::uuidToString($uuid);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
