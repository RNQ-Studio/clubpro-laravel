<?php

namespace App\Services;

/** Read the deployed checkout identity without executing a process or exposing repository paths. */
class RuntimeVersionService
{
    public function __construct(private readonly ?string $projectDirectory = null) {}

    /** @return array{sha: ?string, available: bool} */
    public function current(): array
    {
        $directory = ($this->projectDirectory ?? base_path()).'/.git';
        if (is_file($directory)) {
            $pointer = $this->read($directory);
            $directory = $pointer !== null && str_starts_with($pointer, 'gitdir: ')
                ? $this->resolve(dirname($directory), substr($pointer, 8)) : null;
        }
        $sha = is_string($directory) && is_dir($directory) ? $this->head($directory) : null;

        return ['sha' => $sha, 'available' => $sha !== null];
    }

    private function head(string $directory): ?string
    {
        $head = $this->read($directory.'/HEAD');
        if ($head === null) {
            return null;
        }
        if ($this->validSha($head)) {
            return $head;
        }
        if (! str_starts_with($head, 'ref: ')) {
            return null;
        }
        $ref = substr($head, 5);
        if (! preg_match('/\Arefs\/[A-Za-z0-9_.\/-]+\z/D', $ref)
            || array_intersect(explode('/', $ref), ['', '.', '..']) !== []) {
            return null;
        }
        $common = $this->read($directory.'/commondir');
        $refDirectory = $common === null ? $directory : $this->resolve($directory, $common);
        if ($refDirectory === null) {
            return null;
        }
        $sha = $this->read($refDirectory.'/'.$ref);
        if ($sha !== null) {
            return $this->validSha($sha) ? $sha : null;
        }
        $packed = $this->read($refDirectory.'/packed-refs');
        if ($packed !== null && preg_match('/^([a-f0-9]{40}) '.preg_quote($ref, '/').'$/m', $packed, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function validSha(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{40}\z/D', $value) === 1;
    }

    private function read(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $value = @file_get_contents($path, false, null, 0, 1048576);

        return $value === false ? null : trim($value);
    }

    private function resolve(string $parent, string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $directory = realpath(str_starts_with($path, '/') ? $path : $parent.'/'.$path);

        return $directory !== false && is_dir($directory) ? $directory : null;
    }
}
