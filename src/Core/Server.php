<?php  namespace inboir\CodeigniterS\Core;

use Exception;
use inboir\CodeigniterS\event\Event;
use inboir\CodeigniterS\event\EventCarrier;
use inboir\CodeigniterS\event\eventDispatcher\EventExceptionRepository;
use inboir\CodeigniterS\event\eventDispatcher\EventRepository;
use inboir\CodeigniterS\event\Events;
use Swoole\Coroutine;
use Swoole\Server\Task;
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
    /**
     * host config
     *
     * @var array
     */
    private static array $cfgs =
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
    protected static array $config =
        [
            'daemonize'      => false,        // using as daemonize?
            'package_eof'    => '☯',         // \u262F
            'reload_async'   => true,
            'open_eof_split' => true,
            'open_eof_check' => true,
        ];

    protected static bool $configInitialized = false;

    // ------------------------------------------------------------------------------

    /**
     * start a swoole server in cli
     *
     * @param EventRepository $eventRepository
     * @param EventExceptionRepository $eventExceptionRepository
     * @return mixed
     * @throws Exception
     */
    public static function start(EventRepository $eventRepository, EventExceptionRepository $eventExceptionRepository)
    {
        if(!self::$configInitialized) self::initConfig();

        $server = new \Swoole\Server
        (
            self::$cfgs['server_host'],
            self::$cfgs['server_port'],
            SWOOLE_PROCESS,
            self::$cfgs['server_type']
        );

        // init config
        $server->set(self::$config);

        // listen on server init
        $server->on('ManagerStart', [Server::class, 'onManagerStart']);
        $server->on('WorkerStart',  [Server::class, 'onWorkerStart']);
        $server->on('Start',        [Server::class, 'onMasterStart']);

        // listen on base event
        $server->on('Connect', [Server::class, 'onConnect']);
        $server->on('Receive', [Server::class, 'onReceive']);
        $server->on('Finish',  [Server::class, 'onFinish']);
        $server->on('Close',   [Server::class, 'onClose']);
        if(!self::$config['task_enable_coroutine']) {
            $server->on('Task',    [Server::class, 'onTask']);
        }else {
            $server->on('Task',    [Server::class, 'onCoroutineEnabledTask']);
        }

        Events::initialize($eventRepository, $eventExceptionRepository, false, $server);
        Events::registerAll();

        Events::$dispatcher->optimize();

        // start server
        return $server->start();
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on master start
     *
     * @param \Swoole\Server $serv
     */
    public static function onMasterStart(\Swoole\Server $serv)
    {
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
     * @param int $workerId
     */
    public static function onWorkerStart(\Swoole\Server $serv, int $workerId)
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
    public static function onReceive(\Swoole\Server $serv, int $fd, int $reactorId, $data = '')
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
    public static function onTask(\Swoole\Server $serv, int $taskId, int $workerId, array $data)
    {
        try
        {
            if(array_key_exists('event',$data))
                Events::trigger($data['event']);
            if(array_key_exists('callback', $data) and !empty($data['callback']) and is_callable($data['callback'])){
                $callback = $data['callback'];
                $callback($data['event']);
            }
        }
        catch (Throwable $e) { self::logs($e); }
    }

    /**
     * listen on task
     *
     * @param \Swoole\Server $serv
     * @param Task $task
     */
    public static function onCoroutineEnabledTask(\Swoole\Server $serv, Task $task)
    {
        try
        {
            $data = $task->data;
            if(array_key_exists('event',$data))
                Events::trigger($data['event']);
            if(array_key_exists('callback', $data) and !empty($data['callback']) and is_callable($data['callback'])){
                $callback = $data['callback'];
                Coroutine::create($callback, $data['event']);
            }
        }
        catch (Throwable $e) { self::logs($e); }
    }



    // ------------------------------------------------------------------------------

    /**
     * listen on connect
     *
     * @param \Swoole\Server $serv
     * @param int $fd
     */
    public static function onConnect(\Swoole\Server $serv, int $fd)
    {
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on close
     *
     * @param \Swoole\Server $serv
     * @param int $fd
     */
    public static function onClose(\Swoole\Server $serv, int $fd)
    {
    }

    // ------------------------------------------------------------------------------

    /**
     * listen on task finish
     *
     * @param \Swoole\Server $serv
     * @param int $taskId
     * @param mixed $data
     */
    public static function onFinish(\Swoole\Server $serv, int $taskId, $data)
    {
    }

    // ------------------------------------------------------------------------------

    /**
     * init config
     *
     * @throws Exception
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
        self::$configInitialized = true;
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
                $event = new Event();
                $event->setEventRout($route);
                $event->setEventData((object)[]);
                $eventCarrier = new EventCarrier($event);
                $serv->tick($microSeconds, function () use ($serv, $eventCarrier)
                {
                    $stats = $serv->stats();
                    if ($stats['tasking_num'] < 64) { $serv->task(['event' => $eventCarrier]); }
                });
            }
        }

        catch (Throwable $e) { self::logs($e); }
        finally { unset($timers); }
    }

    // ------------------------------------------------------------------------------

    /**
     * @return array
     * @throws Exception
     */
    public static function getConfig(): array
    {
        if(!self::$configInitialized) self::initConfig();
        return self::$config;
    }


}
