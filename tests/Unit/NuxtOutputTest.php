<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt\Tests\Unit;

use Flytachi\Winter\Front\Nuxt\NuxtOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/** What a line of `nuxt dev` or of the Nitro server means in the application's log. */
final class NuxtOutputTest extends TestCase
{
    private function level(string $line): ?string
    {
        return new NuxtOutput()->line($line)->level;
    }

    public function test_an_ordinary_line_is_info(): void
    {
        $line = new NuxtOutput()->line('◆ Ready in 1.64s  → http://127.0.0.1:3917/');

        self::assertSame(LogLevel::INFO, $line->level);
        self::assertSame('◆ Ready in 1.64s  → http://127.0.0.1:3917/', $line->message);
        self::assertSame(LogLevel::INFO, $this->level('[nitro] ✔ Nuxt Nitro server built in 446ms'));
    }

    public function test_colours_are_left_out(): void
    {
        self::assertSame('✔ Vite client built in 21ms', new NuxtOutput()->line("\e[32m✔\e[39m Vite client built in 21ms")->message);
    }

    public function test_a_warning(): void
    {
        self::assertSame(LogLevel::WARNING, $this->level('▲ Current version of Node.js (22.18.0) is unsupported and might cause issues.'));
        self::assertSame(LogLevel::WARNING, $this->level(' WARN  [nuxt] Failed to resolve component'));
        self::assertSame(LogLevel::WARNING, $this->level('[warn] something'));
    }

    public function test_an_error(): void
    {
        self::assertSame(LogLevel::ERROR, $this->level("\e[41m ERROR \e[49m Cannot find module 'x'"));
        self::assertSame(LogLevel::ERROR, $this->level('✖ Nuxt build error'));
        self::assertSame(LogLevel::ERROR, $this->level('[error] [vite] Internal server error'));
    }

    public function test_an_indented_line_continues_the_one_before(): void
    {
        $line = new NuxtOutput()->line('    at Object.<anonymous> (file.mjs:1:1)');

        self::assertNull($line->level);
        self::assertSame('at Object.<anonymous> (file.mjs:1:1)', $line->message);
    }

    public function test_an_empty_line_is_skipped(): void
    {
        self::assertTrue(new NuxtOutput()->line('')->skip);
        self::assertTrue(new NuxtOutput()->line("\e[2K  ")->skip);
    }
}
