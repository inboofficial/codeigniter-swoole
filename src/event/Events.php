<?php namespace inboir\CodeigniterS\event;


use Exception;
use inboir\CodeigniterS\Core\Server;
use inboir\CodeigniterS\event\eventDispatcher\AsyncEventDispatcher;
use inboir\CodeigniterS\event\eventDispatcher\EventExceptionRepository;
use inboir\CodeigniterS\event\eventDispatcher\EventRepository;
use inboir\CodeigniterS\event\Subscriber\CISyncSubscriber;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * @author      M Ali Nasiri K <mohammad.ank@outlook.com>
 */
class Events {


    public static ?AsyncEventDispatcher $dispatcher = null;
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

    /**
     * @param string $eventRout
     * @param callable[] $callback
     * @param int $priority
     */
    public static function register(string $eventRout, array $callback, int $priority = 0)
    {
        self::$dispatcher->addListener($eventRout, $callback, $priority);
    }

    /**
     * @param string $subscriberClass
     * @param bool $force
     * @param bool $clean
     */
    public static function registerAll($subscriberClass = CISyncSubscriber::class, bool $force = false, bool $clean = false)
    {
        if(!self::$listenerRegistered or $force) {
            if($clean) self::$dispatcher->removeAllListeners();
            $listeners = array_filter(get_declared_classes(), fn($class) => is_subclass_of($class, $subscriberClass));
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
     * @param callable|null $callback
     * @return EventCarrier
     * @throws Exception
     */
    public static function trigger(EventCarrier $eventCarrier, ?callable $callback = null): EventCarrier
    {
        self::$dispatcher->asyncDispatch($eventCarrier, $callback);
        if(self::$dispatcher->hasListeners($eventCarrier->event->getEventRout())) {
            return self::$dispatcher->dispatch($eventCarrier);
        }
        else {
            return $eventCarrier;
        }
    }

    /**
     * @param EventCarrier $eventCarrier
     * @param callable|null $callback
     */
    public static function asyncTrigger(EventCarrier $eventCarrier, ?callable $callback = null)
    {
        self::$dispatcher->asyncDispatch($eventCarrier, $callback);
    }

    /**
     * @param EventCarrier $eventCarrier
     * @return EventCarrier
     * @throws Exception
     */
    public static function syncTrigger(EventCarrier $eventCarrier): EventCarrier
    {
        if(self::$dispatcher->hasListeners($eventCarrier->event->getEventRout())) {
            return self::$dispatcher->dispatch($eventCarrier);
        }
        else {
            return $eventCarrier;
        }
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