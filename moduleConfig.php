<?php

/**
 * Module Configuration entrypoint for oe-module-outreach.
 *
 * Required by OpenEMR's Module Manager — the "Config" action on the
 * module row in Administration → Modules → Manage points here. Without
 * this file present, the Module Manager can't render the row's action
 * column properly, which blocks Disable / Reset and other lifecycle
 * actions.
 *
 * Operational settings live in Administration → Globals → Notifications →
 * Patient Outreach. Day-to-day staff pages (Messages, Concerns, Patient
 * Preferences, Run / Expire) are linked from the Modules dropdown via
 * Bootstrap::injectOutreachMenu.
 *
 * @package OpenEMR\Modules\Outreach
 */

$sessionAllowWrite = true;
require_once(__DIR__ . "/../../../globals.php");
$module_config = 1;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt('Patient Outreach — Module Configuration'); ?></title>
</head>
<body>
<div class="container my-3">
    <h2><?php echo xlt('Patient Outreach Platform'); ?></h2>
    <p>
        <?php echo xlt('Multi-channel patient outreach (SMS / email / push) with central tracking, rate-limit, opt-out, and audit. Concerns from other modules (appointment confirmation, copay reminders, post-visit superbill, questionnaire reminders, etc.) plug in via OutreachConcernRegistryEvent at boot.'); ?>
    </p>

    <h3><?php echo xlt('Where things live'); ?></h3>
    <ul>
        <li><strong><?php echo xlt('Practice settings'); ?>:</strong>
            <a href="../../../super/edit_globals.php?category=Notifications" target="_top">
                <?php echo xlt('Administration → Globals → Notifications → Patient Outreach'); ?>
            </a>
            — master enable, dry-run, default rate limit, quiet hours, default channel order.
        </li>
        <li><strong><?php echo xlt('Day-to-day pages'); ?>:</strong>
            <?php echo xlt('Modules dropdown → Outreach: Messages / Concerns / Patient Preferences / Run / Expire'); ?>
        </li>
        <li><strong><?php echo xlt('REST surface'); ?>:</strong>
            <code>/apis/default/fhir/Outreach/*</code>
            (concerns, sweep, send-one, expire-pending, messages, lookup-by-phone, reply, preferences)
        </li>
        <li><strong><?php echo xlt('Database'); ?>:</strong>
            <code>module_outreach_messages</code>, <code>module_outreach_concerns_config</code>,
            <code>module_outreach_patient_prefs</code>, <code>module_outreach_rate_limits</code>
        </li>
    </ul>

    <h3><?php echo xlt('Concern plug-in pattern'); ?></h3>
    <p>
        <?php echo xlt('Other modules contribute concerns by subscribing to the registry event in their own Bootstrap:'); ?>
    </p>
    <pre class="bg-light p-2"><code>$dispatcher-&gt;addListener(
    'outreach.concern.registry',
    fn ($event) =&gt; $event-&gt;register(new MyConcern())
);</code></pre>

    <h3><?php echo xlt('Channels shipped'); ?></h3>
    <ul>
        <li><?php echo xlt('SMS via OpenEMR AppDispatch (Doximity / Twilio / RingCentral / etc.)'); ?></li>
        <li><?php echo xlt('Email via MyMailer'); ?></li>
        <li><?php echo xlt('Firebase push via webhook (FIREBASE_OUTREACH_WEBHOOK env)'); ?></li>
    </ul>
</div>
</body>
</html>
