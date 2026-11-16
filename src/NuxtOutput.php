<?php

declare(strict_types=1);

namespace Flytachi\Winter\Front\Nuxt;

use Flytachi\Winter\Kernel\App\OutputLine;
use Flytachi\Winter\Kernel\App\ProgramOutput;
use Psr\Log\LogLevel;

/**
 * The lines of `nuxt dev` and of the Nitro server in the application's log: colours left
 * out, an error or a warning at its level, an indented line — a stack frame, a detail —
 * joined to the line before, an empty one dropped.
 */
final readonly class NuxtOutput implements ProgramOutput
{
    public function line(string $line): OutputLine
    {
        $text = rtrim((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line));
        if (trim($text) === '') {
            return OutputLine::skip();
        }
        if ($text[0] === ' ' && !preg_match('/^\s*(ERROR|WARN)\b/', $text)) {
            return OutputLine::more(ltrim($text));
        }
        $text = ltrim($text);
        $level = match (true) {
            preg_match('/^(ERROR\b|✖|\[error\]|ERR_)/i', $text) === 1 => LogLevel::ERROR,
            preg_match('/^(WARN\b|▲|⚠|\[warn\])/i', $text) === 1 => LogLevel::WARNING,
            default => LogLevel::INFO,
        };
        return OutputLine::entry($level, $text);
    }
}
