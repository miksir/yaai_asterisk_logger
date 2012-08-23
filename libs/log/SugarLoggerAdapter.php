<?php
/**
 * Interface between LoggerInterface and Sugar's log system
 */
class SugarLoggerAdapter implements LoggerInterface
{

    /**
     * @param string $message
     * @param string $level 'INFO' 'DEBUG' 'ERROR'
     *
     * @return void
     */
    public function log($message, $level = 'INFO')
    {
        if ($level == 'DEBUG')
            $GLOBALS['log']->debug($message);
        elseif ($level == 'INFO')
            $GLOBALS['log']->info($message);
        elseif ($level == 'ERROR')
            $GLOBALS['log']->error($message);
    }
}
