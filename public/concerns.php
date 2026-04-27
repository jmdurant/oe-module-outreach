<?php

/**
 * Outreach Concerns config — enable/disable concerns + edit per-concern
 * settings (channel preference order, expiration window, prompt template,
 * timing window, rate limit override).
 *
 * Reads from the live OutreachConcernRegistryEvent so newly-installed
 * modules show up automatically. Writes to module_outreach_concerns_config
 * (upsert by concern_type).
 *
 * @package OpenEMR\Modules\Outreach
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Modules\Outreach\Services\PatientOutreachService;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    die(xlt('Not authorised.'));
}

$flash = '';

// ---------------------------------------------------------------------
// POST: upsert per-concern config
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $concern_type           = trim((string) ($_POST['concern_type'] ?? ''));
    $enabled                = isset($_POST['enabled']) ? 1 : 0;
    $channel_preference     = trim((string) ($_POST['channel_preference'] ?? ''));
    $expires_after_hours    = $_POST['expires_after_hours'] !== '' ? (int) $_POST['expires_after_hours'] : null;
    $rate_limit_override    = $_POST['rate_limit_override'] !== '' ? (int) $_POST['rate_limit_override'] : null;
    $timing_window_min      = $_POST['timing_window_min_hours'] !== '' ? (int) $_POST['timing_window_min_hours'] : null;
    $timing_window_max      = $_POST['timing_window_max_hours'] !== '' ? (int) $_POST['timing_window_max_hours'] : null;
    $prompt_template        = trim((string) ($_POST['prompt_template'] ?? ''));

    if ($concern_type !== '') {
        $existing = sqlQuery(
            "SELECT id FROM module_outreach_concerns_config WHERE concern_type = ? LIMIT 1",
            [$concern_type]
        );
        if (empty($existing)) {
            sqlStatement(
                "INSERT INTO module_outreach_concerns_config
                  (concern_type, enabled, channel_preference, expires_after_hours,
                   rate_limit_override, timing_window_min_hours, timing_window_max_hours,
                   prompt_template)
                 VALUES (?,?,?,?,?,?,?,?)",
                [
                    $concern_type, $enabled,
                    $channel_preference !== '' ? $channel_preference : null,
                    $expires_after_hours, $rate_limit_override,
                    $timing_window_min, $timing_window_max,
                    $prompt_template !== '' ? $prompt_template : null,
                ]
            );
        } else {
            sqlStatement(
                "UPDATE module_outreach_concerns_config
                    SET enabled = ?, channel_preference = ?, expires_after_hours = ?,
                        rate_limit_override = ?, timing_window_min_hours = ?,
                        timing_window_max_hours = ?, prompt_template = ?
                  WHERE concern_type = ?",
                [
                    $enabled,
                    $channel_preference !== '' ? $channel_preference : null,
                    $expires_after_hours, $rate_limit_override,
                    $timing_window_min, $timing_window_max,
                    $prompt_template !== '' ? $prompt_template : null,
                    $concern_type,
                ]
            );
        }
        $flash = sprintf('Saved %s', $concern_type);
    }
    // PRG redirect so refresh doesn't re-submit
    header('Location: concerns.php?saved=' . urlencode($concern_type));
    exit;
}
if (isset($_GET['saved']) && $_GET['saved'] !== '') {
    $flash = sprintf('Saved %s', (string) $_GET['saved']);
}

// ---------------------------------------------------------------------
// Discover registered concerns (live registry walk)
// ---------------------------------------------------------------------
$service = PatientOutreachService::create();
$registry = $service->getConcernRegistry();
$channelKeys = array_keys($service->getChannelRegistry());

$configRows = [];
$cfgRs = sqlStatement("SELECT * FROM module_outreach_concerns_config");
while ($r = sqlFetchArray($cfgRs)) {
    $configRows[$r['concern_type']] = $r;
}

$outreach_active_tab = 'concerns';
$outreach_page_title = 'Outreach Concerns';
require __DIR__ . '/_chrome.php';

$csrf = CsrfUtils::collectCsrfToken();
?>

<div class="container-fluid mt-3">
    <?php if ($flash) { ?>
        <div class="alert alert-success py-2 small"><?php echo text($flash); ?></div>
    <?php } ?>

    <?php if (empty($registry)) { ?>
        <div class="outreach-empty">
            <?php echo xlt('No outreach concerns are registered. Concerns are contributed by other modules via OutreachConcernRegistryEvent at boot.'); ?>
        </div>
    <?php } ?>

    <?php foreach ($registry as $key => $concern) {
        $cfg = $configRows[$key] ?? [];
        $enabledCheck = !isset($cfg['enabled']) || (int) $cfg['enabled'] === 1;
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <strong><?php echo text($concern->getLabel()); ?></strong>
                    <code class="ms-2 outreach-meta"><?php echo text($key); ?></code>
                </div>
                <div>
                    <span class="badge bg-light text-dark outreach-pill">
                        <?php echo text($concern->getTimingHint()); ?>
                    </span>
                    <span class="badge bg-light text-dark outreach-pill ms-1">
                        <?php echo text($concern->expectedResponseKind()); ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <p class="outreach-meta mb-3"><?php echo text($concern->getDescription()); ?></p>

                <form method="POST" action="concerns.php">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                    <input type="hidden" name="concern_type" value="<?php echo attr($key); ?>">

                    <div class="row g-3">
                        <div class="col-md-2">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="enabled" id="enabled_<?php echo attr($key); ?>"
                                       <?php echo $enabledCheck ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="enabled_<?php echo attr($key); ?>">
                                    <?php echo xlt('Enabled'); ?>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1"><?php echo xlt('Channel order'); ?></label>
                            <input type="text" name="channel_preference" class="form-control form-control-sm"
                                   value="<?php echo attr((string) ($cfg['channel_preference'] ?? '')); ?>"
                                   placeholder="<?php echo attr(implode(',', $channelKeys)); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1"><?php echo xlt('Expires (h)'); ?></label>
                            <input type="number" name="expires_after_hours" class="form-control form-control-sm"
                                   value="<?php echo attr((string) ($cfg['expires_after_hours'] ?? '')); ?>"
                                   placeholder="<?php echo attr((string) $concern->defaultExpiresAfterHours()); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1"><?php echo xlt('Rate limit'); ?></label>
                            <input type="number" name="rate_limit_override" class="form-control form-control-sm"
                                   value="<?php echo attr((string) ($cfg['rate_limit_override'] ?? '')); ?>"
                                   placeholder="<?php echo xla('global'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1"><?php echo xlt('Window (min/max h)'); ?></label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="timing_window_min_hours" class="form-control"
                                       value="<?php echo attr((string) ($cfg['timing_window_min_hours'] ?? '')); ?>"
                                       placeholder="<?php echo xla('min'); ?>">
                                <input type="number" name="timing_window_max_hours" class="form-control"
                                       value="<?php echo attr((string) ($cfg['timing_window_max_hours'] ?? '')); ?>"
                                       placeholder="<?php echo xla('max'); ?>">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1"><?php echo xlt('Prompt template (overrides concern default)'); ?></label>
                            <textarea name="prompt_template" rows="2" class="form-control form-control-sm"
                                      placeholder="<?php echo xla('Leave blank to use the concern default'); ?>"><?php echo text((string) ($cfg['prompt_template'] ?? '')); ?></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Save'); ?></button>
                            <span class="outreach-meta ms-2">
                                <?php echo xlt('Default expiry'); ?>: <?php echo (int) $concern->defaultExpiresAfterHours(); ?>h
                            </span>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php } ?>

    <div class="outreach-meta mt-3">
        <?php echo xlt('Available channels'); ?>:
        <?php foreach ($channelKeys as $c) { ?>
            <span class="badge bg-info text-dark outreach-pill ms-1"><?php echo text($c); ?></span>
        <?php } ?>
    </div>
</div>

<?php require __DIR__ . '/_chrome_footer.php'; ?>
