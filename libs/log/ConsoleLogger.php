<?php
/**
 * Write all output to STDOUT
 */
class ConsoleLogger implements LoggerInterface
{
    /**
     * @param string $message
     * @param string $level 'INFO' 'DEBUG' 'ERROR'
     *
     * @return void
     */
    public function log($message, $level = 'INFO')
    {
        if (!defined('ASTERISK_DEBUG') && $level == 'DEBUG')
            return;

        print strftime('%x %X') . ' ['.$level.'] '.$message."\n";
    }
}
