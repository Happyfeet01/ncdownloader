<?php

declare(strict_types=1);

namespace OCA\NCDownloader\Controller;

use OCA\NCDownloader\Aria2\Aria2;
use OCA\NCDownloader\Db\Helper as DbHelper;
use OCA\NCDownloader\Tools\Helper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

final class ResetController extends Controller
{
    private string $uid;
    private Aria2 $aria2;
    private DbHelper $dbconn;
    private LoggerInterface $logger;

    public function __construct(
        $appName,
        IRequest $request,
        $UserId,
        Aria2 $aria2,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->uid = (string) $UserId;
        $this->aria2 = $aria2;
        $this->aria2->init();
        $this->dbconn = new DbHelper();
        $this->logger = $logger;
    }

    /**
     * Stop all active downloads belonging to the current user and clear only
     * the live queue. Completed and failed history is intentionally preserved.
     *
     * @NoAdminRequired
     */
    public function reset(): JSONResponse
    {
        $stoppedYtdl = 0;
        $removedAria2 = 0;
        $warnings = [];

        $ytdlRows = $this->dbconn->getYtdlByUidAndStatus($this->uid, [
            Helper::STATUS['ACTIVE'],
            Helper::STATUS['WAITING'],
            Helper::STATUS['PAUSED'],
        ]);

        $pids = [];
        foreach ($ytdlRows as $row) {
            $extra = isset($row['data']) ? $this->dbconn->getExtra($row['data']) : [];
            if (!is_array($extra) || empty($extra['pid'])) {
                continue;
            }

            $pid = (int) $extra['pid'];
            if ($pid > 1) {
                $pids[$pid] = true;
            }
        }

        foreach (array_keys($pids) as $pid) {
            if (!$this->processExists($pid)) {
                continue;
            }

            if (!$this->looksLikeYtdlProcess($pid)) {
                $warnings[] = sprintf('Refused to stop stale PID %d because it no longer looks like yt-dlp.', $pid);
                continue;
            }

            if ($this->stopProcessTree($pid)) {
                $stoppedYtdl++;
            } else {
                $warnings[] = sprintf('Could not terminate yt-dlp process tree %d completely.', $pid);
            }
        }

        foreach ($ytdlRows as $row) {
            if (!empty($row['gid'])) {
                $this->dbconn->deleteByGid((string) $row['gid']);
            }
        }

        $aria2Jobs = [];
        $active = $this->aria2->tellActive([]);
        $waiting = $this->aria2->tellWaiting([0, 999]);
        if (is_array($active)) {
            $aria2Jobs = array_merge($aria2Jobs, $active);
        }
        if (is_array($waiting)) {
            $aria2Jobs = array_merge($aria2Jobs, $waiting);
        }

        foreach ($aria2Jobs as $job) {
            if (!is_array($job) || empty($job['gid'])) {
                continue;
            }

            $rpcGid = (string) $job['gid'];
            $dbGid = (string) ($job['following'] ?? $rpcGid);
            if ($this->dbconn->getUidByGid($dbGid) !== $this->uid) {
                continue;
            }

            $resp = $this->aria2->remove($rpcGid);
            $ok = isset($resp['result']) && is_string($resp['result']) && strtolower($resp['result']) === 'ok';
            if ($ok) {
                $removedAria2++;
                $this->dbconn->deleteByGid($dbGid);
            } else {
                $warnings[] = sprintf('Could not remove aria2 download %s.', $rpcGid);
            }
        }

        if ($warnings !== []) {
            $this->logger->warning('MediaFetch reset completed with warnings.', [
                'app' => 'mediafetch',
                'user' => $this->uid,
                'warnings' => $warnings,
            ]);
        }

        return new JSONResponse([
            'message' => sprintf(
                'Download queue reset: %d yt-dlp process(es) stopped, %d aria2 download(s) removed.',
                $stoppedYtdl,
                $removedAria2
            ),
            'warnings' => $warnings,
            'stopped_ytdl' => $stoppedYtdl,
            'removed_aria2' => $removedAria2,
        ]);
    }

    private function stopProcessTree(int $pid): bool
    {
        $descendants = $this->collectDescendants($pid);
        $targets = array_values(array_unique(array_merge($descendants, [$pid])));

        foreach (array_reverse($targets) as $target) {
            if ($this->processExists($target)) {
                Helper::doSignal($target, 15);
            }
        }

        usleep(250000);

        $ok = true;
        foreach (array_reverse($targets) as $target) {
            if (!$this->processExists($target)) {
                continue;
            }
            if (!Helper::doSignal($target, 9)) {
                $ok = false;
            }
        }

        usleep(100000);
        return $ok && !$this->processExists($pid);
    }

    /** @return int[] */
    private function collectDescendants(int $pid, array &$seen = []): array
    {
        if ($pid <= 1 || isset($seen[$pid])) {
            return [];
        }
        $seen[$pid] = true;

        $path = sprintf('/proc/%d/task/%d/children', $pid, $pid);
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $result = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $child) {
            $childPid = (int) $child;
            if ($childPid <= 1) {
                continue;
            }
            $result[] = $childPid;
            $result = array_merge($result, $this->collectDescendants($childPid, $seen));
        }

        return $result;
    }

    private function processExists(int $pid): bool
    {
        return $pid > 1 && is_dir('/proc/' . $pid);
    }

    private function looksLikeYtdlProcess(int $pid): bool
    {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        if ($cmdline === false) {
            return false;
        }

        $cmdline = str_replace("\0", ' ', strtolower($cmdline));
        return str_contains($cmdline, 'yt-dlp') || str_contains($cmdline, 'mediafetch');
    }
}
