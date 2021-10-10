<?php


namespace inboir\CodeigniterS\event;


class Event
{
    public string $eventID;
    public string $eventStatus;
    public int $eventRetryCount = 0;
    public ?string $eventRout;
    public object $eventData;
    public int $eventCreated;
    public int $eventSchedule;
    public ?EventException $error = null;

    public function __construct(?int $eventSchedule = null)
    {
        $this->eventCreated = time();
        $this->eventSchedule = $eventSchedule ?? time();
        $this->eventData = (object)[];
    }
}

