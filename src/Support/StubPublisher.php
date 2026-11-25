<?php

namespace Molefigog\Payments\Support;

/** Copies stub/asset files into an app without clobbering the user's edits (unless forced). Plain PHP, no framework. */
final class StubPublisher
{
    public const CREATED = 'created';
    public const EXISTS = 'exists';
    public const OVERWRITTEN = 'overwritten';

    public function __construct(private readonly bool $force = false)
    {
    }

    /** @param array<string,string> $replace  {{ key }} placeholders to fill */
    public function file(string $from, string $to, array $replace = []): string
    {
        if (! is_file($from)) {
            throw new \InvalidArgumentException("Missing stub: $from");
        }

        $existed = is_file($to);
        if ($existed && ! $this->force) {
            return self::EXISTS;
        }

        $contents = (string) file_get_contents($from);
        foreach ($replace as $key => $value) {
            $contents = str_replace('{{ '.$key.' }}', $value, $contents);
        }

        $dir = dirname($to);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Could not create directory: $dir");
        }

        file_put_contents($to, $contents);

        return $existed ? self::OVERWRITTEN : self::CREATED;
    }

    /** @return array<string,string>  destination path => status */
    public function directory(string $from, string $to, array $replace = []): array
    {
        $results = [];
        $from = rtrim($from, '/\\');
        $to = rtrim($to, '/\\');

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $item) {
            if (! $item->isFile()) {
                continue;
            }
            $relative = ltrim(substr($item->getPathname(), strlen($from)), '/\\');
            $destination = $to.DIRECTORY_SEPARATOR.$relative;
            $results[$destination] = $this->file($item->getPathname(), $destination, $replace);
        }

        ksort($results);

        return $results;
    }

    /** Absolute paths pass through; relative ones are resolved against $base. */
    public static function resolvePath(string $path, string $base): string
    {
        $isAbsolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);

        return $isAbsolute ? $path : rtrim($base, '/\\').DIRECTORY_SEPARATOR.$path;
    }
}
