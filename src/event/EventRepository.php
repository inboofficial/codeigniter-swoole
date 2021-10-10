<?php


namespace inboir\CodeigniterS\event;


interface EventRepository
{

    /**
     *
     * generate event id if not exist and persist in event storage
     *
     * @param Event $event
     * @return Event|null returns false if event exists
     */
    function saveEventOnNotExist(Event $event): ?Event;


    /**
     *
     * retrieve event form event storage by event id
     *
     * @param string $event_unique
     * @return Event
     */
    function getEvent(string $event_unique): ?Event;

    /**
     *
     * change event status to pulled in Event Storage with in a transaction
     *
     * @param string $event_unique
     * @return Event|null returns null if event status is not 'waiting'
     */
    function pullEvent(string $event_unique): ?Event;

    /**
     * @param string $event_unique
     * @return bool
     */
    function exist(string $event_unique): bool;

    /**
     * @return array [Event]
     */
    function getAllEvents(): array;

    /**
     *
     * retrieve all events and change there status to pulled in a transaction
     *
     * @return array [Event]
     */
    function pullRunnableEvents(): array;

    /**
     * @param Event $event
     * @return Event
     */
    function updateEvent(Event $event): ?Event;
}