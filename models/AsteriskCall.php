<?php
/**
 * Model for track Asterisk calls
 */
class AsteriskCall implements AsteriskCallInterface {
    const TABLE_NAME = '`asterisk_log`';
    const SEQUENCE = NULL;
    protected $_db;
    protected $_log;
    protected $transaction = 0;

    public function __construct(DbAdapterInterface $db, LoggerInterface $log) {
        $this->_db = $db;
        $this->_log = $log;

    }

    /**
     * @param array $attributes
     * @return bool
     */
    public function create_new_record($attributes) {
        $keys = array_keys($attributes);

        try {
            $sth = $this->_db->prepare('INSERT INTO '.self::TABLE_NAME.'('.implode(',', $keys).') VALUES ('.str_repeat('?,', count($keys)-1).'?)');
            $sth->execute(array_values($attributes));
        } catch (DbException $e) {
            $this->log($e->getMessage(), 'ERROR');
            return false;
        }
        $this->log("New record created ID:{$attributes['asterisk_id']}", 'DEBUG');
        return true;
    }

    /**
     * @param string $uniqid
     * @param bool $lock
     * @return bool|array
     */
    public function load_data_by_uniqueid($uniqid, $lock=false) {
        try {
            $sth = $this->_db->prepare('SELECT * FROM '.self::TABLE_NAME.' WHERE asterisk_id = ?');
            return $this->_execute_and_fetch($sth, array($uniqid));
        } catch (DbException $e) {
            $this->log($e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * @param DbStatementAdapterInterface $sth
     * @param array|null $exec_params
     * @return bool|array
     */
    protected function _execute_and_fetch(DbStatementAdapterInterface $sth, $exec_params = array()) {
        $sth->execute($exec_params);
        $res = $sth->fetch(PDO::FETCH_ASSOC);
        return $res;
    }

    /**
     * @param int $id
     * @param array $attributes
     * @return bool
     */
    public function update_data_by_uniqueid($id, $attributes) {

        $set = '';
        foreach($attributes as $key=>$val) {
            $set .= ($set ? ',' : '') . $key . '= ?';
        }

        try {
            $sth = $this->_db->prepare('UPDATE '.self::TABLE_NAME.' SET '.$set.' WHERE id = ?');
            $params = array_values($attributes);
            $params[] = $id;
            $res = $sth->execute($params);
        } catch (DbException $e) {
            $this->log($e->getMessage(), 'ERROR');
            return false;
        }

        if ($res)
            $this->log("Updated record ID:{$id}", 'DEBUG');

        return true;
    }

    /**
     * @param string $id
     * @return bool
     */
    public function delete_data_by_uniqueid($id) {
        try {
            $sth = $this->_db->prepare('DELETE FROM '.self::TABLE_NAME.' WHERE asterisk_id = ?');
            $res = $sth->execute(array($id));
        } catch (DbException $e) {
            $this->log($e->getMessage(), 'ERROR');
            return false;
        }

        if ($res)
            $this->log("Drop record ID:{$id}", 'DEBUG');

        return true;
    }

    protected function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }

    /**
     * @param string $extension
     * @return bool|array
     */
    public function get_calls_by_extension($extension)
    {
        try {
            $sth = $this->_db->prepare("SELECT * FROM ".self::TABLE_NAME." WHERE extension= ? AND uistate != ?");
            $sth->execute(array($extension, 'Closed'));
            return $sth->fetchAll();
        } catch (DbException $e) {
            $this->log($e->getMessage(), 'ERROR');
            return false;
        }
    }
}
