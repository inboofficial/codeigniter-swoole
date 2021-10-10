<?php namespace inboir\CodeigniterS\event;


use inboir\CodeigniterS\event\eventDispatcher\AsyncEventDispatcher;

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Events
 *
 * A simple events system for CodeIgniter.
 *
 * @package		CodeIgniter
 * @subpackage	Events
 * @version		1.0
 * @author		Eric Barnes <http://ericlbarnes.com>
 * @author		Dan Horrigan <http://dhorrigan.com>
 * @author      M Ali Nasiri K <mohammad.ank@outlook.com>
 * @license		MIT
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Events Library
 */
class Events {

    /**
     * @var	array	An array of listeners
     */
    protected static $_listeners = array();
    public static AsyncEventDispatcher $dispatcher ;
    // ------------------------------------------------------------------------

    /**
     * Register
     *
     * Registers a Callback for a given event
     *
     * @access	public
     * @param	string	The name of the event
     * @param	array	The callback for the Event
     * @return	void
     */
    public function __construct()
    {
        self::$dispatcher = new AsyncEventDispatcher();
    }

    public static function register($event, array $callback, $priority = 0)
    {
        $key = get_class($callback[0]).'::'.$callback[1];
        self::$_listeners[$event][$key] = $callback;
        self::$dispatcher->addListener($event,$callback,$priority);
        self::log_message('debug', 'Events::register() - Registered "'.$key.' with event "'.$event.'"');
    }

    // ------------------------------------------------------------------------

    /**
     * Trigger
     *
     * Triggers an event and returns the results.  The results can be returned
     * in the following formats:
     *
     * 'array'
     * 'json'
     * 'serialized'
     * 'string'
     *
     * @access	public
     * @param	string	The name of the event
     * @param	mixed	Any data that is to be passed to the listener
     */
    public static function trigger($event, $eventName = '')
    {
        if (self::has_listeners($eventName))
        {
            self::$dispatcher->dispatch($event,$eventName);
        }
    }

    public static function asyncTrigger($event, ?string $eventName = null, ?int $eventSchedule = null, ?string $event_unique = null)
    {
        if (self::has_listeners($eventName))
        {
            self::$dispatcher->asyncDispatch($event,$eventName,$eventSchedule, $event_unique);
        }
    }


    // ------------------------------------------------------------------------

    /**
     * Format Return
     *
     * Formats the return in the given type
     *
     * @access	protected
     * @param	array	The array of returns
     * @param	string	The return type
     * @return	mixed	The formatted return
     */
    protected static function _format_return(array $calls, $return_type)
    {
        self::log_message('debug', 'Events::_format_return() - Formating calls in type "'.$return_type.'"');

        switch ($return_type)
        {
            case 'json':
                return json_encode($calls);
                break;
            case 'serialized':
                return serialize($calls);
                break;
            case 'string':
                $str = '';
                foreach ($calls as $call)
                {
                    $str .= $call;
                }
                return $str;
                break;
            default:
                return $calls;
                break;
        }

        return FALSE;
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
    public static function has_listeners($event)
    {
        self::log_message('debug', 'Events::has_listeners() - Checking if event "'.$event.'" has listeners.');

        if (isset(self::$_listeners[$event]) AND count(self::$_listeners[$event]) > 0)
        {
            return TRUE;
        }
        return FALSE;
    }

    // ------------------------------------------------------------------------

    /**
     * Log Message
     *
     * Pulled out for unit testing
     *
     * @param string $type
     * @param string $message
     * @return void
     */
    public static function log_message($type = 'debug', $message = '')
    {
        if (function_exists('log_message'))
        {
            log_message($type, $message);
        }
    }
}

/* End of file events.php */