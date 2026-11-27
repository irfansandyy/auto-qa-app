<?php

namespace App\Services;

class RepositoryDiff
{
    public function containsChangedLine(?string $patch, int $line, string $side): bool
    {
        if ($patch === null || $line < 1 || ! in_array($side, ['LEFT', 'RIGHT'], true)) {
            return false;
        }

        $old = $new = null;
        foreach (explode("\n", $patch) as $row) {
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $row, $hunk)) {
                [$old, $new] = [(int) $hunk[1], (int) $hunk[2]];
            } elseif ($old !== null && $new !== null) {
                $prefix = $row[0] ?? '';
                if (($prefix === '-' && $side === 'LEFT' && $old === $line) || ($prefix === '+' && $side === 'RIGHT' && $new === $line)) {
                    return true;
                }
                $old += (int) in_array($prefix, ['-', ' '], true);
                $new += (int) in_array($prefix, ['+', ' '], true);
            }
        }

        return false;
    }

    /** @return array{patch: string, additions: int, deletions: int}|null */
    public function between(string $before, string $after): ?array
    {
        if ($before === $after) {
            return ['patch' => '', 'additions' => 0, 'deletions' => 0];
        }

        $old = $this->lines($before);
        $new = $this->lines($after);
        $start = 0;
        $oldEnd = count($old);
        $newEnd = count($new);
        while ($start < min($oldEnd, $newEnd) && $old[$start] === $new[$start]) {
            $start++;
        }
        while ($oldEnd > $start && $newEnd > $start && $old[$oldEnd - 1] === $new[$newEnd - 1]) {
            $oldEnd--;
            $newEnd--;
        }

        $oldCount = $oldEnd - $start;
        $newCount = $newEnd - $start;
        if ($oldCount * $newCount > 40000 || $oldCount + $newCount > 500) {
            return null;
        }

        $oldChange = array_slice($old, $start, $oldCount);
        $newChange = array_slice($new, $start, $newCount);
        $matches = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));
        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $matches[$i][$j] = $oldChange[$i] === $newChange[$j]
                    ? 1 + $matches[$i + 1][$j + 1]
                    : max($matches[$i + 1][$j], $matches[$i][$j + 1]);
            }
        }

        $contextBefore = min(3, $start);
        $contextAfter = min(3, count($old) - $oldEnd);
        $first = $start - $contextBefore;
        $oldLength = $oldCount + $contextBefore + $contextAfter;
        $newLength = $newCount + $contextBefore + $contextAfter;
        $lines = [sprintf('@@ -%d,%d +%d,%d @@', $first + (int) ($oldLength > 0), $oldLength, $first + (int) ($newLength > 0), $newLength)];
        $this->context($lines, $old, $first, $contextBefore);

        $additions = $deletions = $i = $j = 0;
        while ($i < $oldCount || $j < $newCount) {
            if ($i < $oldCount && $j < $newCount && $oldChange[$i] === $newChange[$j]) {
                $this->append($lines, ' ', $oldChange[$i++]);
                $j++;
            } elseif ($i < $oldCount && ($j === $newCount || $matches[$i + 1][$j] >= $matches[$i][$j + 1])) {
                $this->append($lines, '-', $oldChange[$i++]);
                $deletions++;
            } else {
                $this->append($lines, '+', $newChange[$j++]);
                $additions++;
            }
        }

        $this->context($lines, $old, $oldEnd, $contextAfter);

        return ['patch' => implode("\n", $lines), 'additions' => $additions, 'deletions' => $deletions];
    }

    /** @return list<string> */
    private function lines(string $content): array
    {
        return preg_split('/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY);
    }

    /** @param list<string> $output @param list<string> $source */
    private function context(array &$output, array $source, int $start, int $length): void
    {
        foreach (array_slice($source, $start, $length) as $line) {
            $this->append($output, ' ', $line);
        }
    }

    /** @param list<string> $output */
    private function append(array &$output, string $prefix, string $line): void
    {
        $output[] = $prefix.rtrim($line, "\r\n");
        if (! str_ends_with($line, "\n")) {
            $output[] = '\ No newline at end of file';
        }
    }
}
