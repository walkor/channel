# Channel
基于订阅的多进程通讯组件，类似redis订阅发布机制。
（服务端和客户端只能在workerman环境中使用）

# 服务端
```
use Workerman\Worker;

$channel_server = new Channel\Server(2206);

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
```

# 客户端
use Workerman\Worker;

$worker = new Worker(....);
$worker->onWorkerStart = function()
{
    // Channel客户端连接到Channel服务端
    Channel\Client::connect('<Channel服务端ip>', 2206);
    // 订阅某个主题
    Channel\Client::subscribe('subject_xxx');
    // 当自己订阅的主题有消息时触发的回调
    Channel\Client::$onMessage = function($channel, $data){
        var_dump($channel, $data);
    };
};
$worker->onMessage = function($connection, $data)
{
    // 向subject_xxx的订阅进程发送消息
    Channel\Client::publish('subject_xxx', array('some data', 'some data'));
};

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
````
