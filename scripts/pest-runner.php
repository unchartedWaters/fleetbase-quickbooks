<?php

declare(strict_types=1);

$pestCandidates = [
    getcwd() . '/server_vendor/bin/pest',
    getcwd() . '/vendor/bin/pest',
    getcwd() . '/server_vendor/pestphp/pest/bin/pest',
    getcwd() . '/vendor/pestphp/pest/bin/pest',
];

$pest = null;
foreach ($pestCandidates as $candidate) {
    if (is_file($candidate) === true) {
        $pest = $candidate;
        break;
    }
}

if ($pest === null) {
    fwrite(STDERR, "Unable to find Pest. Run composer install first.\n");
    exit(1);
}

$serverVendor = getcwd() . '/server_vendor';
$vendor       = getcwd() . '/vendor';
if (file_exists($vendor) === false && is_dir($serverVendor) === true && function_exists('symlink') === true) {
    @symlink($serverVendor, $vendor);
}

$autoloadLoaded = false;
foreach ([$serverVendor . '/autoload.php', $vendor . '/autoload.php'] as $autoload) {
    if (is_file($autoload) === true) {
        require $autoload;
        $autoloadLoaded = true;
        break;
    }
}

if ($autoloadLoaded === false) {
    fwrite(STDERR, "Unable to load Composer autoload.\n");
    exit(1);
}

$bootstrap = getcwd() . '/scripts/pest-bootstrap.php';
if (is_file($bootstrap) === false) {
    fwrite(STDERR, "Unable to find Pest bootstrap at scripts/pest-bootstrap.php.\n");
    exit(1);
}

$args             = array_slice($argv, 1);
$hasConfiguration = false;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--configuration') === true) {
        $hasConfiguration = true;
        break;
    }
}
$configuration = getcwd() . '/phpunit.xml.dist';

if ($hasConfiguration === false && is_file($configuration) === true) {
    array_unshift($args, '--configuration=' . $configuration);
}

$command = array_merge([
    PHP_BINARY,
    '-d',
    'display_errors=1',
    '-d',
    'error_reporting=8191',
    '-d',
    'auto_prepend_file=' . $bootstrap,
    $pest,
], $args);

$process = new Symfony\Component\Process\Process($command);
$process->setTimeout(null);
$process->run(static function (string $type, string $buffer): void {
    fwrite($type === Symfony\Component\Process\Process::ERR ? STDERR : STDOUT, $buffer);
});
exit($process->getExitCode() ?? 1);
