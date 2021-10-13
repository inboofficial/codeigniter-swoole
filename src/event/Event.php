<?php


namespace inboir\CodeigniterS\event;


class Event
{
    protected string $eventRout = 'rout.event';
    protected object $eventData;


    /**
     * @return string
     */
    public function getEventRout(): string
    {
        return $this->eventRout;
    }

    /**
     * @param string $eventRout
     */
    public function setEventRout(string $eventRout): void
    {
        $this->eventRout = $eventRout;
    }

    /**
     * @return object
     */
    public function getEventData(): object
    {
        return $this->eventData;
    }

    /**
     * @param object $eventData
     */
    public function setEventData(object $eventData): void
    {
        $this->eventData = $eventData;
    }

}