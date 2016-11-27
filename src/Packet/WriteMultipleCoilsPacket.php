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

namespace PHPModbus\Packet;

use PHPModbus\IecType;

class WriteMultipleCoilsPacket
{
    /**
     * Packet builder FC15 - Write multiple coils
     *
     * @param  int $unitId
     * @param  int $reference
     * @param  array $data
     * @return string
     */
    public static function build($unitId, $reference, array $data)
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
//        foreach ($data_word_stream as $d) {
//            $this->status .= sprintf("byte=b%08b\n", $d);
//        }
        // build data section
        $buffer1 = '';
        foreach ($data_word_stream as $key => $dataitem) {
            $buffer1 .= IecType::iecBYTE($dataitem);   // register values x
            $dataLen += 1;
        }
        // build body
        $buffer2 = '';
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
     * FC15 response parser
     *
     * @return bool
     * @throws \Exception
     */
    public static function parse()
    {
        return true;
    }

}