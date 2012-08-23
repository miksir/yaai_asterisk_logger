<?php
/**
 * AsteriskManager API for SugarCRM and yaai asterisk connector
 * Based on phpagi (http://phpagi.sourceforge.net)
 *
 * phpagi Copyright (c) 2004 - 2010 Matthew Asham <matthew@ochrelabs.com>, David Eder <david@eder.us> and others
 * AsteriskManager Copyright (c) 2012 Dmitry MiksIr <miksir@maker.ru>
 * All Rights Reserved.
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
class AsteriskManager implements ObservableInterface,ObserverInterface
{
    private $logger;
    protected $config;
    protected $socket;
    protected $logged_in = 0;
    protected $connected = 0;
    protected $reconnect = 0;
    protected $current_select_timeout = null;
    private $command_callback = null;
    protected $observers = array();
    protected $count = 1;

    /* Can set up this lines */
    const CRLF = "\r\n";
    const AST_HANDSHAKE = "Asterisk Call Manager/1.1\r\n";
    protected $event_types = 'all';
    protected $reconnect_delay = 500000; // microsec (1sec = 1 000 000 microsec)
    protected $handshake_timeout = 20; // sec


    public function __construct(LoggerInterface $logger, AsteriskData $config, PosixEvent $posix_event) {
        $this->logger = $logger;
        $this->config = $config;
        $posix_event->addObserver($this, 'SIGHUP');
    }

    /**
     * Connect to Asterisk
     * @param bool $reconfig
     * @return boolean true on success
     */
    public function connect($reconfig = false)
    {
        if ($reconfig && !$this->config->isChanged()) {
            return true;
        }

        $this->reconnect = 0;
        if ($this->socket) {
            $this->reconnect = 1;
        }

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === FALSE) {
            $this->log("Unable to create socket - ".socket_last_error(), 'ERROR');

        } else {

            while (!@socket_connect($socket, $this->config->host, $this->config->port)) {
                $this->log("Unable to connect to manager {$this->config->host}:{$this->config->port} - ".socket_last_error($socket), 'ERROR');
                if ($this->reconnect && !$reconfig) {

                    usleep($this->reconnect_delay);

                    $this->fireEvent('Loop');

                    // if we got HUP here, we did connect() once more again, and it can be succeful
                    if (!$this->reconnect) {
                        return true;
                    }

                } else {
                    $socket = FALSE;
                    break;
                }
            }
        }

        if ($socket !== FALSE) {
            socket_clear_error($socket);

            $this->log("Connected to manager {$this->config->host}:{$this->config->port}", 'DEBUG');

            if ($this->reconnect)
                @socket_close($this->socket);
            $this->socket = $socket;

            $this->connected = 0;
            $this->logged_in = 0;
            $this->reconnect = 0;

            $this->current_select_timeout = $this->handshake_timeout;
            $this->config->clearChanged();

            return true;

        } else {

            if ($reconfig)
                $this->log("Fail to change asterisk connection, keep working with old connection", 'ERROR');

            return false;
        }
    }

    public function setEventTypes($str) {
        $this->event_types = $str;
    }

    /**
     * Main loop: receive asterisk data and call callback function
     * @throws AsteriskException
     * @return void
     */
    public function loop() {
        if (!$this->connected) {
            if (!$this->connect()) {
                throw new AsteriskException("Cant connect to Asterisk manager");
            }
        }

        $buffer = '';
        $part_buffer = '';
        while(true) {
            $ret = @socket_select($r=array($this->socket), $w=NULL, $e=NULL, $this->current_select_timeout);

            $this->fireEvent('Loop');

            // If our select iterrupted with posix signal, return value is FALSE,
            // so need to check error code
            if ($ret === FALSE) {
                if (($se = socket_last_error($this->socket)) !== 0) {
                    $this->log("socket_select error - $se", 'ERROR');
                    if (!$this->connect()) {
                        return;
                    }
                }
                continue;
            }

            if ($ret === 0) {
                // socket_select timed out
                if (!$this->connected) {
                    // Asterisk's handshake still not received
                    $this->log("Handshake timeout, reconnecting...", 'ERROR');
                    if (!$this->connect()) {
                        usleep($this->reconnect_delay);
                    }
                }
                continue;
            }

            $len = socket_recv($this->socket, $buffer, 4096, MSG_DONTWAIT);
            if ($len === FALSE) {
                $this->log("socket_recv error - ".socket_last_error($this->socket), 'ERROR');
                if (!$this->connect()) {
                    usleep($this->reconnect_delay);
                }
                continue;
            }

            if (!$len) {
                $this->log("Disconnection detected, reconnecting...", 'INFO');
                if (!$this->connect()) {
                    usleep($this->reconnect_delay);
                }
                continue;
            }

            // Check if this is handshake
            if (!$this->connected && strpos($buffer, self::AST_HANDSHAKE) !== FALSE) {
                $this->log("Handshaked well, logging in...", 'DEBUG');
                if (!$this->process_login()) {
                    throw new AsteriskException("Login to Asterisk fail");
                }
                $this->connected = 1;
                $this->current_select_timeout = null;
                continue;
            }

            while(($pos = strpos($buffer, self::CRLF.self::CRLF)) !== FALSE) {
                // end of packet found
                $this->parse_packet($part_buffer.substr($buffer,0,$pos+4));
                $part_buffer = '';
                $buffer = substr($buffer,$pos+4);
            }

            //buffer without end of packet, save for future use
            $part_buffer = $part_buffer . $buffer;
            $buffer = '';
        }
    }

    /**
     * Send asterisk Login packet
     * @return bool
     */
    protected function process_login() {
        $this->send_request('Login', array(
                'Username' => $this->config->user,
                'Secret' => $this->config->password,
                'Events' => $this->event_types
            ), array($this, 'success_login'));
        return true;
    }

    /**
     * Send packet
     * @param string $action
     * @param array $parameters
     * @param array $callback
     * @return bool
     */
    function send_request($action, $parameters=array(), $callback)
    {
        if ($this->command_callback) {
            $this->log("Commands overlap", 'ERROR');
            return false;
        }
        $this->command_callback = $callback;

        $req = "Action: $action".self::CRLF;
        foreach($parameters as $var=>$val)
            $req .= "$var: $val".self::CRLF;
        $req .= self::CRLF;

        socket_write($this->socket, $req);
        if (defined('ASTERISK_MANAGER_TRACE'))
            $this->log("send_request:\n".$req, 'DEBUG');
        return true;
    }

    /**
     * Convert string buffer to array
     * @param $string
     */
    protected function parse_packet($string) {
        if (defined('ASTERISK_MANAGER_TRACE'))
            $this->log("parse_packet:\n".$string, 'DEBUG');
        $arr = explode(self::CRLF, $string);
        $packet = array();
        foreach ($arr as $line) {
            list($key,$val) = explode(': ', $line);
            if ($key)
                $packet[ucfirst(strtolower($key))] = $val;
        }
        $this->process_packet($packet);
    }

    /**
     * Detect type of packet and call callback
     * @param $arr
     * @return mixed
     */
    protected function process_packet($arr) {
        if ($arr['Response'] && $this->command_callback) {
            $cc = $this->command_callback;
            $this->command_callback = null;
            call_user_func($cc, $arr);
            return;
        }
        if ($arr['Event']) {
            $this->fireEvent('Event', $arr);
            return;
        }
    }


    function log($message, $level = 'INFO') {
        $this->logger->log(get_class().': '.$message, $level);
    }

    /* Responses */
    protected function success_login() {
        $this->logged_in = 1;
        $this->log("Logged in", 'DEBUG');
    }

    public function addObserver(ObserverInterface $observer, $eventType, $priority = 0)
    {
        if ($priority)
            $this->observers[$eventType][$priority] = $observer;
        else
            $this->observers[$eventType][$this->count++] = $observer;

        krsort($this->observers[$eventType], SORT_NUMERIC);
    }

    public function fireEvent($eventType, &$params=null)
    {
        /** @var ObserverInterface $observer */
        if (is_array($this->observers[$eventType])) {
            foreach($this->observers[$eventType] as $observer) {
                $observer->notify($this, $eventType, $params);
            }
        }
    }

    public function notify(ObservableInterface $source, $eventType, &$params = null)
    {
        if ($source instanceof PosixEvent && $eventType == 'SIGHUP') {
            $this->connect(true);
        }
    }
}
