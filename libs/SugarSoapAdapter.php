<?php
/**
 * Adapter to SOAP client for Sugar CRM
 * Doing internal login (Sugar track user via session and need special soap call for login)
 * Checking answers for Suagar errors and support autorelogin
 * On SIGHUP if reconnect to new server fail, SOAP server will be unavailable (and generate error on each call)
 */
class SugarSoapAdapter implements ObserverInterface
{
    protected $_log;
    protected $sessionid;
    protected $config;
    /** @var SoapClient $_soap */
    protected $_soap;
    protected $posix_event;

    protected $fault;

    /**
     * @param LoggerInterface $log
     * @param SugarSoapData $config
     * @param PosixEvent $posix_event
     * @throws AsteriskException
     */
    function __construct(LoggerInterface $log, SugarSoapData $config, PosixEvent $posix_event)
    {
        $this->_log = $log;
        $this->config = $config;
        $this->posix_event = $posix_event;

        $this->create_soap_object();
        $this->login();

        $this->posix_event->addObserver($this, 'SIGHUP');
    }

    protected function create_soap_object() {
        if ($this->config->isChanged('host')) {
            $this->_soap = new SoapClient(
                $this->config->host,
                array(
                    'exceptions' => 0,
                    'trace' => true,
                ));
        }
    }

    /**
     *
     * @param bool $reconfig
     * @throws AsteriskException
     * @return
     */
    public function login($reconfig=false)
    {
        // Can be called from soapCall as autologin
        if (!$this->config->isChanged() && $reconfig)
            return;

        $result = $this->_soap->__soapCall('login', array(
            'user_auth' => array(
                'user_name' => $this->config->user,
                'password' => $this->config->password,
                'version' => '.01'
            )
        ));

        if (!$this->checkResult($result)) {
            $this->sessionid = -1;
        } else {
            $this->sessionid = $result->id;
        }

        if ($this->sessionid != -1) {
            $this->log("SOAP logged in with sessionid {$this->sessionid}", 'DEBUG');
            $this->config->clearChanged();
        } else {
            if (!$reconfig)
                throw new AsteriskException("Can't login to SOAP server.");
            else {
                $this->log("Can't login to SOAP server, keep runing, SOAP disabled", 'ERROR');
            }
        }
    }

    /**
     * Check result on errors (both: soap error and sugar's responce error)
     * @param $result
     * @return bool
     */
    protected function checkResult($result) {
        $this->fault = null;

        if (defined('SUGAR_SOAP_TRACE')) {
            $this->log($this->_soap->__getLastRequest(), 'DEBUG');
            $this->log($this->_soap->__getLastResponse(), 'DEBUG');
        }

        if (is_soap_fault($result)) {
            $this->fault = array(
                'type' => 'soap',
                'code' => $result->faultcode,
                'message' => $result->faultstring
            );
            $this->log("SOAP error {$this->fault['message']}", 'ERROR');
            return false;
        }
        if (isset($result->error) && $result->error->number != 0) {
            $this->fault = array(
                'type' => 'responce',
                'code' => $result->error->number,
                'message' => $result->error->name
            );
            $this->log("SOAP sugar responce error {$this->fault['message']}", 'ERROR');
            return false;
        }
        return true;
    }

    public function getError() {
        return $this->fault;
    }

    public function isError() {
        return !is_null($this->fault);
    }

    public function soapCall($function_name, $arguments)
    {
        array_unshift($arguments, $this->sessionid);
        $result = $this->_soap->__soapCall($function_name, $arguments);
        if (!$this->checkResult($result)) {
            if ($this->fault['code'] == 10) {
                $this->login();
                $arguments[0] = $this->sessionid;
                $result = $this->_soap->__soapCall($function_name, $arguments);
                if (!$this->checkResult($result)) {
                    return false;
                }
            } else {
                return false;
            }
        }
        return $result;
    }

    protected function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }

    public function notify(ObservableInterface $source, $eventType, &$params=null)
    {
        if ($source instanceof PosixEvent && $eventType == 'SIGHUP') {
            $this->create_soap_object();
            $this->login(true);
        }
    }
}

