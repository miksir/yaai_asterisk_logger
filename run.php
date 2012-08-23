<?php

ini_set('display_errors', 1);

$root = __DIR__.'/';

require($root.'autoloader.php');
new AsteriskAutoloader();
$injector = new AsteriskInjector();

define('sugarRootDir', realpath(__DIR__ . "/../../../").'/');
define('asteriskCliRootDir', $root);

define('ASTERISK_DEBUG', ''); // debug logs on
//define('ASTERISK_MANAGER_TRACE', ''); //trace asterisk packets to log

set_exception_handler(function($e) {
        echo "Startup error: ".$e->getMessage()."\n";
        exit(1);
    }
);

/* Some classes depend on interfaces, so we need to show: which classes of this interface to use */
$injector->setMap(array(
    'CacheInterface' => 'InternalCache',
    'DbAdapterInterface' => 'DbPDOAdapter',
));

/* LoggerFabric can select logger depend on config param. If we no need config support, use $logger = new ConsoleLogger() or any other Logger */
$logger = new ConsoleLogger();
$injector->setLoggerInterface($logger);

// Init configs
$injector->getSugarAsteriskConfig();

/* Asterisk Manager doing main loop with listen asterisk events and call */
$api = $injector->getAsteriskManager();
$api->setEventTypes('call,hud');

// "Loop" event will be fired after select() call. We need this event for call pcntl_signal_dispatch()
$api->addObserver($injector->getPosixEvent(), 'Loop');
// And main assigment - who will process all asterisk packets
$api->addObserver($injector->getAsteriskEventsObserver(), 'Event');

$logger->log("Started ".strftime('%x %X'));
$api->loop();
