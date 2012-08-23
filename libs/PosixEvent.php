<?php
/**
 * POSIX event injector
 * For run this class we need to add pcntl_signal_dispatch() to main program loop
 */
class PosixEvent implements ObservableInterface,ObserverInterface
{
    protected $_log;
    protected $observers = array();
    protected $count = 1;

    public function __construct(LoggerInterface $log) {
        $this->_log = $log;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGHUP, array($this, 'posix_hup'));
            pcntl_signal(SIGALRM, array($this, 'posix_alrm'));
        }
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
                $observer->notify($this, $eventType);
            }
        }
    }

    public function posix_alrm() {
        $this->fireEvent('SIGALRM');
    }

    public function posix_hup() {
        $this->log("HUP received, reloading...");
        $this->fireEvent('SIGHUP');
    }

    protected function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }

    public function notify(ObservableInterface $source, $eventType, &$params = null)
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}
