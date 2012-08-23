<?php
/**
 * Injector class. Here we are creating all required classes. On create we are checking constructor of class
 * for required dependence for injection. This is also work as singlton fabric.
 *
 * @author  Dmitry MiksIr <dmiksir@gmail.com>
 * @license GPLv3
 *
 * @method setLoggerInterface(LoggerInterface $logger)
 * @method setPDO(PDO $db)
 * @method setSoapClient(SoapClient $soap_driver)
 * @method setAsteriskLanguage(AsteriskLanguage $lang)
 * @method setDbPDOAdapter(DbPDOAdapter $lang)
 * @method setAsteriskDialPatterns(AsteriskDialPatterns $patterns)
 *
 * @method SugarSoapAdapter getSugarSoapAdapter()
 * @method SugarAsteriskConfig getSugarAsteriskConfig()
 * @method PosixEvent getPosixEvent()
 * @method AsteriskManager getAsteriskManager()
 * @method SugarCall getSugarCall()
 * @method AsteriskCall getAsteriskCall()
 * @method AsteriskEventsObserver getAsteriskEventsObserver()
 * @method SugarPhonesDb getSugarPhonesDb()
 */
class AsteriskInjector
{
    protected $cache = array();
    /* Map for class names, here we can map some required dependence
     * to real classes (its very important if class want interface) */
    protected $_map = array(
        'CacheInterface' => 'NullCache',
    );

    public function __construct() {

    }

    /**
     * Set new entry to class map
     * @param array $arr key=>value, key - source name, value - destination name
     * @throws AsteriskInjectorException
     */
    public function setMap($arr) {
        foreach ($arr as $interface=>$class) {
            $this->_map[$interface] = $class;
        }
    }

    /**
     * Magic for getClass() and setClass()
     *
     * @param string $method
     * @param mixed $arg
     *
     * @return bool|mixed
     */
    public function __call($method, $arg = null) {
        $class = $this->_methodToClass($method);
        if (substr($method, 0, 3) == 'get') {
            return $this->_get($class);
        } elseif (substr($method, 0, 3) == 'set') {
            $this->_set($class, $arg);
            return true;
        }
        return false;
    }

    /**
     * @param string $method
     * @return string
     */
    protected function _methodToClass($method) {
        $class = substr($method, 3); // strip 'get' or 'set'
        return $class;
    }

    /**
     * @param string $class
     * @return mixed
     * @throws AsteriskInjectorException
     */
    protected function _get($class) {
        $class = $this->_findInMap($class);

        if (array_key_exists($class, $this->cache))
            return $this->cache[$class];

        if (class_exists($class))
            $this->cache[$class] = $this->createNewClass($class);
        else
            throw new AsteriskInjectorException('Class '.$class.' not found');

        return $this->cache[$class];
    }

    /**
     * @param string $class
     * @param mixed $args
     */
    protected function _set($class, $args) {
        $class = $this->_findInMap($class);

        $this->cache[$class] = $args[0];
    }

    protected function _findInMap($class) {
        if (array_key_exists($class, $this->_map)) {
            $class = $this->_map[$class];
        }
        return $class;
    }

    /**
     * Create class instance and check class API from constructor. Create all necessary classes
     * @param string $class
     * @return object
     * @throws AsteriskInjectorException
     */
    protected function createNewClass($class) {
        $refl = new ReflectionClass($class);
        if (!$refl->isInstantiable()) {
            throw new AsteriskInjectorException("Class {$class} is not instantiable");
        }
        /** @var $constructor ReflectionMethod  */
        $constructor = $refl->getConstructor();
        $params = array();
        if ($constructor) {
            $params = $constructor->getParameters();
        }
        /** @var $param ReflectionParameter */
        $subst_params = array();
        foreach($params as $param) {
            if ($cl = $param->getClass()) {
                $cl_name = $cl->getName();
                $subst_params[] = $this->_get($cl_name);
                continue;
            }
            if ($param->isOptional()) {
                $subst_params[] = $param->getDefaultValue();
                continue;
            }
            throw new AsteriskInjectorException("Can't create class {$class}, unknown value for param ".$param->getName());
        }
        return empty($subst_params) ? new $class() : $refl->newInstanceArgs($subst_params);
    }
}
