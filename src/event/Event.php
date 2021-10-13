<?php


namespace inboir\CodeigniterS\event;


class Event
{
    protected const EVENT_ROUT = 'rout.event';
    public function getEventRout():string
    {
        return self::EVENT_ROUT;
    }
}