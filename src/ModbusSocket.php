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
     * @var float Socket read timeout (seconds, decimals allowed)
     */
    protected $socket_read_timeout_sec = 0.3;
    /**
     * @var float Socket write timeout (seconds, decimals allowed)
     */
    protected $socket_write_timeout_sec = 1; // 300 ms
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
    protected $sock;
    /**
     * @var array status messages
     */
    protected $statusMessages = [];

    public static function getBuilder() {
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
        // Create a protocol specific socket
        if ($this->socket_protocol === 'TCP') {
            // TCP socket
            $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        } elseif ($this->socket_protocol === 'UDP') {
            // UDP socket
            $this->sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        } else {
            throw new InvalidArgumentException("Unknown socket protocol, should be 'TCP' or 'UDP'");
        }
        // Bind the client socket to a specific local port
        if (strlen($this->client) > 0) {
            $result = socket_bind($this->sock, $this->client, $this->client_port);
            if ($result === false) {
                throw new IOException(
                    "socket_bind() failed. Reason: ($result)" .
                    socket_strerror(socket_last_error($this->sock))
                );
            } else {
                $this->statusMessages[] = 'Bound';
            }
        }

        // Socket settings (send/write timeout)
        $writeTimeout = $this->secsToSecUsecArray($this->socket_write_timeout_sec);
        socket_set_option($this->sock, SOL_SOCKET, SO_SNDTIMEO, $writeTimeout);

        // Connect the socket
        $result = @socket_connect($this->sock, $this->host, $this->port);
        if ($result === false) {
            throw new IOException(
                "socket_connect() failed. Reason: ($result)" .
                socket_strerror(socket_last_error($this->sock))
            );
        } else {
            $this->statusMessages[] = 'Connected';
            return true;
        }
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
        socket_set_nonblock($this->sock);
        $readsocks[] = $this->sock;
        $writesocks = null;
        $exceptsocks = null;
        $rec = '';
        $totalReadTimeout = $this->timeout_sec;
        $lastAccess = microtime(true);
        $readTout = $this->secsToSecUsecArray($this->socket_read_timeout_sec);

        while (false !== socket_select($readsocks, $writesocks, $exceptsocks, $readTout['sec'], $readTout['usec'])) {
            $this->statusMessages[] = 'Wait data ... ';
            if (in_array($this->sock, $readsocks, false)) {
                if (@socket_recv($this->sock, $rec, 2000, 0)) { // read max 2000 bytes
                    $this->statusMessages[] = 'Data received';
                    return $rec;
                }
                $lastAccess = microtime(true);
            } else {
                $timeSpentWaiting = microtime(true) - $lastAccess;
                if ($timeSpentWaiting >= $totalReadTimeout) {
                    throw new IOException(
                        "Watchdog time expired [ $totalReadTimeout sec ]!!! " .
                        "Connection to $this->host:$this->port is not established."
                    );
                }
            }
            $readsocks[] = $this->sock;
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
        socket_write($this->sock, $packet, strlen($packet));
        $this->statusMessages[] = 'Send';
    }

    /**
     * close
     *
     * Close the socket
     */
    public function close()
    {
        if (is_resource($this->sock)) {
            socket_close($this->sock);
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

}
