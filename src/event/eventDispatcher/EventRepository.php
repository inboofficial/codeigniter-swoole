<?php


namespace inboir\CodeigniterS\event;


interface EventRepository
{

    /**
     *
     * generate event id if not exist and persist in event storage
     *
     * @param EventCarrier $event
     * @return EventCarrier|null returns false if event exists
     */
    function saveEventOnNotExist(EventCarrier $event): ?EventCarrier;


    /**
     *
     * retrieve event form event storage by event id
     *
     * @param string $event_unique
     * @return EventCarrier
     */
    function getEvent(string $event_unique): ?EventCarrier;

    /**
     *
     * change event status to pulled in Event Storage with in a transaction
     *
     * @param string $event_unique
     * @return EventCarrier|null returns null if event status is not 'waiting'
     */
    function pullEvent(string $event_unique): ?EventCarrier;

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
     * @param EventCarrier $event
     * @return EventCarrier
     */
    function updateEvent(EventCarrier $event): ?EventCarrier;
}