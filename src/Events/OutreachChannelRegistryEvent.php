<?php

/**
 * OutreachChannelRegistryEvent — fired by Bootstrap.dispatchChannelRegistry()
 * when PatientOutreachService needs the live channel registry.
 *
 * Channel-providing modules subscribe to this event in their own
 * Bootstrap and call $event->register(new TheirDispatcher()). Example:
 *
 *   // in oe-module-doximity/src/Bootstrap.php
 *   $dispatcher->addListener(
 *       'outreach.channel.registry',  // literal string, see below
 *       function ($event) {
 *           $event->register(new FaxChannelDispatcher());
 *       }
 *   );
 *
 * Mirror of the OutreachConcernRegistryEvent pattern — concerns and
 * channels are both registered the same way so adding a new
 * channel-provider module doesn't require platform changes.
 *
 * Auto-registered defaults: SmsChannelDispatcher, EmailChannelDispatcher,
 * PushChannelDispatcher are auto-registered inside the platform BEFORE
 * this event fires. Subscribers add to that set; key collisions are
 * last-write-wins (a doximity-specific FaxChannelDispatcher can
 * override a stock placeholder if one ever existed).
 *
 * Subscribers should use the literal string 'outreach.channel.registry'
 * rather than importing the EVENT_HANDLE constant — touching the
 * Outreach class at boot poisons Composer's missingClasses cache when
 * subscriber-module boot runs before Outreach's. Strings are inert.
 *
 * @package OpenEMR\Modules\Outreach\Events
 */

namespace OpenEMR\Modules\Outreach\Events;

use OpenEMR\Modules\Outreach\Services\OutreachChannelDispatcher;
use Symfony\Contracts\EventDispatcher\Event;

class OutreachChannelRegistryEvent extends Event
{
    public const EVENT_HANDLE = 'outreach.channel.registry';

    /** @var array<string, OutreachChannelDispatcher> keyed by dispatcher.getChannel() */
    private array $channels = [];

    /**
     * Register a channel dispatcher. Last-write-wins on key collision.
     */
    public function register(OutreachChannelDispatcher $dispatcher): self
    {
        $this->channels[$dispatcher->getChannel()] = $dispatcher;
        return $this;
    }

    /** @return array<string, OutreachChannelDispatcher> */
    public function getChannels(): array
    {
        return $this->channels;
    }

    public function getChannel(string $key): ?OutreachChannelDispatcher
    {
        return $this->channels[$key] ?? null;
    }

    public function hasChannel(string $key): bool
    {
        return isset($this->channels[$key]);
    }
}
