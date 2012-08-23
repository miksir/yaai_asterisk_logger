<?php
/**
 * Loger interface
 */
interface LoggerInterface
{
    /**
     * @abstract
     * @param string $message
     * @param string $level 'INFO' 'DEBUG' 'ERROR'
     * @return void
     */
    public function log($message, $level='INFO');
}
