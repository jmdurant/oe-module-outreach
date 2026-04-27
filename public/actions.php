<?php

/**
 * Outreach Actions — manual triggers for sweep + expire-pending.
 *
 * Lets staff run a one-off sweep of all (or one) concern, or close out
 * stale pending messages, without waiting for cron. Operates on the
 * same PatientOutreachService cron uses, so behavior is identical.
 *
 * Returns a structured result block on the same page so staff can see
 * what was sent / skipped / expired.
 *
 * @package OpenEMR\Modules\Outreach
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Modules\Outreach\Services\PatientOutreachService;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    die(xlt('Not authorised.'));
}

$result = null;
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $action = (string) ($_POST['action'] ?? '');
    $service = PatientOutreachService::create();

    if ($action === 'sweep') {
        $concernKey = trim((string) ($_POST['concern_key'] ?? '')) ?: null;
        $limit      = max(1, min(500, (int) ($_POST['limit'] ?? 50)));
        $minH       = $_POST['min_hours_ahead'] !== '' ? (int) $_POST['min_hours_ahead'] : null;
        $maxH       = $_POST['max_hours_ahead'] !== '' ? (int) $_POST['max_hours_ahead'] : null;
        $options = [];
        if ($minH !== null) $options['min_hours_ahead'] = $minH;
        if ($maxH !== null) $options['max_hours_ahead'] = $maxH;

        $result = $service->sweep($concernKey, $limit, $options);
        $result['_action'] = 'sweep';
    } elseif ($action === 'expire_pending') {
        $limit  = max(1, min(1000, (int) ($_POST['limit'] ?? 200)));
        $result = $service->expirePending($limit);
        $result['_action'] = 'expire_pending';
    }
}

$service     = PatientOutreachService::create();
$registry    = $service->getConcernRegistry();
$registryKeys = array_keys($registry);

$outreach_active_tab = 'actions';
$outreach_page_title = 'Outreach Actions';
require __DIR__ . '/_chrome.php';

$csrf = CsrfUtils::collectCsrfToken();
?>

<div class="container-fluid mt-3">
    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header"><strong><?php echo xlt('Run Sweep'); ?></strong></div>
                <div class="card-body">
                    <p class="outreach-meta">
                        <?php echo xlt('Walk one or all concerns, dispatch any due candidates. Idempotent — already-pending references are skipped.'); ?>
                    </p>
                    <form method="POST" action="actions.php">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                        <input type="hidden" name="action" value="sweep">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label small mb-1"><?php echo xlt('Concern'); ?></label>
                                <select name="concern_key" class="form-control form-control-sm">
                                    <option value=""><?php echo xlt('All registered concerns'); ?></option>
                                    <?php foreach ($registryKeys as $k) { ?>
                                        <option value="<?php echo attr($k); ?>"><?php echo text($k); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1"><?php echo xlt('Limit'); ?></label>
                                <input type="number" name="limit" class="form-control form-control-sm" value="50" min="1" max="500">
                            </div>
                            <div class="col-3">
                                <label class="form-label small mb-1"><?php echo xlt('Min h'); ?></label>
                                <input type="number" name="min_hours_ahead" class="form-control form-control-sm" placeholder="<?php echo xla('default'); ?>">
                            </div>
                            <div class="col-3">
                                <label class="form-label small mb-1"><?php echo xlt('Max h'); ?></label>
                                <input type="number" name="max_hours_ahead" class="form-control form-control-sm" placeholder="<?php echo xla('default'); ?>">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Run sweep'); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-header"><strong><?php echo xlt('Expire Pending'); ?></strong></div>
                <div class="card-body">
                    <p class="outreach-meta">
                        <?php echo xlt('Bulk-flip stale pending messages (past expires_at) to resolution=no_response so staff can see who never replied.'); ?>
                    </p>
                    <form method="POST" action="actions.php">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                        <input type="hidden" name="action" value="expire_pending">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small mb-1"><?php echo xlt('Limit'); ?></label>
                                <input type="number" name="limit" class="form-control form-control-sm" value="200" min="1" max="1000">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-sm btn-warning"><?php echo xlt('Expire pending now'); ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($result !== null) { ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <strong>
                    <?php echo xlt('Result'); ?> —
                    <code><?php echo text((string) $result['_action']); ?></code>
                </strong>
                <?php if (!empty($result['success'])) { ?>
                    <span class="badge bg-success outreach-pill"><?php echo xlt('OK'); ?></span>
                <?php } else { ?>
                    <span class="badge bg-danger outreach-pill"><?php echo xlt('Error'); ?></span>
                <?php } ?>
            </div>
            <div class="card-body">
                <?php if (($result['_action'] ?? '') === 'sweep') { ?>
                    <div class="row text-center mb-2">
                        <div class="col-md-4"><div class="h5 mb-0"><?php echo (int) ($result['considered'] ?? 0); ?></div><div class="outreach-meta"><?php echo xlt('Considered'); ?></div></div>
                        <div class="col-md-4"><div class="h5 mb-0 text-success"><?php echo (int) ($result['sent_count'] ?? 0); ?></div><div class="outreach-meta"><?php echo xlt('Sent'); ?></div></div>
                        <div class="col-md-4"><div class="h5 mb-0 text-warning"><?php echo (int) ($result['skipped_count'] ?? 0); ?></div><div class="outreach-meta"><?php echo xlt('Skipped'); ?></div></div>
                    </div>
                <?php } elseif (($result['_action'] ?? '') === 'expire_pending') { ?>
                    <div class="text-center mb-2">
                        <div class="h5 mb-0"><?php echo (int) ($result['expired_count'] ?? 0); ?></div>
                        <div class="outreach-meta"><?php echo xlt('Expired (flipped to no_response)'); ?></div>
                    </div>
                <?php } ?>
                <details class="mt-2">
                    <summary class="outreach-meta"><?php echo xlt('Raw result'); ?></summary>
                    <pre class="small mb-0 mt-2 bg-light p-2"><?php echo text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                </details>
            </div>
        </div>
    <?php } ?>
</div>

<?php require __DIR__ . '/_chrome_footer.php'; ?>
