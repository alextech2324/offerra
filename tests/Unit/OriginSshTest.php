<?php

namespace Tests\Unit;

use App\Jobs\DeployOfferJob;
use App\Support\OriginSsh;
use Tests\TestCase;

class OriginSshTest extends TestCase
{
    public function test_upload_timeout_grows_with_archive_size(): void
    {
        $this->assertSame(90, OriginSsh::timeoutForUploadBytes(1024, 90));
        $this->assertSame(90, OriginSsh::timeoutForUploadBytes(128 * 1024, 90));
        $this->assertSame(300, OriginSsh::timeoutForUploadBytes(80 * 1024 * 1024, 90));
        $this->assertSame(45, OriginSsh::timeoutForUploadBytes(0, 10));
    }

    public function test_hung_upload_message_does_not_ask_to_reboot(): void
    {
        $message = OriginSsh::hungUploadMessage(90);

        $this->assertStringContainsString('90 с', $message);
        $this->assertStringNotContainsString('Перезавантаж', $message);
    }

    public function test_unreachable_ssh_errors_are_detected(): void
    {
        $this->assertTrue(OriginSsh::isUnreachableError(
            'SSH команда не вдалась: ssh: connect to host 144.172.64.248 port 22: No route to host',
        ));
        $this->assertTrue(OriginSsh::isUnreachableError(
            'ssh: connect to host 172.86.84.78 port 22: Connection timed out',
        ));
        $this->assertTrue(OriginSsh::isUnreachableError('Connection refused'));
        $this->assertFalse(OriginSsh::isUnreachableError(OriginSsh::HUNG_EXEC));
        $this->assertFalse(OriginSsh::isUnreachableError('SSH: логін відхилено. Перевір користувача і пароль.'));
    }

    public function test_deploy_job_retries_transient_ssh_failures(): void
    {
        $job = new DeployOfferJob(15);

        $this->assertSame(3, $job->tries);
        $this->assertSame([20, 90], $job->backoff());
        $this->assertSame(3600, $job->uniqueFor);
    }
}
