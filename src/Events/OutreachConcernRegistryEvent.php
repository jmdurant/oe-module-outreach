<?php

/**
 * OutreachConcernRegistryEvent — fired by Bootstrap.dispatchConcernRegistry()
 * when PatientOutreachService needs the live concern registry.
 *
 * Concern-providing modules subscribe to this event in their own
 * Bootstrap and call $event->register(new TheirConcern()). Example:
 *
 *   // in oe-module-online-booking/src/Bootstrap.php
 *   $dispatcher->addListener(
 *       OutreachConcernRegistryEvent::EVENT_HANDLE,
 *       function (OutreachConcernRegistryEvent $event) {
 *           $event->register(new AppointmentConfirmationConcern());
 *       }
 *   );
 *
 * Lazy by design — fired on first sweep, not at boot — because
 * concerns may be expensive to construct (some pull config from
 * module_outreach_concerns_config).
 *
 * @package OpenEMR\Modules\Outreach\Events
 */

namespace OpenEMR\Modules\Outreach\Events;

use OpenEMR\Modules\Outreach\OutreachConcern;
use Symfony\Contracts\EventDispatcher\Event;

class OutreachConcernRegistryEvent extends Event
{
    /**
     * Symfony dispatcher event-name. Modules subscribing pass this
     * constant to addListener.
     */
    public const EVENT_HANDLE = 'outreach.concern.registry';

    /** @var array<string, OutreachConcern> keyed by concern.getKey() */
    private array $concerns = [];

    /**
     * Register a concern. Last-write-wins on key collision — useful
     * for testing (a test harness can register a mock with the same
     * key to override production behavior). Production modules
     * should each have unique keys.
     */
    public function register(OutreachConcern $concern): self
    {
        $this->concerns[$concern->getKey()] = $concern;
        return $this;
    }

    /** @return array<string, OutreachConcern> */
    public function getConcerns(): array
    {
        return $this->concerns;
    }

    public function getConcern(string $key): ?OutreachConcern
    {
        return $this->concerns[$key] ?? null;
    }

    public function hasConcern(string $key): bool
    {
        return isset($this->concerns[$key]);
    }
}
