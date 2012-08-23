<?php
/**
 * Config loader
 * On SIGHUP reload all configs
 */
class SugarAsteriskConfig implements ObserverInterface
{
    protected $root;
    protected $_log;
    protected $sugar_config = array();

    protected $db_config;
    protected $soap_config;
    protected $asterisk_config;
    protected $asterisk_pattern;
    protected $sugar_language;

    public function __construct(
        LoggerInterface $log,
        PosixEvent $posix_event,
        SugarDbData $db_config,
        SugarSoapData $soap_config,
        AsteriskData $asterisk_config,
        AsteriskPatternData $asterisk_pattern,
        SugarLanguageData $sugar_language
    )
    {
        if (!defined("sugarRootDir")) {
            throw new AsteriskException("Constant sugarRootDir required");
        }

        define('sugarEntry', true);

        $this->root = sugarRootDir;

        $this->_log = $log;

        $this->db_config = $db_config;
        $this->soap_config = $soap_config;
        $this->asterisk_config = $asterisk_config;
        $this->asterisk_pattern = $asterisk_pattern;
        $this->sugar_language = $sugar_language;

        $posix_event->addObserver($this, 'SIGHUP', 100);

        $this->load_config();
    }

    public function load_config()
    {
        $sugar_config = array();
        $sugar_version = '0';

        if (defined("asteriskCliRootDir") && file_exists($config = asteriskCliRootDir.'/config_asteriskcli.php'))
            @include($config);

        require($this->root . '/config.php');

        if (file_exists($config = $this->root . '/config_override.php'))
            @include($config);

        if (file_exists($config = $this->root . '/config_asteriskcli.php'))
            @include($config);

        require($this->root . '/sugar_version.php');

        $this->sugar_config = $sugar_config;

        $this->load_db_config();
        $this->load_asterisk_config();
        $this->load_soap_config();
        $this->load_asterisk_pattern();
        $this->load_sugar_language_config();

        $this->log("Sugar config loaded, sugar version " . $sugar_version, 'DEBUG');
    }

    public function notify(ObservableInterface $source, $eventType, &$params=null)
    {
        if ($source instanceof PosixEvent && $eventType == 'SIGHUP') {
            $this->load_config();
        }
    }

    protected function load_db_config()
    {
        $this->db_config->set_host($this->sugar_config['dbconfig']['db_host_name']);
        $this->db_config->set_port($this->sugar_config['dbconfig']['db_port']);
        $this->db_config->set_db($this->sugar_config['dbconfig']['db_name']);
        $this->db_config->set_user($this->sugar_config['dbconfig']['db_user_name']);
        $this->db_config->set_password($this->sugar_config['dbconfig']['db_password']);
        $this->db_config->set_dsn("mysql:dbname={$this->sugar_config['dbconfig']['db_name']};host={$this->sugar_config['dbconfig']['db_host_name']}");
    }

    public function load_asterisk_config()
    {
        $this->asterisk_config->set_host($this->sugar_config['asterisk_host']);
        $this->asterisk_config->set_port($this->sugar_config['asterisk_port']);
        $this->asterisk_config->set_user($this->sugar_config['asterisk_user']);
        $this->asterisk_config->set_password($this->sugar_config['asterisk_secret']);
    }

    public function load_soap_config()
    {
        $this->soap_config->set_host($this->sugar_config['site_url'] . "/soap.php?wsdl");
        $this->soap_config->set_user($this->sugar_config['asterisk_soapuser']);
        $this->soap_config->set_password(md5($this->sugar_config['asterisk_soappass']));
    }

    public function load_asterisk_pattern()
    {
        $this->asterisk_pattern->set_external_channel_pattern($this->sugar_config['asteriskcli_external_channel_pattern']);
        $this->asterisk_pattern->set_internal_channel_pattern($this->sugar_config['asteriskcli_internal_channel_pattern']);
        $this->asterisk_pattern->set_incoming_number_ltrim($this->sugar_config['asteriskcli_incoming_number_ltrim']);
        $this->asterisk_pattern->set_outgoing_number_ltrim($this->sugar_config['asteriskcli_outgoing_number_ltrim']);
        $this->asterisk_pattern->set_callout_prefix($this->sugar_config['asteriskcli_callout_prefix']);
        $this->asterisk_pattern->set_extension_max_len($this->sugar_config['asteriskcli_extension_max_len']);
        $this->asterisk_pattern->set_external_phone_min_len($this->sugar_config['asteriskcli_external_phone_min_len']);
    }

    public function load_sugar_language_config() {
        $this->sugar_language->set_language($this->sugar_config['default_language']);
    }

    public function log($message, $level = 'INFO')
    {
        $this->_log->log(get_class() . ': ' . $message, $level);
    }
}

/**
 * Base class for config storages
 */
class ConfigEntry
{
    private $changed = array();

    public function __call($name, $arguments) {
        if (substr($name, 0, 4) == 'get_') {
            $name = substr($name, 4);
            return $this->get($name);
        }
        if (substr($name, 0, 4) == 'set_') {
            $name = substr($name, 4);
            $this->set($name, $arguments[0]);
            return true;
        }
        return false;
    }

    public function set($param, $value)
    {
        if (property_exists($this, $param)) {
            if ($this->$param != $value) {
                $this->changed[$param] = true;
                $this->$param = $value;
            }
        }
    }

    public function get($param, $reset_changed=false)
    {
        if ($reset_changed)
            unset($this->changed[$param]);
        return $this->$param;
    }

    public function isChanged($param = null, $reset_changed=false)
    {
        if ($param) {
            $changed = isset($this->changed[$param]);
            if ($reset_changed && $changed)
                unset($this->changed[$param]);
        }
        else {
            $changed = !empty($this->changed);
        }

        return $changed;
    }

    public function clearChanged()
    {
        $this->changed = array();
    }
}

/* Config classes for each config group. It's better to manage that one superclass with all config records */

class SugarDbData extends ConfigEntry
{
    public $dsn;
    public $host;
    public $port;
    public $db;
    public $user;
    public $password;
}

class AsteriskData extends ConfigEntry
{
    public $host;
    public $port;
    public $user;
    public $password;
}

class SugarSoapData extends ConfigEntry
{
    public $host;
    public $user;
    public $password;
}

/**
 * @method set_external_channel_pattern
 * @method set_internal_channel_pattern
 * @method set_incoming_number_ltrim
 * @method set_outgoing_number_ltrim
 * @method set_callout_prefix
 * @method set_extension_max_len
 * @method set_external_phone_min_len
 */
class AsteriskPatternData extends ConfigEntry
{
    public $external_channel_pattern; // regexp for match Channel of incoming call from city
    public $internal_channel_pattern; // regexp for match local office Channel
    public $incoming_number_ltrim;    // regexp for cleanup incoming CallerID string (matched pattern will be removed)
    public $outgoing_number_ltrim;    // regexp for cleanup Destination string for outgoing call ( -- "" -- )
    public $callout_prefix;           // prefix for call to city
    public $extension_max_len;        // maximum length of extension number
    public $external_phone_min_len;   // minimum length of city numbers (dialed and CallerID)
                                      // $extension_max_len must be lower than $external_phone_min_len
}

class SugarLanguageData extends ConfigEntry
{
    public $language;
}