#!/usr/bin/php
<?php

/**
 * Unified outreach sweep cron.
 *
 * Walks every registered concern (or a specific one passed as the
 * first arg) and dispatches due messages. Intended to run on a
 * single cron schedule covering all the cadence-based concerns:
 *
 *   appointment_reminder              (default 7/3/1/0 days before)
 *   unpaid_statement_reminder         (default 30/14/7/3/1 days before)
 *   pre_visit_questionnaire_reminder  (default 14/7/3/1 days before)
 *   post_visit_followup_form          (default 1 day after)
 *   post_visit_superbill              (catch-up after encounter sign)
 *   prepayment_request                (catch-up after row creation)
 *   payment_confirmation              (catch-up after payment)
 *
 * appt_confirmation also gets swept (2-hop reminder window) — its
 * Concern's findCandidates filters to the right window.
 *
 * Recommended cron cadence: every 15 minutes. Sweeps are idempotent
 * (per-concern dedup via module_outreach_messages, per-cadence dedup
 * via concern_subtype), so re-runs within the window are safe.
 *
 *   (every-15-min crontab line goes here — see README)
 *
 * Pass a single concern key as argv[1] to sweep just that one (e.g.
 * for testing or for a more frequent cadence on one specific concern):
 *
 *   php outreach_sweep.php appointment_reminder
 *
 * Returns exit 0 on success, 1 on platform error or any concern
 * sweep error. Per-concern errors are logged but don't abort the
 * other concerns' sweeps.
 *
 * @package OpenEMR\Modules\Outreach
 */

$ignoreAuth = true;
$silent = true;
// CLI invocation has no $_GET — pin site to default so OpenEMR's
// globals.php site validator doesn't reject the request.
$_GET['site'] = $_GET['site'] ?? 'default';

// Path: this.php → cron → module → custom_modules → modules → interface
// (dirname 5) → append /globals.php
require_once dirname(__FILE__, 5) . '/globals.php';

use OpenEMR\Common\Logging\SystemLogger;

$logger = new SystemLogger();

if (!class_exists(\OpenEMR\Modules\Outreach\Services\PatientOutreachService::class)) {
    $logger->error('outreach_sweep: oe-module-outreach not installed; cannot run sweep.');
    exit(1);
}

$concernKey = $argv[1] ?? null;

try {
    $service = \OpenEMR\Modules\Outreach\Services\PatientOutreachService::create();

    if ($concernKey !== null && $concernKey !== '') {
        $logger->info("outreach_sweep: sweeping single concern '$concernKey'");
        $result = $service->sweep($concernKey);
    } else {
        $logger->info('outreach_sweep: sweeping all enabled concerns');
        $result = $service->sweep(null);
    }

    $logger->info('outreach_sweep: complete', [
        'considered'    => $result['considered']    ?? 0,
        'sent_count'    => $result['sent_count']    ?? 0,
        'skipped_count' => $result['skipped_count'] ?? 0,
    ]);

    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit(empty($result['success']) ? 1 : 0);
} catch (\Throwable $e) {
    $logger->error('outreach_sweep: failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
}
