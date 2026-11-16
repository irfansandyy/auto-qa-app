<?php

namespace App\Services;

class SseParser
{
    private string $buffer = '';

    /**
     * @return array<int, string>
     */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (($position = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $position), "\r");
            $this->buffer = substr($this->buffer, $position + 1);

            if (str_starts_with($line, 'data:')) {
                $events[] = ltrim(substr($line, 5));
            }
        }

        return $events;
    }

    public function flush(): void
    {
        $this->buffer = '';
    }
}
