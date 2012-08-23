<?php
/**
 * Interface for cache
 */
interface CacheInterface
{
    /**
     * @abstract
     * @param string $key
     * @param mixed $value
     * @param int $expire
     * @return bool
     */
    public function set($key, &$value, $expire = 0);

    /**
     * @abstract
     * @param string $key
     * @return mixed|null
     */
    public function &get($key);

    /**
     * @abstract
     * @param string $key
     * @return bool
     */
    public function delete($key);
}
