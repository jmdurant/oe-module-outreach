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
     * Cell preferred over home; either works.
     */
    public function canDispatch(array $patient): bool
    {
        $phone = $patient['phone_cell'] ?? $patient['phone_home'] ?? null;
        return !empty($phone);
    }

    public function dispatch(array $patient, string $messageText, array $context = []): array
    {
        $phone = $this->normalizePhone(
            $patient['phone_cell'] ?? $patient['phone_home'] ?? ''
        );
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
            $result = (string) $client->sendSMS($phone, '', $messageText);
            $isError = stripos($result, 'error') === 0;
            if ($isError) {
                return ['success' => false, 'error' => $result, 'raw' => $result];
            }
            return ['success' => true, 'message_id' => $result, 'raw' => $result];
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
