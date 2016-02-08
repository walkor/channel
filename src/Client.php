<?php
namespace Channel;
use Workerman\Lib\Timer;
use Workerman\Connection\AsyncTcpConnection;

class Client 
{
    public static $onMessage = null;

    protected static $_remoteConnection = null;

    protected static $_remoteIp = null;

    protected static $_remotePort = null;

    protected static $_timer = null;
    
    protected static $_events = array();

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
                 else
                 {
                     call_user_func(Client::$onMessage, $event, $event_data);
                 }
             };
             self::$_remoteConnection->connect();
         }    
    }

    public static function onRemoteClose()
    {
        echo "Waring channel connection closed and try to reconnect\n";
        self::clearTimer();
        self::$_timer = Timer::add(1, 'Channel\Client::connect', array(self::$_remoteIp, self::$_remotePort));
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
        if(self::$_timer)
        {
           Timer::del(self::$_timer);
           self::$_timer = null;
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
