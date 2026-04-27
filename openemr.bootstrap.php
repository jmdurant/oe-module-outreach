<?php

/**
 * Bootstrap entry for oe-module-outreach.
 *
 * Loaded by OpenEMR's module dispatcher on every request when the module is
 * enabled. Registers the module's namespace + wires up the Bootstrap class
 * which subscribes to the events this module cares about.
 *
 * @package OpenEMR\Modules\Outreach
 */

namespace OpenEMR\Modules\Outreach;

/**
 * @global \OpenEMR\Core\ModulesClassLoader $classLoader
 */
$classLoader->registerNamespaceIfNotExists(
    'OpenEMR\\Modules\\Outreach\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src'
);

/**
 * @global \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
 */
$bootstrap = new Bootstrap($eventDispatcher, $GLOBALS['kernel']);
$bootstrap->subscribeToEvents();
