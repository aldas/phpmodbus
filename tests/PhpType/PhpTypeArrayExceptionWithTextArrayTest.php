<?php
namespace Tests\PhpType;

use PHPModbus\PhpType;
use PHPUnit\Framework\TestCase;

class PhpTypeArrayExceptionWithTextArrayTest extends TestCase
{
    const DATA = [
        "0" => 100, // 32098 (DINT)
        "1" => "e",
        "2" => 0,
        "3" => 0
    ];

    public function testExceptionWhenSize2ContainsString()
    {
        $this->expectException(\Exception::class);
        PhpType::bytes2unsignedInt(array_slice(self::DATA, 0, 2));
    }

    public function testExceptionWhenSize4ContainsString()
    {
        $this->expectException(\Exception::class);
        PhpType::bytes2unsignedInt(array_slice(self::DATA, 0, 4));
    }
}
