<?php

/**
 * Outreach Messages — audit table over module_outreach_messages.
 *
 * Read-only staff view. Filter by concern, patient, dispatch status,
 * resolution; paginate. The intent is "what did we send, who replied,
 * who didn't."
 *
 * Every column is a real column in the audit table — no derived state,
 * so a quick scan tells the whole story.
 *
 * @package OpenEMR\Modules\Outreach
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    die(xlt('Not authorised.'));
}

// ---------------------------------------------------------------------
// Filter inputs (GET)
// ---------------------------------------------------------------------
$concern_key     = trim((string) ($_GET['concern_key']     ?? ''));
$patient_id      = isset($_GET['patient_id']) && $_GET['patient_id'] !== '' ? (int) $_GET['patient_id'] : null;
$dispatch_status = trim((string) ($_GET['dispatch_status'] ?? ''));
$resolution      = trim((string) ($_GET['resolution']      ?? ''));
$limit           = max(1, min(500, (int) ($_GET['limit'] ?? 50)));
$offset          = max(0, (int) ($_GET['offset'] ?? 0));

$where  = [];
$params = [];
if ($concern_key !== '') {
    $where[] = 'concern_type = ?';
    $params[] = $concern_key;
}
if ($patient_id !== null && $patient_id > 0) {
    $where[] = 'patient_id = ?';
    $params[] = $patient_id;
}
if ($dispatch_status !== '') {
    $where[] = 'dispatch_status = ?';
    $params[] = $dispatch_status;
}
if ($resolution === '__null__') {
    $where[] = 'resolution IS NULL';
} elseif ($resolution !== '') {
    $where[] = 'resolution = ?';
    $params[] = $resolution;
}
$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

$rows = sqlStatement(
    "SELECT id, uuid, concern_type, patient_id, patient_phone, patient_email,
            channel, dispatch_status, resolution,
            sent_at, expires_at, resolved_at, created_at
       FROM module_outreach_messages
       $whereSql
   ORDER BY id DESC
      LIMIT $limit OFFSET $offset",
    $params
);
$messages = [];
while ($row = sqlFetchArray($rows)) {
    $messages[] = $row;
}

$totalRow = sqlQuery(
    "SELECT COUNT(*) AS n FROM module_outreach_messages $whereSql",
    $params
);
$total = (int) ($totalRow['n'] ?? 0);

// Concern key options for the filter dropdown — pulled live from the
// audit table so empty deployments don't render bogus values.
$keyRows = sqlStatement("SELECT DISTINCT concern_type FROM module_outreach_messages ORDER BY concern_type");
$concernKeyOptions = [];
while ($r = sqlFetchArray($keyRows)) {
    $concernKeyOptions[] = (string) $r['concern_type'];
}

$dispatchStatusOptions = ['sent', 'dry_run', 'failed', 'skipped', 'pending'];
$resolutionOptions     = ['__null__', 'confirmed', 'rescheduling', 'rescheduled', 'no_response', 'free_text_escalate', 'noted'];

$outreach_active_tab = 'messages';
$outreach_page_title = 'Outreach Messages';
require __DIR__ . '/_chrome.php';

// Helper: resolution-pill colour class
$pill = function (?string $resolution, string $dispatch): string {
    if ($dispatch === 'failed' || $dispatch === 'skipped') {
        return 'bg-danger';
    }
    if ($resolution === null) {
        return $dispatch === 'sent' ? 'bg-warning text-dark' : 'bg-secondary';
    }
    return match ($resolution) {
        'confirmed', 'rescheduled', 'noted' => 'bg-success',
        'rescheduling'                       => 'bg-info text-dark',
        'no_response'                        => 'bg-secondary',
        'free_text_escalate'                 => 'bg-warning text-dark',
        default                              => 'bg-light text-dark',
    };
};

?>
<div class="container-fluid mt-3">
    <form method="GET" class="row g-2 align-items-end mb-3">
        <div class="col-md-2">
            <label class="form-label small mb-1"><?php echo xlt('Concern'); ?></label>
            <select name="concern_key" class="form-control form-control-sm">
                <option value=""><?php echo xlt('All'); ?></option>
                <?php foreach ($concernKeyOptions as $k) { ?>
                    <option value="<?php echo attr($k); ?>" <?php echo $k === $concern_key ? 'selected' : ''; ?>>
                        <?php echo text($k); ?>
                    </option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1"><?php echo xlt('Patient ID'); ?></label>
            <input type="number" name="patient_id" class="form-control form-control-sm"
                   value="<?php echo attr($patient_id ?? ''); ?>" placeholder="pid">
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1"><?php echo xlt('Dispatch'); ?></label>
            <select name="dispatch_status" class="form-control form-control-sm">
                <option value=""><?php echo xlt('All'); ?></option>
                <?php foreach ($dispatchStatusOptions as $s) { ?>
                    <option value="<?php echo attr($s); ?>" <?php echo $s === $dispatch_status ? 'selected' : ''; ?>>
                        <?php echo text($s); ?>
                    </option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1"><?php echo xlt('Resolution'); ?></label>
            <select name="resolution" class="form-control form-control-sm">
                <option value=""><?php echo xlt('All'); ?></option>
                <option value="__null__" <?php echo $resolution === '__null__' ? 'selected' : ''; ?>><?php echo xlt('(unresolved)'); ?></option>
                <?php foreach ($resolutionOptions as $r) {
                    if ($r === '__null__') continue;
                    ?>
                    <option value="<?php echo attr($r); ?>" <?php echo $r === $resolution ? 'selected' : ''; ?>>
                        <?php echo text($r); ?>
                    </option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small mb-1"><?php echo xlt('Per page'); ?></label>
            <select name="limit" class="form-control form-control-sm">
                <?php foreach ([25, 50, 100, 250] as $n) { ?>
                    <option value="<?php echo $n; ?>" <?php echo $limit === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-primary"><?php echo xlt('Filter'); ?></button>
            <a href="messages.php" class="btn btn-sm btn-outline-secondary"><?php echo xlt('Reset'); ?></a>
        </div>
    </form>

    <div class="outreach-meta mb-2">
        <?php
        $rangeStart = $total > 0 ? ($offset + 1) : 0;
        $rangeEnd   = min($offset + $limit, $total);
        echo text(sprintf('Showing %d-%d of %d', $rangeStart, $rangeEnd, $total));
        ?>
    </div>

    <?php if (empty($messages)) { ?>
        <div class="outreach-empty"><?php echo xlt('No outreach messages match the current filters.'); ?></div>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover outreach-table align-middle">
                <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th><?php echo xlt('Concern'); ?></th>
                    <th><?php echo xlt('Patient'); ?></th>
                    <th><?php echo xlt('Channel'); ?></th>
                    <th><?php echo xlt('Status'); ?></th>
                    <th><?php echo xlt('Sent'); ?></th>
                    <th><?php echo xlt('Expires'); ?></th>
                    <th><?php echo xlt('Resolved'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($messages as $m) {
                    $resPill = $pill($m['resolution'] ?: null, (string) $m['dispatch_status']);
                    $resLabel = $m['resolution'] ?? ($m['dispatch_status'] === 'sent' ? 'pending' : $m['dispatch_status']);
                    ?>
                    <tr>
                        <td><?php echo (int) $m['id']; ?></td>
                        <td><code><?php echo text((string) $m['concern_type']); ?></code></td>
                        <td>
                            <a href="../../../../patient_file/summary/demographics.php?set_pid=<?php echo (int) $m['patient_id']; ?>"
                               target="_blank">
                                <?php echo (int) $m['patient_id']; ?>
                            </a>
                            <div class="outreach-meta"><?php echo text((string) ($m['patient_phone'] ?? '')); ?></div>
                        </td>
                        <td><span class="badge bg-info text-dark outreach-pill"><?php echo text((string) $m['channel']); ?></span></td>
                        <td><span class="badge outreach-pill <?php echo attr($resPill); ?>"><?php echo text((string) $resLabel); ?></span></td>
                        <td><span class="outreach-meta"><?php echo text((string) ($m['sent_at'] ?? '—')); ?></span></td>
                        <td><span class="outreach-meta"><?php echo text((string) ($m['expires_at'] ?? '—')); ?></span></td>
                        <td><span class="outreach-meta"><?php echo text((string) ($m['resolved_at'] ?? '—')); ?></span></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <?php
        $qsBase = $_GET;
        unset($qsBase['offset']);
        $prevOffset = max(0, $offset - $limit);
        $nextOffset = $offset + $limit;
        $prevQs = http_build_query(array_merge($qsBase, ['offset' => $prevOffset]));
        $nextQs = http_build_query(array_merge($qsBase, ['offset' => $nextOffset]));
        ?>
        <div class="d-flex gap-2 mt-2">
            <a class="btn btn-sm btn-outline-secondary <?php echo $offset <= 0 ? 'disabled' : ''; ?>"
               href="?<?php echo attr($prevQs); ?>">&larr; <?php echo xlt('Previous'); ?></a>
            <a class="btn btn-sm btn-outline-secondary <?php echo $nextOffset >= $total ? 'disabled' : ''; ?>"
               href="?<?php echo attr($nextQs); ?>"><?php echo xlt('Next'); ?> &rarr;</a>
        </div>
    <?php } ?>
</div>

<?php require __DIR__ . '/_chrome_footer.php'; ?>
