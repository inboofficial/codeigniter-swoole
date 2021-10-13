<?php namespace inboir\CodeigniterS\event\eventDispatcher;


use Closure;
use Co\WaitGroup;
use Exception;
use inboir\CodeigniterS\Core\Client;
use inboir\CodeigniterS\Core\Server as InboServer;
use inboir\CodeigniterS\event\EventCarrier;
use inboir\CodeigniterS\event\EventStatus;
use Swoole\Coroutine;
use Swoole\Server;
use Symfony\Component\EventDispatcher\Debug\WrappedListener;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;
use function count;
use function is_array;
use function is_string;


/**
 * @author M Ali Nasiri K <mohammad.ank@outlook.com>
 */
class AsyncEventDispatcher
{

    protected array $listeners = [];
    protected array $sorted = [];
    protected array $optimized = [];
    protected array $coroutineCallable = [];

    protected bool $eagerOptimizer;
    protected bool $coroutineSupport;

    protected ?EventRepository $eventRepository;
    protected ?EventExceptionRepository $eventExceptionRepository;
    protected ?Server $swooleServer;

    public function __construct( ?EventRepository $eventRepository, ?EventExceptionRepository $eventExceptionRepository, ?Server &$swooleServer = null,
                                 bool $eagerOptimizer = true, bool $coroutineSupport = false)
    {
        $this->eagerOptimizer = $eagerOptimizer;
        $this->coroutineSupport = $coroutineSupport;
        $this->eventRepository = $eventRepository;
        $this->eventExceptionRepository = $eventExceptionRepository;
        $this->swooleServer =& $swooleServer;
    }


    public function dispatch(EventCarrier $eventCarrier): EventCarrier
    {
        if($this->eventRepository != null) {
            $eventCarrier->eventStatus = EventStatus::PULLED;
            $eventCarrier = $this->eventRepository->saveEventOnNotExist($eventCarrier);
            if(!$eventCarrier) return $eventCarrier;
        }
        if($eventCarrier->eventSchedule && $eventCarrier->eventSchedule > time()){
            $this->scheduleEvent($eventCarrier);
        }else
            $this->callListeners($eventCarrier);
        return $eventCarrier;
    }

    /**
     * @param $eventCarrier EventCarrier
     * @throws Exception
     */
    protected function scheduleEvent(EventCarrier $eventCarrier)
    {
        $scheduleInterval = time() - $eventCarrier->eventSchedule;
        if($scheduleInterval < 1) {
            $this->callListeners($eventCarrier);
            return;
        }
        if(($this->swooleServer) == null) {
            if($this->eventRepository != null) {
                $eventCarrier->eventStatus = EventStatus::BLOCKED;
                $this->eventRepository->updateEvent($eventCarrier);
            }
            throw new Exception('scheduled event dispatching must be in swoole server context');
        }
        $timerStart = $scheduleInterval >= InboServer::TIMER_LIMIT ? InboServer::TIMER_LIMIT : $scheduleInterval * 1000;
        $this->swooleServer->after($timerStart, function() use ($eventCarrier)
            {
                self::scheduleEvent($eventCarrier);
            });
    }

    /**
     * @param EventCarrier $eventCarrier
     * @param callable $callback
     * @return string EventId
     */
    public function asyncDispatch(EventCarrier $eventCarrier, callable $callback): string
    {
        Client::send([
            'event' => $eventCarrier,
            'callback' => $callback
        ]);
        return $eventCarrier;
    }


    public function getListeners(string $eventRout = null): array
    {
        return $this->optimized[$eventRout];
    }


    public function getListenerPriority(string $eventRout, $listener)
    {
        if (empty($this->listeners[$eventRout])) {
            return null;
        }

        if (is_array($listener) && isset($listener[0]) && $listener[0] instanceof Closure && 2 >= count($listener)) {
            $listener[0] = $listener[0]();
            $listener[1] = $listener[1] ?? '__invoke';
        }

        foreach ($this->listeners[$eventRout] as $priority => &$listeners) {
            foreach ($listeners as &$v) {
                if ($v !== $listener && is_array($v) && isset($v[0]) && $v[0] instanceof Closure && 2 >= count($v)) {
                    $v[0] = $v[0]();
                    $v[1] = $v[1] ?? '__invoke';
                }
                if ($v === $listener) {
                    return $priority;
                }
            }
        }

        return null;
    }

    public function hasListeners(string $eventRout = null): bool
    {
        if (null !== $eventRout) {
            return !empty($this->listeners[$eventRout]);
        }

        foreach ($this->listeners as $eventListeners) {
            if ($eventListeners) {
                return true;
            }
        }

        return false;
    }


    public function addListener(string $eventRout, $listener, int $priority = 0)
    {
        $this->listeners[$eventRout][$priority][] = $listener;
        unset($this->sorted[$eventRout], $this->optimized[$eventRout]);
        if($this->eagerOptimizer) {
            $this->optimizeListeners($eventRout);
        }
    }


    public function removeListener(string $eventRout, $listener)
    {
        if (empty($this->listeners[$eventRout])) {
            return;
        }

        if (is_array($listener) && isset($listener[0]) && $listener[0] instanceof Closure && 2 >= count($listener)) {
            $listener[0] = $listener[0]();
            $listener[1] = $listener[1] ?? '__invoke';
        }

        foreach ($this->listeners[$eventRout] as $priority => &$listeners) {
            foreach ($listeners as $k => &$v) {
                if ($v !== $listener && is_array($v) && isset($v[0]) && $v[0] instanceof Closure && 2 >= count($v)) {
                    $v[0] = $v[0]();
                    $v[1] = $v[1] ?? '__invoke';
                }
                if ($v === $listener) {
                    unset($listeners[$k], $this->sorted[$eventRout], $this->optimized[$eventRout]);
                }
            }

            if (!$listeners) {
                unset($this->listeners[$eventRout][$priority]);
            }
        }
        if($this->eagerOptimizer) {
            $this->optimizeListeners($eventRout);
        }
    }


    public function addSubscriber(EventSubscriberInterface $subscriber)
    {
        foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
            if (is_string($params)) {
                $this->addListener($eventName, [$subscriber, $params]);
            } elseif (is_string($params[0])) {
                $this->addListener($eventName, [$subscriber, $params[0]], isset($params[1]) ? $params[1] : 0);
            } else {
                foreach ($params as $listener) {
                    $this->addListener($eventName, [$subscriber, $listener[0]], isset($listener[1]) ? $listener[1] : 0);
                }
            }
            if($this->eagerOptimizer) {
                $this->optimizeListeners($eventName);
            }
        }
    }

    /**
     * @param EventSubscriberInterface $subscriber
     */
    public function removeSubscriber(EventSubscriberInterface $subscriber)
    {
        foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
            if (is_array($params) && is_array($params[0])) {
                foreach ($params as $listener) {
                    $this->removeListener($eventName, [$subscriber, $listener[0]]);
                }
            } else {
                $this->removeListener($eventName, [$subscriber, is_string($params) ? $params : $params[0]]);
            }
            if($this->eagerOptimizer) {
                $this->optimizeListeners($eventName);
            }
        }
    }

    /**
     * Triggers the listeners of an event.
     *
     * This method can be overridden to add functionality that is executed
     * for each listener.
     *
     * @param EventCarrier $eventCarrier
     */
    protected function callListeners(EventCarrier $eventCarrier)
    {
        $event = $eventCarrier->event;
        $eventRout = $event->getEventRout();
        if($this->coroutineSupport) {
            $waitGroup = new WaitGroup();
            foreach ($this->coroutineCallable[$eventRout] as $wrappedListener){
                Coroutine::create($wrappedListener, $eventCarrier, $waitGroup);
            }
            $waitGroup->wait();
        }
        else {
            if(empty($this->optimized[$eventRout]))
                $this->optimizeListeners($eventRout);
            foreach ($this->optimized[$eventRout] as $listener) {
                try {
                    $listener($event, $eventRout, $this);
                }catch (Throwable $exception){
                    if($this->eventExceptionRepository != null)
                        $this->eventExceptionRepository->addEventError($eventCarrier->eventID, $exception);
                }
            }
        }
        if($this->eventRepository != null) {
            $eventCarrier->eventStatus = ($this->eventExceptionRepository->hasError($eventCarrier->eventID))? EventStatus::FAILED : EventStatus::FINISHED;
            $this->eventRepository->updateEvent($eventCarrier);
        }
    }


    /**
     * Optimizes the internal list of listeners for the given event by priority.
     * @param string $eventRout
     * @return array
     */
    protected function optimizeListeners(string $eventRout): array
    {
        krsort($this->listeners[$eventRout]);
        $this->optimized[$eventRout] = [];

        foreach ($this->listeners[$eventRout] as &$listeners) {
            foreach ($listeners as &$listener) {
                $closure = &$this->optimized[$eventRout][];
                if (is_array($listener) && isset($listener[0]) && $listener[0] instanceof Closure && 2 >= count($listener)) {
                    $closure = static function (...$args) use (&$listener, &$closure) {
                        if ($listener[0] instanceof Closure) {
                            $listener[0] = $listener[0]();
                            $listener[1] = $listener[1] ?? '__invoke';
                        }
                        ($closure = Closure::fromCallable($listener))(...$args);
                    };
                } else {
                    $closure = $listener instanceof Closure || $listener instanceof WrappedListener ? $listener : Closure::fromCallable($listener);
                }
            }
        }
        if($this->coroutineSupport) {
            $this->coroutineCallable = [];
            foreach ($this->optimized as $eventRout => $listeners) {
                foreach ($listeners as $listener) {
                    $eventDispatcher =& $this;
                    $this->coroutineCallable[$eventRout][] = function (EventCarrier $eventCarrier,WaitGroup $waitGroup) use ($listener, $eventDispatcher) {
                        $waitGroup->add();
                        try {
                            $event =& $eventCarrier->event;
                            $listener($event, $event->getEventRout(), $eventDispatcher);
                        } catch (Throwable $ex) {
                            if($eventDispatcher->eventExceptionRepository != null)
                                $eventDispatcher->eventExceptionRepository->addEventError($eventCarrier->eventID, $ex);
                        } finally {
                            $waitGroup->done();
                        }
                    };
                }
            }
        }
        return $this->optimized[$eventRout];
    }

    public function optimize(){
        foreach ($this->listeners as $eventName => $listeners){
            $this->optimizeListeners($eventName);
        }
    }

    /**
     * @return EventRepository
     */
    public function getEventRepository(): EventRepository
    {
        return $this->eventRepository;
    }

    /**
     * @param EventRepository $eventRepository
     */
    public function setEventRepository(EventRepository $eventRepository): void
    {
        $this->eventRepository = $eventRepository;
    }

    /**
     * @return EventExceptionRepository
     */
    public function getEventExceptionRepository(): EventExceptionRepository
    {
        return $this->eventExceptionRepository;
    }

    /**
     * @param EventExceptionRepository $eventExceptionRepository
     */
    public function setEventExceptionRepository(EventExceptionRepository $eventExceptionRepository): void
    {
        $this->eventExceptionRepository = $eventExceptionRepository;
    }

    /**
     * @return Server|null
     */
    public function getSwooleServer(): ?Server
    {
        return $this->swooleServer;
    }

    /**
     * @param Server|null $swooleServer
     */
    public function setSwooleServer(?Server &$swooleServer): void
    {
        $this->swooleServer =& $swooleServer;
    }

    /**
     * @return bool
     */
    public function isEagerOptimizer(): bool
    {
        return $this->eagerOptimizer;
    }

    /**
     * @param bool $eagerOptimizer
     */
    public function setEagerOptimizer(bool $eagerOptimizer): void
    {
        $this->eagerOptimizer = $eagerOptimizer;
    }

    /**
     * @return bool
     */
    public function isCoroutineSupport(): bool
    {
        return $this->coroutineSupport;
    }

    /**
     * @param bool $coroutineSupport
     */
    public function setCoroutineSupport(bool $coroutineSupport): void
    {
        $this->coroutineSupport = $coroutineSupport;
    }

}
