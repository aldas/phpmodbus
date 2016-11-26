<?php

namespace PHPModbus;

use Exception;
use InvalidArgumentException;

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


/**
 * ModbusMaster
 *
 * This class deals with the MODBUS master
 *
 * Implemented MODBUS master functions:
 *   - FC  1: read coils
 *   - FC  2: read input discretes
 *   - FC  3: read multiple registers
 *   - FC  4: read multiple input registers
 *   - FC  5: write single coil
 *   - FC  6: write single register
 *   - FC 15: write multiple coils
 *   - FC 16: write multiple registers
 *   - FC 22: mask write register
 *   - FC 23: read write registers
 *
 * @author    Jan Krakora
 * @copyright Copyright (c) 2004, 2013 Jan Krakora
 * @package   Phpmodbus
 */
class ModbusMaster
{
    /**
     *
     *
     * @var string Modbus device IP address
     */
    public $host = '192.168.1.1';
    /**
     *
     *
     * @var string gateway port
     */
    public $port = 502;
    /**
     * @var string (optional) client IP address when binding client
     */
    public $client = '';
    /**
     *
     *
     * @var string client port set when binding client to local ip&port
     */
    public $client_port = 502;
    /**
     *
     *
     * @var string ModbusMaster status messages (echo for debugging)
     */
    public $status;
    /**
     *
     *
     * @var float Total response timeout (seconds, decimals allowed)
     */
    public $timeout_sec = 5;
    /**
     * @var float Socket read timeout (seconds, decimals allowed)
     */
    public $socket_read_timeout_sec = 0.3;
    /**
     *
     *
     * @var float Socket write timeout (seconds, decimals allowed)
     */
    public $socket_write_timeout_sec = 1; // 300 ms
    /**
     * @var int Endianness codding (0 = little endian = 0, 1 = big endian)
     */
    public $endianness = 0;
    /**
     *
     *
     * @var string Socket protocol (TCP, UDP)
     */
    public $socket_protocol = 'UDP'; //
    /**
     *
     *
     * @var resource Communication socket
     */
    private $sock;

    /**
     * ModbusMaster
     *
     * This is the constructor that defines {@link $host} IP address of the object.
     *
     * @param String $host An IP address of a Modbus TCP device. E.g. "192.168.1.1"
     * @param String $protocol Socket protocol (TCP, UDP)
     */
    public function __construct($host, $protocol)
    {
        $this->socket_protocol = $protocol;
        $this->host = $host;
    }

    /**
     * __toString
     *
     * Magic method
     */
    public function __toString()
    {
        return '<pre>' . $this->status . '</pre>';
    }

    /**
     * fc1
     *
     * Alias to {@link readCoils} method
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return bool[]
     * @throws \Exception
     */
    public function fc1($unitId, $reference, $quantity)
    {
        return $this->readCoils($unitId, $reference, $quantity);
    }

    /**
     * readCoils
     *
     * Modbus function FC 1(0x01) - Read Coils
     *
     * Reads {@link $quantity} of Coils (boolean) from reference
     * {@link $reference} of a memory of a Modbus device given by
     * {@link $unitId}.
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return bool[]
     * @throws \Exception
     */
    public function readCoils($unitId, $reference, $quantity)
    {
        $this->status .= "readCoils: START\n";

        $receivedData = $this->sendAndReceive(
            function () use ($unitId, $reference, $quantity) {
                return $this->readCoilsPacketBuilder($unitId, $reference, $quantity);
            },
            function ($data) use ($quantity) {
                return $this->readCoilsParser($data, $quantity);
            }
        );

        $this->status .= "readCoils: DONE\n";
        return $receivedData;
    }

    /**
     * connect
     *
     * Connect the socket
     *
     * @return bool
     * @throws Exception
     */
    private function connect()
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
                $this->status .= "Bound\n";
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
            $this->status .= "Connected\n";
            return true;
        }
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
     * readCoilsPacketBuilder
     *
     * FC1 packet builder - read coils
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return string
     */
    private function readCoilsPacketBuilder($unitId, $reference, $quantity)
    {
        $dataLen = 0;
        // build data section
        $buffer1 = '';
        // build body
        $buffer2 = '';
        $buffer2 .= IecType::iecBYTE(1);              // FC 1 = 1(0x01)
        // build body - read section
        $buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
        $buffer2 .= IecType::iecINT($quantity);       // quantity
        $dataLen += 5;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));   // transaction ID
        $buffer3 .= IecType::iecINT(0);               // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);    // length
        $buffer3 .= IecType::iecBYTE($unitId);        //unit ID
        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * printPacket
     *
     * Print a packet in the hex form
     *
     * @param  string $packet
     * @return string
     */
    private function printPacket($packet)
    {
        $str = 'Packet: ';
        for ($i = 0, $len = strlen($packet); $i < $len; $i++) {
            $str .= $this->byte2hex(ord($packet[$i]));
        }
        $str .= "\n";
        return $str;
    }

    /**
     * byte2hex
     *
     * Parse data and get it to the Hex form
     *
     * @param  int $value
     * @return string
     */
    private function byte2hex($value)
    {
        $h = dechex(($value >> 4) & 0x0F);
        $l = dechex($value & 0x0F);
        return "$h$l";
    }

    /**
     * send
     *
     * Send the packet via Modbus
     *
     * @param string $packet
     */
    private function send($packet)
    {
        socket_write($this->sock, $packet, strlen($packet));
        $this->status .= "Send\n";
    }

    /**
     * rec
     *
     * Receive data from the socket
     *
     * @return bool
     * @throws Exception
     */
    private function rec()
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
            $this->status .= "Wait data ... \n";
            if (in_array($this->sock, $readsocks)) {
                if (@socket_recv($this->sock, $rec, 2000, 0)) { // read max 2000 bytes
                    $this->status .= "Data received \n";
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
     * readCoilsParser
     *
     * FC 1 response parser
     *
     * @param  string $packet
     * @param  int $quantity
     * @return bool[]
     * @throws \Exception
     */
    private function readCoilsParser($packet, $quantity)
    {
        $data = array();
        // check Response code
        $this->responseCode($packet);
        // get data from stream
        for ($i = 0, $len = ord($packet[8]); $i < $len; $i++) {
            $data[$i] = ord($packet[9 + $i]);
        }
        // get bool values to array
        $data_boolean_array = array();
        $di = 0;
        foreach ($data as $value) {
            for ($i = 0; $i < 8; $i++) {
                if ($di == $quantity) {
                    continue;
                }
                // get boolean value
                $v = ($value >> $i) & 0x01;
                // build boolean array
                if ($v == 0) {
                    $data_boolean_array[] = false;
                } else {
                    $data_boolean_array[] = true;
                }
                $di++;
            }
        }
        return $data_boolean_array;
    }

    /**
     * responseCode
     *
     * Check the Modbus response code
     *
     * @param  string $packet
     * @return bool
     * @throws Exception
     */
    private function responseCode($packet)
    {
        if ((ord($packet[7]) & 0x80) > 0) {
            // failure code
            $failure_code = ord($packet[8]);
            // failure code strings
            $failures = array(
                0x01 => 'ILLEGAL FUNCTION',
                0x02 => 'ILLEGAL DATA ADDRESS',
                0x03 => 'ILLEGAL DATA VALUE',
                0x04 => 'SLAVE DEVICE FAILURE',
                0x05 => 'ACKNOWLEDGE',
                0x06 => 'SLAVE DEVICE BUSY',
                0x08 => 'MEMORY PARITY ERROR',
                0x0A => 'GATEWAY PATH UNAVAILABLE',
                0x0B => 'GATEWAY TARGET DEVICE FAILED TO RESPOND',
            );
            // get failure string
            if (array_key_exists($failure_code, $failures)) {
                $failure_str = $failures[$failure_code];
            } else {
                $failure_str = 'UNDEFINED FAILURE CODE';
            }
            // exception response
            throw new ModbusException("Modbus response error code: $failure_code ($failure_str)");
        } else {
            $this->status .= "Modbus response error code: NOERROR\n";
            return true;
        }
    }

    /**
     * disconnect
     *
     * Disconnect the socket
     */
    protected function disconnect()
    {
        if (is_resource($this->sock)) {
            socket_close($this->sock);
            $this->status .= "Disconnected\n";
        }
    }


    /**
     * Close socket it still open
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * fc2
     *
     * Alias to {@link readInputDiscretes} method
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return bool[]
     * @throws \Exception
     */
    public function fc2($unitId, $reference, $quantity)
    {
        return $this->readInputDiscretes($unitId, $reference, $quantity);
    }

    /**
     * readInputDiscretes
     *
     * Modbus function FC 2(0x02) - Read Input Discretes
     *
     * Reads {@link $quantity} of Inputs (boolean) from reference
     * {@link $reference} of a memory of a Modbus device given by
     * {@link $unitId}.
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return bool[]
     * @throws \Exception
     */
    public function readInputDiscretes($unitId, $reference, $quantity)
    {
        $this->status .= "readInputDiscretes: START\n";

        $receivedData = $this->sendAndReceive(
            function () use ($unitId, $reference, $quantity) {
                return $this->readInputDiscretesPacketBuilder($unitId, $reference, $quantity);
            },
            function ($data) use ($quantity) {
                return $this->readInputDiscretesParser($data, $quantity);
            }
        );

        $this->status .= "readInputDiscretes: DONE\n";
        return $receivedData;
    }

    /**
     * readInputDiscretesPacketBuilder
     *
     * FC2 packet builder - read coils
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return string
     */
    private function readInputDiscretesPacketBuilder($unitId, $reference, $quantity)
    {
        $dataLen = 0;
        // build data section
        $buffer1 = '';
        // build body
        $buffer2 = '';
        $buffer2 .= IecType::iecBYTE(2);              // FC 2 = 2(0x02)
        // build body - read section
        $buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
        $buffer2 .= IecType::iecINT($quantity);       // quantity
        $dataLen += 5;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));   // transaction ID
        $buffer3 .= IecType::iecINT(0);               // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
        $buffer3 .= IecType::iecBYTE($unitId);        //unit ID
        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * readInputDiscretesParser
     *
     * FC 2 response parser, alias to FC 1 parser i.e. readCoilsParser.
     *
     * @param  string $packet
     * @param  int $quantity
     * @return bool[]
     * @throws \Exception
     */
    private function readInputDiscretesParser($packet, $quantity)
    {
        return $this->readCoilsParser($packet, $quantity);
    }

    /**
     * fc3
     *
     * Alias to {@link readMultipleRegisters} method.
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return false|array
     * @throws \Exception
     */
    public function fc3($unitId, $reference, $quantity)
    {
        return $this->readMultipleRegisters($unitId, $reference, $quantity);
    }

    /**
     * readMultipleRegisters
     *
     * Modbus function FC 3(0x03) - Read Multiple Registers.
     *
     * This function reads {@link $quantity} of Words (2 bytes) from reference
     * {@link $referenceRead} of a memory of a Modbus device given by
     * {@link $unitId}.
     *
     * @param  int $unitId usually ID of Modbus device
     * @param  int $reference Reference in the device memory to read data (e.g. in device WAGO 750-841, memory MW0
     *                       starts at address 12288).
     * @param  int $quantity Amounth of the data to be read from device.
     * @return false|array Success flag or array of received data.
     * @throws \Exception
     */
    public function readMultipleRegisters($unitId, $reference, $quantity)
    {
        $this->status .= "readMultipleRegisters: START\n";

        $receivedData = $this->sendAndReceive(
            function () use ($unitId, $reference, $quantity) {
                return $this->readMultipleRegistersPacketBuilder($unitId, $reference, $quantity);
            },
            function ($data) {
                return $this->readMultipleRegistersParser($data);
            }
        );

        $this->status .= "readMultipleRegisters: DONE\n";
        return $receivedData;
    }

    /**
     * readMultipleRegistersPacketBuilder
     *
     * Packet FC 3 builder - read multiple registers
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return string
     */
    private function readMultipleRegistersPacketBuilder($unitId, $reference, $quantity)
    {
        $dataLen = 0;
        // build data section
        $buffer1 = '';
        // build body
        $buffer2 = '';
        $buffer2 .= IecType::iecBYTE(3);             // FC 3 = 3(0x03)
        // build body - read section
        $buffer2 .= IecType::iecINT($reference);  // refnumber = 12288
        $buffer2 .= IecType::iecINT($quantity);       // quantity
        $dataLen += 5;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));   // transaction ID
        $buffer3 .= IecType::iecINT(0);               // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);    // length
        $buffer3 .= IecType::iecBYTE($unitId);        //unit ID
        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * readMultipleRegistersParser
     *
     * FC 3 response parser
     *
     * @param  string $packet
     * @return array
     * @throws \Exception
     */
    private function readMultipleRegistersParser($packet)
    {
        $data = array();
        // check Response code
        $this->responseCode($packet);
        // get data
        for ($i = 0, $len = ord($packet[8]); $i < $len; $i++) {
            $data[$i] = ord($packet[9 + $i]);
        }
        return $data;
    }

    /**
     * fc4
     *
     * Alias to {@link readMultipleInputRegisters} method.
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return false|array
     * @throws \Exception
     */
    public function fc4($unitId, $reference, $quantity)
    {
        return $this->readMultipleInputRegisters($unitId, $reference, $quantity);
    }

    /**
     * readMultipleInputRegisters
     *
     * Modbus function FC 4(0x04) - Read Multiple Input Registers.
     *
     * This function reads {@link $quantity} of Words (2 bytes) from reference
     * {@link $referenceRead} of a memory of a Modbus device given by
     * {@link $unitId}.
     *
     * @param  int $unitId usually ID of Modbus device
     * @param  int $reference Reference in the device memory to read data.
     * @param  int $quantity Amounth of the data to be read from device.
     * @return false|array Success flag or array of received data.
     * @throws \Exception
     */
    public function readMultipleInputRegisters($unitId, $reference, $quantity)
    {
        $this->status .= "readMultipleInputRegisters: START\n";

        $receivedData = $this->sendAndReceive(
            function () use ($unitId, $reference, $quantity) {
                return $this->readMultipleInputRegistersPacketBuilder($unitId, $reference, $quantity);
            },
            function ($data) {
                return $this->readMultipleInputRegistersParser($data);
            }
        );

        $this->status .= "readMultipleInputRegisters: DONE\n";
        return $receivedData;
    }

    /**
     * readMultipleInputRegistersPacketBuilder
     *
     * Packet FC 4 builder - read multiple input registers
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $quantity
     * @return string
     */
    private function readMultipleInputRegistersPacketBuilder($unitId, $reference, $quantity)
    {
        $dataLen = 0;
        // build data section
        $buffer1 = '';
        // build body
        $buffer2 = '';
        $buffer2 .= IecType::iecBYTE(4);                                                // FC 4 = 4(0x04)
        // build body - read section
        $buffer2 .= IecType::iecINT($reference);                                        // refnumber = 12288
        $buffer2 .= IecType::iecINT($quantity);                                         // quantity
        $dataLen += 5;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));                                     // transaction ID
        $buffer3 .= IecType::iecINT(0);                                                 // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);                                      // length
        $buffer3 .= IecType::iecBYTE($unitId);                                          // unit ID
        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * readMultipleInputRegistersParser
     *
     * FC 4 response parser
     *
     * @param  string $packet
     * @return array
     * @throws \Exception
     */
    private function readMultipleInputRegistersParser($packet)
    {
        $data = array();
        // check Response code
        $this->responseCode($packet);
        // get data
        for ($i = 0, $len = ord($packet[8]); $i < $len; $i++) {
            $data[$i] = ord($packet[9 + $i]);
        }
        return $data;
    }

    /**
     * fc5
     *
     * Alias to {@link writeSingleCoil} method
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @return bool
     * @throws \Exception
     */
    public function fc5($unitId, $reference, array $data)
    {
        return $this->writeSingleCoil($unitId, $reference, $data);
    }

    /**
     * writeSingleCoil
     *
     * Modbus function FC5(0x05) - Write Single Register.
     *
     * This function writes {@link $data} single coil at {@link $reference} position of
     * memory of a Modbus device given by {@link $unitId}.
     *
     * @param  int $unitId usually ID of Modbus device
     * @param  int $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at address 12288)
     *                         address 12288)
     * @param  array $data value to be written (TRUE|FALSE).
     * @return bool Success flag
     * @throws \Exception
     */
    public function writeSingleCoil($unitId, $reference, array $data)
    {
        $this->status .= "writeSingleCoil: START\n";

        $receivedData = $this->sendAndReceive(
            function () use ($unitId, $reference, $data) {
                return $this->writeSingleCoilPacketBuilder($unitId, $reference, $data);
            },
            function ($data) {
                return $this->writeSingleCoilParser($data);
            }
        );

        $this->status .= "writeSingleCoil: DONE\n";
        return $receivedData;
    }

    /**
     * writeSingleCoilPacketBuilder
     *
     * Packet builder FC5 - WRITE single register
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @return string
     */
    private function writeSingleCoilPacketBuilder($unitId, $reference, array $data)
    {
        $dataLen = 0;
        // build data section
        $buffer1 = '';
        foreach ($data as $key => $dataitem) {
            if ($dataitem == true) {
                $buffer1 = IecType::iecINT(0xFF00);
            } else {
                $buffer1 = IecType::iecINT(0x0000);
            }
        }
        $dataLen += 2;
        // build body
        $buffer2 = '';
        $buffer2 .= IecType::iecBYTE(5);             // FC5 = 5(0x05)
        $buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
        $dataLen += 3;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));   // transaction ID
        $buffer3 .= IecType::iecINT(0);               // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
        $buffer3 .= IecType::iecBYTE($unitId);        //unit ID

        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * writeSingleCoilParser
     *
     * FC5 response parser
     *
     * @param  string $packet
     * @return bool
     * @throws \Exception
     */
    private function writeSingleCoilParser($packet)
    {
        $this->responseCode($packet);
        return true;
    }

    /**
     * fc6
     *
     * Alias to {@link writeSingleRegister} method
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @return bool
     * @throws \Exception
     */
    public function fc6($unitId, $reference, array $data)
    {
        return $this->writeSingleRegister($unitId, $reference, $data);
    }

    /**
     * writeSingleRegister
     *
     * Modbus function FC6(0x06) - Write Single Register.
     *
     * This function writes {@link $data} single word value at {@link $reference} position of
     * memory of a Modbus device given by {@link $unitId}.
     *
     * @param  int $unitId usually ID of Modbus device
     * @param  int $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at address 12288)
     *                         address 12288)
     * @param  array $data Array of values to be written.
     * @return bool Success flag
     * @throws \Exception
     */
    public function writeSingleRegister($unitId, $reference, array $data)
    {
        $this->status .= "writeSingleRegister: START\n";
        $result = $this->sendAndReceive(
            function () use ($unitId, $reference, $data) {
                return $this->writeSingleRegisterPacketBuilder($unitId, $reference, $data);
            },
            function ($data) {
                return $this->writeSingleRegisterParser($data);
            }
        );
        $this->status .= "writeSingleRegister: DONE\n";
        return $result;
    }

    public function sendAndReceive(callable $buildRequest, callable $parseResponse)
    {
        try {
            $this->connect();
            $packet = $buildRequest();

            $this->send($packet);
            $data = $this->rec();

            $this->status .= $this->printPacket($data);
            return $parseResponse($data);
        } finally {
            $this->disconnect();
        }

    }

    /**
     * writeSingleRegisterPacketBuilder
     *
     * Packet builder FC6 - WRITE single register
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @return string
     */
    private function writeSingleRegisterPacketBuilder($unitId, $reference, array $data)
    {
        $dataLen = 0;
        // build data section
        $buffer1 = '';
        foreach ($data as $key => $dataitem) {
            $buffer1 .= IecType::iecINT($dataitem);   // register values x
            $dataLen += 2;
            break;
        }
        // build body
        $buffer2 = '';
        $buffer2 .= IecType::iecBYTE(6);             // FC6 = 6(0x06)
        $buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
        $dataLen += 3;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));   // transaction ID
        $buffer3 .= IecType::iecINT(0);               // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);    // length
        $buffer3 .= IecType::iecBYTE($unitId);        //unit ID

        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * writeSingleRegisterParser
     *
     * FC6 response parser
     *
     * @param  string $packet
     * @return bool
     * @throws \Exception
     */
    private function writeSingleRegisterParser($packet)
    {
        $this->responseCode($packet);
        return true;
    }

    /**
     * fc15
     *
     * Alias to {@link writeMultipleCoils} method
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @return bool
     * @throws \Exception
     */
    public function fc15($unitId, $reference, array $data)
    {
        return $this->writeMultipleCoils($unitId, $reference, $data);
    }

    /**
     * writeMultipleCoils
     *
     * Modbus function FC15(0x0F) - Write Multiple Coils
     *
     * This function writes {@link $data} array at {@link $reference} position of
     * memory of a Modbus device given by {@link $unitId}.
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @return bool
     * @throws \Exception
     */
    public function writeMultipleCoils($unitId, $reference, array $data)
    {
        $this->status .= "writeMultipleCoils: START\n";

        $receivedData = $this->sendAndReceive(
            function () use ($unitId, $reference, $data) {
                return $this->writeMultipleCoilsPacketBuilder($unitId, $reference, $data);
            },
            function ($data) {
                return $this->writeMultipleCoilsParser($data);
            }
        );

        $this->status .= "writeMultipleCoils: DONE\n";
        return $receivedData;
    }

    /**
     * writeMultipleCoilsPacketBuilder
     *
     * Packet builder FC15 - Write multiple coils
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @return string
     */
    private function writeMultipleCoilsPacketBuilder($unitId, $reference, array $data)
    {
        $dataLen = 0;
        // build bool stream to the WORD array
        $data_word_stream = array();
        $data_word = 0;
        $shift = 0;
        for ($i = 0, $len = count($data); $i < $len; $i++) {
            if ((($i % 8) === 0) && ($i > 0)) {
                $data_word_stream[] = $data_word;
                $shift = 0;
                $data_word = 0;
                $data_word |= (0x01 && $data[$i]) << $shift;
                $shift++;
            } else {
                $data_word |= (0x01 && $data[$i]) << $shift;
                $shift++;
            }
        }
        $data_word_stream[] = $data_word;
        // show binary stream to status string
        foreach ($data_word_stream as $d) {
            $this->status .= sprintf("byte=b%08b\n", $d);
        }
        // build data section
        $buffer1 = "";
        foreach ($data_word_stream as $key => $dataitem) {
            $buffer1 .= IecType::iecBYTE($dataitem);   // register values x
            $dataLen += 1;
        }
        // build body
        $buffer2 = "";
        $buffer2 .= IecType::iecBYTE(15);             // FC 15 = 15(0x0f)
        $buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
        $buffer2 .= IecType::iecINT(count($data));      // bit count
        $buffer2 .= IecType::iecBYTE((count($data) + 7) / 8);       // byte count
        $dataLen += 6;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));   // transaction ID
        $buffer3 .= IecType::iecINT(0);               // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);    // length
        $buffer3 .= IecType::iecBYTE($unitId);        // unit ID

        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * writeMultipleCoilsParser
     *
     * FC15 response parser
     *
     * @param  string $packet
     * @return bool
     * @throws \Exception
     */
    private function writeMultipleCoilsParser($packet)
    {
        $this->responseCode($packet);
        return true;
    }

    /**
     * fc16
     *
     * Alias to {@link writeMultipleRegister} method
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @param  array $dataTypes
     * @return bool
     * @throws \Exception
     */
    public function fc16($unitId, $reference, array $data, array $dataTypes)
    {
        return $this->writeMultipleRegister($unitId, $reference, $data, $dataTypes);
    }

    /**
     * writeMultipleRegister
     *
     * Modbus function FC16(0x10) - Write Multiple Register.
     *
     * This function writes {@link $data} array at {@link $reference} position of
     * memory of a Modbus device given by {@link $unitId}.
     *
     * @param  int $unitId usually ID of Modbus device
     * @param  int $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at address 12288)
     *                         address 12288)
     * @param  array $data Array of values to be written.
     * @param  array $dataTypes Array of types of values to be written. The array should consists of string "INT",
     *                         "DINT" and "REAL".
     * @return bool Success flag
     * @throws \Exception
     */
    public function writeMultipleRegister($unitId, $reference, array $data, array $dataTypes)
    {
        $this->status .= "writeMultipleRegister: START\n";

        $receivedData = $this->sendAndReceive(
            function () use ($unitId, $reference, $data, $dataTypes) {
                return $this->writeMultipleRegisterPacketBuilder($unitId, $reference, $data, $dataTypes);
            },
            function ($data) {
                return $this->writeMultipleRegisterParser($data);
            }
        );

        $this->status .= "writeMultipleRegister: DONE\n";
        return $receivedData;
    }

    /**
     * writeMultipleRegisterPacketBuilder
     *
     * Packet builder FC16 - WRITE multiple register
     *     e.g.: 4dd90000000d0010300000030603e807d00bb8
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @param  array $dataTypes
     * @return string
     */
    private function writeMultipleRegisterPacketBuilder($unitId, $reference, array $data, array $dataTypes)
    {
        $dataLen = 0;
        // build data section
        $buffer1 = "";
        foreach ($data as $key => $dataitem) {
            if ($dataTypes[$key] === 'INT') {
                $buffer1 .= IecType::iecINT($dataitem);   // register values x
                $dataLen += 2;
            } elseif ($dataTypes[$key] === 'DINT') {
                $buffer1 .= IecType::iecDINT($dataitem, $this->endianness);   // register values x
                $dataLen += 4;
            } elseif ($dataTypes[$key] === 'REAL') {
                $buffer1 .= IecType::iecREAL($dataitem, $this->endianness);   // register values x
                $dataLen += 4;
            } else {
                $buffer1 .= IecType::iecINT($dataitem);   // register values x
                $dataLen += 2;
            }
        }
        // build body
        $buffer2 = '';
        $buffer2 .= IecType::iecBYTE(16);             // FC 16 = 16(0x10)
        $buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
        $buffer2 .= IecType::iecINT($dataLen / 2);        // word count
        $buffer2 .= IecType::iecBYTE($dataLen);     // byte count
        $dataLen += 6;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));   // transaction ID
        $buffer3 .= IecType::iecINT(0);               // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);    // length
        $buffer3 .= IecType::iecBYTE($unitId);        //unit ID

        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * writeMultipleRegisterParser
     *
     * FC16 response parser
     *
     * @param  string $packet
     * @return bool
     * @throws \Exception
     */
    private function writeMultipleRegisterParser($packet)
    {
        $this->responseCode($packet);
        return true;
    }

    /**
     * fc22
     *
     * Alias to {@link maskWriteRegister} method
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $andMask
     * @param  int $orMask
     * @return bool
     * @throws \Exception
     */
    public function fc22($unitId, $reference, $andMask, $orMask)
    {
        return $this->maskWriteRegister($unitId, $reference, $andMask, $orMask);
    }

    /**
     * maskWriteRegister
     *
     * Modbus function FC22(0x16) - Mask Write Register.
     *
     * This function alter single bit(s) at {@link $reference} position of
     * memory of a Modbus device given by {@link $unitId}.
     *
     * Result = (Current Contents AND And_Mask) OR (Or_Mask AND (NOT And_Mask))
     *
     * @param  int $unitId usually ID of Modbus device
     * @param  int $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at address
     *                       12288)
     * @param  int $andMask
     * @param  int $orMask
     * @return bool Success flag
     * @throws \Exception
     */
    public function maskWriteRegister($unitId, $reference, $andMask, $orMask)
    {
        $this->status .= "maskWriteRegister: START\n";

        $receivedData = $this->sendAndReceive(
            function () use ($unitId, $reference, $andMask, $orMask) {
                return $this->maskWriteRegisterPacketBuilder($unitId, $reference, $andMask, $orMask);
            },
            function ($data) {
                return $this->maskWriteRegisterParser($data);
            }
        );

        $this->status .= "maskWriteRegister: DONE\n";
        return $receivedData;
    }

    /**
     * maskWriteRegisterPacketBuilder
     *
     * Packet builder FC22 - MASK WRITE register
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  int $andMask
     * @param  int $orMask
     * @return string
     */
    private function maskWriteRegisterPacketBuilder($unitId, $reference, $andMask, $orMask)
    {
        $dataLen = 0;
        // build data section
        $buffer1 = '';
        // build body
        $buffer2 = '';
        $buffer2 .= IecType::iecBYTE(22);             // FC 22 = 22(0x16)
        $buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
        $buffer2 .= IecType::iecINT($andMask);        // AND mask
        $buffer2 .= IecType::iecINT($orMask);          // OR mask
        $dataLen += 7;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));   // transaction ID
        $buffer3 .= IecType::iecINT(0);               // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
        $buffer3 .= IecType::iecBYTE($unitId);        //unit ID
        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * maskWriteRegisterParser
     *
     * FC22 response parser
     *
     * @param  string $packet
     * @return bool
     * @throws \Exception
     */
    private function maskWriteRegisterParser($packet)
    {
        $this->responseCode($packet);
        return true;
    }

    /**
     * fc23
     *
     * Alias to {@link readWriteRegisters} method.
     *
     * @param  int $unitId
     * @param  int $referenceRead
     * @param  int $quantity
     * @param  int $referenceWrite
     * @param  array $data
     * @param  array $dataTypes
     * @return false|array
     * @throws \Exception
     */
    public function fc23($unitId, $referenceRead, $quantity, $referenceWrite, array $data, array $dataTypes)
    {
        return $this->readWriteRegisters($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes);
    }

    /**
     * readWriteRegisters
     *
     * Modbus function FC23(0x17) - Read Write Registers.
     *
     * This function writes {@link $data} array at reference {@link $referenceWrite}
     * position of memory of a Modbus device given by {@link $unitId}. Simultanously,
     * it returns {@link $quantity} of Words (2 bytes) from reference {@link $referenceRead}.
     *
     * @param  int $unitId usually ID of Modbus device
     * @param  int $referenceRead Reference in the device memory to read data (e.g. in device WAGO 750-841, memory MW0 starts at address 12288). MW0 starts at address 12288).
     *                              MW0 starts at address 12288).
     * @param  int $quantity Amounth of the data to be read from device.
     * @param  int $referenceWrite Reference in the device memory to write data.
     * @param  array $data Array of values to be written.
     * @param  array $dataTypes Array of types of values to be written. The array should consists of string "INT", "DINT" and "REAL".
     *                              "DINT" and "REAL".
     * @return false|array Success flag or array of data.
     * @throws \Exception
     */
    public function readWriteRegisters($unitId, $referenceRead, $quantity, $referenceWrite, array $data, array $dataTypes)
    {
        $this->status .= "readWriteRegisters: START\n";

        $receivedData = $this->sendAndReceive(
            function () use ($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes) {
                return $this->readWriteRegistersPacketBuilder(
                    $unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes
                );
            },
            function ($data) {
                return $this->readWriteRegistersParser($data);
            }
        );

        $this->status .= "writeMultipleRegister: DONE\n";
        return $receivedData;
    }

    /**
     * readWriteRegistersPacketBuilder
     *
     * Packet FC23 builder - READ WRITE registers
     *
     * @param  int $unitId
     * @param  int $referenceRead
     * @param  int $quantity
     * @param  int $referenceWrite
     * @param  array $data
     * @param  array $dataTypes
     * @return string
     */
    private function readWriteRegistersPacketBuilder(
        $unitId,
        $referenceRead,
        $quantity,
        $referenceWrite,
        array $data,
        array $dataTypes
    )
    {

        $dataLen = 0;
        // build data section
        $buffer1 = '';
        foreach ($data as $key => $dataitem) {
            if ($dataTypes[$key] === 'INT') {
                $buffer1 .= IecType::iecINT($dataitem);   // register values x
                $dataLen += 2;
            } elseif ($dataTypes[$key] === 'DINT') {
                $buffer1 .= IecType::iecDINT($dataitem, $this->endianness);   // register values x
                $dataLen += 4;
            } elseif ($dataTypes[$key] === 'REAL') {
                $buffer1 .= IecType::iecREAL($dataitem, $this->endianness);   // register values x
                $dataLen += 4;
            } else {
                $buffer1 .= IecType::iecINT($dataitem);   // register values x
                $dataLen += 2;
            }
        }
        // build body
        $buffer2 = '';
        $buffer2 .= IecType::iecBYTE(23);             // FC 23 = 23(0x17)
        // build body - read section
        $buffer2 .= IecType::iecINT($referenceRead);  // refnumber = 12288
        $buffer2 .= IecType::iecINT($quantity);       // quantity
        // build body - write section
        $buffer2 .= IecType::iecINT($referenceWrite); // refnumber = 12288
        $buffer2 .= IecType::iecINT($dataLen / 2);      // word count
        $buffer2 .= IecType::iecBYTE($dataLen);       // byte count
        $dataLen += 10;
        // build header
        $buffer3 = '';
        $buffer3 .= IecType::iecINT(mt_rand(0, 65000));   // transaction ID
        $buffer3 .= IecType::iecINT(0);               // protocol ID
        $buffer3 .= IecType::iecINT($dataLen + 1);    // length
        $buffer3 .= IecType::iecBYTE($unitId);        //unit ID

        // return packet string
        return $buffer3 . $buffer2 . $buffer1;
    }

    /**
     * readWriteRegistersParser
     *
     * FC23 response parser
     *
     * @param  string $packet
     * @return array|false
     * @throws \Exception
     */
    private function readWriteRegistersParser($packet)
    {
        $data = array();
        // if not exception
        if (!$this->responseCode($packet)) {
            return false;
        }
        // get data
        for ($i = 0, $len = ord($packet[8]); $i < $len; $i++) {
            $data[$i] = ord($packet[9 + $i]);
        }
        return $data;
    }

    /**
     * Set data receive timeout.
     * Writes property timeout_sec
     *
     * @param float $seconds seconds
     */
    public function setTimeout($seconds)
    {
        $this->timeout_sec = $seconds;
    }

    /**
     * Set socket read/write timeout. Null = no change.
     *
     * @param    float|null $read_timeout_sec data read timeout (seconds, default 0.3)
     * @param    float|null $write_timeout_sec data write timeout (seconds, default 1.0)
     * @internal param float $seconds seconds
     */
    public function setSocketTimeout($read_timeout_sec, $write_timeout_sec)
    {
        // Set read timeout if given
        if ($read_timeout_sec !== null) {
            $this->socket_read_timeout_sec = $read_timeout_sec;
        }

        // Set write timeout if given
        if ($write_timeout_sec !== null) {
            $this->socket_write_timeout_sec = $write_timeout_sec;
        }
    }
}
