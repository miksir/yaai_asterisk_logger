<?php
/**
 * Write all output to file
 */
class FileLogger implements LoggerInterface
{
    private $filename;

    public function __construct($filename) {
        $this->filename = $filename;
    }

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

        file_put_contents($this->filename, strftime('%x %X')." [$level] $message\n", FILE_APPEND);
    }
}
