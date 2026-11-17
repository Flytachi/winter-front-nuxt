<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt;

/**
 * What `call run dev` runs for a Nuxt frontend (`bin/nuxt-dev <folder> <manager>`): Node
 * checked, the dependencies installed when the lock file changed since the last install
 * here — a container's `node_modules` volume is filled once and then kept — and then
 * `nuxt dev`, in the frontend's folder, in its place: its PID is the one the supervisor
 * watches and stops. It listens where the kernel said, `PORT` and `HOST`.
 *
 * What it says goes to stderr, which the application logs under the frontend's name.
 *
 * @internal Run by {@see Nuxt::program()}.
 */
final class DevRunner
{
    /** Where the last install is written down, in `node_modules`. */
    public const string STAMP = '.winter-install';

    /**
     * @param list<string> $argv The script, the folder from the project root, the manager.
     * @return int Why it could not start `nuxt dev` — it never returns when it did.
     */
    public static function main(array $argv): int
    {
        $folder = (string) ($argv[1] ?? '');
        $manager = PackageManager::tryFrom((string) ($argv[2] ?? ''));
        $directory = getcwd() . '/' . $folder;
        if ($folder === '' || $manager === null || !is_dir($directory)) {
            return self::say('usage: nuxt-dev <folder> <pnpm|npm|yarn|bun>, run in the project root', 2);
        }
        if (self::onPath('node') === null) {
            return self::say(self::inContainer()
                ? 'Node is not in the image: rebuild with DEV=true docker compose up --build'
                : 'Node is not installed: https://nodejs.org', 1);
        }

        $env = [...getenv(), 'COREPACK_ENABLE_DOWNLOAD_PROMPT' => '0'];
        $code = self::install($directory, $manager, $env);
        if ($code !== 0) {
            return $code;
        }

        $nuxt = "{$directory}/node_modules/.bin/nuxt";
        if (!is_executable($nuxt)) {
            return self::say("no nuxt in {$folder}/node_modules/.bin: is nuxt in {$folder}/package.json?", 1);
        }
        chdir($directory);
        @pcntl_exec($nuxt, ['dev'], $env);
        return self::say("nuxt dev could not be run: " . pcntl_strerror(pcntl_get_last_error()), 127);
    }

    /**
     * Installs when what was installed last is not what the folder asks for now.
     *
     * @param array<string, string> $env
     */
    private static function install(string $directory, PackageManager $manager, array $env): int
    {
        $lock = $manager->lockFile($directory);
        $wanted = $manager->value . ' ' . sha1_file($directory . '/' . ($lock ?? 'package.json'));
        $stamp = "{$directory}/node_modules/" . self::STAMP;
        $last = is_file($stamp) ? trim((string) file_get_contents($stamp)) : null;
        if ($last === $wanted) {
            return 0;
        }
        $command = $manager->install($directory, image: false);
        if ($manager === PackageManager::Pnpm && self::inContainer()) {
            // In a container pnpm keeps its store at the top of the bind mount — among the
            // project's sources, its home being elsewhere: in node_modules instead, the volume
            // it is installed into. Not on the host: there it has a store of its own, and
            // refuses to change it for an existing install.
            $command .= ' --store-dir ' . escapeshellarg("{$directory}/node_modules/.pnpm-store");
        }
        $why = match (true) {
            $lock === null => 'no lock file',
            // A volume mounted there is an empty folder before its first install.
            !is_dir("{$directory}/node_modules") || self::isEmpty("{$directory}/node_modules") => 'no node_modules yet',
            $last === null => 'node_modules was not installed here',
            default => "{$lock} changed since the last install",
        };
        self::say("{$why}: {$command}");

        $process = proc_open(['/bin/sh', '-c', $command], [1 => STDOUT, 2 => STDERR], $pipes, $directory, $env);
        $code = $process === false ? 127 : proc_close($process);
        if ($code !== 0) {
            return self::say("the install failed (exit {$code}): nuxt dev is not started", $code);
        }
        @file_put_contents($stamp, $wanted . "\n");
        return 0;
    }

    /** Whether it runs in a container: Docker's mark, or Podman's. */
    private static function inContainer(): bool
    {
        return is_file('/.dockerenv') || is_file('/run/.containerenv');
    }

    private static function isEmpty(string $folder): bool
    {
        return (scandir($folder) ?: ['.', '..']) === ['.', '..'];
    }

    private static function onPath(string $program): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_file("{$dir}/{$program}") && is_executable("{$dir}/{$program}")) {
                return "{$dir}/{$program}";
            }
        }
        return null;
    }

    private static function say(string $line, int $code = 0): int
    {
        fwrite(STDERR, $line . "\n");
        return $code;
    }
}
