<?php


namespace inboir\CodeigniterS\event;


class Event
{
    protected string $event_rout = 'rout.event';
    public function getEventRout():string
    {
        return $this->event_rout;
    }
}