<?php

/**
 * Bootstrap for oe-module-outreach.
 *
 * Wires the module into OpenEMR. Three responsibilities:
 *
 *   1. Globals: register the module's settings section in
 *      Admin > Globals so practice can configure cadence + channel
 *      defaults from the GUI.
 *
 *   2. REST routes: register the FHIR-prefixed Outreach endpoints
 *      (sweep, messages, preferences, lookup-by-phone). Routes are
 *      under /apis/default/fhir/Outreach/* — the FHIR prefix is the
 *      only route-registration slot OpenEMR exposes for custom
 *      modules; the routes themselves are NOT FHIR-canonical resources.
 *
 *   3. Concern registry: fires the OutreachConcernRegistryEvent
 *      (lazy, on first sweep — not at boot) so other modules
 *      (online-booking, prepayment, portal-messaging-v2) can register
 *      their concerns. Each module's own Bootstrap subscribes to this
 *      event and calls $event->register(new TheirConcern()).
 *
 * @package OpenEMR\Modules\Outreach
 */

namespace OpenEMR\Modules\Outreach;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\Kernel;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\Events\RestApiExtend\RestApiScopeEvent;
use OpenEMR\Modules\Outreach\Events\OutreachConcernRegistryEvent;
use OpenEMR\Services\Globals\GlobalSetting;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
    const MODULE_NAME = "oe-module-outreach";

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    /** @var GlobalConfig */
    private $globalsConfig;

    /** @var SystemLogger */
    private $logger;

    public function __construct(EventDispatcherInterface $eventDispatcher, ?Kernel $kernel = null)
    {
        global $GLOBALS;
        $this->eventDispatcher = $eventDispatcher;
        $this->globalsConfig   = new GlobalConfig($GLOBALS);
        $this->logger          = new SystemLogger();
    }

    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    public function getGlobalConfig(): GlobalConfig
    {
        return $this->globalsConfig;
    }

    public function subscribeToEvents(): void
    {
        $this->addGlobalSettings();
        $this->addRestRoutes();
    }

    // -------------------------------------------------------------------
    // Globals (Admin > Globals > Outreach)
    // -------------------------------------------------------------------

    public function addGlobalSettings(): void
    {
        $this->eventDispatcher->addListener(
            GlobalsInitializedEvent::EVENT_HANDLE,
            [$this, 'addGlobalSettingsSection']
        );
    }

    public function addGlobalSettingsSection(GlobalsInitializedEvent $event): void
    {
        global $GLOBALS;
        $service = $event->getGlobalsService();
        $section = xlt("Patient Outreach");
        $service->createSection($section, 'Notifications');

        foreach ($this->globalsConfig->getGlobalSettingSectionConfiguration() as $key => $cfg) {
            $value = $GLOBALS[$key] ?? $cfg['default'];
            $service->appendToSection(
                $section,
                $key,
                new GlobalSetting(
                    xlt($cfg['title']),
                    $cfg['type'],
                    $value,
                    xlt($cfg['description']),
                    true
                )
            );
        }
    }

    // -------------------------------------------------------------------
    // REST routes (under /apis/default/fhir/Outreach/*)
    // -------------------------------------------------------------------

    public function addRestRoutes(): void
    {
        $this->eventDispatcher->addListener(
            RestApiCreateEvent::EVENT_HANDLE,
            [$this, 'registerOutreachRoutes']
        );
        $this->eventDispatcher->addListener(
            RestApiScopeEvent::EVENT_TYPE_GET_SUPPORTED_SCOPES,
            [$this, 'addOutreachScopes']
        );
    }

    public function registerOutreachRoutes(RestApiCreateEvent $event): RestApiCreateEvent
    {
        // Route handlers wired in Task #16. This Bootstrap intentionally
        // registers an empty route set on first scaffold so the module
        // loads cleanly before the full surface lands. Endpoints landing
        // when controllers exist:
        //   GET  /fhir/Outreach/concerns
        //   POST /fhir/Outreach/sweep
        //   GET  /fhir/Outreach/messages
        //   GET  /fhir/Outreach/lookup-by-phone
        //   GET  /fhir/Outreach/preferences/:patient_uuid
        //   PUT  /fhir/Outreach/preferences/:patient_uuid
        return $event;
    }

    public function addOutreachScopes(RestApiScopeEvent $event): RestApiScopeEvent
    {
        if ($event->getApiType() !== RestApiScopeEvent::API_TYPE_FHIR) {
            return $event;
        }
        $scopes = $event->getScopes();
        // user-* scope shape since these are admin/operational endpoints,
        // not patient-facing FHIR canonical resources.
        $scopes[] = 'user/Outreach.read';
        $scopes[] = 'user/Outreach.write';
        $event->setScopes($scopes);
        return $event;
    }

    // -------------------------------------------------------------------
    // Concern registry — other modules contribute their concerns here
    // -------------------------------------------------------------------

    /**
     * Fire the registry event and return whatever concerns other modules
     * have subscribed. Called lazily by PatientOutreachService on the
     * first sweep, NOT at boot — concerns can be expensive to build
     * (some pull config from DB) and we don't want that cost on every
     * request.
     */
    public function dispatchConcernRegistry(): OutreachConcernRegistryEvent
    {
        $event = new OutreachConcernRegistryEvent();
        $this->eventDispatcher->dispatch($event, OutreachConcernRegistryEvent::EVENT_HANDLE);
        return $event;
    }
}
