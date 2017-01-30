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
namespace PHPModbus\Network;

use InvalidArgumentException;

class ModbusConnection extends ModbusConnectionProperties
{
    /**
     * @var resource Communication socket
     */
    private $streamSocket;
    /**
     * @var array status messages
     */
    protected $statusMessages = [];


    public function __construct(ModbusConnectionBuilder $builder)
    {
        $this->host = $builder->getHost();
        $this->port = $builder->getPort();
        $this->client = $builder->getClient();
        $this->clientPort = $builder->getClientPort();
        $this->timeoutSec = $builder->getTimeoutSec();
        $this->connectTimeoutSec = $builder->getConnectTimeoutSec();
        $this->readTimeoutSec = $builder->getReadTimeoutSec();
        $this->writeTimeoutSec = $builder->getWriteTimeoutSec();
        $this->protocol = $builder->getProtocol();
    }

    public static function getBuilder()
    {
        return new ModbusConnectionBuilder();
    }

    /**
     * connect
     *
     * Connect the socket
     *
     * @return bool
     * @throws \InvalidArgumentException
     * @throws \PHPModbus\Network\IOException
     */
    public function connect()
    {
        $protocol = null;
        switch ($this->protocol) {
            case 'TCP':
            case 'UDP':
                $protocol = strtolower($this->protocol);
                break;
            default:
                throw new InvalidArgumentException("Unknown socket protocol, should be 'TCP' or 'UDP'");
        }

        $opts = [];
        if (strlen($this->client) > 0) {
            // Bind the client stream to a specific local port
            $opts = array(
                'socket' => array(
                    'bindto' => "{$this->client}:{$this->clientPort}",
                ),
            );
        }
        $context = stream_context_create($opts);

        $this->streamSocket = @stream_socket_client(
            "$protocol://$this->host:$this->port",
            $errno,
            $errstr,
            $this->connectTimeoutSec,
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

        $writeTimeoutParts = $this->secsToSecUsecArray($this->writeTimeoutSec);
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
        $totalReadTimeout = $this->timeoutSec;
        $lastAccess = microtime(true);

        $readTimeout = $this->secsToSecUsecArray($this->readTimeoutSec);
        while (true) {
            $read = array($this->streamSocket);
            $write = null;
            $except = null;
            $data = '';
            if (false !== stream_select($read, $write, $except, $readTimeout['sec'], $readTimeout['usec'])) {
                $this->statusMessages[] = 'Wait data ... ';

                if (in_array($this->streamSocket, $read, false)) {
                    $data .= fread($this->streamSocket, 2048); // read max 2048 bytes
                    if (!empty($data)) {
                        $this->statusMessages[] = 'Data received';
                        return $data;
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