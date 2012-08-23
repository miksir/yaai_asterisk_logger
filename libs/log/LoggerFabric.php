<?php
/**
 * Fabric for select logger based on Sugar's config value
 * @depricated
 * @TODO Change logger patters for config reloading support
 */
class LoggerFabric
{
    static protected $instances;

    /**
     * @static
     * @param string $param
     * @return LoggerInterface
     */
    static function getLogger($param) {
        if (isset(self::$instances[$param]))
            return self::$instances[$param];

        if (!$param) {
            return (self::$instances[$param] = new ConsoleLogger());
        }

        if (preg_match('/^\w+Logger$/', $param)) {
            return (self::$instances[$param] = new $param());
        }

        if ($param[0] = '/') {
            return (self::$instances[$param] = new FileLogger($param));
        }

        return (self::$instances[$param] = new ConsoleLogger());
    }
}
