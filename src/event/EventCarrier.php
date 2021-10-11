<?php


namespace inboir\CodeigniterS\event;


class EventCarrier
{
    public string $eventID;
    public string $eventStatus;
    public int $eventRetryCount = 0;
    public Event $event;
    public int $eventCreated;
    public int $eventSchedule;

    public function __construct(Event $event, ?int $eventSchedule = null)
    {
        $this->eventCreated = time();
        $this->eventSchedule = $eventSchedule ?? time();
        $this->event = $event;
    }
}

