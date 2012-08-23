<?php
/**
 * Our autoloader class. Use SPL for autoload. If default __autoload was defined early - switch it to SPL
 */
class AsteriskAutoloader {
    protected $map = array();

    public function __construct($dir=null) {
        if (is_null($dir))
            $dir = __DIR__;

        $map2 = $this->readdirR($dir);
        $this->map = array_merge($this->map, $map2);
        unset($map2);

        if (spl_autoload_functions() === FALSE && function_exists('__autoload')) {
            spl_autoload_register('__autoload');
        }
        spl_autoload_register(array($this, 'autoload'));
    }

    protected function &readdirR($dir) {
        $map = array();

        $h = opendir($dir);
        while ($file = readdir($h)) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            if (is_dir($dir.'/'.$file)) {
                $map2 = $this->readdirR($dir.'/'.$file);
                $map = array_merge($map, $map2);
                unset($map2);
            } elseif (preg_match('/^(.+)\.php$/i', $file, $match)) {
                $map[$match[1]] = $dir.'/'.$file;
            }
        }
        closedir($h);

        unset($file);
        unset($dir);
        return $map;
    }

    public function autoload($class) {
        if (!isset($this->map[$class]))
            return;

        require($this->map[$class]);
    }
}

