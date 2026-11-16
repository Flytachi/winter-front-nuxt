<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt\Tests\Unit;

use Flytachi\Winter\Front\Nuxt\Nuxt;
use Flytachi\Winter\Kernel\Kernel;
use PHPUnit\Framework\TestCase;

/** What a Nuxt frontend tells the developer at start: a config that does not match its mode. */
final class NuxtHintsTest extends TestCase
{
    private string $project = '';
    private string $front = '';

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/wfn_hints_' . bin2hex(random_bytes(4));
        $this->front = $this->project . '/frontend';
        mkdir($this->front . '/.output/public', 0777, true);
        file_put_contents($this->front . '/package.json', '{"name":"front"}');
        touch($this->front . '/pnpm-lock.yaml');
        touch($this->front . '/.output/public/200.html');
        touch($this->front . '/.output/public/index.html');
        Kernel::$pathRoot = $this->project;
        putenv('NUXT_APP_BASE_URL');
    }

    protected function tearDown(): void
    {
        putenv('NUXT_APP_BASE_URL');
        exec('rm -rf ' . escapeshellarg($this->project));
    }

    private function config(string $body): void
    {
        file_put_contents($this->front . '/nuxt.config.ts', "export default defineNuxtConfig({\n{$body}\n})\n");
    }

    public function test_a_ready_spa_has_no_hints(): void
    {
        $this->config("  ssr: false,\n  nitro: { compressPublicAssets: true },");

        self::assertSame([], Nuxt::spa('frontend')->hints());
    }

    public function test_a_missing_folder_is_the_only_hint(): void
    {
        self::assertSame(['no folder elsewhere/ in the project'], Nuxt::spa('elsewhere')->hints());
    }

    public function test_a_folder_without_a_config(): void
    {
        self::assertSame(['no nuxt.config.ts in frontend/: is it a Nuxt project?'], Nuxt::spa('frontend')->hints());
    }

    public function test_the_js_config_counts(): void
    {
        file_put_contents($this->front . '/nuxt.config.mjs', "export default { ssr: false }\n");

        self::assertSame([], Nuxt::spa('frontend')->hints());
    }

    public function test_a_spa_without_ssr_false(): void
    {
        $hint = 'nuxt.config.ts has no ssr: false — generate prerenders every page; '
            . 'set it for a single-page app, or use Nuxt::ssg()';

        $this->config('  devtools: { enabled: true },');
        self::assertSame([$hint], Nuxt::spa('frontend')->hints());

        $this->config('  ssr: true,');
        self::assertSame([$hint], Nuxt::spa('frontend')->hints());
    }

    public function test_a_commented_out_ssr_is_absent(): void
    {
        $this->config("  // ssr: false,\n  /* ssr: false */");

        self::assertCount(1, Nuxt::spa('frontend')->hints());
    }

    public function test_an_ssg_with_ssr_false(): void
    {
        $this->config('  ssr: false,');

        self::assertSame(
            ['nuxt.config.ts has ssr: false — the pages are empty shells; use Nuxt::spa(), or drop it'],
            Nuxt::ssg('frontend')->hints(),
        );
        $this->config('');
        self::assertSame([], Nuxt::ssg('frontend')->hints(), 'no ssr key: pages are rendered, as meant');
    }

    public function test_computed_values_give_no_hint(): void
    {
        $this->config("  ssr: process.env.SPA !== '1',\n  app: { baseURL: `\${base}/` },");

        self::assertSame([], Nuxt::spa('frontend', path: '/admin')->hints());
    }

    public function test_a_path_without_its_base_url(): void
    {
        $this->config('  ssr: false,');

        self::assertSame(
            ['app.baseURL is not "/admin/" — the pages ask for /_nuxt/ instead of /admin/_nuxt/; '
                . "set app: { baseURL: '/admin/' }"],
            Nuxt::spa('frontend', path: '/admin')->hints(),
        );
        $this->config("  ssr: false,\n  app: { baseURL: '/panel/' },");
        self::assertCount(1, Nuxt::spa('frontend', path: '/admin')->hints(), 'another path: the same hint');
    }

    public function test_base_url_with_or_without_slash_matches(): void
    {
        foreach (["'/admin'", "'/admin/'", '"/admin/"'] as $base) {
            $this->config("  ssr: false,\n  app: { baseURL: {$base} },");
            self::assertSame([], Nuxt::spa('frontend', path: '/admin')->hints(), $base);
        }
    }

    public function test_the_env_base_url_counts(): void
    {
        $this->config('  ssr: false,');
        putenv('NUXT_APP_BASE_URL=/admin/');

        self::assertSame([], Nuxt::spa('frontend', path: '/admin')->hints());
    }

    public function test_a_base_url_at_the_root(): void
    {
        $this->config("  ssr: false,\n  app: { baseURL: '/admin/' },");

        self::assertSame(
            ["app.baseURL is \"/admin/\" but the frontend answers at /: drop it, or say path: '/admin'"],
            Nuxt::spa('frontend')->hints(),
        );
    }

    public function test_not_built_yet(): void
    {
        $this->config('  ssr: false,');
        unlink($this->front . '/.output/public/200.html');

        self::assertSame(['not built yet: run "pnpm run generate" in frontend/'], Nuxt::spa('frontend')->hints());
    }

    public function test_the_package_managers_hint_is_passed_on(): void
    {
        $this->config('  ssr: false,');
        touch($this->front . '/package-lock.json');

        self::assertSame(
            ['several lock files (pnpm-lock.yaml, package-lock.json): pnpm taken — say packageManager: to choose'],
            Nuxt::spa('frontend')->hints(),
        );
    }

    public function test_an_unreadable_config_gives_no_hint_about_it(): void
    {
        $this->config('  ssr: true,');
        chmod($this->front . '/nuxt.config.ts', 0000);
        try {
            self::assertSame([], Nuxt::spa('frontend')->hints());
        } finally {
            chmod($this->front . '/nuxt.config.ts', 0644);
        }
    }

    public function test_a_trailing_slash_in_the_path_matches(): void
    {
        $this->config("  ssr: false,\n  app: { baseURL: '/admin/' },");

        self::assertSame([], Nuxt::spa('frontend', path: '/admin/')->hints());
    }

    public function test_a_base_url_outside_app_is_not_apps(): void
    {
        $this->config("  ssr: false,\n  runtimeConfig: { public: { baseURL: 'https://api.example.com' } },");
        self::assertSame([], Nuxt::spa('frontend')->hints(), 'runtimeConfig.public.baseURL is the API, not the app');

        $this->config("  ssr: false,\n  runtimeConfig: { public: { baseURL: 'https://api.example.com' } },\n  app: { baseURL: '/admin/' },");
        self::assertSame([], Nuxt::spa('frontend', path: '/admin')->hints());
    }

    public function test_a_nested_ssr_is_not_the_apps(): void
    {
        $this->config("  routeRules: { '/admin/**': { ssr: false } },");
        self::assertSame([], Nuxt::ssg('frontend')->hints(), 'a route rule is not the mode');

        $this->config("  \$development: { ssr: true },\n  routeRules: { '/blog/**': { ssr: true } },\n  ssr: false,");
        self::assertSame([], Nuxt::spa('frontend')->hints(), 'the top-level ssr: false counts');
    }

    public function test_a_quoted_ssr_key_counts(): void
    {
        $this->config("  'ssr': false,");

        self::assertSame([], Nuxt::spa('frontend')->hints());
    }

    public function test_a_glob_in_a_string_is_not_a_comment(): void
    {
        $this->config("  routeRules: { '/api/**': { cors: true } },\n  ssr: false,\n  /** doc */\n  app: { baseURL: '/' },\n  nitro: { prerender: { ignore: ['/x/**'] } }, // https://nuxt.com");

        self::assertSame([], Nuxt::spa('frontend')->hints());
    }
}
