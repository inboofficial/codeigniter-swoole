<?php namespace inboir\CodeigniterS\event;


use Exception;
use inboir\CodeigniterS\Core\Server;
use inboir\CodeigniterS\event\eventDispatcher\AsyncEventDispatcher;
use inboir\CodeigniterS\event\eventDispatcher\EventExceptionRepository;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * @author      M Ali Nasiri K <mohammad.ank@outlook.com>
 */
class Events {


    public static AsyncEventDispatcher $dispatcher ;
    protected static bool $listenerRegistered = false;
    // ------------------------------------------------------------------------

    private function __construct()
    {
    }

    /**
     * Register
     *
     * Registers a Callback for a given event
     *
     * @param EventRepository|null $eventRepository
     * @param EventExceptionRepository|null $eventExceptionRepository
     * @param bool $eagerOptimizer
     * @param \Swoole\Server|null $server
     * @throws Exception
     */
    public static function initialize(?EventRepository $eventRepository = null, ?EventExceptionRepository $eventExceptionRepository = null, bool $eagerOptimizer = true, ?\Swoole\Server &$server = null)
    {
        if(self::$dispatcher == null) {
            self::$dispatcher = new AsyncEventDispatcher($eventRepository, $eventExceptionRepository, $server,
                $eagerOptimizer, Server::getConfig()['task_enable_coroutine']);
        }else{
            if($server) Events::$dispatcher->setSwooleServer($server);
            if($eventExceptionRepository) Events::$dispatcher->setEventExceptionRepository($eventExceptionRepository);
            if($eventRepository) Events::$dispatcher->setEventRepository($eventRepository);
            self::$dispatcher->setCoroutineSupport(Server::getConfig()['task_enable_coroutine']);
        }
    }


    public static function register($eventRout, array $callback, $priority = 0)
    {
        self::$dispatcher->addListener($eventRout, $callback, $priority);
    }

    public static function registerAll(bool $force = false)
    {
        if(!self::$listenerRegistered or $force) {
            $listeners = array_filter(get_declared_classes(), fn($class) => is_subclass_of($class, CISubscriber::class));
            foreach ($listeners as $listener) {
                $listener::getInstance();
            }
        }
        self::$listenerRegistered = true;
    }

    // ------------------------------------------------------------------------

    /**
     * Trigger
     *
     *
     * @access    public
     * @param EventCarrier $eventCarrier
     * @return EventCarrier
     */
    public static function trigger(EventCarrier $eventCarrier): EventCarrier
    {
        if(self::$dispatcher->hasListeners($eventCarrier->event->getEventRout()))
            return self::$dispatcher->serverDispatch($eventCarrier);
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