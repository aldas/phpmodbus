<?php
namespace Tests\PhpType;

use PHPModbus\PhpType;
use PHPUnit\Framework\TestCase;

class PhpTypeArraySizeExceptionsTest extends TestCase
{
    const DATA = [
        "0" => 100, // 32098 (DINT)
        "1" => 2,
        "2" => 0,
        "3" => 0,
        "4" => 100, // 32098 (DINT)
        "5" => 2
    ];

    public function testExceptionWhenSizeShort()
    {
        $this->expectException(\Exception::class);
        PhpType::bytes2unsignedInt(array_slice(self::DATA, 0, 1));
    }

    public function testExceptionWhenSizeShort3()
    {
        $this->expectException(\Exception::class);
        PhpType::bytes2unsignedInt(array_slice(self::DATA, 0, 3));
    }

    public function testExceptionWhenSizeLong()
    {
        $this->expectException(\Exception::class);
        PhpType::bytes2unsignedInt(array_slice(self::DATA, 0, 5));
    }

    public function testNoExceptionWhenSize2()
    {
        $this->assertEquals(25602, PhpType::bytes2unsignedInt(array_slice(self::DATA, 0, 2)));
    }

    public function testNoExceptionWhenSize4()
    {
        $this->assertEquals(25602, PhpType::bytes2unsignedInt(array_slice(self::DATA, 0, 4)));
    }

}