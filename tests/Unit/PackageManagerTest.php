<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt\Tests\Unit;

use Flytachi\Winter\Front\Nuxt\PackageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The package manager a frontend folder uses: by its lock file, and the commands the image runs. */
final class PackageManagerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wfn_pm_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/package.json', '{"name":"front"}');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param array<string, mixed> $json */
    private function packageJson(array $json): void
    {
        file_put_contents($this->dir . '/package.json', json_encode($json));
    }

    /** @return iterable<string, array{string, PackageManager, string, string}> */
    public static function locks(): iterable
    {
        yield 'pnpm' => ['pnpm-lock.yaml', PackageManager::Pnpm, 'pnpm install --frozen-lockfile', 'pnpm run generate'];
        yield 'npm' => ['package-lock.json', PackageManager::Npm, 'npm ci', 'npm run generate'];
        yield 'yarn' => ['yarn.lock', PackageManager::Yarn, 'yarn install --frozen-lockfile', 'yarn run generate'];
        yield 'bun' => ['bun.lock', PackageManager::Bun, 'npm install -g bun@1 && bun install --frozen-lockfile', 'bun run generate'];
        yield 'bun legacy' => ['bun.lockb', PackageManager::Bun, 'npm install -g bun@1 && bun install --frozen-lockfile', 'bun run generate'];
    }

    #[DataProvider('locks')]
    public function test_one_lock_file_decides(string $lock, PackageManager $expected, string $install, string $run): void
    {
        touch($this->dir . '/' . $lock);

        [$manager, $hint] = PackageManager::detect($this->dir);

        self::assertSame($expected, $manager);
        self::assertNull($hint);
        self::assertSame($install, $manager->install($this->dir));
        self::assertSame($run, $manager->run('generate'));
    }

    public function test_several_lock_files_take_the_package_json_field(): void
    {
        touch($this->dir . '/pnpm-lock.yaml');
        touch($this->dir . '/package-lock.json');
        $this->packageJson(['packageManager' => 'npm@10.9.0']);

        [$manager, $hint] = PackageManager::detect($this->dir);

        self::assertSame(PackageManager::Npm, $manager);
        self::assertNull($hint, 'the field says which: nothing to warn of');
    }

    public function test_several_lock_files_without_a_field_take_the_first_and_say_so(): void
    {
        touch($this->dir . '/package-lock.json');
        touch($this->dir . '/pnpm-lock.yaml');

        [$manager, $hint] = PackageManager::detect($this->dir);

        self::assertSame(PackageManager::Pnpm, $manager);
        self::assertSame(
            'several lock files (pnpm-lock.yaml, package-lock.json): pnpm taken — say packageManager: to choose',
            $hint,
        );
    }

    public function test_no_lock_file_falls_back_to_npm_install_and_says_so(): void
    {
        [$manager, $hint] = PackageManager::detect($this->dir);

        self::assertSame(PackageManager::Npm, $manager);
        self::assertSame('npm install', $manager->install($this->dir));
        self::assertSame('no lock file: the image installs whatever versions are newest — commit one', $hint);
    }

    public function test_no_lock_file_takes_the_package_json_field(): void
    {
        $this->packageJson(['packageManager' => 'pnpm@9.15.0']);

        [$manager] = PackageManager::detect($this->dir);

        self::assertSame(PackageManager::Pnpm, $manager);
        self::assertSame('pnpm install', $manager->install($this->dir));
    }

    public function test_yarn_berry_installs_immutable(): void
    {
        touch($this->dir . '/yarn.lock');
        $this->packageJson(['packageManager' => 'yarn@4.5.0']);

        self::assertSame('yarn install --immutable', PackageManager::Yarn->install($this->dir));
    }

    public function test_manifest_takes_the_config_files_that_exist(): void
    {
        touch($this->dir . '/pnpm-lock.yaml');
        touch($this->dir . '/.npmrc');
        touch($this->dir . '/pnpm-workspace.yaml');

        self::assertSame(
            ['package.json', 'pnpm-lock.yaml', '.npmrc', 'pnpm-workspace.yaml'],
            PackageManager::Pnpm->manifest($this->dir),
        );
        self::assertSame(
            ['package.json', '.npmrc', 'pnpm-workspace.yaml'],
            PackageManager::Npm->manifest($this->dir),
            'no lock of its own: the rest still',
        );
    }

    public function test_a_missing_folder_is_npm_without_a_lock(): void
    {
        [$manager, $hint] = PackageManager::detect($this->dir . '/none');

        self::assertSame(PackageManager::Npm, $manager);
        self::assertNotNull($hint);
    }
}
