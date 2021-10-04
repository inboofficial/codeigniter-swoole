<?php namespace inboir\CodeigniterS\event;

use inboir\CodeigniterS\Core\Client;
use Symfony\Component\EventDispatcher\EventDispatcher;

class QueueableEventDispatcher extends EventDispatcher
{

    public function dispatch(object $event, string $eventName = null, bool $queued = false, string $type = 'general'): object
    {
        if(!$queued) parent::dispatch($event, $eventName);
        else{
            Client::send([
                'eventRout' => $eventName,
                'eventData' => $event
            ]);
        }
        return $event;
    }

    public function scheduleEvent(object $event, string $eventName, $schedule)
    {
        $this->enqueueEvent($event, $eventName, 'scheduled', $schedule);
    }

    protected function enqueueEvent($event, $event_name, string $type = 'general', $schedule = null)
    {

    }

}