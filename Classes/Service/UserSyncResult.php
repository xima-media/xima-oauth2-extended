<?php

namespace Xima\XimaOauth2Extended\Service;

/**
 * Mutable counters describing the outcome of a user sync run.
 */
final class UserSyncResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $failed = 0;

    public function total(): int
    {
        return $this->created + $this->updated + $this->skipped + $this->failed;
    }
}
