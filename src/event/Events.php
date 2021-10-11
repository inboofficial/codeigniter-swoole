<?php namespace inboir\CodeigniterS\event;


use inboir\CodeigniterS\Core\Server;
use inboir\CodeigniterS\event\eventDispatcher\AsyncEventDispatcher;
use inboir\CodeigniterS\event\eventDispatcher\EventExceptionRepository;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * @author      M Ali Nasiri K <mohammad.ank@outlook.com>
 */
class Events {


    public static AsyncEventDispatcher $dispatcher ;
    // ------------------------------------------------------------------------

    /**
     * Register
     *
     * Registers a Callback for a given event
     *
     * @param EventRepository $eventRepository
     * @param EventExceptionRepository $eventExceptionRepository
     * @param bool $eagerOptimizer
     */
    public function __construct(EventRepository $eventRepository,EventExceptionRepository $eventExceptionRepository, bool $eagerOptimizer = true)
    {
        self::$dispatcher = new AsyncEventDispatcher($eventRepository, $eventExceptionRepository,
            $eagerOptimizer ,Server::getConfig()['task_enable_coroutine']);
    }


    public static function register($eventRout, array $callback, $priority = 0)
    {
        self::$dispatcher->addListener($eventRout, $callback, $priority);
    }

    // ------------------------------------------------------------------------

    /**
     * Trigger
     *
     *
     * @access    public
     * @param mixed    Any data that is to be passed to the listener
     * @return EventCarrier
     */
    public static function trigger(EventCarrier $eventCarrier): EventCarrier
    {
        if(self::$dispatcher->hasListeners($eventCarrier->event->getEventRout()))
            return self::$dispatcher->dispatch($eventCarrier);
        else
            return $eventCarrier;
    }

    public static function asyncTrigger(EventCarrier $eventCarrier, callable $callback)
    {
        self::$dispatcher->asyncDispatch($eventCarrier, $callback);
    }

    // ------------------------------------------------------------------------

    /**
     * Has Listeners
     *
     * Checks if the event has listeners
     *
     * @access	public
     * @param	string	The name of the event
     * @return	bool	Whether the event has listeners
     */
    public static function has_listeners(string $eventRout): bool
    {
        return self::$dispatcher->hasListeners($eventRout);
    }

    // ------------------------------------------------------------------------

}

/* End of file events.php */