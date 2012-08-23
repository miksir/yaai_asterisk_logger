<?php
/**
 * Interface for work with Asterisk's calls trackinig system
 * We need it for track current calls of Astersik
 */
interface AsteriskCallInterface
{
    /**
     * @abstract
     * @param array $attributes
     * @return bool
     */
    public function create_new_record($attributes);

    /**
     * @abstract
     * @param string $uniqid
     * @return bool|array
     */
    public function load_data_by_uniqueid($uniqid);

    /**
     * @abstract
     * @param string $uniqid
     * @param array $attributes
     * @return bool
     */
    public function update_data_by_uniqueid($uniqid, $attributes);

    /**
     * @abstract
     * @param string $uniqid
     * @return bool
     */
    public function delete_data_by_uniqueid($uniqid);

    /**
     * @abstract
     * @param string $extension
     * @return bool|array
     */
    public function get_calls_by_extension($extension);
}
