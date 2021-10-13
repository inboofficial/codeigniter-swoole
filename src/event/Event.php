<?php


namespace inboir\CodeigniterS\event;


class Event
{
    public string $event_rout = 'rout.event';
    public function getEventRout():string
    {
        return $this->event_rout;
    }
}