<?php

/**
 * GlobalConfig — exposes practice-wide outreach settings via
 * Admin > Globals > Notifications > Patient Outreach.
 *
 * These are knobs operators twiddle from the GUI:
 *   - Master enable/disable
 *   - Default rate-limit (max SMS per patient per day)
 *   - Quiet hours (no SMS between X and Y o'clock)
 *   - Default channel preference order (sms,email,push)
 *
 * Per-concern timing + per-patient preferences are stored in the
 * outreach DB tables, NOT here — globals are for practice-wide
 * defaults only.
 *
 * @package OpenEMR\Modules\Outreach
 */

namespace OpenEMR\Modules\Outreach;

use OpenEMR\Services\Globals\GlobalSetting;

class GlobalConfig
{
    const CONFIG_ENABLED              = 'oe_outreach_enabled';
    const CONFIG_DEFAULT_RATE_LIMIT   = 'oe_outreach_default_rate_limit';
    const CONFIG_QUIET_HOURS_START    = 'oe_outreach_quiet_hours_start';
    const CONFIG_QUIET_HOURS_END      = 'oe_outreach_quiet_hours_end';
    const CONFIG_DEFAULT_CHANNELS     = 'oe_outreach_default_channels';
    const CONFIG_DRY_RUN              = 'oe_outreach_dry_run';

    /** @var array */
    private $globalsArray;

    public function __construct(array &$globalsArray)
    {
        $this->globalsArray = &$globalsArray;
    }

    /**
     * Schema for the GlobalsService section. The module's Bootstrap
     * iterates this and registers each setting with OpenEMR's globals
     * UI so practice can edit them in Admin > Globals.
     */
    public function getGlobalSettingSectionConfiguration(): array
    {
        return [
            self::CONFIG_ENABLED => [
                'title'       => 'Enable patient outreach',
                'description' => 'Master switch. When OFF, the outreach service refuses to dispatch any messages, including manual MCP calls. Use to halt outreach during maintenance.',
                'type'        => GlobalSetting::DATA_TYPE_BOOL,
                'default'     => '1',
            ],
            self::CONFIG_DRY_RUN => [
                'title'       => 'Dry-run mode',
                'description' => 'When ON, the service computes everything (candidates, messages, tracking rows) but skips the actual SMS/email/push dispatch. Useful for staging + first-week verification.',
                'type'        => GlobalSetting::DATA_TYPE_BOOL,
                'default'     => '0',
            ],
            self::CONFIG_DEFAULT_RATE_LIMIT => [
                'title'       => 'Default rate limit (messages per patient per day)',
                'description' => 'Maximum outreach messages dispatched to a single patient in a 24h window across all concerns. Per-concern overrides happen on the concerns config page.',
                'type'        => GlobalSetting::DATA_TYPE_TEXT,
                'default'     => '4',
            ],
            self::CONFIG_QUIET_HOURS_START => [
                'title'       => 'Quiet hours start (24h, e.g. 21 = 9pm)',
                'description' => 'Hour after which SMS dispatch is deferred until the next morning. Email + push ignore quiet hours.',
                'type'        => GlobalSetting::DATA_TYPE_TEXT,
                'default'     => '21',
            ],
            self::CONFIG_QUIET_HOURS_END => [
                'title'       => 'Quiet hours end (24h, e.g. 8 = 8am)',
                'description' => 'Hour before which SMS dispatch is deferred. Used as the wake-up time after quiet hours start.',
                'type'        => GlobalSetting::DATA_TYPE_TEXT,
                'default'     => '8',
            ],
            self::CONFIG_DEFAULT_CHANNELS => [
                'title'       => 'Default channel preference (comma-separated, in order)',
                'description' => 'When a concern emits a message and the patient has no per-channel preference set, the service walks this list left-to-right and uses the first channel the patient has contact info for. Example: "sms,email,push".',
                'type'        => GlobalSetting::DATA_TYPE_TEXT,
                'default'     => 'sms,email,push',
            ],
        ];
    }

    public function isEnabled(): bool
    {
        return (string) ($this->globalsArray[self::CONFIG_ENABLED] ?? '1') === '1';
    }

    public function isDryRun(): bool
    {
        return (string) ($this->globalsArray[self::CONFIG_DRY_RUN] ?? '0') === '1';
    }

    public function getDefaultRateLimit(): int
    {
        return max(0, (int) ($this->globalsArray[self::CONFIG_DEFAULT_RATE_LIMIT] ?? 4));
    }

    public function getQuietHoursStart(): int
    {
        return max(0, min(23, (int) ($this->globalsArray[self::CONFIG_QUIET_HOURS_START] ?? 21)));
    }

    public function getQuietHoursEnd(): int
    {
        return max(0, min(23, (int) ($this->globalsArray[self::CONFIG_QUIET_HOURS_END] ?? 8)));
    }

    /**
     * Channel preference list from globals — the FALLBACK when a patient
     * has no per-patient preference stored. Returns lowercased names.
     */
    public function getDefaultChannels(): array
    {
        $raw = (string) ($this->globalsArray[self::CONFIG_DEFAULT_CHANNELS] ?? 'sms,email,push');
        $parts = array_map('trim', explode(',', $raw));
        return array_values(array_filter(array_map('strtolower', $parts)));
    }
}
