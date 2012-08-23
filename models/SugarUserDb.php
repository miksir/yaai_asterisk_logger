<?php

/**
 * Work with sugar's User
 * @TODO interface?
 */
class SugarUserDb {
    protected $_log;
    protected $_db;
    protected $_cache;

    /**
     * @param DbAdapterInterface $db
     * @param LoggerInterface $log
     * @param CacheInterface $cache
     */
    public function __construct(DbAdapterInterface $db, LoggerInterface $log, CacheInterface $cache) {
        $this->_log = $log;
        $this->_db = $db;
        $this->_cache = $cache;
    }

    /**
     * @param $ext
     * @return bool
     */
    public function findUserByAsteriskExtension($ext) {
        if (!is_null($ret = $this->_cache->get('ext_'.$ext))) {
            return $ret;
        }

        try {
            $sth = $this->_db->prepare("SELECT users.id FROM users LEFT JOIN users_cstm ON users.id=users_cstm.id_c ".
                    "WHERE users_cstm.asterisk_ext_c = ? AND users_cstm.asterisk_inbound_c=1 AND users.status='Active' AND users.deleted=0 LIMIT 1");
            $sth->execute(array($ext));
            $result = $sth->fetch(PDO::FETCH_ASSOC);

            if (empty($result))
                $result = false;
            else
                $result = $result['id'];

        } catch (DbException $e) {
            $this->log($e->getMessage(), 'ERROR');
            return false;
        }

        $this->_cache->set('ext_'.$ext, $result, 0);
        return $result;
    }

    protected function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }
}


