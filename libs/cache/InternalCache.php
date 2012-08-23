<?php
/**
 * Implements caching system in PHP vars. This can be good only if we use one process and do not need
 * cached data outside this process.
 */
class InternalCache implements CacheInterface
{
    protected $storage = array();
    protected $storage_atime = array(); // access times
    protected $storage_etime = array(); // expire times
    protected $storage_size = 100;
    protected $cleanup_delta = 10;   // seconds, drop all records with atime between oldest atime and oldest atime + cleanup_delta
    protected $default_lifetime = 0; // seconds, if 0 - do not expire records
    protected $record_not_found = null;

    /**
     * @param string $key
     * @param mixed $value
     * @param int $expire
     * @return bool
     */
    public function set($key, &$value, $expire = 0) {
        $time = microtime(true);
        if (count($this->storage) >= $this->storage_size) {
            if (!$this->drop_expired())
                if (!$this->drop_oldest())
                    return false; // no space
        }
        $this->storage[$key] =& $value;
        $this->storage_atime[$key] = $time;

        if (!$expire) $expire = $this->default_lifetime;
        if ($expire) $this->storage_etime[$key] = $time + $expire;
        return true;
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    public function &get($key) {
        $time = microtime(true);
        if (isset($this->storage[$key])) {
            // we found key
            if (isset($this->storage_etime[$key]) && $this->storage_etime[$key] < $time) {
                // Our record expired
                $this->delete($key);
            } else {
                // Update access time and return value
                $this->storage_atime[$key] = $time;
                return $this->storage[$key];
            }
        }
        return $this->record_not_found;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete($key) {
        unset($this->storage[$key]);
        unset($this->storage_atime[$key]);
        if (isset($this->storage_etime[$key])) unset($this->storage_etime[$key]);
        return true;
    }

    /**
     * Find oldest accessed record and remove records accessed between found time and found time plus $this->cleanup_delta
     * Set $this->cleanup_delta to 0 for remove only one oldest record (or few if they created at same time)
     * @return bool
     */
    protected function drop_oldest() {
        $min_time = min($this->storage_atime);
        $was = false;
        foreach($this->storage_atime as $key=>$val_time) {
            if ($val_time <= ($min_time+$this->cleanup_delta)) {
                $this->delete($key);
                $was = true;
            }
        }
        return $was;
    }

    /**
     * @return bool
     */
    protected function drop_expired() {
        $time = microtime(true);
        $was = false;
        foreach($this->storage_etime as $key=>$e_time) {
            if ($e_time < $time) {
                $this->delete($key);
                $was = true;
            }
        }
        return $was;
    }
}
