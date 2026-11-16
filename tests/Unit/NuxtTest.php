<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt\Tests\Unit;

use Flytachi\Winter\Front\Nuxt\Nuxt;
use Flytachi\Winter\Front\Nuxt\NuxtOutput;
use Flytachi\Winter\Front\Nuxt\PackageManager;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\Kernel;
use PHPUnit\Framework\TestCase;

/** A Nuxt frontend as the kernel gets it: its site, its build, its image. */
final class NuxtTest extends TestCase
{
    private const array DEV_RUN = [
        'npm install -g corepack',
        'corepack enable',
        'mkdir -p /home/winter && chown winter:winter /home/winter',
    ];

    private string $project = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/wfn_nuxt_' . bin2hex(random_bytes(4));
        mkdir($this->project . '/frontend', 0777, true);
        file_put_contents($this->project . '/frontend/package.json', '{"name":"front"}');
        Kernel::$pathRoot = $this->project;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    public function test_spa_serves_the_output_with_the_shell_and_nuxt_assets(): void
    {
        $site = Nuxt::spa('frontend')->site();

        self::assertSame('frontend', $site->name);
        self::assertSame($this->project . '/frontend/.output/public', $site->root);
        self::assertSame('200.html', $site->shell);
        self::assertSame('/_nuxt', $site->assets);
        self::assertSame('/', $site->path);
        self::assertNull($site->port);
        self::assertFalse($site->critical);
        self::assertSame(Priority::Low, $site->priority);
    }

    public function test_ssg_has_no_shell(): void
    {
        self::assertNull(Nuxt::ssg('frontend')->site()->shell);
    }

    public function test_the_place_reaches_the_site(): void
    {
        $site = Nuxt::spa('admin/', path: '/panel', port: 9091, routes: '/admin', exclusive: false, critical: true, overlap: false)->site();

        self::assertSame($this->project . '/admin/.output/public', $site->root, 'the trailing slash dropped');
        self::assertSame('/panel', $site->path);
        self::assertSame(9091, $site->port);
        self::assertSame(['/admin'], $site->routes, 'a string is a list of one');
        self::assertFalse($site->exclusive);
        self::assertTrue($site->critical);
        self::assertFalse($site->overlap);
    }

    public function test_the_name_comes_from_the_folder(): void
    {
        self::assertSame('admin-ui', Nuxt::spa('apps/Admin-UI')->site()->name);
        self::assertSame('front.v2', Nuxt::spa('_Front.V2')->site()->name, 'lowercased, from its first letter or digit');
        self::assertSame('front', Nuxt::spa('frontend', name: 'front')->site()->name);
    }

    public function test_a_folder_that_gives_no_name_asks_for_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('the folder "___" gives no name: say name:');
        Nuxt::spa('___');
    }

    public function test_a_folder_outside_the_project_is_refused(): void
    {
        foreach (['../front', '/srv/front', 'a/../../b', '', 'Admin UI'] as $folder) {
            try {
                Nuxt::spa($folder);
                self::fail("accepted: {$folder}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('folder is relative to the project root', $e->getMessage());
            }
        }
    }

    public function test_the_build_uses_the_detected_manager_and_generate(): void
    {
        touch($this->project . '/frontend/pnpm-lock.yaml');

        $build = Nuxt::spa('frontend')->build();

        self::assertSame('frontend', $build->context);
        self::assertSame('.output/public', $build->output);
        self::assertSame(['package.json', 'pnpm-lock.yaml'], $build->manifest);
        self::assertSame('pnpm install --frozen-lockfile', $build->install);
        self::assertSame('pnpm run generate', $build->build);
        self::assertSame('node:24-alpine', $build->image);
    }

    public function test_an_explicit_manager_wins(): void
    {
        touch($this->project . '/frontend/pnpm-lock.yaml');

        $build = Nuxt::ssg('frontend', packageManager: PackageManager::Npm)->build();

        self::assertSame('npm install', $build->install, 'npm without its own lock: a plain install');
        self::assertSame('npm run generate', $build->build);
    }

    public function test_the_production_image_needs_nothing(): void
    {
        $image = Nuxt::spa('frontend')->image();

        self::assertSame([], $image->packages);
        self::assertSame([], $image->run);
        self::assertSame(['nodejs', 'npm'], $image->devPackages, 'Node only in a development build');
    }

    public function test_the_factories_touch_no_disk(): void
    {
        $site = Nuxt::spa('not/there')->site();

        self::assertSame($this->project . '/not/there/.output/public', $site->root);
    }

    public function test_ssr_serves_the_public_output_and_runs_its_server(): void
    {
        $nuxt = Nuxt::ssr('frontend');
        $site = $nuxt->site();

        self::assertSame($this->project . '/frontend/.output/public', $site->root);
        self::assertNull($site->shell, 'its pages are rendered, there is no shell');
        self::assertSame($this->project . '/frontend/.output/nitro.json', $site->marker(), 'built: written on every build');
        self::assertSame('/_nuxt', $site->assets);

        $program = $nuxt->program(false);
        self::assertNotNull($program);
        self::assertSame(['node', 'frontend/.output/server/index.mjs'], $program->command);
        self::assertTrue($program->server, 'Nitro has routes of its own: every method');
        self::assertInstanceOf(NuxtOutput::class, $program->output);
    }

    public function test_spa_and_ssg_have_no_program_in_production(): void
    {
        self::assertNull(Nuxt::spa('frontend')->program(false));
        self::assertNull(Nuxt::ssg('frontend')->program(false));
    }

    public function test_dev_runs_the_runner_for_every_mode(): void
    {
        touch($this->project . '/frontend/pnpm-lock.yaml');
        foreach (['spa' => false, 'ssg' => false, 'ssr' => true] as $mode => $server) {
            $program = Nuxt::$mode('frontend')->program(true);

            self::assertNotNull($program, $mode);
            self::assertSame(PHP_BINARY, $program->command[0]);
            self::assertSame(realpath(dirname(__DIR__, 2) . '/bin/nuxt-dev'), realpath($program->command[1]));
            self::assertSame(['frontend', 'pnpm'], array_slice($program->command, 2), $mode);
            self::assertSame($server, $program->server, $mode);
            self::assertSame(180, $program->startTimeout, 'a first start installs');
        }
    }

    public function test_the_ssr_image_carries_node(): void
    {
        $image = Nuxt::ssr('frontend')->image();

        self::assertSame(['nodejs'], $image->packages);
        self::assertSame(['npm'], $image->devPackages);
        self::assertSame(self::DEV_RUN, $image->devRun);
    }

    public function test_spa_image_carries_node_only_in_dev(): void
    {
        $image = Nuxt::spa('frontend')->image();

        self::assertSame([], $image->packages);
        self::assertSame(['nodejs', 'npm'], $image->devPackages);
        self::assertSame(self::DEV_RUN, $image->devRun, 'corepack, and a home for its cache and the pnpm store');
    }

    public function test_ssr_builds_with_build_into_output(): void
    {
        touch($this->project . '/frontend/pnpm-lock.yaml');
        $build = Nuxt::ssr('frontend')->build();

        self::assertSame('.output', $build->output, 'the server and the public files');
        self::assertSame('pnpm run build', $build->build);
    }
}
