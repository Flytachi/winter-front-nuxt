<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt;

use Flytachi\Winter\Kernel\App\FrontBuild;
use Flytachi\Winter\Kernel\App\Frontend;
use Flytachi\Winter\Kernel\App\FrontProgram;
use Flytachi\Winter\Kernel\App\FrontSite;
use Flytachi\Winter\Kernel\App\Priority;
use Flytachi\Winter\Kernel\App\ServiceImage;
use Flytachi\Winter\Kernel\Kernel;

/**
 * A Nuxt frontend of the application, declared in one line: the application's own HTTP
 * server sends its built files after the routes — a controller always wins — and
 * `call service docker` builds it into the image in a Node stage of its own.
 *
 * ```php
 * protected static function components(Components $components): void
 * {
 *     $components->frontend(Nuxt::spa('frontend'));                              // beside the API
 *     $components->frontend(Nuxt::spa('admin', port: 9091, routes: '/admin'));    // a port of its own
 * }
 * ```
 *
 * Two modes, both built with `generate` into `.output/public`: {@see spa()} — one page,
 * the browser builds the rest (`ssr: false`); {@see ssg()} — every page built in advance.
 * The production image has no Node: only the files. Rendering on every request (SSR) is a
 * Node service of its own, run beside the application, not inside it.
 *
 * Under `call run dev` every mode runs `nuxt dev` instead, through the application's own
 * address, its live reload included.
 *
 * @link https://winterframe.net/docs/frontend The frontend in the application
 */
final readonly class Nuxt implements Frontend
{
    /** What `nuxt generate` writes, from the frontend's folder. */
    public const string OUTPUT = '.output/public';

    /**
     * What a development build of the image needs for `nuxt dev`: corepack for pnpm and
     * yarn, and the home they keep their cache and store in — flytachi/winter has none.
     */
    private const array DEV_RUN = [
        'npm install -g corepack',
        'corepack enable',
        'mkdir -p /home/winter && chown winter:winter /home/winter',
    ];

    /** Nuxt's folder of content-hashed files (`buildAssetsDir`). */
    public const string ASSETS = '/_nuxt';

    private const string FOLDER = '#^[A-Za-z0-9._][A-Za-z0-9._/-]*$#';

    /** @param list<string> $routes */
    private function __construct(
        public string $folder,
        public Mode $mode,
        public string $name,
        public string $path,
        public ?int $port,
        public array $routes,
        public bool $exclusive,
        public ?PackageManager $packageManager,
        public bool $critical,
        public bool $overlap,
    ) {
    }

    /**
     * A single-page app (`ssr: false` in `nuxt.config.ts`): `generate` writes one page,
     * `200.html`, that every route of the app gets; the browser builds the rest.
     *
     * @param string $folder The frontend's folder, from the project root: `frontend`.
     * @param string $path Where it answers on its port: `/`, or `/admin` (with
     *     `app.baseURL: '/admin/'` in `nuxt.config.ts`).
     * @param int|null $port A port of its own; null — the HTTP server's.
     * @param string|list<string> $routes The controller prefixes its own port lets through:
     *     `/admin` — and closes on the HTTP server's port.
     * @param bool $exclusive False: those prefixes stay open on the HTTP server's port too.
     * @param string|null $name How logs and `app status` name it; null — the folder's name.
     * @param PackageManager|null $packageManager Null — told by the lock file.
     * @param bool $critical Whether the application stops when it cannot be served.
     * @param bool $overlap False: a controller taking one of its pages is meant — no warning.
     * @throws \InvalidArgumentException A folder outside the project, a place it cannot take.
     */
    public static function spa(
        string $folder,
        string $path = '/',
        ?int $port = null,
        string|array $routes = [],
        bool $exclusive = true,
        ?string $name = null,
        ?PackageManager $packageManager = null,
        bool $critical = false,
        bool $overlap = true,
    ): self {
        return self::make(
            Mode::Spa,
            $folder,
            $path,
            $port,
            $routes,
            $exclusive,
            $name,
            $packageManager,
            $critical,
            $overlap,
        );
    }

    /**
     * A pre-rendered site: `generate` writes a page for every route it finds; a path with
     * no page of its own is a 404. The parameters are {@see spa()}'s.
     *
     * @param string|list<string> $routes
     * @throws \InvalidArgumentException A folder outside the project, a place it cannot take.
     */
    public static function ssg(
        string $folder,
        string $path = '/',
        ?int $port = null,
        string|array $routes = [],
        bool $exclusive = true,
        ?string $name = null,
        ?PackageManager $packageManager = null,
        bool $critical = false,
        bool $overlap = true,
    ): self {
        return self::make(
            Mode::Ssg,
            $folder,
            $path,
            $port,
            $routes,
            $exclusive,
            $name,
            $packageManager,
            $critical,
            $overlap,
        );
    }

    /** @param string|list<string> $routes */
    private static function make(
        Mode $mode,
        string $folder,
        string $path,
        ?int $port,
        string|array $routes,
        bool $exclusive,
        ?string $name,
        ?PackageManager $packageManager,
        bool $critical,
        bool $overlap,
    ): self {
        $folder = rtrim($folder, '/');
        if (preg_match(self::FOLDER, $folder) !== 1 || in_array('..', explode('/', $folder), true)) {
            throw new \InvalidArgumentException("folder is relative to the project root, \"{$folder}\" given.");
        }
        if ($name === null) {
            $name = ltrim(strtolower(basename($folder)), '_.-');
            if ($name === '') {
                throw new \InvalidArgumentException("the folder \"{$folder}\" gives no name: say name:.");
            }
        }
        $nuxt = new self(
            $folder,
            $mode,
            $name,
            $path === '/' ? '/' : rtrim($path, '/'),
            $port,
            is_string($routes) ? [$routes] : array_values($routes),
            $exclusive,
            $packageManager,
            $critical,
            $overlap,
        );
        // What the kernel cannot take is said here, where the developer wrote it.
        $nuxt->site();
        return $nuxt;
    }

    /** The frontend's folder, absolute. */
    public function directory(): string
    {
        return rtrim(Kernel::$pathRoot, '/') . '/' . $this->folder;
    }

    public function site(): FrontSite
    {
        return new FrontSite(
            $this->name,
            $this->directory() . '/' . self::OUTPUT,
            shell: $this->mode === Mode::Spa ? '200.html' : null,
            path: $this->path,
            port: $this->port,
            routes: $this->routes,
            exclusive: $this->exclusive,
            critical: $this->critical,
            priority: Priority::Low,
            overlap: $this->overlap,
            assets: self::ASSETS,
        );
    }

    /**
     * Node only in a development build of the image (`DEV=true`), for `nuxt dev`: the
     * production image ships the files without it.
     */
    public function image(): ServiceImage
    {
        return new ServiceImage(devPackages: ['nodejs', 'npm'], devRun: self::DEV_RUN);
    }

    public function build(): FrontBuild
    {
        $manager = $this->manager();
        return new FrontBuild(
            $this->folder,
            self::OUTPUT,
            $manager->manifest($this->directory()),
            $manager->install($this->directory()),
            $manager->run('generate'),
        );
    }

    /**
     * `nuxt dev` under `call run dev`, in either mode — through `bin/nuxt-dev`, which
     * installs the dependencies when the lock file changed since the last install.
     */
    public function devServer(): FrontProgram
    {
        return new FrontProgram(
            [PHP_BINARY, dirname(__DIR__) . '/bin/nuxt-dev', $this->folder, $this->manager()->value],
            output: new NuxtOutput(),
            startTimeout: 180,
        );
    }

    /**
     * What does not match its mode: no `ssr: false` for a single-page app, `ssr: false`
     * for a pre-rendered site, `app.baseURL` other than its path, no build yet (outside
     * `call run dev`), a package manager chosen among several. Only what the config says
     * literally is argued with.
     *
     * @return list<string>
     */
    public function hints(bool $dev): array
    {
        $directory = $this->directory();
        if (!is_dir($directory)) {
            return ["no folder {$this->folder}/ in the project"];
        }
        $hints = [];
        $config = NuxtConfig::read($directory);
        if ($config === null && NuxtConfig::find($directory) === null) {
            $hints[] = "no nuxt.config.ts in {$this->folder}/: is it a Nuxt project?";
        }
        if ($config !== null) {
            if ($this->mode === Mode::Spa && ($config->ssr === null || $config->ssr === 'true')) {
                $hints[] = "{$config->file} has no ssr: false — generate prerenders every page; "
                    . 'set it for a single-page app, or use Nuxt::ssg()';
            }
            if ($this->mode === Mode::Ssg && $config->ssr === 'false') {
                $hints[] = "{$config->file} has ssr: false — the pages are empty shells; use Nuxt::spa(), or drop it";
            }
            $hint = $this->baseUrlHint($config->baseURL);
            if ($hint !== null) {
                $hints[] = $hint;
            }
        }
        if (!$dev && !$this->site()->isBuilt()) {
            $hints[] = sprintf('not built yet: run "%s" in %s/', $this->manager()->run('generate'), $this->folder);
        }
        if ($this->packageManager === null) {
            [, $hint] = PackageManager::detect($directory);
            if ($hint !== null) {
                $hints[] = $hint;
            }
        }
        return $hints;
    }

    /** `app.baseURL` against the path it answers at — the env's `NUXT_APP_BASE_URL` counts. */
    private function baseUrlHint(?string $baseURL): ?string
    {
        $env = getenv('NUXT_APP_BASE_URL');
        if (is_string($env) && $env !== '') {
            $baseURL = $env;
        }
        if ($baseURL === 'expr') {
            return null;
        }
        $base = rtrim((string) $baseURL, '/');
        if ($this->path !== '/' && $base !== $this->path) {
            return "app.baseURL is not \"{$this->path}/\" — the pages ask for /_nuxt/ instead of "
                . "{$this->path}/_nuxt/; set app: { baseURL: '{$this->path}/' }";
        }
        if ($this->path === '/' && $base !== '') {
            return "app.baseURL is \"{$baseURL}\" but the frontend answers at /: drop it, or say path: '{$base}'";
        }
        return null;
    }

    /** The package manager: the one given, or the one the lock file tells. */
    public function manager(): PackageManager
    {
        return $this->packageManager ?? PackageManager::detect($this->directory())[0];
    }
}
