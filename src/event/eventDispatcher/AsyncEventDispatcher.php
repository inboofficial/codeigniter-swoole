<?php namespace inboir\CodeigniterS\event\eventDispatcher;


use Co\WaitGroup;
use inboir\CodeigniterS\Core\Client;
use inboir\CodeigniterS\event\Event;
use inboir\CodeigniterS\event\EventRepository;
use inboir\CodeigniterS\event\EventStatus;
use Psr\EventDispatcher\StoppableEventInterface;
use Swoole\Coroutine;
use Symfony\Component\EventDispatcher\Debug\WrappedListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;



/**
 * The EventDispatcherInterface is the central point of Symfony's event listener system.
 *
 * Listeners are registered on the manager and events are dispatched through the
 * manager.
 *
 * @author M Ali Nasiri K <mohammad.ank@outlook.com>
 */
class AsyncEventDispatcher implements EventDispatcherInterface
{

    protected array $listeners = [];
    protected array $sorted = [];
    protected array $optimized = [];
    protected array $coroutineCallable = [];

    protected bool $eagerOptimizer;
    protected bool $coroutineSupport;

    public function __construct(bool $eagerOptimizer = true, bool $coroutineSupport = false)
    {
        $this->eagerOptimizer = $eagerOptimizer;
        $this->coroutineSupport = $coroutineSupport;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(object $event, string $eventName = null): object
    {
        $eventName = $eventName ?? \get_class($event);

        if (null !== $this->optimized) {
            $listeners = $this->optimized[$eventName];
        } else {
            $listeners = $this->getListeners($eventName);
        }

        if ($listeners) {
            $this->callListeners($listeners, $eventName, $event);
        }

        return $event;
    }

    /**
     * @param object $event
     * @param string|null $eventName
     * @param int|null $eventSchedule
     * @param string|null $event_unique
     * @return string EventId
     */
    public function asyncDispatch(object $event, ?string $eventName = null, ?int $eventSchedule = null, ?string $event_unique = null): string
    {
        $eventModel = new Event($eventSchedule);
        if($event_unique != null) $eventModel->eventID = $event_unique;
        $eventModel->eventStatus = EventStatus::WAITING;
        $eventModel->eventData = $event;
        $eventModel->eventRout = $eventName;
        Client::send(['event' => $eventModel]);
        return $eventModel;
    }


    /**
     * {@inheritdoc}
     */
    public function getListeners(string $eventName = null): array
    {
        if (null !== $eventName) {
            if (empty($this->listeners[$eventName])) {
                return [];
            }

            if (!isset($this->sorted[$eventName])) {
                $this->sortListeners($eventName);
            }

            return $this->sorted[$eventName];
        }

        foreach ($this->listeners as $eventName => $eventListeners) {
            if (!isset($this->sorted[$eventName])) {
                $this->sortListeners($eventName);
            }
        }

        return array_filter($this->sorted);
    }

    /**
     * {@inheritdoc}
     */
    public function getListenerPriority(string $eventName, $listener)
    {
        if (empty($this->listeners[$eventName])) {
            return null;
        }

        if (\is_array($listener) && isset($listener[0]) && $listener[0] instanceof \Closure && 2 >= \count($listener)) {
            $listener[0] = $listener[0]();
            $listener[1] = $listener[1] ?? '__invoke';
        }

        foreach ($this->listeners[$eventName] as $priority => &$listeners) {
            foreach ($listeners as &$v) {
                if ($v !== $listener && \is_array($v) && isset($v[0]) && $v[0] instanceof \Closure && 2 >= \count($v)) {
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

    /**
     * {@inheritdoc}
     */
    public function hasListeners(string $eventName = null): bool
    {
        if (null !== $eventName) {
            return !empty($this->listeners[$eventName]);
        }

        foreach ($this->listeners as $eventListeners) {
            if ($eventListeners) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function addListener(string $eventName, $listener, int $priority = 0)
    {
        $this->listeners[$eventName][$priority][] = $listener;
        unset($this->sorted[$eventName], $this->optimized[$eventName]);
        if($this->eagerOptimizer) {
            $this->optimizeListeners($eventName);
            $this->sortListeners($eventName);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeListener(string $eventName, $listener)
    {
        if (empty($this->listeners[$eventName])) {
            return;
        }

        if (\is_array($listener) && isset($listener[0]) && $listener[0] instanceof \Closure && 2 >= \count($listener)) {
            $listener[0] = $listener[0]();
            $listener[1] = $listener[1] ?? '__invoke';
        }

        foreach ($this->listeners[$eventName] as $priority => &$listeners) {
            foreach ($listeners as $k => &$v) {
                if ($v !== $listener && \is_array($v) && isset($v[0]) && $v[0] instanceof \Closure && 2 >= \count($v)) {
                    $v[0] = $v[0]();
                    $v[1] = $v[1] ?? '__invoke';
                }
                if ($v === $listener) {
                    unset($listeners[$k], $this->sorted[$eventName], $this->optimized[$eventName]);
                }
            }

            if (!$listeners) {
                unset($this->listeners[$eventName][$priority]);
            }
        }
        if($this->eagerOptimizer) {
            $this->optimizeListeners($eventName);
            $this->sortListeners($eventName);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addSubscriber(EventSubscriberInterface $subscriber)
    {
        foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
            if (\is_string($params)) {
                $this->addListener($eventName, [$subscriber, $params]);
            } elseif (\is_string($params[0])) {
                $this->addListener($eventName, [$subscriber, $params[0]], isset($params[1]) ? $params[1] : 0);
            } else {
                foreach ($params as $listener) {
                    $this->addListener($eventName, [$subscriber, $listener[0]], isset($listener[1]) ? $listener[1] : 0);
                }
            }
            if($this->eagerOptimizer) {
                $this->optimizeListeners($eventName);
                $this->sortListeners($eventName);
            }
        }
    }

    /**
     * @param EventSubscriberInterface $subscriber
     */
    public function removeSubscriber(EventSubscriberInterface $subscriber)
    {
        foreach ($subscriber->getSubscribedEvents() as $eventName => $params) {
            if (\is_array($params) && \is_array($params[0])) {
                foreach ($params as $listener) {
                    $this->removeListener($eventName, [$subscriber, $listener[0]]);
                }
            } else {
                $this->removeListener($eventName, [$subscriber, \is_string($params) ? $params : $params[0]]);
            }
            if($this->eagerOptimizer) {
                $this->optimizeListeners($eventName);
                $this->sortListeners($eventName);
            }
        }
    }

    /**
     * Triggers the listeners of an event.
     *
     * This method can be overridden to add functionality that is executed
     * for each listener.
     *
     * @param callable[] $listeners The event listeners
     * @param string     $eventName The name of the event to dispatch
     * @param object     $event     The event object to pass to the event handlers/listeners
     */
    protected function callListeners(iterable $listeners, string $eventName, object $event)
    {
        if($this->coroutineSupport) {
            $waitGroup = new WaitGroup();
            foreach ($this->coroutineCallable[$eventName] as $wrappedListener){
                Coroutine::create($wrappedListener, [$eventName, $event, $waitGroup]);
            }
            $waitGroup->wait();
        }
        else {
            foreach ($listeners as $listener) {
                $listener($event, $eventName, $this);
            }
        }
    }

    /**
     * Sorts the internal list of listeners for the given event by priority.
     * @param string $eventName
     */
    protected function sortListeners(string $eventName)
    {
        krsort($this->listeners[$eventName]);
        $this->sorted[$eventName] = [];

        foreach ($this->listeners[$eventName] as &$listeners) {
            foreach ($listeners as $k => &$listener) {
                if (\is_array($listener) && isset($listener[0]) && $listener[0] instanceof \Closure && 2 >= \count($listener)) {
                    $listener[0] = $listener[0]();
                    $listener[1] = $listener[1] ?? '__invoke';
                }
                $this->sorted[$eventName][] = $listener;
            }
        }
    }

    /**
     * Optimizes the internal list of listeners for the given event by priority.
     * @param string $eventName
     * @return array
     */
    protected function optimizeListeners(string $eventName): array
    {
        krsort($this->listeners[$eventName]);
        $this->optimized[$eventName] = [];

        foreach ($this->listeners[$eventName] as &$listeners) {
            foreach ($listeners as &$listener) {
                $closure = &$this->optimized[$eventName][];
                if (\is_array($listener) && isset($listener[0]) && $listener[0] instanceof \Closure && 2 >= \count($listener)) {
                    $closure = static function (...$args) use (&$listener, &$closure) {
                        if ($listener[0] instanceof \Closure) {
                            $listener[0] = $listener[0]();
                            $listener[1] = $listener[1] ?? '__invoke';
                        }
                        ($closure = \Closure::fromCallable($listener))(...$args);
                    };
                } else {
                    $closure = $listener instanceof \Closure || $listener instanceof WrappedListener ? $listener : \Closure::fromCallable($listener);
                }
            }
        }
        if($this->coroutineSupport) {
            $this->coroutineCallable = [];
            foreach ($this->optimized as $eventName => $listeners) {
                foreach ($listeners as $listener) {
                    $this->coroutineCallable[$eventName][] = function ($eventName, $event, WaitGroup $waitGroup) use ($listener) {
                        $waitGroup->add();
                        try {
                            $listener($event, $eventName);
                        } catch (\Throwable $ex) {
                            print 'dashmajan';
                        } finally {
                            $waitGroup->done();
                        }
                    };
                }
            }
        }
        return $this->optimized[$eventName];
    }

    public function optimize(){
        foreach ($this->listeners as $eventName => $listeners){
            $this->optimizeListeners($eventName);
            $this->sortListeners($listeners);
        }
    }
}
