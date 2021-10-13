<?php


namespace inboir\CodeigniterS\event;



class Event
{
    protected string $event_rout = 'rout.event';
    public function getEventRout():string
    {
        return $this->event_rout;
    }
    protected array $eventData;

    /**
     * @return array
     */
    public function getEventData(): array
    {
        return $this->eventData;
    }

    /**
     * @param array $eventData
     */
    public function setEventData(array $eventData): void
    {
        $this->eventData = $eventData;
    }

}