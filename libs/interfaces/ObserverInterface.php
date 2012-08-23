<?php
/**
 * Observer from Observer pattern
 */
interface ObserverInterface
{
    public function notify(ObservableInterface $source, $eventType, &$params=null);
}
