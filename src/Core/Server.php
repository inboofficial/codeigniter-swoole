<?php  namespace inboir\CodeigniterS\Core;

use inboir\CodeigniterS\event\Event;
use inboir\CodeigniterS\event\EventRepository;
use inboir\CodeigniterS\event\Events;
use inboir\CodeigniterS\event\EventStatus;
use Swoole\Process;
use Throwable;
use function inboir\CodeigniterS\Helpers\getCiSwooleConfig;

/**
 * ------------------------------------------------------------------------------------
 * Swoole Server
 * ------------------------------------------------------------------------------------
 *
 * @author lanlin
 * @change 2019/07/26
 */
class Server
{

    // ------------------------------------------------------------------------------
    const TIMER_LIMIT = 86400000;
    protected static EventRepository $eventRepository;
    /**
     * host config
     *
     * @var array
     */
    private static $cfgs =
    [
        'server_port' => null,
        'server_host' => '/var/run/swoole.sock',
        'server_type' => SWOOLE_SOCK_UNIX_STREAM,
        'debug_file'  => APPPATH . 'logs/swoole_debug.log',
    ];

    // ------------------------------------------------------------------------------

    /**
     * server config
     *
     * warning: do not change this
     *
     * @var array
     */
    private static $config =
    [
        'daemonize'      => false,        // using as daemonize?
        'package_eof'    => '☯',         // \u262F
        'reload_async'   => true,
        'open_eof_split' => true,
        'open_eof_check' => true,
    ];

    // ------------------------------------------------------------------------------

    /**
     * start a swoole server in cli
     *
     * @param EventRepository $eventRepository
     * @return mixed
     * @throws \Exception
     */
    public static function start(EventRepository $eventRepository)
    {
        self::initConfig();
        self::$eventRepository = $eventRepository;

        $serv = new \Swoole\Server
        (
            self::$cfgs['server_host'],
            self::$cfgs['server_port'],
            SWOOLE_PROCESS,
            self::$cfgs['server_type']
        );

        // init config
        $serv->set(self::$config);

        // listen on server init
        $serv->on('ManagerStart', [Server::class, 'onManagerStart']);
        $serv->on('WorkerStart',  [Server::class, 'onWorkerStart']);
        $serv->on('Start',        [Server::class, 'onMasterStart']);

        // listen on base event
        $serv->on('Connect', [Server::class, 'onConnect']);
        $serv->on('Receive', [Server::class, 'onReceive']);
        $serv->on('Finish',  [Server::class, 'onFinish']);
        $serv->on('Close',   [Server::class, 'onClose']);
        $serv->on('Task',    [Server::class, 'onTask']);

        // start server
        return $serv->start();
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on master start
     *
     * @param \Swoole\Server $serv
     */
    public static function onMasterStart(\Swoole\Server $serv)
    {
        new Events();
        self::initTimers($serv);
        if (self::$cfgs['server_port'] === null)
        {
            @chmod(self::$cfgs['server_host'], 0777);
        }

        @swoole_set_process_name($serv->setting['process_name'].'-MASTER');

        $msg = "SWOOLE MASTER: {$serv->manager_pid}\n";

        error_log($msg, 3, self::$cfgs['debug_file']);
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on manager start
     *
     * @param \Swoole\Server $serv
     */
    public static function onManagerStart(\Swoole\Server $serv)
    {
        @swoole_set_process_name($serv->setting['process_name'].'-MANAGER');

        $msg = "SWOOLE MANAGER: {$serv->manager_pid}\n";

        error_log($msg, 3, self::$cfgs['debug_file']);
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on workers start & set timers
     *
     * @param \Swoole\Server $serv
     * @param  int $workerId
     */
    public static function onWorkerStart(\Swoole\Server $serv, $workerId)
    {
        // set process name
        if(($workerId >= $serv->setting['worker_num']))
            @swoole_set_process_name($serv->setting['process_name'].'-TASK');
        else
            @swoole_set_process_name($serv->setting['process_name'].'-WORKER');

        // when task start, return
        if ($serv->taskworker) { return; }

        // init all timers
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on receive data
     *
     * @param \Swoole\Server $serv
     * @param int $fd
     * @param int $reactorId
     * @param string $data
     */
    public static function onReceive(\Swoole\Server $serv, $fd, $reactorId, $data = '')
    {
        // close client
        $serv->close($fd);

        // format passed
        $data = str_replace(self::$config['package_eof'], '', $data);
        $data = unserialize($data);

        if (!$data) { return; }

        // check is command
        if(!empty($data['shutdown']))
        {
            $serv->shutdown();
            return;
        }

        // reload command
        if (!empty($data['reload']))
        {
           $serv->reload();
           return;
        }

        // start a task
        $serv->task($data);
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on task
     *
     * @param \Swoole\Server $serv
     * @param int $taskId
     * @param int $workerId
     * @param array $data
     */
    public static function onTask(\Swoole\Server $serv, $taskId, $workerId, $data)
    {
        try
        {
            /** @var Event $event */
            $event = $data['event'];
            if(!$event->eventRout) return;
            if(!Events::has_listeners($event->eventRout)) return;
            if(!$event->eventSchedule || $event->eventSchedule > time())
                self::createEventCall($event);
            else{
                self::createdScheduledEvent($event, $serv);
            }
        }
        // kill process
        catch (Throwable $e) { self::logs($e); }
        finally { Process::kill(getmypid()); }
    }


    /**
     * @param $event Event
     */
    protected static function createEventCall($event){
        $event->eventStatus = EventStatus::PULLED;
        $event = self::$eventRepository->saveEventOnNotExist($event);
        if(!$event) return;
        self::callEvent($event);
    }

    /**
     * @param $event Event
     * @param \Swoole\Server $server
     */
    protected static function createdScheduledEvent($event, \Swoole\Server $server)
    {
        $scheduleInterval = time() - $event->eventSchedule;
        if($scheduleInterval < 1) {
            self::createEventCall($event);
            return;
        }
        $event->eventStatus = EventStatus::SCHEDULED;
        $event = self::$eventRepository->saveEventOnNotExist($event);
        if(!$event) return;
        self::scheduleEvent($event, $server);
    }

    /**
     * @param $event Event
     * @param \Swoole\Server $server
     */
    protected static function scheduleEvent($event, \Swoole\Server $server)
    {
        $scheduleInterval = time() - $event->eventSchedule;
        if($scheduleInterval < 1) {
            self::callEvent($event);
            return;
        }
        $timerStart = $scheduleInterval >= self::TIMER_LIMIT ? self::TIMER_LIMIT : $scheduleInterval * 1000;
        $server->after($timerStart,
            function() use ($event, $server)
            {
                self::scheduleEvent($event, $server);
            });
    }


    /**
     * @param $event Event
     */
    protected static function callEvent($event)
    {
        try {
            Events::trigger($event->eventData, $event->eventRout);
            $event->eventStatus = EventStatus::FINISHED;
        }catch (Throwable $ex){
            $event->eventStatus = EventStatus::FAILED;
        } finally {
            self::$eventRepository->updateEvent($event);
        }
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on connect
     *
     * @param \Swoole\Server $serv
     * @param int $fd
     */
    public static function onConnect(\Swoole\Server $serv, $fd)
    {
        return;
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on close
     *
     * @param \Swoole\Server $serv
     * @param int $fd
     */
    public static function onClose(\Swoole\Server $serv, $fd)
    {
        return;
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on task finish
     *
     * @param \Swoole\Server $serv
     * @param int $taskId
     * @param mixed $data
     */
    public static function onFinish(\Swoole\Server $serv, $taskId, $data)
    {
        return;
    }

    // ------------------------------------------------------------------------------

    /**
     * init config
     *
     * @throws \Exception
     */
    private static function initConfig()
    {
        $config = getCiSwooleConfig('swoole');

        self::$cfgs['debug_file']  = $config['debug_file'];
        self::$cfgs['server_host'] = $config['server_host'];
        self::$cfgs['server_port'] = $config['server_port'];
        self::$cfgs['server_type'] = $config['server_type'];

        unset(
            $config['debug_file'], $config['server_host'],
            $config['server_port'], $config['server_type'],
        );

        self::$config = array_merge($config, self::$config);
    }

    // ------------------------------------------------------------------------------

    /**
     * log message to debug
     *
     * @param Throwable $msg
     */
    private static function logs(Throwable $msg)
    {
        $strings  = $msg->getMessage() . "\n";
        $strings .= $msg->getTraceAsString();

        $time_nw  = date('Y-m-d H:i:s');
        $content  = "\n== {$time_nw} ============================\n";
        $content .= "{$strings}";
        $content .= "\n===================================================\n\n";

        error_log($content, 3, self::$cfgs['debug_file']);
    }

    // ------------------------------------------------------------------------------

    /**
     * init timers for stamsel
     *
     * @param \Swoole\Server $serv
     */
    private static function initTimers(\Swoole\Server $serv)
    {
        try
        {
            $timers = getCiSwooleConfig('timers');
            foreach ($timers[0] as $route => $microSeconds)
            {
                $serv->tick($microSeconds, function () use ($serv, $route)
                {
                    $stats = $serv->stats();
                    $event = new Event();
                    $event->eventRout = $route;
                    if ($stats['tasking_num'] < 64) { $serv->task(['event' => $route]); }
                });
            }
        }

        catch (Throwable $e) { self::logs($e); }
        finally { unset($timers); }
    }

    // ------------------------------------------------------------------------------


}
