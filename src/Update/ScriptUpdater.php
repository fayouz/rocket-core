<?php

namespace Rocket\Core\Update;

use Rocket\Core\I18n\CoreMessages;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Update of a server without Docker: runs the update script (deploy/update.sh by default), which checks out the new
 * version, installs the dependencies, applies the migrations, builds the interface and restarts the services.
 * Called by the scheduled task app:update:run, as the user owning the files, never by the web server.
 */
class ScriptUpdater
{
    public function __construct(
        #[Autowire('%env(resolve:UPDATE_SCRIPT)%')] private readonly string $script,
        #[Autowire('%env(UPDATE_RESTART_COMMAND)%')] private readonly string $restartCommand,
        #[Autowire('%env(int:UPDATE_SCRIPT_TIMEOUT)%')] private readonly int $timeout,
        private readonly ?CoreMessages $messages = null,
    ) {
    }

    public function script(): string
    {
        return realpath($this->script) ?: $this->script;
    }

    public function isInstalled(): bool
    {
        return is_file($this->script) && is_executable($this->script);
    }

    /**
     * @param callable(string): void $onOutput receives the output as it comes (stdout and stderr)
     *
     * @return int exit code of the script
     */
    public function run(?string $target, callable $onOutput): int
    {
        if (!$this->isInstalled()) {
            throw new UpdateException($this->trans('update.script_missing', ['script' => $this->script]));
        }
        if (null !== $target && null === AppVersion::releaseOf($target)) {
            throw new UpdateException($this->trans('update.invalid_version', ['version' => $target]));
        }

        $process = new Process([$this->script()], \dirname($this->script()), [
            'TARGET_VERSION' => $target ?? '',
            'UPDATE_RESTART_COMMAND' => $this->restartCommand,
        ], timeout: $this->timeout);

        return $process->run(static function (string $type, string $output) use ($onOutput): void {
            $onOutput($output);
        });
    }

    /** @param array<string, string|int> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        return ($this->messages ?? CoreMessages::french())->trans($key, $parameters);
    }
}
