<?php

namespace Channel;

use Workerman\Connection\TcpConnection;

class Queue
{

    public $name = 'default';
    public $watcher = array();
    public $pulls = array();
    protected $queue = null;

    public function __construct($name)
    {
        $this->name = $name;
        $this->queue = new \SplQueue();
    }

    /**
     * @param TcpConnection $connection
     */
    public function addWatch($connection)
    {
        $this->watcher[$connection->id] = $connection;
    }

    /**
     * @param TcpConnection $connection
     */
    public function removeWatch($connection)
    {
        if (isset($connection->watchs[$this->name])) {
            unset($connection->watchs[$this->name]);
        }
        if (isset($this->watcher[$connection->id])) {
            unset($this->watcher[$connection->id]);
        }
        if (isset($this->pulls[$connection->id])) {
            unset($this->pulls[$connection->id]);
        }
    }

    public function isEmpty()
    {
        return empty($this->watcher) && $this->queue->isEmpty();
    }

}