<?php

namespace Tests\Unit;

use App\Services\ThinkFilter;
use PHPUnit\Framework\TestCase;

class ThinkFilterTest extends TestCase
{
    public function test_removes_thinking_content_split_across_chunks(): void
    {
        $filter = new ThinkFilter();

        $this->assertSame('Hello ', $filter->push('Hello <thi'));
        $this->assertSame('', $filter->push('nk>private reasoning</think>'));
        $this->assertSame(' visible', $filter->push(' visible'));
        $this->assertSame('', $filter->flush());
    }

    public function test_removes_multiple_blocks_and_flushes_plain_text(): void
    {
        $filter = new ThinkFilter();

        $this->assertSame('A B ', $filter->push('A <think>one</think>B <think>two</think>'));
        $this->assertSame('C', $filter->push('C'));
        $this->assertSame('', $filter->flush());
    }
}
