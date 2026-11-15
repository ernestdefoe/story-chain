<?php

namespace ErnestDefoe\StoryChain;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * One writer per story at a time, on every database. The row lock taken
 * inside (Story::locked) is a no-op on SQLite; this cache lock, shared by
 * every process on the forum's cache, isn't. A request never waits for it:
 * if another writer holds the story, the visitor is told to try again
 * ("busy"). Only the scheduler's closer waits its turn.
 */
class StoryLock
{
    /** Held at most this long, so a crashed writer never blocks a story. */
    public const SECONDS = 20;

    /** How long the scheduler's closer waits its turn, in seconds. */
    public static int $wait = 10;

    public function __construct(private readonly Cache $cache)
    {
    }

    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public function hold(int $storyId, callable $run, bool $wait = false): mixed
    {
        $store = $this->cache->getStore();
        if (! $store instanceof LockProvider) {
            return $run();
        }

        $lock = $store->lock('ernestdefoe-story-chain.story.'.$storyId, self::SECONDS);
        try {
            if ($wait) {
                $lock->block(self::$wait);
            } elseif (! $lock->get()) {
                throw new LockTimeoutException();
            }
        } catch (LockTimeoutException) {
            throw new Refusal('busy', 409);
        }

        try {
            return $run();
        } finally {
            $lock->release();
        }
    }
}
