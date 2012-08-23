<?php
/**
 * Interface for work with database
 */
interface DbAdapterInterface
{
    /**
     * @abstract
     * @param $sql
     * @return DbStatementAdapterInterface|bool
     */
    public function prepare($sql);

    /**
     * @abstract
     * @return array (0 - SQLSTATE error, 1 - Driver error, 2 - Driver message)
     */
    public function errorInfo();
}

/**
 * Interface for database statement object
 */
interface DbStatementAdapterInterface
{
    /**
     * @abstract
     * @param array $params
     * @return bool
     */
    public function execute($params=array());

    /**
     * @abstract
     * @return array
     */
    public function errorInfo();

    /**
     * @abstract
     * @return mixed
     */
    public function fetch();

    /**
     * @abstract
     * @return mixed
     */
    public function fetchAll();
}

/**
 * Exceptions
 */
class DbException extends Exception {


}
