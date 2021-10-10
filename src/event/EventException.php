<?php


namespace inboir\CodeigniterS\event;


class EventException
{
    public $exception_code;
    public string $exception_message;
    public string $exception_file;
    public string $exception_line;
    public string $exception_trace;
}