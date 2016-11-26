<?php
namespace Tests\PhpType;

use PHPModbus\PhpType;
use PHPUnit\Framework\TestCase;

class Bytes2String extends TestCase
{
    const DATA = [ // String "Hello word!"
        0x48, //H
        0x65, //e
        0x6c, //l
        0x6c, //l
        0x6f, //o
        0x20, //
        0x77, //w
        0x6f, //o
        0x72, //r
        0x6c, //l
        0x64, //d
        0x21, //!
        0x00, //\0
        0x61, //a
        0x61  //a
    ];

    public function testBytesToString()
    {
        $this->assertEquals('eHll oowlr!da', PhpType::bytes2string(self::DATA));
        $this->assertEquals('Hello world!', PhpType::bytes2string(self::DATA, true));
    }
}
