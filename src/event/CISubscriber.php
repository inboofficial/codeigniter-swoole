<?php namespace inboir\CodeigniterS\event;


use Symfony\Component\EventDispatcher\EventSubscriberInterface;

abstract class CISubscriber implements EventSubscriberInterface
{

    public function __construct()
    {
        $this->register();
    }

    private static ?self $instance = null;

    public static function getInstance(): CISubscriber
    {
        if (!(static::$instance instanceof static)) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    protected function register()
    {
        foreach ($this->getSubscribedEvents() as $eventName => $params) {
            if (is_string($params)) {
                Events::register($eventName, [$this, $params]);
            } elseif (is_string($params[0])) {
                Events::register($eventName, [$this, $params[0]], isset($params[1]) ? $params[1] : 0);
            } else {
                foreach ($params as $listener) {
                    Events::register($eventName, [$this, $listener[0]], isset($listener[1]) ? $listener[1] : 0);
                }
            }
        }
    }

    public abstract static function getSubscribedEvents(): array;
}