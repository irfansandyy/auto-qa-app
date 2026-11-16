<?php

namespace Tests\Unit;

use App\Services\SseParser;
use PHPUnit\Framework\TestCase;

class SseParserTest extends TestCase
{
    public function test_parses_data_lines_across_chunk_boundaries(): void
    {
        $parser = new SseParser();

        $this->assertSame([], $parser->push("event: token\n"));
        $this->assertSame([], $parser->push('da'));
        $this->assertSame(['{"delta":"Hel'], $parser->push("ta: {\"delta\":\"Hel\n\n"));
        $this->assertSame(['lo"}', '[DONE]'], $parser->push("data: lo\"}\n\ndata: [DONE]\n\n"));
    }
}
