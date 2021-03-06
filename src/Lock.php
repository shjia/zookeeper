<?php

namespace SparkInfluence\Zookeeper;

use SparkInfluence\Zookeeper\Exception\Exception;
use Throwable;
use Zookeeper as ZkExt;

class Lock
{

    /** @var ZookeeperInterface */
    private $zk;

    const TYPE_EXCLUSIVE = 'exclusive';
    const TYPE_READ = 'read';
    const TYPE_WRITE = 'write';

    /**
     * Lock constructor.
     * @param ZookeeperInterface $zk
     */
    public function __construct(ZookeeperInterface $zk)
    {
        $this->zk = $zk;
    }

    public function lock(string $key, int $timeout = 0): ?string
    {
        return $this->doLock($key, $timeout, static::TYPE_EXCLUSIVE);
    }

    public function writeLock(string $key, int $timeout = 0): ?string
    {
        return $this->doLock($key, $timeout, static::TYPE_WRITE);
    }

    public function readLock(string $key, int $timeout = 0): ?string
    {
        return $this->doLock($key, $timeout, static::TYPE_READ);
    }

    private function doLock(string $key, int $timeout = 0, string $mode = self::TYPE_EXCLUSIVE): ?string
    {
        try {
            $full_key = $this->getLockName($key, $mode);
            $lock_key = $this->createLockKey($full_key);

            if (!$this->waitForLock($lock_key, $full_key, $timeout, $mode)) {
                // Clean up
                $this->zk->remove($lock_key);
                return null;
            }

            return $lock_key;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function unlock(string $key): bool
    {
        return $this->zk->remove($key);
    }

    public function isLocked(string $key, string $mode = 'exclusive')
    {
        $lockName = $this->getLockName($key);
        return !$this->waitForLock($lockName, $lockName, 0, $mode);
    }

    /**
     * @param string $key
     * @return string
     * @throws Exception
     */
    private function createLockKey(string $key): string
    {
        if (!$this->zk->ensurePath($key)) {
            throw new Exception('Could not create parent node!');
        }
        $flags = ZkExt::EPHEMERAL | ZkExt::SEQUENCE;
        return $this->zk->create($key, '1', $flags);
    }

    private function getLockName(string $key, string $type = 'exclusive'): string
    {
        switch ($type) {
            case self::TYPE_READ:
                $name = 'read-';
                break;
            case self::TYPE_WRITE:
            case self::TYPE_EXCLUSIVE:
            default:
                $name = 'lock-';
                break;
        }
        return $key . '/' . $name;
    }

    private function waitForLock(string $acquiredKey, string $baseKey, int $timeout, string $mode): bool
    {
        $deadline = microtime(true) + $timeout;

        $nameFilter = $indexFilter = null;
        $parent = dirname($baseKey);
        switch ($mode) {
            case static::TYPE_READ:
                $nameFilter = $this->getLockName($parent, static::TYPE_WRITE);
                break;
            case static::TYPE_WRITE:
            case static::TYPE_EXCLUSIVE:
                $nameFilter = '';
                $indexFilter = $this->getIndex($acquiredKey);
                break;
        }

        while (true) {
            if (!$this->isCurrentlyLocked($baseKey, $indexFilter, $nameFilter)) {
                return true;
            }
            if ($deadline <= microtime(true)) {
                break;
            }
            usleep(100000); // sleep for a tenth of a second
        }
        return false;
    }

    private function getIndex(string $key): ?int
    {
        if (!preg_match("/[0-9]+$/", $key, $matches)) {
            return null;
        }
        return intval(ltrim($matches[0], '0'));
    }

    /**
     * Check if the key is currently locked
     *
     * Providing an index filter will restrict the check to higher priority nodes (i.e. smaller numbers). If you use the
     * default name filter (empty string), the nameFilter will be set to $baseKey. If a name filter is provided, the
     * method will only match locks that share a base key name.
     *
     * @param string $baseKey
     * @param int|null $indexFilter
     * @param string|null $nameFilter
     * @return bool
     */
    private function isCurrentlyLocked(string $baseKey, ?int $indexFilter = null, ?string $nameFilter = ''): bool
    {
        $parent = dirname($baseKey);
        if (!$this->zk->exists($parent)) {
            return false;
        }
        if (is_string($nameFilter) && empty($nameFilter)) {
            $nameFilter = $baseKey;
        }
        $children = $this->zk->getChildren($parent);
        foreach ($children as $childKey) {
            $child = "$parent/$childKey";
            if (!is_null($nameFilter) && strpos($child, $nameFilter) !== 0) {
                continue;
            }
            if (is_null($indexFilter)) {
                return true;
            }
            $child_index = $this->getIndex($childKey);
            if (is_null($child_index)) {
                // Not a sequence node
                continue;
            }
            if ($child_index < $indexFilter) {
                // smaller index
                return true;
            }
        }
        return false;
    }

}
