<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `bin/nuxt-dev` — what `call run dev` runs for a Nuxt frontend: Node checked, the
 * dependencies installed when the lock file changed since the last install, then `nuxt dev`
 * in the frontend's folder. Run as the supervisor runs it, with a fake package manager and
 * a fake `nuxt` that say what they were called with.
 */
final class DevRunnerTest extends TestCase
{
    private string $project = '';
    private string $tools = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/wfn_dev_' . bin2hex(random_bytes(4));
        $this->tools = $this->project . '/.tools';
        mkdir($this->project . '/frontend', 0777, true);
        mkdir($this->tools, 0777, true);
        file_put_contents($this->project . '/frontend/package.json', '{"name":"front"}');
        file_put_contents($this->project . '/frontend/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
        // A package manager that "installs": records its call, makes node_modules and nuxt.
        $this->tool('pnpm', <<<'SH'
            echo "pnpm $*" >> "$WFN_CALLS"
            [ "$WFN_FAIL" = "1" ] && exit 7
            mkdir -p node_modules/.bin
            printf '#!/bin/sh\necho "nuxt $* in $(pwd) port=$PORT"\n' > node_modules/.bin/nuxt
            chmod +x node_modules/.bin/nuxt
            SH);
        $this->tool('npm', 'echo "npm $*" >> "$WFN_CALLS"; mkdir -p node_modules/.bin; printf \'#!/bin/sh\necho nuxt $*\n\' > node_modules/.bin/nuxt; chmod +x node_modules/.bin/nuxt');
        $this->tool('node', 'exit 0');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    private function tool(string $name, string $body): void
    {
        file_put_contents("{$this->tools}/{$name}", "#!/bin/sh\n{$body}\n");
        chmod("{$this->tools}/{$name}", 0755);
    }

    /** @return array{code: int, out: string, calls: list<string>} */
    private function start(string $manager = 'pnpm', array $env = [], ?string $path = null): array
    {
        $calls = $this->project . '/calls';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/nuxt-dev', 'frontend', $manager],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [
                'PATH' => $path ?? $this->tools . ':/usr/bin:/bin',
                'PORT' => '4123',
                'HOST' => '127.0.0.1',
                'WFN_CALLS' => $calls,
                ...$env,
            ],
        );
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'code' => proc_close($process),
            'out' => (string) $out,
            'calls' => @file($calls, FILE_IGNORE_NEW_LINES) ?: [],
        ];
    }

    public function test_it_execs_nuxt_dev_in_the_folder_with_the_port(): void
    {
        $run = $this->start();

        self::assertSame(0, $run['code'], $run['out']);
        self::assertStringContainsString('nuxt dev in ' . realpath($this->project . '/frontend') . ' port=4123', $run['out']);
    }

    public function test_it_installs_when_there_are_no_dependencies_and_says_why(): void
    {
        $run = $this->start();

        self::assertCount(1, $run['calls']);
        self::assertStringStartsWith('pnpm install --frozen-lockfile', $run['calls'][0]);
        self::assertStringContainsString('no node_modules yet: pnpm install --frozen-lockfile', $run['out']);
    }

    public function test_an_empty_volume_is_no_node_modules_yet(): void
    {
        mkdir($this->project . '/frontend/node_modules');
        $run = $this->start();

        self::assertStringContainsString('no node_modules yet: pnpm install --frozen-lockfile', $run['out']);
    }

    public function test_pnpms_store_is_kept_in_node_modules(): void
    {
        $run = $this->start();

        self::assertStringEndsWith(
            ' --store-dir ' . realpath($this->project . '/frontend') . '/node_modules/.pnpm-store',
            $run['calls'][0] ?? '',
            'in the node_modules volume, never beside the project\'s sources',
        );
    }

    public function test_it_installs_nothing_the_second_time(): void
    {
        $this->start();
        $second = $this->start();

        self::assertCount(1, $second['calls'], 'one install, from the first start');
        self::assertStringNotContainsString('install', $second['out']);
    }

    public function test_it_installs_when_the_lock_changed_and_says_why(): void
    {
        $this->start();
        file_put_contents($this->project . '/frontend/pnpm-lock.yaml', "lockfileVersion: '9.0'\n# changed\n");
        $run = $this->start();

        self::assertCount(2, $run['calls']);
        self::assertStringContainsString('pnpm-lock.yaml changed since the last install: pnpm install --frozen-lockfile', $run['out']);
    }

    public function test_without_a_lock_file_it_installs_and_says_so(): void
    {
        unlink($this->project . '/frontend/pnpm-lock.yaml');
        $run = $this->start('npm');

        self::assertSame(['npm install'], $run['calls']);
        self::assertStringContainsString('no lock file: npm install', $run['out']);
        self::assertSame(['npm install'], $this->start('npm')['calls'], 'and not again while package.json is the same');
    }

    public function test_a_failing_install_stops_it_with_its_code(): void
    {
        $run = $this->start(env: ['WFN_FAIL' => '1']);

        self::assertSame(7, $run['code'], $run['out']);
        self::assertStringNotContainsString('nuxt dev in', $run['out'], 'nuxt dev is not run');
        self::assertStringContainsString('the install failed (exit 7)', $run['out']);
        self::assertSame(7, $this->start(env: ['WFN_FAIL' => '1'])['code'], 'tried again next time: nothing was recorded');
    }

    public function test_without_node_it_says_how_to_get_it(): void
    {
        unlink($this->tools . '/node');
        $run = $this->start();

        self::assertSame(1, $run['code']);
        self::assertMatchesRegularExpression('#Node is not (installed|in the image)#', $run['out']);
        self::assertSame([], $run['calls']);
    }
}
