<?php
namespace Channel;
use Workerman\Lib\Timer;
use Workerman\Connection\AsyncTcpConnection;

/**
 * Channel/Client
 * @version 1.0.1
 */
class Client 
{
    public static $onMessage = null;

    protected static $_remoteConnection = null;

    protected static $_remoteIp = null;

    protected static $_remotePort = null;

    protected static $_reconnectTimer = null;
    
    protected static $_pingTimer = null;
    
    protected static $_events = array();
    
    const PING_INTERVAL = 20;

    public static function connect($ip = '127.0.0.1', $port = 2206)
    {
        if(!self::$_remoteConnection)
        {
             self::$_remoteIp = $ip;
             self::$_remotePort = $port;
             self::$_remoteConnection = new AsyncTcpConnection('Text://'.self::$_remoteIp.':'.self::$_remotePort);
             self::$_remoteConnection->onClose = 'Channel\Client::onRemoteClose'; 
             self::$_remoteConnection->onConnect = 'Channel\Client::onRemoteConnect';
             self::$_remoteConnection->onMessage = function($connection, $data)
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
             };
             self::$_remoteConnection->connect();
             
             if(empty(self::$_pingTimer))
             {
                 self::$_pingTimer = Timer::add(self::PING_INTERVAL, 'Channel\Client::ping');
             }
         }    
    }
    
    public static function ping()
    {
        self::$_remoteConnection->send('');
    }

    public static function onRemoteClose()
    {
        echo "Waring channel connection closed and try to reconnect\n";
        self::$_remoteConnection = null;
        self::clearTimer();
        self::$_reconnectTimer = Timer::add(1, 'Channel\Client::connect', array(self::$_remoteIp, self::$_remotePort));
    }

    public static function onRemoteConnect()
    {
        $all_event_names = array_keys(self::$_events);
        if($all_event_names)
        {
            self::subscribe($all_event_names);
        }
        self::clearTimer();
    }

    public static function clearTimer()
    {
        if(self::$_reconnectTimer)
        {
           Timer::del(self::$_reconnectTimer);
           self::$_reconnectTimer = null;
        }
    }
    
    public static function on($event, $callback)
    {
        if(!is_callable($callback))
        {
            throw new \Exception('callback is not callable');
        }
        self::$_events[$event] = $callback;
        self::subscribe(array($event));
    }

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

    public static function publish($events, $data)
    {
        self::connect();
        self::$_remoteConnection->send(serialize(array('type' => 'publish', 'channels'=>(array)$events, 'data' => $data)));
    }
}
