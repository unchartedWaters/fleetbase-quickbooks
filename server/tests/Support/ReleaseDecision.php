<?php

namespace Fleetbase\Quickbooks\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Runs scripts/fleetbase-release/decide.sh for FleetbaseReleaseCheckTest, with gh stubbed by a
 * script that answers from the given artifacts JSON through the same jq filter, and records the
 * arguments it was called with.
 */
class ReleaseDecision
{
    /**
     * result is "true" or "false" (the run= output), or "error" when the script failed; gh is
     * every gh call's arguments; log is what the script printed.
     *
     * @return array{result: string, gh: string, log: string}
     */
    public static function run(string $event, int $publishedDaysAgo, string $digest, string $artifactsJson): array
    {
        $dir = sys_get_temp_dir() . '/qb-release-decide-' . uniqid();
        mkdir($dir);
        $stub = $dir . '/gh';
        file_put_contents($stub, "#!/usr/bin/env bash\n"
            . "echo \"\$*\" >> \"\$GH_CALLS\"\n"
            . "filter=''\n"
            . "while [ \$# -gt 0 ]; do\n"
            . "    if [ \"\$1\" = --jq ]; then filter=\"\$2\"; shift; fi\n"
            . "    shift\n"
            . "done\n"
            . "jq -r \"\$filter\" <<<\"\$ARTIFACTS_JSON\"\n");
        chmod($stub, 0755);

        $output = $dir . '/output';
        $calls  = $dir . '/calls';
        touch($output);
        touch($calls);
        $env = [
            'PATH'              => $dir . ':' . (string) getenv('PATH'),
            'EVENT'             => $event,
            'VERSION'           => 'v9.9.9',
            'PUBLISHED_AT'      => gmdate('Y-m-d\TH:i:s\Z', time() - $publishedDaysAgo * 86400 - 60),
            'DIGEST'            => $digest,
            'GITHUB_REPOSITORY' => 'owner/repo',
            'GITHUB_OUTPUT'     => $output,
            'GH_TOKEN'          => 'not-a-token',
            'ARTIFACTS_JSON'    => $artifactsJson,
            'GH_CALLS'          => $calls,
        ];
        $process = new Process(['bash', DocsSource::path('scripts/fleetbase-release/decide.sh')], null, $env);
        $status  = $process->run();

        preg_match('/^run=(\w+)$/m', (string) file_get_contents($output), $match);
        $result = $status === 0 ? ($match[1] ?? 'none') : 'error';
        $gh     = (string) file_get_contents($calls);
        array_map('unlink', [$stub, $output, $calls]);
        rmdir($dir);

        return ['result' => $result, 'gh' => $gh, 'log' => $process->getOutput() . $process->getErrorOutput()];
    }
}
