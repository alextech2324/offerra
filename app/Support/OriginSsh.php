<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * SSH exec that cannot hang a queue worker.
 *
 * phpseclib's exec() waits forever when OpenSSH accepts the password but never
 * confirms a session channel ("Undefined array key 1" = CHANNEL_EXEC). On the
 * panel we shell out to `timeout` + sshpass instead.
 */
class OriginSsh
{
    public const HUNG_EXEC = 'SSH: логін пройшов, але сесія зависла (канал команди не відкрився). Перезавантаж VPS з панелі хостера.';

    /**
     * TCP never reached sshd — archive/teardown should skip this host.
     */
    public static function isUnreachableError(?string $message): bool
    {
        if ($message === null || $message === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'no route to host')
            || str_contains($normalized, 'network is unreachable')
            || str_contains($normalized, 'network unreachable')
            || str_contains($normalized, 'host is unreachable')
            || str_contains($normalized, 'connection timed out')
            || str_contains($normalized, 'connection refused')
            || str_contains($normalized, 'connection reset')
            || str_contains($normalized, 'unable to connect')
            || str_contains($normalized, 'operation timed out')
            || str_contains($normalized, 'no route to network');
    }

    public function exec(
        string $host,
        int $port,
        string $username,
        string $password,
        string $command,
        int $timeout = 20,
    ): string {
        $host = trim($host);
        $username = trim($username);
        $password = (string) $password;
        $port = $port > 0 ? $port : 22;
        $timeout = max(5, $timeout);

        if ($host === '' || $username === '' || $password === '') {
            throw new RuntimeException('Заповніть host, користувача і пароль SSH.');
        }

        if ($this->canUseOpenSsh()) {
            return $this->execOpenSsh($host, $port, $username, $password, $command, $timeout);
        }

        return $this->execPhpSecLib($host, $port, $username, $password, $command, $timeout);
    }

    public function upload(
        string $host,
        int $port,
        string $username,
        string $password,
        string $localFile,
        string $remoteFile,
        int $timeout = 90,
    ): void {
        if (! is_file($localFile)) {
            throw new RuntimeException('Локальний файл для upload не знайдено.');
        }

        $timeout = self::timeoutForUploadBytes((int) filesize($localFile), $timeout);

        if ($this->canUseOpenSsh() && is_executable('/usr/bin/scp')) {
            $this->uploadOpenSsh($host, $port, $username, $password, $localFile, $remoteFile, $timeout);

            return;
        }

        $this->uploadPhpSecLib($host, $port, $username, $password, $localFile, $remoteFile, $timeout);
    }

    public function canUseOpenSsh(): bool
    {
        return PHP_OS_FAMILY === 'Linux'
            && is_executable('/usr/bin/timeout')
            && is_executable('/usr/bin/sshpass')
            && is_executable('/usr/bin/ssh');
    }

    /**
     * Give slow archives enough time, but never wait more than 5 minutes.
     */
    public static function timeoutForUploadBytes(int $bytes, int $requested = 90): int
    {
        $fromSize = 45 + (int) ceil(max(0, $bytes) / (128 * 1024));

        return max(20, min(300, max($requested, $fromSize)));
    }

    public static function hungUploadMessage(int $timeout): string
    {
        return 'SFTP: завантаження не завершилось за '.$timeout.' с. Повторіть деплой.';
    }

    /**
     * @return list<string>
     */
    private function sshFlags(): array
    {
        return [
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'LogLevel=ERROR',
            '-o', 'ConnectTimeout=8',
            '-o', 'PreferredAuthentications=password',
            '-o', 'PubkeyAuthentication=no',
            '-o', 'NumberOfPasswordPrompts=1',
            '-o', 'ServerAliveInterval=3',
            '-o', 'ServerAliveCountMax=2',
        ];
    }

    private function execOpenSsh(
        string $host,
        int $port,
        string $username,
        string $password,
        string $command,
        int $timeout,
    ): string {
        $process = $this->openSshProcess(
            [
                '/usr/bin/ssh',
                ...$this->sshFlags(),
                '-p', (string) $port,
                $username.'@'.$host,
                $command,
            ],
            $password,
            $timeout,
        );

        $process->run();
        $code = $process->getExitCode();
        $out = trim($process->getOutput()."\n".$process->getErrorOutput());

        if ($this->isTimeoutCode($code)) {
            throw new RuntimeException(self::HUNG_EXEC);
        }

        if ($code !== 0) {
            $hint = $out !== '' ? mb_substr($out, 0, 220) : 'exit '.$code;
            throw new RuntimeException('SSH команда не вдалась: '.$hint);
        }

        return trim($process->getOutput());
    }

    private function execPhpSecLib(
        string $host,
        int $port,
        string $username,
        string $password,
        string $command,
        int $timeout,
    ): string {
        $ssh = new SSH2($host, $port, min(12, $timeout));
        $ssh->setTimeout($timeout);

        try {
            if (! $ssh->login($username, $password)) {
                throw new RuntimeException('SSH: логін відхилено. Перевір користувача і пароль.');
            }

            $output = $ssh->exec($command);
            if ($output === false) {
                throw new RuntimeException('SSH: сесія не відкрила канал команди.');
            }

            return (string) $output;
        } catch (\ErrorException $e) {
            if (str_contains($e->getMessage(), 'Undefined array key')) {
                throw new RuntimeException(self::HUNG_EXEC, 0, $e);
            }

            throw new RuntimeException('SSH: '.$e->getMessage(), 0, $e);
        } finally {
            try {
                $ssh->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    private function uploadOpenSsh(
        string $host,
        int $port,
        string $username,
        string $password,
        string $localFile,
        string $remoteFile,
        int $timeout,
    ): void {
        $result = $this->runScp($host, $port, $username, $password, $localFile, $remoteFile, $timeout);
        if ($result['code'] === 0) {
            return;
        }

        if ($this->isTimeoutCode($result['code'])) {
            usleep(1_500_000);
            $result = $this->runScp($host, $port, $username, $password, $localFile, $remoteFile, $timeout);
            if ($result['code'] === 0) {
                return;
            }

            $fallback = $this->runExecCat($host, $port, $username, $password, $localFile, $remoteFile, $timeout);
            if ($fallback['code'] === 0) {
                return;
            }

            throw new RuntimeException(self::hungUploadMessage($timeout));
        }

        $hint = trim($result['error'].' '.$result['output']);
        throw new RuntimeException('SFTP: не вдалося завантажити файл.'.($hint !== '' ? ' '.mb_substr($hint, 0, 180) : ''));
    }

    /**
     * @return array{code: int|null, output: string, error: string}
     */
    private function runScp(
        string $host,
        int $port,
        string $username,
        string $password,
        string $localFile,
        string $remoteFile,
        int $timeout,
    ): array {
        $process = $this->openSshProcess(
            [
                '/usr/bin/scp',
                ...$this->sshFlags(),
                '-P', (string) ($port > 0 ? $port : 22),
                $localFile,
                $username.'@'.$host.':'.$remoteFile,
            ],
            $password,
            $timeout,
        );
        $process->run();

        return [
            'code' => $process->getExitCode(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    /**
     * scp uses the SFTP subsystem; a hung subsystem can still allow exec+stdin.
     *
     * @return array{code: int|null, output: string, error: string}
     */
    private function runExecCat(
        string $host,
        int $port,
        string $username,
        string $password,
        string $localFile,
        string $remoteFile,
        int $timeout,
    ): array {
        $input = fopen($localFile, 'rb');
        if ($input === false) {
            return ['code' => 1, 'output' => '', 'error' => 'cannot read local file'];
        }

        try {
            $process = $this->openSshProcess(
                [
                    '/usr/bin/ssh',
                    ...$this->sshFlags(),
                    '-p', (string) $port,
                    $username.'@'.$host,
                    'cat > '.escapeshellarg($remoteFile),
                ],
                $password,
                $timeout,
                $input,
            );
            $process->run();

            return [
                'code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ];
        } finally {
            fclose($input);
        }
    }

    private function openSshProcess(array $command, string $password, int $timeout, mixed $input = null): Process
    {
        return new Process(
            [
                '/usr/bin/timeout', '-k', '2', (string) $timeout,
                '/usr/bin/sshpass', '-e',
                ...$command,
            ],
            null,
            ['SSHPASS' => $password],
            $input,
            $timeout + 4,
        );
    }

    private function isTimeoutCode(?int $code): bool
    {
        return $code === 124 || $code === 137;
    }

    private function uploadPhpSecLib(
        string $host,
        int $port,
        string $username,
        string $password,
        string $localFile,
        string $remoteFile,
        int $timeout,
    ): void {
        $sftp = new SFTP($host, $port, min(20, $timeout));
        $sftp->setTimeout($timeout);

        try {
            if (! $sftp->login($username, $password)) {
                throw new RuntimeException('SFTP: логін відхилено під час завантаження архіву.');
            }
            if (! $sftp->put($remoteFile, $localFile, SFTP::SOURCE_LOCAL_FILE)) {
                throw new RuntimeException('SFTP: не вдалося завантажити tar.gz на origin.');
            }
        } finally {
            try {
                $sftp->disconnect();
            } catch (\Throwable) {
            }
        }
    }
}
