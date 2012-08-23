<?php

/**
 * Model for find contacts and accounts by phone
 * @TODO interface?
 */
class SugarPhonesDb {
    protected $_log;
    protected $_db;
    protected $_cache;
    protected $cache_expire = 600; // sec

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
     * Prepare array for create related records. 'relation_type', 'relation_id' - special fields used for module Calls as fields 'parent_type' and 'parent_id'.
     * We whant to try to relate call with Account and only if account not found - with Contact. It's because we will link this call with Contact
     * using module's relationships, but no relationships between Calls and Accounts supported in Sugar. But we want to see this call in Accounts history. So,
     * I found only one way to do it - link call to account and put Contact as module relationship.
     * @param $phone
     * @return array keys: 'relation_type', 'relation_id' - for Calls module, 'account', 'contact' - found relations
     */
    public function &findRelationByPhone($phone) {
        if (!is_null($ret = $this->_cache->get('rel_'.$phone))) {
            return $ret;
        }

        $this->log("Looking up phone $phone", 'DEBUG');

        $contact = $this->findContactByPhone($phone);
        if (!$contact || !$contact['a_id']) {
            $account = $this->findAccountByPhone($phone);
        } else {
            $account = array(
                'a_id' => $contact['a_id'],
                'a_assigned' => $contact['a_assigned'],
                'a_name' => $contact['a_name'],
            );
            unset($contact['a_id']);
            unset($contact['a_assigned']);
            unset($contact['a_name']);
        }
        $ret = array();
        if ($account) {
            $ret['relation_type'] = 'Accounts';
            $ret['relation_id'] = $account['a_id'];
            $ret['assigned_to'] = $account['a_assigned'];
        } elseif ($contact) {
            $ret['relation_type'] = 'Contacts';
            $ret['relation_id'] = $contact['c_id'];
            $ret['assigned_to']= $contact['c_assigned'] ? $contact['c_assigned'] : $account['a_assigned'];
        } else {
            $ret['relation_type'] = null;
            $ret['relation_id'] = null;
            $ret['assigned_to'] = null;
        }
        $ret['account'] = $account;
        $ret['contact'] = $contact;

        $this->_cache->set('rel_'.$phone, $ret, $this->cache_expire);
        return $ret;
    }

    public function findContactByPhone($phone) {
        $result = $this->findContactsByPhone($phone);
        return !empty($result) ? $result[0] : false;
    }

    public function findAccountByPhone($phone) {
        $result = $this->findAccountsByPhone($phone);
        return !empty($result) ? $result[0] : false;
    }

    /**
     * @param $phone
     * @return string
     */
    public function findContactsByPhone($phone) {
        try {
            $sth = $this->_db->prepare("SELECT c.id as c_id, c.assigned_user_id as c_assigned, c.first_name, c.last_name, a.id as a_id, a.assigned_user_id as a_assigned, a.name as a_name ".
                    "FROM contacts c LEFT JOIN accounts_contacts ac ON (c.id = ac.contact_id AND ac.deleted = 0) LEFT JOIN accounts a ON (ac.account_id = a.id AND a.deleted = 0 ) ".
                    "WHERE c.deleted = 0 AND (phone_home LIKE ? OR phone_mobile LIKE ? OR phone_work LIKE ? OR phone_other LIKE ?)");
            $ph = $phone.'%';
            $sth->execute(array($ph, $ph, $ph, $ph));
            $result = $sth->fetchAll();
        } catch (DbException $e) {
            $this->log($e->getMessage(), 'ERROR');
            return false;
        }
        return empty($result) ? false : $result;
    }

    /**
     * @param $phone
     * @return string
     */
    public function findAccountsByPhone($phone) {
        try {
            $sth = $this->_db->prepare("SELECT a.id as a_id, a.assigned_user_id as a_assigned, a.name as a_name  ".
                    "FROM accounts a ".
                    "WHERE a.deleted = 0 AND (phone_office LIKE ? OR phone_alternate LIKE ?)");
            $ph = $phone.'%';
            $sth->execute(array($ph, $ph));
            $result = $sth->fetchAll();
        } catch (DbException $e) {
            $this->log($e->getMessage(), 'ERROR');
            return false;
        }
        return empty($result) ? false : $result;
    }

    protected function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }
}
