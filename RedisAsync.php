<?php

/**
 * Class RedisAsync
 *
 * based on https://github.com/swoole/redis-async
 *
 * @method set
 * @method get
 * @method select
 * @method hexists
 * @method sadd
 * @method sMembers
 */
class RedisAsync
{
    public $crlf = "\r\n";

    public $host;
    public $port;
    public $debug = false;

    /**
     * 空闲连接池
     * @var array
     */
    public $pool = array();

    public function __construct($host = 'localhost', $port = 6379, $timeout = 0.1)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function trace($msg)
    {
        echo "-----------------------------------------" . PHP_EOL;
        echo trim($msg) . PHP_EOL;
        echo "-----------------------------------------" . PHP_EOL;
    }

    public function stats()
    {
        $stats = 'Idle connection: ' . count($this->pool) . '<br />' . PHP_EOL;
        return $stats;
    }

    public function hmset($key, array $value, $callback)
    {
        $lines[] = 'hmset';
        $lines[] = $key;
        foreach ($value as $k => $v) {
            $lines[] = $k;
            $lines[] = $v;
        }
        $connection = $this->getConnection();
        $cmd = $this->parseRequest($lines);
        $connection->command($cmd, $callback);
    }

    public function hmget($key, array $value, $callback)
    {
        $connection = $this->getConnection();
        $connection->fields = $value;

        array_unshift($value, 'hmget', $key);
        $cmd = $this->parseRequest($value);
        $connection->command($cmd, $callback);
    }

    public function parseRequest($array)
    {
        $cmd = '*' . count($array) . $this->crlf;
        foreach ($array as $item) {
            $cmd .= '$' . strlen($item) . $this->crlf . $item . $this->crlf;
        }
        return $cmd;
    }

    public function __call($method, array $args)
    {
        $callback = array_pop($args);
        array_unshift($args, $method);
        $cmd = $this->parseRequest($args);
        $connection = $this->getConnection();
        $connection->command($cmd, $callback);
    }

    /**
     * 从连接池中取出一个连接资源
     * @return RedisConnection
     */
    protected function getConnection()
    {
        if (count($this->pool) > 0) {
            /**
             * @var $connection RedisConnection
             */
            foreach ($this->pool as $k => $connection) {
                unset($this->pool[$k]);
                break;
            }
            return $connection;
        } else {
            return new RedisConnection($this);
        }
    }

    public function lockConnection($id)
    {
        unset($this->pool[$id]);
    }

    public function freeConnection($id, RedisConnection $connection)
    {
        $this->pool[$id] = $connection;
    }
}

class RedisConnection
{
    public $crlf = "\r\n";

    /**
     * @var RedisAsync
     */
    protected $redis;
    protected $buffer = '';
    /**
     * @var \swoole_client
     */
    protected $client;
    protected $callback;

    /**
     * 等待发送的数据
     */
    protected $wait_send = false;
    protected $wait_recv = false;
    public $fields;

    public function __construct(RedisAsync $redis)
    {
        $client = new \swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
        $client->on('connect', [$this, 'onConnect']);
        $client->on('error', [$this, 'onError']);
        $client->on('receive', [$this, 'onReceive']);
        $client->on('close', [$this, 'onClose']);
        $client->connect($redis->host, $redis->port);
        $this->client = $client;
        $redis->pool[$client->sock] = $this;
        $this->redis = $redis;
    }

    /**
     * 清理数据
     */
    public function clean()
    {
        $this->buffer = '';
        $this->callback;
        $this->wait_send = false;
        $this->wait_recv = false;
        $this->fields = array();
    }

    /**
     * 执行redis指令
     * @param $cmd
     * @param $callback
     */
    public function command($cmd, $callback)
    {
        /**
         * 如果已经连接，直接发送数据
         */
        if ($this->client->isConnected()) {
            $this->client->send($cmd);
        } /**
         * 未连接，等待连接成功后发送数据
         */
        else {
            $this->wait_send = $cmd;
        }
        $this->callback = $callback;
        //从空闲连接池中移除，避免被其他任务使用
        $this->redis->lockConnection($this->client->sock);
    }

    public function onConnect(\swoole_client $client)
    {
        if ($this->wait_send) {
            $client->send($this->wait_send);
            $this->wait_send = '';
        }
    }

    public function onError()
    {
        echo 'Failed to connect redis server' . PHP_EOL;
    }

    public function onReceive($cli, $data)
    {
        if ($this->redis->debug)
            $this->redis->trace($data);

        $result = null;
        if ($this->wait_recv) {

            $this->buffer .= $data;

            if (strlen($this->buffer) >= $this->wait_recv) {
                $result = substr($this->buffer, 0, -2);
            } else
                return;

        } else {
            $this->buffer = $data;
            $result = $this->read();
            if ($this->wait_recv)
                return;
        }

        $this->clean();
        $this->redis->freeConnection($cli->sock, $this);
        call_user_func($this->callback, $result, $result !== null);
    }

    public function read()
    {
        $chunk = $this->readLine();

//        if ($chunk === false || $chunk === '') {
//            $this->onConnectionError('Error while reading line from the server.');
//        }

        $prefix = $chunk[0];
        $payload = substr($chunk, 1);

        switch ($prefix) {
            case '+':
                return $payload;

            case '$':
                if ($payload == -1) {
                    return null;
                }

                $len = $payload;
                $chunk = $this->readBucket($len);
                if ($len > strlen($chunk)) {
                    $this->wait_recv = $len + 2;
                    $this->buffer = $chunk;
                    return null;
                }

                return $chunk;

            case '*':
                if ($payload == -1) {
                    return null;
                }

                $bulk = array();
                for ($i = 0; $i < $payload; ++$i) {
                    $bulk[$i] = $this->read();
                }

                return $bulk;

            case ':':
                return (int)$payload;

            case '-':
                return $payload;

            default:
                echo "Response is not a redis result. String:\n$payload\n";
                return null;
        }
    }

    public function readLine()
    {
        list($chunk, $this->buffer) = \explode($this->crlf, $this->buffer, 2);
        return $chunk;
    }

    public function readBucket($len)
    {
        $chunk = substr($this->buffer, 0, $len);
        if ($this->buffer)
            $this->buffer = substr($this->buffer, $len + 2);

        return $chunk;
    }

    public function onClose(\swoole_client $cli)
    {
        if ($this->wait_send) {
            $this->redis->freeConnection($cli->sock, $this);
            call_user_func($this->callback, 'timeout', false);
        }
    }
}
