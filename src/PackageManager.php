<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt;

/**
 * The package manager a frontend folder uses, told by its lock file, and the commands the
 * image's build stage runs with it. pnpm and yarn come through corepack, which takes the
 * version from `packageManager` in `package.json`; bun is installed into the Node image.
 *
 * @link https://winterframe.net/docs/frontend The frontend in the application
 */
enum PackageManager: string
{
    case Pnpm = 'pnpm';
    case Npm = 'npm';
    case Yarn = 'yarn';
    case Bun = 'bun';

    /** Config files the install reads besides `package.json` and the lock file. */
    private const array CONFIGS = ['.npmrc', 'pnpm-workspace.yaml', '.yarnrc.yml'];

    /**
     * The manager of a folder: the one whose lock file is there; among several, the one
     * `packageManager` in `package.json` names, else the first in the order of the cases;
     * without a lock file, the one it names, else npm.
     *
     * @return array{self, ?string} The manager, and what to tell the developer — null when
     *     the choice was clear.
     */
    public static function detect(string $folder): array
    {
        $found = array_values(array_filter(self::cases(), static fn(self $pm): bool => $pm->lockIn($folder) !== null));
        $named = self::named($folder);
        if (count($found) === 1) {
            return [$found[0], null];
        }
        if ($found === []) {
            return [$named ?? self::Npm, 'no lock file: the image installs whatever versions are newest — commit one'];
        }
        if ($named !== null && in_array($named, $found, true)) {
            return [$named, null];
        }
        $locks = implode(', ', array_map(static fn(self $pm): string => (string) $pm->lockIn($folder), $found));
        return [
            $found[0],
            "several lock files ({$locks}): {$found[0]->value} taken — say packageManager: to choose",
        ];
    }

    /**
     * The install command: the lock file kept as it is; without one, a plain install.
     *
     * @param bool $image For the image's build stage, where bun is not there yet and is
     *     installed first; false — where it runs, under `call run dev`.
     */
    public function install(string $folder, bool $image = true): string
    {
        $bun = $image ? 'npm install -g bun@1 && ' : '';
        if ($this->lockIn($folder) === null) {
            return $this === self::Bun ? "{$bun}bun install" : "{$this->value} install";
        }
        return match ($this) {
            self::Pnpm => 'pnpm install --frozen-lockfile',
            self::Npm => 'npm ci',
            self::Yarn => self::majorOf($folder, 'yarn') >= 2
                ? 'yarn install --immutable'
                : 'yarn install --frozen-lockfile',
            self::Bun => "{$bun}bun install --frozen-lockfile",
        };
    }

    /** A script of `package.json`: `pnpm run generate`. */
    public function run(string $script): string
    {
        return "{$this->value} run {$script}";
    }

    /**
     * What the install needs, copied into the build stage before the sources so it is
     * cached while they do not change: `package.json`, the lock file, the config files there.
     *
     * @return list<string>
     */
    public function manifest(string $folder): array
    {
        $files = ['package.json'];
        $lock = $this->lockIn($folder);
        if ($lock !== null) {
            $files[] = $lock;
        }
        foreach (self::CONFIGS as $config) {
            if (is_file("{$folder}/{$config}")) {
                $files[] = $config;
            }
        }
        return $files;
    }

    /** The lock file of this manager in the folder, null when there is none. */
    public function lockFile(string $folder): ?string
    {
        return $this->lockIn($folder);
    }

    private function lockIn(string $folder): ?string
    {
        $locks = match ($this) {
            self::Pnpm => ['pnpm-lock.yaml'],
            self::Npm => ['package-lock.json'],
            self::Yarn => ['yarn.lock'],
            self::Bun => ['bun.lock', 'bun.lockb'],
        };
        foreach ($locks as $lock) {
            if (is_file("{$folder}/{$lock}")) {
                return $lock;
            }
        }
        return null;
    }

    /** The manager `packageManager` in `package.json` names (`pnpm@9.15.0`), null when none. */
    private static function named(string $folder): ?self
    {
        $field = self::field($folder);
        return $field === null ? null : self::tryFrom(strstr($field, '@', true) ?: $field);
    }

    /** The major version `packageManager` gives this manager; 0 when it gives none. */
    private static function majorOf(string $folder, string $manager): int
    {
        $field = (string) self::field($folder);
        return preg_match('/^' . $manager . '@(\d+)/', $field, $m) === 1 ? (int) $m[1] : 0;
    }

    private static function field(string $folder): ?string
    {
        $json = @file_get_contents("{$folder}/package.json");
        $data = is_string($json) ? json_decode($json, true) : null;
        return is_array($data) && is_string($data['packageManager'] ?? null) ? $data['packageManager'] : null;
    }
}
