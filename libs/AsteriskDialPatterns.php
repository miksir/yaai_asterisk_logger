<?php
/**
 * Patterns used for correct phone number coming to/from Asterisk
 * On SIGHUP reload chaned patterns
 */
class AsteriskDialPatterns implements ObserverInterface
{
    protected $config;
    protected $_log;

    public function __construct(LoggerInterface $log, AsteriskPatternData $config, PosixEvent $posix_event) {
        $this->config = $config;
        $this->_log = $log;
        $this->posix_event = $posix_event;
        $this->check_config();

        $posix_event->addObserver($this, 'SIGHUP');
    }

    protected function check_config($verify_changed = false) {
        $params = array_keys(get_class_vars(get_class($this->config)));
        foreach ($params as $param) {
            if (!$verify_changed || $this->config->isChanged($param)) {
                $value = $this->config->$param;
                $this->log($param . ' = ' . $value, 'DEBUG');
            }
        }
        $this->config->clearChanged();
    }

    /**
     * Cleanup phone string received from Asterisk (usually as CallerID) for search in Sugar
     * @param $phone
     * @return mixed
     */
    public function cleanIncomingPhone($phone) {
        return $this->trim($phone, $this->config->incoming_number_ltrim);
    }

    /**
     * Cleanup phone string sended by Asterisk to uplink (usually as Dialstring param - Asterisk 1.6.1+) for search in Sugar
     * Usually format of dialstring is "string/number", so pattern need to stip string, slash and may be some digits if we need it
     * @param $phone
     * @return mixed
     */
    public function cleanOutgoingPhone($phone) {
        return $this->trim($phone, $this->config->outgoing_number_ltrim);
    }

    /**
     * This pattern checked via Asterisk's Channel parameter, if matched - this is internal channel (for example /^SIP\/\d{3,4}-/)
     * @param $channel
     * @return int
     */
    public function isInternalChannel($channel) {
        return $this->match($channel, $this->config->internal_channel_pattern);
    }

    /**
     * This pattern checked via Asterisk's Channel parameter, if matched - this is external channel
     * (for example /^SIP\/peername-/)
     * @param $channel
     * @return int
     */
    public function isExternalChannel($channel) {
        return $this->match($this->config->external_channel_pattern, $channel);
    }

    /**
     * Is this number office extension? Usually we check it using number length (office extension = 2-4 digits)
     * @param $number
     * @return bool
     */
    public function isExtensionNumber($number) {
        return strlen($number) <= $this->config->extension_max_len;
    }

    /**
     * Doing trim based on pattern. If pattern started from slash - its regexp, so replace matched part to empty string.
     * If pattern started from other char - left trim this pattern from string. If we need to use string trim and start pattern
     * from slash - escape slash with backslash (note: "\/" wrong because escaped by php internaly, so we need to use "\\/")
     * @param string $string
     * @param string $pattern
     * @return string
     */
    protected function trim($string, $pattern) {
        if ($pattern && $pattern[0] == '/') {
            $string = preg_replace($pattern, '', $string);
        } elseif ($pattern) {
            if (substr($pattern, 0, 2) == "\\/") {
                $pattern = substr($pattern, 1);
            }
            $length = strlen($pattern);
            if (substr($string, 0, $length) == $pattern) {
                $string = substr($string, $length);
            }
        }
        return $string;
    }

    /**
     * Check pattern. Same pattern syntax as in self::trim
     * @param $string
     * @param $pattern
     * @return bool
     * @see trim
     */
    protected function match($string, $pattern) {
        $result = true;
        if ($pattern && $pattern[0] == '/') {
            $result = preg_match($pattern, $string);
            $result = $result ? true : false;
        } elseif ($pattern) {
            if (substr($pattern, 0, 2) == "\\/") {
                $pattern = substr($pattern, 1);
            }
            $length = strlen($pattern);
            if (substr($string, 0, $length) == $pattern) {
                $result = true;
            } else {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * Is this number city phone number? Usually we check it using number length (city phone number 5-7 or more digits)
     * @param $number
     * @return bool
     */
    public function isExternalNumber($number) {
        return strlen($number) >= $this->config->external_phone_min_len;
    }

    public function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }

    public function notify(ObservableInterface $source, $eventType, &$params=null)
    {
        if ($source instanceof PosixEvent && $eventType == 'SIGHUP') {
            $this->check_config(true);
        }
    }
}
