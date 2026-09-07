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
     * Stop all active downloads belonging to the current user, clear the live
     * queue and clear failed history. Completed history is preserved.
     *
     * A live yt-dlp row is only removed after its tracked process has actually
     * stopped. This avoids showing an empty queue while a privileged wrapper
     * or ffmpeg child is still running in the background.
     *
     * @NoAdminRequired
     */
    public function reset(): JSONResponse
    {
        $stoppedYtdl = 0;
        $removedAria2 = 0;
        $clearedFailedYtdl = 0;
        $clearedFailedAria2 = 0;
        $warnings = [];

        $liveYtdlRows = $this->dbconn->getYtdlByUidAndStatus($this->uid, [
            Helper::STATUS['ACTIVE'],
            Helper::STATUS['WAITING'],
            Helper::STATUS['PAUSED'],
        ]);

        $pids = [];
        foreach ($liveYtdlRows as $row) {
            $extra = isset($row['data']) ? $this->dbconn->getExtra($row['data']) : [];
            if (!is_array($extra) || empty($extra['pid'])) {
                continue;
            }

            $pid = (int) $extra['pid'];
            if ($pid > 1) {
                $pids[$pid] = true;
            }
        }

        $stoppedPids = [];
        $failedPids = [];
        foreach (array_keys($pids) as $pid) {
            if (!$this->processExists($pid)) {
                $stoppedPids[$pid] = true;
                continue;
            }

            if (!$this->looksLikeYtdlProcess($pid)) {
                $failedPids[$pid] = true;
                $warnings[] = sprintf('Refused to stop stale PID %d because it no longer looks like yt-dlp.', $pid);
                continue;
            }

            if ($this->stopProcessTree($pid)) {
                $stoppedYtdl++;
                $stoppedPids[$pid] = true;
            } else {
                $failedPids[$pid] = true;
                $warnings[] = sprintf('Could not terminate yt-dlp process tree %d completely. The queue entry was kept.', $pid);
            }
        }

        foreach ($liveYtdlRows as $row) {
            if (empty($row['gid'])) {
                continue;
            }

            $extra = isset($row['data']) ? $this->dbconn->getExtra($row['data']) : [];
            $pid = is_array($extra) && !empty($extra['pid']) ? (int) $extra['pid'] : 0;

            if ($pid > 1 && isset($failedPids[$pid])) {
                continue;
            }

            if ($pid > 1 && !isset($stoppedPids[$pid]) && $this->processExists($pid)) {
                $warnings[] = sprintf('yt-dlp PID %d is still running. The queue entry was kept.', $pid);
                continue;
            }

            $this->dbconn->deleteByGid((string) $row['gid']);
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

        // Clear failed yt-dlp history for the current user. Completed rows stay.
        $failedYtdlRows = $this->dbconn->getYtdlByUidAndStatus($this->uid, [Helper::STATUS['ERROR']]);
        foreach ($failedYtdlRows as $row) {
            if (!empty($row['gid'])) {
                $clearedFailedYtdl += $this->dbconn->deleteByGid((string) $row['gid']) > 0 ? 1 : 0;
            }
        }

        // aria2 keeps failed results in its stopped-result store. Remove only
        // entries owned by the current user and keep successful history intact.
        $failedAria2Jobs = $this->aria2->tellFail([0, 999]);
        if (is_array($failedAria2Jobs)) {
            foreach ($failedAria2Jobs as $job) {
                if (!is_array($job) || empty($job['gid'])) {
                    continue;
                }

                $rpcGid = (string) $job['gid'];
                $dbGid = (string) ($job['following'] ?? $rpcGid);
                if ($this->dbconn->getUidByGid($dbGid) !== $this->uid) {
                    continue;
                }

                $resp = $this->aria2->removeDownloadResult($rpcGid);
                $ok = isset($resp['result']) && is_string($resp['result']) && strtolower($resp['result']) === 'ok';
                if ($ok) {
                    $clearedFailedAria2++;
                    $this->dbconn->deleteByGid($dbGid);
                } else {
                    $warnings[] = sprintf('Could not clear failed aria2 result %s.', $rpcGid);
                }
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
                'Reset complete: %d yt-dlp process(es) stopped, %d aria2 download(s) removed, %d failed item(s) cleared.',
                $stoppedYtdl,
                $removedAria2,
                $clearedFailedYtdl + $clearedFailedAria2
            ),
            'warnings' => $warnings,
            'stopped_ytdl' => $stoppedYtdl,
            'removed_aria2' => $removedAria2,
            'cleared_failed_ytdl' => $clearedFailedYtdl,
            'cleared_failed_aria2' => $clearedFailedAria2,
        ]);
    }

    /**
     * The Symfony Process PID can be the privileged sudo/unshare/nsenter
     * wrapper, while yt-dlp and ffmpeg themselves are deliberately dropped back
     * to the PHP-FPM uid by nc-vpn-exec. We therefore stop the descendants that
     * are owned by the current PHP user first. The privileged wrapper is then
     * expected to unwind naturally when its child exits.
     */
    private function stopProcessTree(int $pid): bool
    {
        if (!$this->processExists($pid)) {
            return true;
        }

        $targets = $this->getOwnedProcessTargets($pid);
        if ($targets === []) {
            // The VPN wrapper may still be between sudo/nsenter and setpriv.
            // Keep the queue row instead of claiming success; a retry a moment
            // later will see the www-data yt-dlp child once it has started.
            return false;
        }

        $this->signalTargets($targets, 15);
        if ($this->waitForOwnedTargetsToExit($pid, 20, 100000)) {
            return true;
        }

        $targets = $this->getOwnedProcessTargets($pid);
        $this->signalTargets($targets, 9);

        return $this->waitForOwnedTargetsToExit($pid, 20, 100000);
    }

    /** @param int[] $targets */
    private function signalTargets(array $targets, int $signal): void
    {
        // collectDescendants() is parent-first. Reverse it so ffmpeg/yt-dlp
        // children are stopped before their parent process.
        foreach (array_reverse(array_values(array_unique($targets))) as $target) {
            if ($this->processExists($target)) {
                Helper::doSignal($target, $signal);
            }
        }
    }

    private function waitForOwnedTargetsToExit(int $pid, int $attempts, int $sleepMicros): bool
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($this->getOwnedProcessTargets($pid) === []) {
                return true;
            }
            usleep($sleepMicros);
        }

        return $this->getOwnedProcessTargets($pid) === [];
    }

    /** @return int[] */
    private function getOwnedProcessTargets(int $pid): array
    {
        $targets = $this->processExists($pid) ? $this->collectDescendants($pid) : [];
        if ($this->processExists($pid)) {
            $targets[] = $pid;
        }

        $selfUid = $this->getProcessUid(getmypid());
        if ($selfUid === null) {
            return [];
        }

        return array_values(array_filter(
            array_unique($targets),
            fn(int $target): bool => $this->getProcessUid($target) === $selfUid
        ));
    }

    private function getProcessUid(int $pid): ?int
    {
        if ($pid <= 1) {
            return null;
        }

        $status = @file_get_contents('/proc/' . $pid . '/status');
        if ($status === false || !preg_match('/^Uid:\s+(\d+)/m', $status, $matches)) {
            return null;
        }

        return (int) $matches[1];
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
