<?php
/**
 * Observable from Observer pattern
 */
interface ObservableInterface
{
    public function addObserver(ObserverInterface $observer, $eventType, $priority = 0);
    public function fireEvent($eventType, &$params=null);
}
