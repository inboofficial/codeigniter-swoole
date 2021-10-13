<?php


namespace inboir\CodeigniterS\event;


class Event
{
    protected string $eventRout = 'rout.event';
    protected object $eventData;

    /**
     * Event constructor.
     * @param string $eventRout
     * @param object $eventData
     */
    public function __construct(string $eventRout, object $eventData)
    {
        $this->eventRout = $eventRout;
        $this->eventData = $eventData;
    }


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