<?php
namespace Channel;
use Workerman\Lib\Timer;
use Workerman\Connection\AsyncTcpConnection;

/**
 * Channel/Client
 * @version 1.0.5
 */
class Client
{
    /**
     * onMessage.
     * @var callback
     */
    public static $onMessage = null;

    /**
     * onConnect
     * @var callback
     */
    public static $onConnect = null;

    /**
     * onClose
     * @var callback
     */
    public static $onClose = null;

    /**
     * Connction to channel server.
     * @var TcpConnection
     */
    protected static $_remoteConnection = null;

    /**
     * Channel server ip.
     * @var string
     */
    protected static $_remoteIp = null;

    /**
     * Channel server port.
     * @var int
     */
    protected static $_remotePort = null;

    /**
     * Reconnect timer.
     * @var Timer
     */
    protected static $_reconnectTimer = null;

    /**
     * Ping timer.
     * @var Timer
     */
    protected static $_pingTimer = null;

    /**
     * All event callback.
     * @var array
     */
    protected static $_events = array();

    /**
     * All queue callback.
     * @var callable
     */
    protected static $_queues = array();

    /**
     * @var bool
     */
    protected static $_isWorkermanEnv = true;

    /**
     * Ping interval.
     * @var int
     */
    public static $pingInterval = 25;

    /**
     * Connect to channel server
     * @param string $ip
     * @param int $port
     * @return void
     */
    public static function connect($ip = '127.0.0.1', $port = 2206)
    {
        if(!self::$_remoteConnection)
        {
            self::$_remoteIp = $ip;
            self::$_remotePort = $port;
            if (PHP_SAPI !== 'cli' || !class_exists('Workerman\Worker', false)) {
                self::$_isWorkermanEnv = false;
            }
            // For workerman environment.
            if (self::$_isWorkermanEnv) {
                self::$_remoteConnection = new AsyncTcpConnection('frame://' . self::$_remoteIp . ':' . self::$_remotePort);
                self::$_remoteConnection->onClose = 'Channel\Client::onRemoteClose';
                self::$_remoteConnection->onConnect = 'Channel\Client::onRemoteConnect';
                self::$_remoteConnection->onMessage = 'Channel\Client::onRemoteMessage';
                self::$_remoteConnection->connect();

                if (empty(self::$_pingTimer)) {
                    self::$_pingTimer = Timer::add(self::$pingInterval, 'Channel\Client::ping');
                }
                // Not workerman environment.
            } else {
                self::$_remoteConnection = stream_socket_client('tcp://'.self::$_remoteIp.':'.self::$_remotePort, $code, $message, 5);
                if (!self::$_remoteConnection) {
                    throw new \Exception($message);
                }
            }
        }
    }

    /**
     * onRemoteMessage.
     * @param TcpConnection $connection
     * @param string $data
     * @throws \Exception
     */
    public static function onRemoteMessage($connection, $data)
    {
        $data = unserialize($data);
        $event = $data['channel'];
        $event_data = $data['data'];
        if (!empty(self::$_events[$event])) {
            call_user_func(self::$_events[$event], $event_data);
        } elseif (!empty(Client::$onMessage)) {
            call_user_func(Client::$onMessage, $event, $event_data);
        } else {
            throw new \Exception("event:$event have not callback");
        }
    }

    /**
     * Ping.
     * @return void
     */
    public static function ping()
    {
        if(self::$_remoteConnection)
        {
            self::$_remoteConnection->send('');
        }
    }

    /**
     * onRemoteClose.
     * @return void
     */
    public static function onRemoteClose()
    {
        echo "Waring channel connection closed and try to reconnect\n";
        self::$_remoteConnection = null;
        self::clearTimer();
        self::$_reconnectTimer = Timer::add(1, 'Channel\Client::connect', array(self::$_remoteIp, self::$_remotePort));
        if (self::$onClose) {
            call_user_func(Client::$onClose);
        }
    }

    /**
     * onRemoteConnect.
     * @return void
     */
    public static function onRemoteConnect()
    {
        $all_event_names = array_keys(self::$_events);
        if($all_event_names)
        {
            self::subscribe($all_event_names);
        }
        self::clearTimer();

        if (self::$onConnect) {
            call_user_func(Client::$onConnect);
        }
    }

    /**
     * clearTimer.
     * @return void
     */
    public static function clearTimer()
    {
        if (!self::$_isWorkermanEnv) {
            throw new \Exception('Channel\\Client not support clearTimer method when it is not in the workerman environment.');
        }
        if(self::$_reconnectTimer)
        {
            Timer::del(self::$_reconnectTimer);
            self::$_reconnectTimer = null;
        }
    }

    /**
     * On.
     * @param string $event
     * @param callback $callback
     * @throws \Exception
     */
    public static function on($event, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception('callback is not callable for event.');
        }
        self::$_events[$event] = $callback;
        self::subscribe($event);
    }

    /**
     * Subscribe.
     * @param string $events
     * @return void
     */
    public static function subscribe($events)
    {
        $events = (array)$events;
        self::send(array('type' => 'subscribe', 'channels'=>$events));
        foreach ($events as $event) {
            if(!isset(self::$_events[$event])) {
                self::$_events[$event] = null;
            }
        }
    }

    /**
     * Unsubscribe.
     * @param string $events
     * @return void
     */
    public static function unsubscribe($events)
    {
        $events = (array)$events;
        self::send(array('type' => 'unsubscribe', 'channels'=>$events));
        foreach($events as $event) {
            unset(self::$_events[$event]);
        }
    }

    /**
     * Publish.
     * @param string $events
     * @param mixed $data
     */
    public static function publish($events, $data)
    {
        self::sendAnyway(array('type' => 'publish', 'channels' => (array)$events, 'data' => $data));
    }

    /**
     * Watch a channel of queue
     * @param $channel
     * @param $callback
     * @throws \Exception
     */
    public static function watch($channel, $callback)
    {
        if (!is_callable($callback)) {
            throw new \Exception('callback is not callable for watch.');
        }

        self::send(array('type' => 'watch', 'channels'=>$channel));

        self::$_events[$channel] = static function($data) use ($callback) {
            try {
                call_user_func($callback, $data);
            } catch (\Exception $e) {
                throw $e;
            } catch (\Error $e) {
                throw $e;
            } finally {
                self::send(array('type' => 'pull'));
            }
        };

        self::send(array('type' => 'pull'));
    }

    /**
     * Unwatch a channel of queue
     * @param string $channel
     * @throws \Exception
     */
    public static function unwatch($channel)
    {
        self::send(array('type' => 'unwatch', 'channels'=>$channel));
        if (isset(self::$_events[$channel])) {
            unset(self::$_events[$channel]);
        }
    }

    public static function push($channel, $data)
    {
        self::sendAnyway(array('type' => 'push', 'channels' => $channel, 'data' => $data));
    }

    /**
     * Send through workerman environment
     * @param $data
     * @throws \Exception
     */
    protected static function send($data)
    {
        if (!self::$_isWorkermanEnv) {
            throw new \Exception("Channel\\Client not support {$data['type']} method when it is not in the workerman environment.");
        }
        self::connect(self::$_remoteIp, self::$_remotePort);
        self::$_remoteConnection->send(serialize($data));
    }

    /**
     * Send from any environment
     * @param $data
     * @throws \Exception
     */
    protected static function sendAnyway($data)
    {
        self::connect(self::$_remoteIp, self::$_remotePort);
        $body = serialize($data);
        if (self::$_isWorkermanEnv) {
            self::$_remoteConnection->send($body);
        } else {
            $buffer = pack('N', 4+strlen($body)) . $body;
            fwrite(self::$_remoteConnection, $buffer);
        }
    }

}
