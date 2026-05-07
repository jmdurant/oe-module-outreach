<?php

/**
 * ModuleManagerListener — runs install/enable/disable hooks for the
 * Patient Outreach module.
 *
 * On install + first-enable, calls setupDatabase() which creates the
 * four tables that back the central outreach state. Migrations are
 * idempotent — re-running install never destroys data; missing tables
 * get created, missing columns get added.
 *
 * @package OpenEMR\Modules\Outreach
 */

namespace OpenEMR\Modules\Outreach;

class ModuleManagerListener
{
    public function __construct()
    {
        // No-op constructor. OpenEMR's module manager instantiates listeners
        // before kernel boot — keep work in the action methods, not here.
    }

    /**
     * Module-manager dispatch table. install_action / enable / disable
     * each get a chance to fire. We want setupDatabase to run on
     * install AND enable so re-enabling after a disable picks up any
     * schema additions shipped in the meantime.
     */
    public function moduleManagerAction($methodName, $modId, string $currentActionStatus = 'Success'): string
    {
        if (method_exists($this, $methodName)) {
            return $this->$methodName($modId, $currentActionStatus);
        }
        return $currentActionStatus;
    }

    public function install_action($modId, $currentActionStatus = ''): string
    {
        return $this->install($modId, $currentActionStatus);
    }

    public function install($modId, $currentActionStatus): string
    {
        try {
            $this->setupDatabase();
            return 'Success';
        } catch (\Throwable $e) {
            error_log("oe-module-outreach: install failed: " . $e->getMessage());
            return 'Failure: ' . $e->getMessage();
        }
    }

    public function enable($modId, $currentActionStatus): string
    {
        try {
            // Re-running setupDatabase on enable is safe (idempotent SQL).
            // It also picks up new tables/columns shipped in updates that
            // happen between disable and re-enable.
            $this->setupDatabase();
            return 'Success';
        } catch (\Throwable $e) {
            error_log("oe-module-outreach: enable failed: " . $e->getMessage());
            return 'Failure: ' . $e->getMessage();
        }
    }

    public function disable($modId, $currentActionStatus): string
    {
        // Disable is intentionally non-destructive — keep the tables
        // and data so re-enabling restores everything. Practice can
        // drop the tables manually if they truly want to remove the
        // module; we don't gate that on a UI flow.
        return 'Success';
    }

    /**
     * Create the four tables that back outreach state.
     *
     * Schema design notes:
     *
     *   • module_outreach_messages — one row per dispatched message.
     *     The audit trail. concern_type discriminates which concern
     *     emitted it; reference_id / reference_type point at the
     *     domain object the message is about (appointment_id for
     *     appt-confirmation, encounter_id for post-visit, etc).
     *     resolution + resolution_reply track inbound responses.
     *
     *   • module_outreach_concerns_config — per-concern overrides
     *     for cadence, channel preference, rate limits. NULL means
     *     "use the global default." Concerns ship sensible defaults
     *     in code; this table is for practice-specific tuning via
     *     the GUI.
     *
     *   • module_outreach_patient_prefs — per-patient opt-in/out and
     *     channel preference. Honors HIPAA "STOP" replies + lets
     *     individual patients prefer email over SMS, etc.
     *
     *   • module_outreach_rate_limits — cached counters used by the
     *     dispatch path to enforce the per-day per-patient cap. Reset
     *     by a daily sweep (or just by the natural sliding window).
     */
    private function setupDatabase(): void
    {
        // -----------------------------------------------------------------
        // module_outreach_messages — audit + state for every dispatch
        // -----------------------------------------------------------------
        $exists = sqlQuery("SHOW TABLES LIKE 'module_outreach_messages'");
        if (empty($exists)) {
            sqlStatement(
                "CREATE TABLE IF NOT EXISTS `module_outreach_messages` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `uuid` CHAR(36) NOT NULL UNIQUE,
                    `concern_type` VARCHAR(64) NOT NULL,
                    `concern_subtype` VARCHAR(64) NULL,
                    `patient_id` INT NOT NULL,
                    `patient_uuid` VARCHAR(36) NULL,
                    `patient_phone` VARCHAR(32) NULL,
                    `patient_email` VARCHAR(255) NULL,
                    `reference_type` VARCHAR(32) NULL,
                    `reference_id` INT NULL,
                    `channel` ENUM('sms','email','push','none') NOT NULL,
                    `external_thread_id` VARCHAR(128) NULL,
                    `prompt_text` TEXT NULL,
                    `meta` JSON NULL,
                    `expected_response_kind` VARCHAR(32) DEFAULT 'no_reply',
                    `dispatch_status` ENUM('pending','sent','failed','skipped','dry_run') DEFAULT 'pending',
                    `dispatch_result` TEXT NULL,
                    `sent_at` DATETIME NULL,
                    `expires_at` DATETIME NULL,
                    `resolved_at` DATETIME NULL,
                    `resolution` VARCHAR(64) NULL,
                    `resolution_reply` TEXT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_concern_pending` (`concern_type`, `dispatch_status`, `resolution`),
                    INDEX `idx_patient_phone_pending` (`patient_phone`, `resolution`, `expires_at`),
                    INDEX `idx_patient_recent` (`patient_id`, `sent_at`),
                    INDEX `idx_reference` (`reference_type`, `reference_id`),
                    INDEX `idx_expires` (`expires_at`),
                    INDEX `idx_dispatch_status` (`dispatch_status`),
                    INDEX `idx_external_thread_id` (`external_thread_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } else {
            // Idempotent column upgrades for already-installed sites.
            // external_thread_id was added 2026-04-27 to support
            // unambiguous inbound-reply correlation when the SMS
            // provider exposes a stable per-thread id (Doximity does:
            // PatientMessageThread/<uuid>). Phone-number lookup is
            // the fallback; thread-id wins when both exist.
            $col = sqlQuery(
                "SHOW COLUMNS FROM `module_outreach_messages`
                  WHERE Field = 'external_thread_id'"
            );
            if (empty($col)) {
                sqlStatement(
                    "ALTER TABLE `module_outreach_messages`
                       ADD COLUMN `external_thread_id` VARCHAR(128) NULL AFTER `channel`,
                       ADD INDEX `idx_external_thread_id` (`external_thread_id`)"
                );
            }
        }

        // -----------------------------------------------------------------
        // module_outreach_concerns_config — per-concern practice overrides
        // -----------------------------------------------------------------
        $exists = sqlQuery("SHOW TABLES LIKE 'module_outreach_concerns_config'");
        if (empty($exists)) {
            sqlStatement(
                "CREATE TABLE IF NOT EXISTS `module_outreach_concerns_config` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `concern_type` VARCHAR(64) NOT NULL UNIQUE,
                    `enabled` TINYINT(1) DEFAULT 1,
                    `timing_window_min_hours` INT NULL,
                    `timing_window_max_hours` INT NULL,
                    `channel_preference` VARCHAR(64) NULL,
                    `rate_limit_per_period` INT NULL,
                    `rate_limit_period_hours` INT NULL,
                    `prompt_template` TEXT NULL,
                    `expires_after_hours` INT NULL,
                    `meta` JSON NULL,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `updated_by_user_id` INT NULL,
                    INDEX `idx_enabled` (`enabled`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        // -----------------------------------------------------------------
        // module_outreach_patient_prefs — per-patient opt-in/out
        // -----------------------------------------------------------------
        $exists = sqlQuery("SHOW TABLES LIKE 'module_outreach_patient_prefs'");
        if (empty($exists)) {
            sqlStatement(
                "CREATE TABLE IF NOT EXISTS `module_outreach_patient_prefs` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `patient_id` INT NOT NULL UNIQUE,
                    `patient_uuid` VARCHAR(36) NULL,
                    `master_opt_out` TINYINT(1) DEFAULT 0,
                    `channel_preference` VARCHAR(64) NULL,
                    `concern_opt_outs` JSON NULL,
                    `quiet_hours_start` TINYINT(2) NULL,
                    `quiet_hours_end` TINYINT(2) NULL,
                    `notes` TEXT NULL,
                    `master_opt_out_at` DATETIME NULL,
                    `master_opt_out_reason` VARCHAR(255) NULL,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX `idx_master_opt_out` (`master_opt_out`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        // -----------------------------------------------------------------
        // module_outreach_rate_limits — daily counter (sliding by sent_at)
        // -----------------------------------------------------------------
        // Note: this is a CACHE of activity in module_outreach_messages
        // for fast rate-limit checks. The authoritative source is the
        // messages table; this is just a precomputed daily roll-up to
        // keep dispatch latency low. A daily cron resets old buckets.
        $exists = sqlQuery("SHOW TABLES LIKE 'module_outreach_rate_limits'");
        if (empty($exists)) {
            sqlStatement(
                "CREATE TABLE IF NOT EXISTS `module_outreach_rate_limits` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `patient_id` INT NOT NULL,
                    `patient_uuid` VARCHAR(36) NULL,
                    `bucket_date` DATE NOT NULL,
                    `concern_type` VARCHAR(64) NULL,
                    `count` INT DEFAULT 0,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uk_bucket` (`patient_id`, `patient_uuid`, `bucket_date`, `concern_type`),
                    INDEX `idx_bucket_date` (`bucket_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } else {
            // Idempotent column upgrade for already-installed sites.
            // patient_uuid was added 2026-05-06 to disambiguate recycled
            // pids — when patient_data is wiped (sim clean_slate or hard
            // delete in production) and a new patient is later assigned
            // the same pid, the prior patient's stale rate_limits rows
            // would silently count toward the new patient's daily cap
            // because the (patient_id, bucket_date) tuple alone can't
            // tell them apart. Including patient_uuid in the unique key
            // means recycled pids start fresh.
            $col = sqlQuery(
                "SHOW COLUMNS FROM `module_outreach_rate_limits`
                  WHERE Field = 'patient_uuid'"
            );
            if (empty($col)) {
                sqlStatement(
                    "ALTER TABLE `module_outreach_rate_limits`
                       ADD COLUMN `patient_uuid` VARCHAR(36) NULL AFTER `patient_id`,
                       DROP INDEX `uk_bucket`,
                       ADD UNIQUE KEY `uk_bucket` (`patient_id`, `patient_uuid`, `bucket_date`, `concern_type`)"
                );
            }
        }
    }
}
