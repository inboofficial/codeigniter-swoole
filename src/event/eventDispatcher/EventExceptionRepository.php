<?php


namespace inboir\CodeigniterS\event\eventDispatcher;


use Throwable;

interface EventExceptionRepository
{
    public function addEventError(string $eventID, Throwable $ex);

    public function hasError(string $eventID): bool;
}