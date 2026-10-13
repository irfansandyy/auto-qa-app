<?php

namespace App\Services;

use InvalidArgumentException;

class PatchPolicy
{
    public function validate(array $files, array $originalFiles = []): string
    {
        if (! $files || count($files) > 5) {
            throw new InvalidArgumentException('A repair must change between one and five files.');
        }
        $bytes = 0;
        $test = null;
        $hasApplicationChange = false;
        foreach ($files as $path => $content) {
            if (! is_string($path) || ! is_string($content)
                || ! preg_match('~^(app|resources/views|routes|tests/Feature)/[A-Za-z0-9_./-]+\.(php|blade\.php)$~D', $path)
                || str_contains($path, '..') || str_contains($path, '//') || str_contains($path, "\0")) {
                throw new InvalidArgumentException('The proposed patch contains a file outside the permitted repair scope.');
            }
            $bytes += strlen($content);
            if (str_starts_with($path, 'tests/')) {
                if (isset($originalFiles[$path]) || $test || ! str_ends_with($path, 'Test.php')) {
                    throw new InvalidArgumentException('Existing tests cannot be replaced by an automated repair. Add a new regression test.');
                }
                $test = $path;
            } elseif (! array_key_exists($path, $originalFiles)) {
                throw new InvalidArgumentException('Automated repairs may only change existing application files.');
            } else {
                $hasApplicationChange = true;
            }
        }
        if ($bytes > 65536 || ! $test || ! $hasApplicationChange) {
            throw new InvalidArgumentException('A repair needs a new regression test and must stay below 64 KB.');
        }

        return $test;
    }
}
