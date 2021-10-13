<?php


namespace inboir\CodeigniterS\event\eventDispatcher;


use Throwable;

interface EventExceptionRepository
{
    public function addEventError(string $event_unique, Throwable $ex);

    public function hasError(string $event_unique): bool;
}