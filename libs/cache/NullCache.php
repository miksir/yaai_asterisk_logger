<?php
/**
 * If We don't wanna use any cache
 */
class NullCache implements CacheInterface
{
    protected $record_not_found = null;

    public function set($key, &$value, $expire = 0)
    {
        return false;
    }

    public function &get($key)
    {
        return $this->record_not_found;
    }

    public function delete($key)
    {
        return false;
    }
}
