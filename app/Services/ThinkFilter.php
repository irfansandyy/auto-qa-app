<?php

namespace App\Services;

class ThinkFilter
{
    private string $buffer = '';

    private bool $inside = false;

    public function push(string $delta): string
    {
        $this->buffer .= $delta;
        $output = '';

        while ($this->buffer !== '') {
            if ($this->inside) {
                $end = strpos($this->buffer, '</think>');

                if ($end === false) {
                    if (strlen($this->buffer) > 7) {
                        $this->buffer = substr($this->buffer, -7);
                    }

                    return $output;
                }

                $this->buffer = substr($this->buffer, $end + 8);
                $this->inside = false;

                continue;
            }

            $start = strpos($this->buffer, '<think>');

            if ($start === false) {
                $lastOpen = strrpos($this->buffer, '<');

                if ($lastOpen !== false && $this->isPotentialThinkPrefix(substr($this->buffer, $lastOpen))) {
                    $output .= substr($this->buffer, 0, $lastOpen);
                    $this->buffer = substr($this->buffer, $lastOpen);

                    return $output;
                }

                $output .= $this->buffer;
                $this->buffer = '';

                return $output;
            }

            $output .= substr($this->buffer, 0, $start);
            $this->buffer = substr($this->buffer, $start + 7);
            $this->inside = true;
        }

        return $output;
    }

    public function flush(): string
    {
        $output = $this->inside ? '' : $this->buffer;
        $this->buffer = '';
        $this->inside = false;

        return $output;
    }

    private function isPotentialThinkPrefix(string $value): bool
    {
        return $value !== '' && str_starts_with('<think>', $value);
    }
}
