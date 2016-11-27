<?php
/**
 * Phpmodbus Copyright (c) 2004, 2013 Jan Krakora
 *
 * This source file is subject to the "PhpModbus license" that is bundled
 * with this package in the file license.txt.
 *
 * @copyright Copyright (c) 2004, 2013 Jan Krakora
 * @license   PhpModbus license
 * @category  Phpmodbus
 * @tutorial  Phpmodbus.pkg
 * @package   Phpmodbus
 * @version   $id$
 */

namespace PHPModbus;


use InvalidArgumentException;


class ModbusSocket
{
    /**
     * @var string (optional) client IP address when binding client
     */
    protected $client = '';
    /**
     * @var string client port set when binding client to local ip&port
     */
    protected $client_port = 502;
    /**
     * @var float Total response timeout (seconds, decimals allowed)
     */
    protected $timeout_sec = 5;
    /**
     * @var float Socket connect timeout (seconds, decimals allowed)
     */
    protected $socket_connect_timeout_sec = 1;
    /**
     * @var float Socket read timeout (seconds, decimals allowed)
     */
    protected $socket_read_timeout_sec = 0.3; // 300 ms
    /**
     * @var float Socket write timeout (seconds, decimals allowed)
     */
    protected $socket_write_timeout_sec = 1;
    /**
     * @var string Socket protocol (TCP, UDP)
     */
    protected $socket_protocol = 'UDP';
    /**
     * @var string Modbus device IP address
     */
    protected $host = '192.168.1.1';
    /**
     * @var string gateway port
     */
    protected $port = 502;
    /**
     * @var resource Communication socket
     */
    private $streamSocket;
    /**
     * @var array status messages
     */
    protected $statusMessages = [];

    public static function getBuilder()
    {
        return new ModbusSocketBuilder();
    }

    /**
     * connect
     *
     * Connect the socket
     *
     * @return bool
     * @throws \InvalidArgumentException
     * @throws \PHPModbus\IOException
     */
    public function connect()
    {
        $protocol = null;
        switch ($this->socket_protocol) {
            case 'TCP':
            case 'UDP':
                $protocol = strtolower($this->socket_protocol);
                break;
            default:
                throw new InvalidArgumentException("Unknown socket protocol, should be 'TCP' or 'UDP'");
        }

        $opts = [];
        if (strlen($this->client) > 0) {
            // Bind the client stream to a specific local port
            $opts = array(
                'socket' => array(
                    'bindto' => "{$this->client}:{$this->client_port}",
                ),
            );
        }
        $context = stream_context_create($opts);

        $this->streamSocket = @stream_socket_client(
            "$protocol://$this->host:$this->port",
            $errno,
            $errstr,
            $this->socket_connect_timeout_sec,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (false === $this->streamSocket) {
            $message = "Unable to create client socket to {$protocol}://{$this->host}:{$this->port}: {$errstr}";
            throw new IOException($message, $errno);
        }

        if (strlen($this->client) > 0) {
            $this->statusMessages[] = 'Bound';
        }
        $this->statusMessages[] = 'Connected';

        stream_set_blocking($this->streamSocket, false); // use non-blocking stream

        $writeTimeoutParts = $this->secsToSecUsecArray($this->socket_write_timeout_sec);
        // set as stream timeout as we use 'stream_select' to read data and this method has its own timeout
        // this call will only affect our fwrite parts (send data method)
        stream_set_timeout($this->streamSocket, $writeTimeoutParts['sec'], $writeTimeoutParts['usec']);

        return true;
    }

    /**
     * receive
     *
     * Receive data from the socket
     *
     * @return bool
     * @throws \Exception
     */
    public function receive()
    {
        $totalReadTimeout = $this->timeout_sec;
        $lastAccess = microtime(true);

        $readTimeout = $this->secsToSecUsecArray($this->socket_read_timeout_sec);
        while (true) {
            $read = array($this->streamSocket);
            $write = null;
            $except = null;
            if (false !== stream_select($read, $write, $except, $readTimeout['sec'], $readTimeout['usec'])) {
                $this->statusMessages[] = 'Wait data ... ';

                if (in_array($this->streamSocket, $read, false)) {
                    $data = fread($this->streamSocket, 2048); // read max 2048 bytes
                    if (!empty($data)) {
                        $this->statusMessages[] = 'Data received';
                        return $data; //FIXME what if we are waiting for more than that?
                    }
                    $lastAccess = microtime(true);
                } else {
                    $timeSpentWaiting = microtime(true) - $lastAccess;
                    if ($timeSpentWaiting >= $totalReadTimeout) {
                        throw new IOException(
                            "Watchdog time expired [ {$totalReadTimeout} sec ]!!! " .
                            "Connection to {$this->host}:{$this->port} is not established."
                        );
                    }
                }
            } else {
                throw new IOException("Failed to read data from {$this->host}:{$this->port}.");
            }
        }
        return null;
    }

    /**
     * send
     *
     * Send the packet via Modbus
     *
     * @param string $packet
     */
    public function send($packet)
    {
        fwrite($this->streamSocket, $packet, strlen($packet));
        $this->statusMessages[] = 'Send';
    }

    /**
     * close
     *
     * Close the socket
     */
    public function close()
    {
        if (is_resource($this->streamSocket)) {
            fclose($this->streamSocket);
            $this->statusMessages[] = 'Disconnected';
        }
    }

    /**
     * Close socket it still open
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Convert float in seconds to array
     *
     * @param  float $secs
     * @return array {sec: ..., usec: ...}
     */
    private function secsToSecUsecArray($secs)
    {
        $remainder = $secs - floor($secs);

        return [
            'sec' => round($secs - $remainder),
            'usec' => round($remainder * 1e6),
        ];
    }

    /**
     * @return array
     */
    public function getStatusMessages()
    {
        return $this->statusMessages;
    }

}


class ModbusSocketBuilder extends ModbusSocket
{
    /**
     * @var ModbusSocket instance to be built
     */
    private $modbusSocket;

    public function __construct()
    {
        $this->modbusSocket = new ModbusSocket();
    }

    /**
     * Return built instance of ModbusSocket
     *
     * @return ModbusSocket built instance
     */
    public function build()
    {
        return $this->modbusSocket;
    }

    /**
     * @param string $client
     * @return ModbusSocketBuilder
     */
    public function setClient($client)
    {
        $this->modbusSocket->client = $client;
        return $this;
    }

    /**
     * @param string $client_port
     * @return ModbusSocketBuilder
     */
    public function setClientPort($client_port)
    {
        $this->modbusSocket->client_port = $client_port;
        return $this;
    }

    /**
     * @param float $timeout_sec
     * @return ModbusSocketBuilder
     */
    public function setTimeoutSec($timeout_sec)
    {
        $this->modbusSocket->timeout_sec = $timeout_sec;
        return $this;
    }

    /**
     * @param float $socket_read_timeout_sec
     * @return ModbusSocketBuilder
     */
    public function setSocketReadTimeoutSec($socket_read_timeout_sec)
    {
        $this->modbusSocket->socket_read_timeout_sec = $socket_read_timeout_sec;
        return $this;
    }

    /**
     * @param float $socket_write_timeout_sec
     * @return ModbusSocketBuilder
     */
    public function setSocketWriteTimeoutSec($socket_write_timeout_sec)
    {
        $this->modbusSocket->socket_write_timeout_sec = $socket_write_timeout_sec;
        return $this;
    }

    /**
     * @param string $socket_protocol
     * @return ModbusSocketBuilder
     */
    public function setSocketProtocol($socket_protocol)
    {
        $this->modbusSocket->socket_protocol = $socket_protocol;
        return $this;
    }

    /**
     * @param string $host
     * @return ModbusSocketBuilder
     */
    public function setHost($host)
    {
        $this->modbusSocket->host = $host;
        return $this;
    }

    /**
     * @param string $port
     * @return ModbusSocketBuilder
     */
    public function setPort($port)
    {
        $this->modbusSocket->port = $port;
        return $this;
    }

    /**
     * @param float $socket_connect_timeout_sec
     * @return ModbusSocketBuilder
     */
    public function setSocketConnectTimeoutSec($socket_connect_timeout_sec)
    {
        $this->modbusSocket->socket_connect_timeout_sec = $socket_connect_timeout_sec;
        return $this;
    }

}
