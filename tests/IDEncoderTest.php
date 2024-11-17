<?php

namespace Tests;


use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SearchEngine\IDEncoder;

#[CoversClass(IDEncoder::class)]
class IDEncoderTest extends TestCase {
    public function testEncode() {
        $this->assertEquals('0', IDEncoder::encode(0));
        $this->assertEquals('1', IDEncoder::encode(1));
        $this->assertEquals('10', IDEncoder::encode(62));
        $this->assertEquals('z', IDEncoder::encode(61));
        $this->assertEquals('100', IDEncoder::encode(3844));
    }

    public function testDecode() {
        $this->assertEquals(0, IDEncoder::decode('0'));
        $this->assertEquals(1, IDEncoder::decode('1'));
        $this->assertEquals(62, IDEncoder::decode('10'));
        $this->assertEquals(61, IDEncoder::decode('z'));
        $this->assertEquals(3844, IDEncoder::decode('100'));
    }

    public function testEncodeDecodeConsistency() {
        $numbers = [0, 1, 61, 62, 123, 3844, 99999];
        foreach ($numbers as $number) {
            $encoded = IDEncoder::encode($number);
            $decoded = IDEncoder::decode($encoded);
            $this->assertEquals($number, $decoded, "Failed for number: $number");
        }
    }

    public function testCompare() {
        $this->assertLessThan(0, IDEncoder::compare('0', '1'));
        $this->assertGreaterThan(0, IDEncoder::compare('10', '1'));
        $this->assertEquals(0, IDEncoder::compare('Z', 'Z'));
        $this->assertLessThan(0, IDEncoder::compare('Z', '100'));
    }

    public function testSortingWithCompare() {
        $encodedIds = ['Z', '1', '100', '10'];
        usort($encodedIds, [IDEncoder::class, 'compare']);

        $this->assertEquals(['1', 'Z', '10', '100'], $encodedIds);
    }
}
