<?php

namespace App\Services\GitHub;

use InvalidArgumentException;

final class RepositoryUrl
{
    /** @return array{owner: string, name: string, full_name: string} */
    public static function parse(string $url): array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'github.com'
            || isset($parts['user'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Use a repository URL such as https://github.com/owner/repository.');
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if (! preg_match('~^/([A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?)/([A-Za-z0-9_.-]{1,100})$~D', $path, $matches)) {
            throw new InvalidArgumentException('The GitHub URL must identify one repository, without a branch or file path.');
        }

        $owner = $matches[1];
        $name = preg_replace('/\.git$/i', '', $matches[2]);
        if ($name === '' || in_array($name, ['.', '..'], true)) {
            throw new InvalidArgumentException('The GitHub repository name is invalid.');
        }

        return ['owner' => $owner, 'name' => $name, 'full_name' => "$owner/$name"];
    }

    public static function normalize(string $url): string
    {
        return 'https://github.com/'.self::parse($url)['full_name'];
    }
}
