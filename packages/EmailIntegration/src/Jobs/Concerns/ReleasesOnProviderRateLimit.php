<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs\Concerns;

use Illuminate\Bus\Batchable;
use Relaticle\EmailIntegration\Services\ProviderRateLimit;
use RuntimeException;
use Throwable;

trait ReleasesOnProviderRateLimit
{
    protected function releaseIfProviderCoolingDown(string $accountId): bool
    {
        $seconds = ProviderRateLimit::remainingSeconds($accountId);

        if ($seconds === null) {
            return false;
        }

        throw_if($this->shouldFailBatchJobOnProviderRateLimit(), RuntimeException::class, "Mailbox is rate limited for {$seconds} more seconds.");

        $this->release($seconds);

        return true;
    }

    protected function releaseIfProviderRateLimited(string $accountId, Throwable $exception): bool
    {
        $seconds = ProviderRateLimit::retryAfterSeconds($exception);

        if ($seconds === null) {
            return false;
        }

        ProviderRateLimit::trip($accountId, $seconds);

        if ($this->shouldFailBatchJobOnProviderRateLimit()) {
            return false;
        }

        $this->release($seconds);

        return true;
    }

    /**
     * History import batches count pending jobs until each store job finishes. release() does not
     * consume tries, so 429 cooldown loops can park the batch at 99% indefinitely.
     */
    protected function shouldFailBatchJobOnProviderRateLimit(): bool
    {
        if (! in_array(Batchable::class, class_uses_recursive(static::class), true)) {
            return false;
        }

        return $this->batch() !== null;
    }
}
