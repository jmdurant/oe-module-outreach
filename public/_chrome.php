<?php

/**
 * Shared page chrome for the oe-module-outreach admin UI.
 *
 * Each public/*.php page requires this AFTER it has done globals.php
 * + ACL check + any POST-redirect handling. Emits the OpenEMR header
 * and a tab strip linking the four operational pages.
 *
 * Usage:
 *   $outreach_active_tab = 'messages';   // one of: messages|concerns|prefs|actions
 *   $outreach_page_title = 'Outreach Messages';
 *   require __DIR__ . '/_chrome.php';   // emits <html>..<body>..nav..
 *   echo "<div class='container-fluid mt-3'>your page body</div>";
 *   require __DIR__ . '/_chrome_footer.php';
 *
 * @package OpenEMR\Modules\Outreach
 */

use OpenEMR\Core\Header;

$outreach_active_tab = $outreach_active_tab ?? '';
$outreach_page_title = $outreach_page_title ?? 'Patient Outreach';

$tabs = [
    'messages' => ['label' => 'Messages',           'href' => 'messages.php'],
    'concerns' => ['label' => 'Concerns',           'href' => 'concerns.php'],
    'prefs'    => ['label' => 'Patient Preferences','href' => 'patient_prefs.php'],
    'actions'  => ['label' => 'Run / Expire',       'href' => 'actions.php'],
];

?><!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo text($outreach_page_title); ?></title>
    <?php Header::setupHeader(['datetime-picker']); ?>
    <style>
        .outreach-pill { font-size: 0.75rem; padding: 0.15rem 0.5rem; }
        .outreach-meta { font-size: 0.85rem; color: #6c757d; }
        .outreach-tabs { margin-bottom: 1rem; }
        .outreach-table th { white-space: nowrap; }
        .outreach-empty { padding: 2rem; text-align: center; color: #6c757d; }
    </style>
</head>
<body class="body_top">
<nav class="outreach-tabs navbar navbar-expand navbar-light bg-light border-bottom">
    <div class="container-fluid">
        <span class="navbar-brand mb-0 h5"><?php echo xlt('Patient Outreach'); ?></span>
        <ul class="navbar-nav me-auto">
            <?php foreach ($tabs as $key => $tab) {
                $active = $key === $outreach_active_tab ? ' active' : '';
                ?>
                <li class="nav-item">
                    <a class="nav-link<?php echo $active; ?>" href="<?php echo attr($tab['href']); ?>"><?php echo xlt($tab['label']); ?></a>
                </li>
            <?php } ?>
        </ul>
        <a class="btn btn-sm btn-outline-secondary" href="/interface/super/edit_globals.php?category=Notifications">
            <?php echo xlt('Settings'); ?>
        </a>
    </div>
</nav>
