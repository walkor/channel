<?php
namespace Channel;
use Workerman\Lib\Timer;
use Workerman\Connection\AsyncTcpConnection;

/**
 * Channel/Client
 * @version 1.0.3
 */
class Client 
{
    /**
     * onMessage.
     * @var callback
     */
    public static $onMessage = null;

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
             self::$_remoteConnection = new AsyncTcpConnection('frame://'.self::$_remoteIp.':'.self::$_remotePort);
             self::$_remoteConnection->onClose = 'Channel\Client::onRemoteClose'; 
             self::$_remoteConnection->onConnect = 'Channel\Client::onRemoteConnect';
             self::$_remoteConnection->onMessage = 'Channel\Client::onRemoteMessage';
             self::$_remoteConnection->connect();
             
             if(empty(self::$_pingTimer))
             {
                 self::$_pingTimer = Timer::add(self::$pingInterval, 'Channel\Client::ping');
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
         if(!empty(self::$_events[$event]))
         {
             call_user_func(self::$_events[$event], $event_data);
         }
         elseif(!empty(Client::$onMessage))
         {
             call_user_func(Client::$onMessage, $event, $event_data);
         }
         else
         {
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
    }

    /**
     * clearTimer.
     * @return void
     */
    public static function clearTimer()
    {
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
        if(!is_callable($callback))
        {
            throw new \Exception('callback is not callable');
        }
        self::$_events[$event] = $callback;
        self::subscribe(array($event));
    }

    /**
     * Subscribe.
     * @param string $events
     * @return void
     */
    public static function subscribe($events)
    {
         self::connect();
         $events = (array)$events;
         foreach($events as $event)
         {
             if(!isset(self::$_events[$event]))
             {
                 self::$_events[$event] = null;
             }
         }
         self::$_remoteConnection->send(serialize(array('type' => 'subscribe', 'channels'=>(array)$events)));
    }

    /**
     * Unsubscribe.
     * @param string $events
     * @return void
     */
    public static function unsubscribe($events)
    {
        self::connect();
        $events = (array)$events;
        foreach($events as $event)
        {
            unset(self::$_events[$event]);
        }
        self::$_remoteConnection->send(serialize(array('type' => 'unsubscribe', 'channels'=>$events))); 
    }

    /**
     * Publish.
     * @param string $events
     * @param mixed $data
     */
    public static function publish($events, $data)
    {
        self::connect();
        self::$_remoteConnection->send(serialize(array('type' => 'publish', 'channels'=>(array)$events, 'data' => $data)));
    }
}
