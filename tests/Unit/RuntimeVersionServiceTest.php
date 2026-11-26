<?php

namespace Tests\Unit;

use App\Services\RuntimeVersionService;
use PHPUnit\Framework\TestCase;

class RuntimeVersionServiceTest extends TestCase
{
    private string $directory;

    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/clubpro-runtime-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/.git/refs/heads', 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_loose_reference_reads_current_checkout_on_each_call_without_stale_cache(): void
    {
        file_put_contents($this->directory.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->directory.'/.git/refs/heads/main', self::SHA."\n");
        $service = new RuntimeVersionService($this->directory);
        $this->assertSame(['sha' => self::SHA, 'available' => true], $service->current());
        $next = str_repeat('a', 40);
        file_put_contents($this->directory.'/.git/refs/heads/main', $next."\n");
        $this->assertSame(['sha' => $next, 'available' => true], $service->current());
    }

    public function test_detached_head_is_supported(): void
    {
        file_put_contents($this->directory.'/.git/HEAD', self::SHA."\n");
        $this->assertSame(self::SHA, (new RuntimeVersionService($this->directory))->current()['sha']);
    }

    public function test_packed_reference_matches_exact_branch_and_ignores_other_refs(): void
    {
        file_put_contents($this->directory.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->directory.'/.git/packed-refs', '# pack-refs with: peeled'."\n".str_repeat('a', 40)." refs/heads/main-old\n".self::SHA." refs/heads/main\n");
        $this->assertSame(self::SHA, (new RuntimeVersionService($this->directory))->current()['sha']);
    }

    public function test_worktree_pointer_and_common_reference_directory_are_supported(): void
    {
        mkdir($this->directory.'/checkout');
        mkdir($this->directory.'/.git/worktrees/check', 0700, true);
        file_put_contents($this->directory.'/checkout/.git', "gitdir: ../.git/worktrees/check\n");
        file_put_contents($this->directory.'/.git/worktrees/check/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->directory.'/.git/worktrees/check/commondir', "../..\n");
        file_put_contents($this->directory.'/.git/refs/heads/main', self::SHA."\n");
        $this->assertSame(self::SHA, (new RuntimeVersionService($this->directory.'/checkout'))->current()['sha']);
    }

    public function test_missing_checkout_metadata_is_explicitly_unavailable(): void
    {
        $this->assertSame(['sha' => null, 'available' => false], (new RuntimeVersionService($this->directory.'/absent'))->current());
    }

    public function test_invalid_hash_does_not_fall_back_to_an_older_packed_hash_or_leak_file_content(): void
    {
        file_put_contents($this->directory.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->directory.'/.git/refs/heads/main', 'private non-hash content');
        file_put_contents($this->directory.'/.git/packed-refs', self::SHA." refs/heads/main\n");
        $this->assertSame(['sha' => null, 'available' => false], (new RuntimeVersionService($this->directory))->current());
    }

    public function test_traversal_reference_and_process_syntax_are_rejected_without_reading_or_execution(): void
    {
        foreach (['ref: refs/heads/../../secret', 'ref: refs//heads/main', 'ref: refs/heads/$(touch marker)', '$(touch marker)', str_repeat('a', 39)] as $invalid) {
            file_put_contents($this->directory.'/.git/HEAD', $invalid);
            $this->assertSame(['sha' => null, 'available' => false], (new RuntimeVersionService($this->directory))->current());
        }
        $this->assertFileDoesNotExist($this->directory.'/marker');
    }
}
