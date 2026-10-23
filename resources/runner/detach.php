<?php

/*
 * php detach.php <command> [args...]
 *
 * Runs the command in a session of its own, so signals sent to the
 * runner's terminal (Ctrl+C) don't reach it. The process keeps its pid:
 * exec replaces this script with the command.
 */

posix_setsid();

$command = (string) ($argv[1] ?? '');
$arguments = array_slice($argv, 2);

if ($command !== '' && ! str_contains($command, '/')) {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_executable($dir.'/'.$command)) {
            $command = $dir.'/'.$command;

            break;
        }
    }
}

pcntl_exec($command, $arguments);

fwrite(STDERR, "detach: could not run {$command}\n");
exit(127);
