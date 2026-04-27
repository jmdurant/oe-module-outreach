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

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\Kernel;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\Events\RestApiExtend\RestApiScopeEvent;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Modules\Outreach\Controllers\OutreachController;
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
        $this->addMenuItems();
    }

    // -------------------------------------------------------------------
    // Top-level menu (Modules > Patient Outreach)
    // -------------------------------------------------------------------

    public function addMenuItems(): void
    {
        $this->eventDispatcher->addListener(
            MenuEvent::MENU_UPDATE,
            [$this, 'injectOutreachMenu']
        );
    }

    /**
     * Append outreach pages as children of the existing "Modules"
     * dropdown (menu_id=modimg). Six entries — separator, then the
     * four operational pages, then a deep link to the Globals
     * settings section.
     *
     * Wrapped in a try/catch so a listener error here can't break
     * the whole navbar render (which historically presents as a
     * silent session-expired logout in OpenEMR).
     */
    public function injectOutreachMenu(MenuEvent $event): MenuEvent
    {
        try {
            $base = self::MODULE_INSTALLATION_PATH . self::MODULE_NAME . '/public';

            $build = function (string $menuId, string $label, string $url, array $aclReq, array $children = []) {
                $item = new \stdClass();
                $item->requirement = 0;
                $item->target      = 'outreach';
                $item->menu_id     = $menuId;
                $item->label       = xlt($label);
                $item->url         = $url;
                $item->children    = $children;
                $item->acl_req     = $aclReq;
                return $item;
            };

            $aclReq = ['admin', 'super'];
            $children = [
                $build('outreach_msgs',     'Outreach: Messages',            "$base/messages.php",      $aclReq),
                $build('outreach_concerns', 'Outreach: Concerns',            "$base/concerns.php",      $aclReq),
                $build('outreach_prefs',    'Outreach: Patient Preferences', "$base/patient_prefs.php", $aclReq),
                $build('outreach_actions',  'Outreach: Run / Expire',        "$base/actions.php",       $aclReq),
            ];

            $menu = $event->getMenu();
            $appended = false;
            foreach ($menu as $item) {
                if (isset($item->menu_id) && $item->menu_id === 'modimg') {
                    if (!is_array($item->children)) {
                        $item->children = [];
                    }
                    foreach ($children as $c) {
                        $item->children[] = $c;
                    }
                    $appended = true;
                    break;
                }
            }

            // If for some reason this site has no Modules dropdown,
            // skip silently rather than fabricate a parent — better
            // a missing menu than a broken navbar.
            if (!$appended) {
                $this->logger->debug('OUTREACH: Modules (modimg) menu item not found; skipping menu injection.');
            }

            $event->setMenu($menu);
        } catch (\Throwable $e) {
            // The navbar render is downstream of every page in OpenEMR
            // — a thrown exception here can present as a silent logout.
            // Swallow + log so the rest of the navbar still renders.
            $this->logger->error('OUTREACH: menu injection failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
        return $event;
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
        // Capture Bootstrap + logger for closures — the Bootstrap is
        // what PatientOutreachService needs to dispatch the lazy
        // concern-registry event, so the controller takes it via DI.
        $bootstrap = $this;
        $logger    = $this->logger;

        $wrap = function (string $method) use ($bootstrap, $logger) {
            return function (HttpRestRequest $request) use ($bootstrap, $logger, $method) {
                try {
                    $controller = new OutreachController($bootstrap);
                    return $controller->$method($request);
                } catch (\Throwable $e) {
                    $logger->error("OUTREACH: $method failed", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    http_response_code(500);
                    return ['success' => false, 'error' => $e->getMessage()];
                }
            };
        };

        $event->addToFHIRRouteMap("GET /fhir/Outreach/concerns",         $wrap('listConcerns'));
        $event->addToFHIRRouteMap("POST /fhir/Outreach/sweep",           $wrap('sweep'));
        $event->addToFHIRRouteMap("POST /fhir/Outreach/send-one",        $wrap('sendOne'));
        $event->addToFHIRRouteMap("POST /fhir/Outreach/expire-pending",  $wrap('expirePending'));
        $event->addToFHIRRouteMap("GET /fhir/Outreach/messages",         $wrap('listMessages'));
        $event->addToFHIRRouteMap("GET /fhir/Outreach/lookup-by-phone",  $wrap('lookupByPhone'));
        $event->addToFHIRRouteMap("POST /fhir/Outreach/reply",           $wrap('reply'));
        $event->addToFHIRRouteMap("GET /fhir/Outreach/preferences",      $wrap('getPreferences'));
        $event->addToFHIRRouteMap("PUT /fhir/Outreach/preferences",      $wrap('setPreferences'));

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
