<?php


namespace inboir\CodeigniterS\event;


interface EventRepository
{
    function logEvent(Event $event);
    function getEvent(string $eventId): Event;
    function getAllEvents(): array;
    function pullRunnableEvents(): array;
    function UpdateEvent(Event $event);
}