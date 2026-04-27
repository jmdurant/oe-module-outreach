<?php

/**
 * EmailChannelDispatcher — sends outreach email via OpenEMR's MyMailer.
 *
 * MyMailer is the wrapper around PHPMailer that OpenEMR core uses for
 * everything email-related. SMTP / sendmail / mail() configuration is
 * read from the practice's globals (Admin > Globals > Notifications).
 *
 * Context fields the dispatcher honors:
 *   - context.subject       (string) email subject; falls back to a
 *                           generic "Message from your practice"
 *   - context.from_name     (string) sender display name; falls back to
 *                           the practice name from globals
 *   - context.html_body     (string) optional HTML body; if absent the
 *                           plain-text $messageText is used
 *   - context.attachments   (array) [{path, name}] for attachments
 *                           (e.g. superbill PDF on post-visit notify)
 *
 * @package OpenEMR\Modules\Outreach\Services\Channels
 */

namespace OpenEMR\Modules\Outreach\Services\Channels;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Modules\Outreach\Services\OutreachChannelDispatcher;

class EmailChannelDispatcher implements OutreachChannelDispatcher
{
    private SystemLogger $logger;

    public function __construct()
    {
        $this->logger = new SystemLogger();
    }

    public function getChannel(): string
    {
        return 'email';
    }

    public function canDispatch(array $patient): bool
    {
        $email = $patient['email'] ?? null;
        return !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    public function dispatch(array $patient, string $messageText, array $context = []): array
    {
        $email = $patient['email'] ?? null;
        if (empty($email)) {
            return ['success' => false, 'error' => 'No email on file'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => "Invalid email format: $email"];
        }

        if (!class_exists('\\MyMailer')) {
            return [
                'success' => false,
                'error' => 'MyMailer not available (OpenEMR mailer config missing)',
            ];
        }

        global $GLOBALS;
        $subject  = (string) ($context['subject'] ?? 'Message from your practice');
        $fromName = (string) ($context['from_name'] ?? $GLOBALS['practice_return_email_path'] ?? 'OpenEMR');
        $fromAddr = (string) ($GLOBALS['practice_return_email_path'] ?? $GLOBALS['patient_reminder_sender_email'] ?? null);
        if (empty($fromAddr)) {
            return [
                'success' => false,
                'error' => 'No sender email configured (Admin > Globals > Notifications > practice_return_email_path)',
            ];
        }

        $mail = new \MyMailer();
        $mail->From     = $fromAddr;
        $mail->FromName = $fromName;
        $mail->isHTML(!empty($context['html_body']));
        $mail->Subject  = $subject;
        $mail->Body     = $context['html_body'] ?? $messageText;
        $mail->AltBody  = $messageText;
        $mail->addAddress($email, trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? '')));

        // Attachments — mainly for post-visit superbill notifications that
        // ship the PDF inline.
        if (!empty($context['attachments']) && is_array($context['attachments'])) {
            foreach ($context['attachments'] as $att) {
                $path = $att['path'] ?? null;
                $name = $att['name'] ?? null;
                if (!empty($path) && file_exists($path)) {
                    $mail->addAttachment($path, $name ?: basename($path));
                }
            }
        }

        try {
            $sent = $mail->send();
        } catch (\Throwable $e) {
            $this->logger->error("OUTREACH email dispatch threw", ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (!$sent) {
            return [
                'success' => false,
                'error' => $mail->ErrorInfo ?: 'unknown mailer failure',
            ];
        }

        return ['success' => true, 'message_id' => $mail->getLastMessageID() ?: null];
    }
}
