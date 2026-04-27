<?php

/**
 * Outreach Patient Preferences — search a patient + view/edit their
 * outreach preferences (master opt-out, channel preference, per-concern
 * opt-outs, quiet hours).
 *
 * Single-screen: search box → patient hits → click one to edit.
 * Selected-patient form upserts module_outreach_patient_prefs.
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

$flash = '';

// ---------------------------------------------------------------------
// POST: upsert patient prefs
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $patient_id        = (int) ($_POST['patient_id'] ?? 0);
    $master_opt_out    = isset($_POST['master_opt_out']) ? 1 : 0;
    $channel_pref      = trim((string) ($_POST['channel_preference'] ?? ''));
    $concern_opt_outs  = trim((string) ($_POST['concern_opt_outs'] ?? ''));
    $quiet_start       = $_POST['quiet_hours_start'] !== '' ? (int) $_POST['quiet_hours_start'] : null;
    $quiet_end         = $_POST['quiet_hours_end']   !== '' ? (int) $_POST['quiet_hours_end']   : null;

    $optOutArr = array_values(array_filter(array_map('trim', explode(',', $concern_opt_outs))));
    $optOutJson = json_encode($optOutArr, JSON_UNESCAPED_SLASHES);

    if ($patient_id > 0) {
        $existing = sqlQuery(
            "SELECT id FROM module_outreach_patient_prefs WHERE patient_id = ? LIMIT 1",
            [$patient_id]
        );
        if (empty($existing)) {
            sqlStatement(
                "INSERT INTO module_outreach_patient_prefs
                  (patient_id, master_opt_out, channel_preference,
                   concern_opt_outs, quiet_hours_start, quiet_hours_end)
                 VALUES (?,?,?,?,?,?)",
                [
                    $patient_id, $master_opt_out,
                    $channel_pref !== '' ? $channel_pref : null,
                    $optOutJson, $quiet_start, $quiet_end,
                ]
            );
        } else {
            sqlStatement(
                "UPDATE module_outreach_patient_prefs
                    SET master_opt_out = ?, channel_preference = ?,
                        concern_opt_outs = ?, quiet_hours_start = ?,
                        quiet_hours_end = ?
                  WHERE patient_id = ?",
                [
                    $master_opt_out,
                    $channel_pref !== '' ? $channel_pref : null,
                    $optOutJson, $quiet_start, $quiet_end,
                    $patient_id,
                ]
            );
        }
    }
    header('Location: patient_prefs.php?pid=' . $patient_id . '&saved=1');
    exit;
}

if (!empty($_GET['saved'])) {
    $flash = (string) xl('Saved.');
}

// ---------------------------------------------------------------------
// Search + load
// ---------------------------------------------------------------------
$query = trim((string) ($_GET['q'] ?? ''));
$selectedPid = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;

$searchHits = [];
if ($query !== '') {
    // Match by pid, last name, first name, or phone (digits-only)
    $like = '%' . $query . '%';
    $digits = preg_replace('/\D/', '', $query);
    $hitsRs = sqlStatement(
        "SELECT pid, fname, lname, phone_cell, phone_home, email
           FROM patient_data
          WHERE CAST(pid AS CHAR) = ?
             OR lname LIKE ?
             OR fname LIKE ?
             OR " . ($digits !== '' ? "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone_cell, phone_home, ''), '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?" : "1=0") . "
          ORDER BY lname, fname LIMIT 25",
        $digits !== '' ? [$query, $like, $like, '%' . $digits . '%'] : [$query, $like, $like]
    );
    while ($r = sqlFetchArray($hitsRs)) {
        $searchHits[] = $r;
    }
}

$selected   = null;
$selectedPrefs = null;
if ($selectedPid > 0) {
    $selected = sqlQuery(
        "SELECT pid, fname, lname, phone_cell, phone_home, email
           FROM patient_data WHERE pid = ? LIMIT 1",
        [$selectedPid]
    );
    if (!empty($selected)) {
        $selectedPrefs = sqlQuery(
            "SELECT master_opt_out, channel_preference, concern_opt_outs,
                    quiet_hours_start, quiet_hours_end, updated_at
               FROM module_outreach_patient_prefs WHERE patient_id = ? LIMIT 1",
            [$selectedPid]
        ) ?: null;
    }
}

// Concern keys for the opt-out chip suggestions
$registryKeys = array_keys(PatientOutreachService::create()->getConcernRegistry());
$channelKeys  = array_keys(PatientOutreachService::create()->getChannelRegistry());

$existingOptOuts = [];
if ($selectedPrefs !== null && !empty($selectedPrefs['concern_opt_outs'])) {
    $existingOptOuts = json_decode((string) $selectedPrefs['concern_opt_outs'], true) ?: [];
}

$outreach_active_tab = 'prefs';
$outreach_page_title = 'Outreach Patient Preferences';
require __DIR__ . '/_chrome.php';

$csrf = CsrfUtils::collectCsrfToken();
?>

<div class="container-fluid mt-3">
    <?php if ($flash) { ?>
        <div class="alert alert-success py-2 small"><?php echo text($flash); ?></div>
    <?php } ?>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-6">
            <input type="search" name="q" class="form-control form-control-sm"
                   value="<?php echo attr($query); ?>"
                   placeholder="<?php echo xla('Search by pid, name, or phone'); ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Search'); ?></button>
            <a href="patient_prefs.php" class="btn btn-sm btn-outline-secondary"><?php echo xlt('Reset'); ?></a>
        </div>
    </form>

    <?php if ($query !== '' && empty($searchHits)) { ?>
        <div class="outreach-empty"><?php echo xlt('No patients matched.'); ?></div>
    <?php } ?>

    <?php if (!empty($searchHits)) { ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-hover outreach-table align-middle">
                <thead class="table-light">
                <tr>
                    <th>#</th><th><?php echo xlt('Name'); ?></th>
                    <th><?php echo xlt('Phone'); ?></th><th><?php echo xlt('Email'); ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($searchHits as $h) {
                    $href = 'patient_prefs.php?q=' . urlencode($query) . '&pid=' . (int) $h['pid'];
                    ?>
                    <tr>
                        <td><?php echo (int) $h['pid']; ?></td>
                        <td><?php echo text(trim((string) ($h['lname'] ?? '') . ', ' . (string) ($h['fname'] ?? ''))); ?></td>
                        <td><?php echo text((string) ($h['phone_cell'] ?: $h['phone_home'] ?? '')); ?></td>
                        <td><?php echo text((string) ($h['email'] ?? '')); ?></td>
                        <td><a href="<?php echo attr($href); ?>" class="btn btn-sm btn-outline-primary"><?php echo xlt('Edit'); ?></a></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <?php if (!empty($selected)) { ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <strong><?php echo text(trim((string) ($selected['lname'] ?? '') . ', ' . (string) ($selected['fname'] ?? ''))); ?></strong>
                    <span class="outreach-meta ms-2">pid <?php echo (int) $selected['pid']; ?></span>
                </div>
                <?php if ($selectedPrefs === null) { ?>
                    <span class="badge bg-secondary outreach-pill"><?php echo xlt('No explicit prefs (uses defaults)'); ?></span>
                <?php } else { ?>
                    <span class="outreach-meta">
                        <?php echo xlt('Updated'); ?> <?php echo text((string) ($selectedPrefs['updated_at'] ?? '')); ?>
                    </span>
                <?php } ?>
            </div>
            <div class="card-body">
                <form method="POST" action="patient_prefs.php">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                    <input type="hidden" name="patient_id" value="<?php echo (int) $selected['pid']; ?>">

                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="master_opt_out" id="master_opt_out"
                                       <?php echo (int) ($selectedPrefs['master_opt_out'] ?? 0) === 1 ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="master_opt_out">
                                    <strong><?php echo xlt('Opt out of ALL outreach'); ?></strong>
                                </label>
                                <div class="outreach-meta"><?php echo xlt('Master switch — wins over per-concern.'); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1"><?php echo xlt('Channel preference'); ?></label>
                            <input type="text" name="channel_preference" class="form-control form-control-sm"
                                   value="<?php echo attr((string) ($selectedPrefs['channel_preference'] ?? '')); ?>"
                                   placeholder="<?php echo attr(implode(',', $channelKeys)); ?>">
                            <div class="outreach-meta"><?php echo xlt('Comma-separated. First channel patient is reachable on wins.'); ?></div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small mb-1"><?php echo xlt('Per-concern opt-outs'); ?></label>
                            <input type="text" name="concern_opt_outs" class="form-control form-control-sm"
                                   value="<?php echo attr(implode(',', $existingOptOuts)); ?>"
                                   placeholder="<?php echo attr(implode(',', $registryKeys)); ?>">
                            <div class="outreach-meta">
                                <?php echo xlt('Available'); ?>:
                                <?php foreach ($registryKeys as $k) { ?>
                                    <code class="me-1"><?php echo text($k); ?></code>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1"><?php echo xlt('Quiet hours (24h)'); ?></label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="quiet_hours_start" min="0" max="23" class="form-control"
                                       value="<?php echo attr((string) ($selectedPrefs['quiet_hours_start'] ?? '')); ?>"
                                       placeholder="<?php echo xla('start'); ?>">
                                <input type="number" name="quiet_hours_end" min="0" max="23" class="form-control"
                                       value="<?php echo attr((string) ($selectedPrefs['quiet_hours_end'] ?? '')); ?>"
                                       placeholder="<?php echo xla('end'); ?>">
                            </div>
                            <div class="outreach-meta"><?php echo xlt('SMS only.'); ?></div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Save preferences'); ?></button>
                            <a href="patient_prefs.php?q=<?php echo attr(urlencode($query)); ?>" class="btn btn-sm btn-outline-secondary ms-1">
                                <?php echo xlt('Cancel'); ?>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php } ?>
</div>

<?php require __DIR__ . '/_chrome_footer.php'; ?>
