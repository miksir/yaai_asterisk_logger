<?php
/**
 * PDO adapter with keep-alive and auto-reconnect
 * On SIGHUP if reconnect fail - keep old connection on
 */
class DbPDOAdapter implements DbAdapterInterface,ObserverInterface
{
    /** @var PDO */
    protected $_db;
    protected $_log;

    protected $config;
    protected $options = array(
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES \'UTF8\''
    );

    protected $ping_time = 600;

    /**
     * @param LoggerInterface $log
     * @param SugarDbData $config
     * @param PosixEvent $posix
     */
    public function __construct(LoggerInterface $log, SugarDbData $config, PosixEvent $posix) {
        $this->_log = $log;

        $this->config = $config;

        $this->create_pdo_object();
        $this->setup_keepalive();

        $posix->addObserver($this, 'SIGHUP');
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    protected function call_pdo($method, $args) {
        $this->setup_keepalive();
        return PDOMethodCaller::call_pdo($this, null, $this->_db, $method, $args);
    }

    /**
     * @param bool $reconfig
     * @throws DbException
     * @return PDO
     */
    public function create_pdo_object($reconfig = false) {
        try {
            $pdo = new PDO(
                $this->config->dsn,
                $this->config->user,
                $this->config->password,
                $this->options
            );

            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->log("MySQL connected {$this->config->dsn}", 'DEBUG');

        } catch (PDOException $e) {
            $this->log($e->getCode() . ' ' . $e->getMessage(), 'ERROR');

            if (!$reconfig) {
                throw new DbException($e->getMessage());
            } else {
                $this->log("Reconfig fail, keep old connection", 'DEBUG');
                return $this->_db;
            }
        }

        $this->config->clearChanged();
        return $this->_db = $pdo;
    }

    /**
     * @param $sql
     * @throws DbException
     * @return DbPDOStatementAdapter
     */
    public function prepare($sql)
    {
        return new DbPDOStatementAdapter($this->call_pdo('prepare', array($sql)), $this);
    }

    /**
     * @throws DbException
     * @return array
     */
    public function errorInfo()
    {
        return $this->call_pdo('errorInfo', array());
    }

    public function ping() {
        $this->call_pdo("query", array("SELECT 1"));
        $this->log("Ping? Pong!", 'DEBUG');
    }

    /**
     * @param string $message
     * @param string $level
     */
    public function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }

    /**
     * prepare for call alarm signal after timeout
     */
    protected function setup_keepalive() {
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm($this->ping_time);
        }
    }

    public function notify(ObservableInterface $source, $eventType, &$params = null)
    {
        if ($source instanceof PosixEvent) {
            if ($eventType == 'SIGALRM')
                $this->ping();
            elseif ($eventType == 'SIGHUP') {
                if ($this->config->isChanged())
                    $this->create_pdo_object(true);
            }
        }
    }
}


class DbPDOStatementAdapter implements DbStatementAdapterInterface {
    protected $_st;
    protected $_dba;

    /**
     * @param PDOStatement $st
     * @param DbPDOAdapter $db
     */
    public function __construct(PDOStatement $st, DbPDOAdapter $db) {
        $this->_st = $st;
        $this->_dba = $db;
    }

    /**
     * @param PDOStatement $st
     */
    public function setPDOStatement(PDOStatement $st) {
        $this->_st = $st;
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    protected function call_pdo_st($method, $args) {
        return PDOMethodCaller::call_pdo($this->_dba, $this, $this->_st, $method, $args);
    }

    /**
     * @param array $params
     * @throws DbException
     * @return bool
     */
    public function execute($params = null)
    {
        return $this->call_pdo_st('execute', array($params));
    }

    /**
     * @throws DbException
     * @return array
     */
    public function errorInfo()
    {
        return $this->call_pdo_st('errorInfo', array());
    }

    /**
     * @throws DbException
     * @return mixed
     */
    public function fetch()
    {
        return $this->call_pdo_st('fetch', array(PDO::FETCH_ASSOC));
    }

    /**
     * @throws DbException
     * @return mixed
     */
    public function fetchAll()
    {
        return $this->call_pdo_st('fetchAll', array(PDO::FETCH_ASSOC));
    }
}

class PDOMethodCaller {
    /**
     * @static
     *
     * @param DbPDOAdapter                $adapter_object
     * @param DbPDOStatementAdapter|null  $statement_object
     * @param PDO|PDOStatement                 $callable_object
     * @param string                           $name
     * @param array                            $arguments
     *
     * @throws DbException
     * @return mixed
     */
    static public function call_pdo($adapter_object, $statement_object, $callable_object, $name, $arguments)
    {
        try {

            return call_user_func_array(array($callable_object, $name), $arguments);

        } catch (PDOException $e) {
            if ($e->getCode() != 'HY000') {
                $adapter_object->log($e->getCode() . ' ' . $e->getMessage(), 'ERROR');
                throw new DbException($e->getMessage());
            }

            $adapter_object->log($e->getCode() . ' ' . $e->getMessage() . '. Reconnecting...', 'ERROR');
            // lets try to reconnect

            try {

                $newpdo = $adapter_object->create_pdo_object();

            } catch (DbException $f) {
                $adapter_object->log($f->getMessage(), 'ERROR');
                throw new DbException($e->getMessage());
            }

            try {

                if ($callable_object instanceof PDOStatement) {
                    $callable_object = $newpdo->prepare($callable_object->queryString);
                    $statement_object->setPDOStatement($callable_object);
                } else {
                    $callable_object = $newpdo;
                }

                return call_user_func_array(array($callable_object, $name), $arguments);

            } catch (PDOException $f) {
                $adapter_object->log($f->getCode() . ' ' . $f->getMessage(), 'ERROR');
                throw new DbException($f->getMessage());
            }

        }
    }
}