<?php

namespace Tests\Unit\Support;

use App\Support\ExactJson;
use PHPUnit\Framework\TestCase;

class ExactJsonTest extends TestCase
{
    public function test_decimal_lexemes_and_large_ids_are_exact_while_strings_and_integers_remain_unchanged(): void
    {
        $v = ExactJson::decode('{"amount":12345678901234.01,"zero":0.00,"scientific":1e2,"id":123,"big":123456789012345678901,"text":"price 1.23 \\"ok\\"","list":[-1.25,0]}');
        $this->assertSame('12345678901234.01', $v['amount']);
        $this->assertSame('0.00', $v['zero']);
        $this->assertSame('1e2', $v['scientific']);
        $this->assertSame(123, $v['id']);
        $this->assertSame('123456789012345678901', $v['big']);
        $this->assertSame('price 1.23 "ok"', $v['text']);
        $this->assertSame(['-1.25', 0], $v['list']);
    }

    public function test_malformed_numbers_are_not_repaired(): void
    {
        $this->expectException(\JsonException::class);
        ExactJson::decode('{"amount":01.20}');
    }
}
