<?php
class DbSugarAdapter implements DbAdapterInterface
{
    /** @var DBManager */
    protected $_db;

    public function __construct() {
        // Sugar database connector
        $this->_db = DBManagerFactory::getInstance();
    }

    /**
     * @param $sql
     * @return DbStatementAdapterInterface|bool
     */
    public function prepare($sql)
    {
        $res = $this->_db->prepareQuery($sql);
        return new DbSugarStatementAdapter($res, $this, $this->_db);
    }

    /**
     * @return array
     */
    public function errorInfo()
    {
        return array();
    }

}

class DbSugarStatementAdapter implements DbStatementAdapterInterface {
    protected $st;
    protected $caller;
    /** @var DBManager */
    protected $_db;
    protected $result;

    public function __construct($st, $caller, $db) {
        $this->st = $st;
        $this->caller = $caller;
        $this->_db = $db;
    }

    /**
     * @param array $params
     *
     * @throws DbException
     * @return bool
     */
    public function execute($params = array())
    {
        $this->result = $this->_db->executePreparedQuery($this->result, $params);
        if ($error = $this->_db->lastError()) {
            throw new DbException($error);
        }
        return $this->result;
    }

    /**
     * @return array
     */
    public function errorInfo()
    {
        $this->_db->checkError();
        return array(
            0,
            0,
            $this->_db->lastError()
        );
    }

    /**
     * @return mixed
     */
    public function fetch()
    {
        return $this->_db->fetchByAssoc($this->result, false);
    }

    /**
     * @return mixed
     */
    public function fetchAll()
    {
        $ret = array();
        while ($res = $this->fetch()) {
            $ret[] = $res;
        }
        return $ret;
    }
}