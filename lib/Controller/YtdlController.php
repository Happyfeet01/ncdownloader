<?php

namespace OCA\NCDownloader\Controller;

use OCA\NCDownloader\Aria2\Aria2;
use OCA\NCDownloader\Db\Helper as DbHelper;
use OCA\NCDownloader\Files\MediaImporter;
use OCA\NCDownloader\Tools\Helper;
use OCA\NCDownloader\Ytdl\Ytdl;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class YtdlController extends Controller
{
    private $l10n;
    private $audio_extensions = array("mp3", "m4a", "vorbis");
    private $video_extensions = array("mp4", "webm", "mkv");
    private $uid;
    private $downloadDir;
    private $dbconn;
    private $ytdl;
    private $aria2;
    private $urlGenerator;
    private $dataDir;
    private MediaImporter $mediaImporter;
    private LoggerInterface $logger;

    public function __construct(
        $appName,
        IRequest $request,
        $UserId,
        IL10N $IL10N,
        Aria2 $aria2,
        Ytdl $ytdl,
        IURLGenerator $urlGenerator,
        MediaImporter $mediaImporter,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->appName = $appName;
        $this->uid = $UserId;
        $this->urlGenerator = $urlGenerator;
        $this->l10n = $IL10N;
        $this->downloadDir = Helper::getDownloadDir();
        $this->dbconn = new DbHelper();
        $this->ytdl = $ytdl;
        $this->aria2 = $aria2;
        $this->aria2->init();
        $this->mediaImporter = $mediaImporter;
        $this->logger = $logger;
    }

    /**
     * @NoAdminRequired
     */
    public function Index()
    {
        $data = $this->dbconn->getYtdlByUidAndStatus($this->uid, [
            Helper::STATUS['ACTIVE'],
            Helper::STATUS['WAITING'],
        ]);
        if (count($data) < 1) {
            return new JSONResponse([]);
        }

        $resp = ['title' => [], 'row' => []];
        foreach ($data as $value) {
            $extra = isset($value['data']) ? $this->dbconn->getExtra($value['data']) : [];
            if (!is_array($extra)) {
                $extra = [];
            }

            $folder = (string) ($extra['path'] ?? $this->downloadDir);
            $folderLink = $this->urlGenerator->linkToRoute('files.view.index', ['dir' => $folder]);
            $safeFilename = htmlspecialchars((string) ($value['filename'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $filename = sprintf('<a class="download-file-folder" href="%s">%s</a>', htmlspecialchars($folderLink, ENT_QUOTES, 'UTF-8'), $safeFilename);
            $link = htmlspecialchars((string) ($extra['link'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $filesize = htmlspecialchars((string) ($value['filesize'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $timestamp = isset($value['timestamp']) ? date("Y-m-d H:i:s", (int) $value['timestamp']) : '';
            $fileInfo = sprintf('<div class="ncd-file-info"><button id="icon-clipboard" class="icon-clipboard" data-text="%s"></button> %s | %s</div>', $link, $filesize, $timestamp);

            $tmp = [];
            $tmp['filename'] = [$filename, $fileInfo];
            $tmp['speed'] = explode("|", (string) ($value['speed'] ?? ''));
            $tmp['progress'] = (string) ($value['progress'] ?? '0%');
            $tmp['status'] = $this->statusLabel((int) ($value['status'] ?? Helper::STATUS['ACTIVE']));
            $tmp['actions'] = [];

            if (!empty($extra['pid'])) {
                $tmp['actions'][] = [
                    'name' => 'cancel',
                    'path' => $this->urlGenerator->linkToRoute('mediafetch.Ytdl.Delete'),
                ];
            }

            $tmp['data_gid'] = (string) ($value['gid'] ?? '');
            $resp['row'][] = $tmp;
        }

        $resp['title'] = ['filename', 'speed', 'progress', 'status', 'actions'];
        $resp['counter'] = ['ytdl' => count($data)];
        return new JSONResponse($resp);
    }

    /**
     * @NoAdminRequired
     */
    public function Download(?string $url = null, ?string $extension = null)
    {
        $url = $url ?? $this->request->getParam('url') ?? $this->request->getParam('text-input-value');
        $extension = $extension ?? $this->request->getParam('extension') ?? "mp4";
        $extension = trim((string) $extension) ?: "mp4";
        if (!$url || !is_string($url)) {
            return new JSONResponse(['error' => "no url value is received!"]);
        }

        $url = trim($url);
        $yt = $this->ytdl;
        if (in_array($extension, $this->audio_extensions, true)) {
            $yt->audioOnly = true;
            $yt->audioFormat = $extension;
        } else {
            $yt->videoFormat = $extension;
        }

        if (!$yt->isInstalled()) {
            return new JSONResponse(["error" => "Please install the latest yt-dlp or configure an administrator-managed yt-dlp binary."]);
        }

        if (Helper::isGetUrlSite($url)) {
            return new JSONResponse($this->downloadUrlSite($url));
        }

        return new JSONResponse($this->executeYtdlDownload($yt, $url));
    }

    private function executeYtdlDownload(Ytdl $yt, string $url): array
    {
        try {
            $workspace = $this->mediaImporter->createWorkspace($this->uid);
        } catch (\Throwable $e) {
            $this->logger->error('Could not create MediaFetch workspace: ' . $e->getMessage(), ['app' => 'mediafetch']);
            return ['error' => 'MediaFetch could not create a private download workspace.'];
        }

        $targetPath = Helper::getDownloadDir();
        $directImported = [];
        $directImportFailed = false;

        $yt->setDownloadDir($workspace);
        $yt->dbDlPath = $targetPath;
        $yt->setCompletedFileHandler(function (string $source) use ($yt, $workspace, $targetPath, &$directImported, &$directImportFailed): void {
            if (isset($directImported[$source])) {
                return;
            }

            $yt->markCurrentImporting();

            try {
                $item = $this->mediaImporter->importFile(
                    $this->uid,
                    $workspace,
                    $source,
                    $targetPath,
                    false
                );
                $directImported[$source] = $item;
                $yt->markCurrentImported((string) $item['name']);
            } catch (\Throwable $e) {
                $directImportFailed = true;
                $this->logger->error(
                    'Could not import completed yt-dlp item immediately: ' . $e->getMessage(),
                    ['app' => 'mediafetch', 'workspace' => $workspace, 'source' => $source, 'user' => $this->uid]
                );
            }
        });

        try {
            $resp = $yt->forceIPV4()->download($url);
        } finally {
            $yt->setCompletedFileHandler(null);
        }

        $alreadyImportedSources = array_keys($directImported);

        if (isset($resp['error'])) {
            try {
                $this->mediaImporter->importWorkspace(
                    $this->uid,
                    $workspace,
                    $targetPath,
                    $alreadyImportedSources
                );
                $this->mediaImporter->cleanupWorkspace($workspace);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'yt-dlp failed and MediaFetch could not salvage all completed files: ' . $e->getMessage(),
                    ['app' => 'mediafetch', 'workspace' => $workspace, 'user' => $this->uid]
                );
            }

            return $resp;
        }

        try {
            $remaining = $this->mediaImporter->importWorkspace(
                $this->uid,
                $workspace,
                $targetPath,
                $alreadyImportedSources
            );

            $imported = array_merge(array_values($directImported), $remaining);
            $yt->markImported($imported);
            $this->mediaImporter->cleanupWorkspace($workspace);
        } catch (\Throwable $e) {
            $yt->markImportFailed();
            $this->logger->error(
                'yt-dlp download completed but Nextcloud import failed: ' . $e->getMessage(),
                ['app' => 'mediafetch', 'workspace' => $workspace, 'user' => $this->uid]
            );
            return ['error' => 'Download finished, but MediaFetch could not add all files to Nextcloud. The temporary download was kept for recovery.'];
        }

        if ($directImportFailed) {
            $this->logger->warning(
                'One or more immediate yt-dlp imports failed but were recovered by the final workspace import.',
                ['app' => 'mediafetch', 'user' => $this->uid]
            );
        }

        if ($imported === []) {
            return $resp;
        }

        $names = array_values(array_map(static fn(array $item): string => (string) $item['name'], $imported));
        return [
            'message' => count($names) === 1
                ? sprintf('Added %s to Nextcloud', $names[0])
                : sprintf('Added %d files to Nextcloud', count($names)),
            'file' => $names[0] ?? '',
            'files' => $names,
            'path' => $targetPath,
        ];
    }

    private function downloadUrlSite($url)
    {
        $yt = $this->ytdl;
        if ($data = $yt->forceIPV4()->getDownloadUrl($url)) {
            return $this->_download($data['url'], $data['filename'] ?? null);
        }
        return ['error' => $this->l10n->t("failed to get any url!")];
    }

    /**
     * @NoAdminRequired
     */
    public function Delete(?string $gid = null)
    {
        $gid = $gid ?? $this->request->getParam('gid');
        if (!$gid) {
            return new JSONResponse(['error' => "no gid value is received!"]);
        }

        $row = $this->dbconn->getByGid($gid);
        if (!$row || !isset($row['data'])) {
            return new JSONResponse(['error' => sprintf("%s was not found in database!", $gid)]);
        }

        $data = $this->dbconn->getExtra($row['data']);
        if (!is_array($data)) {
            $data = [];
        }

        $status = (int) ($row['status'] ?? Helper::STATUS['ACTIVE']);
        $isRunningStatus = in_array($status, [Helper::STATUS['ACTIVE'], Helper::STATUS['WAITING']], true);

        if ($isRunningStatus) {
            if (empty($data['pid'])) {
                return new JSONResponse(['error' => 'The yt-dlp process is still starting. Please try cancelling again in a moment.']);
            }

            $pid = (int) $data['pid'];
            if (Helper::isRunning($pid) && !Helper::stop($pid)) {
                return new JSONResponse(['error' => sprintf('Failed to terminate yt-dlp process %d.', $pid)]);
            }

            $this->dbconn->updateStatus($gid, Helper::STATUS['ERROR']);
            return new JSONResponse(['message' => $this->l10n->t('Download cancelled')]);
        }

        $deleted = $this->dbconn->deleteByGid($gid);
        return new JSONResponse([
            'message' => $deleted ? $this->l10n->t('Entry removed') : $this->l10n->t('Nothing deleted'),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function Redownload(?string $gid = null)
    {
        $gid = $gid ?? $this->request->getParam('gid');
        if (!$gid) {
            return new JSONResponse(['error' => "no gid value is received!"]);
        }
        $row = $this->dbconn->getByGid($gid);
        if (!$row || !isset($row['data'])) {
            return new JSONResponse(['error' => sprintf("%s was not found in database!", $gid)]);
        }
        $data = $this->dbconn->getExtra($row["data"]);
        if (!is_array($data) || empty($data['link'])) {
            return new JSONResponse(['error' => "no link"]);
        }

        if (isset($data['ext'])) {
            if (in_array($data['ext'], $this->audio_extensions, true)) {
                $this->ytdl->audioOnly = true;
                $this->ytdl->audioFormat = $data['ext'];
            } else {
                $this->ytdl->audioOnly = false;
                $this->ytdl->videoFormat = $data['ext'];
            }
        }

        return new JSONResponse($this->executeYtdlDownload($this->ytdl, (string) $data['link']));
    }

    private function _download($url, $filename = null)
    {
        if (!$filename) {
            $filename = Helper::getFileName($url);
        }
        $this->aria2->setFileName($filename);
        $result = $this->aria2->download($url);

        if (!$result) {
            return ['error' => 'failed to download the file for some reason!'];
        }
        if (isset($result['error'])) {
            return $result;
        }

        $data = [
            'uid' => $this->uid,
            'gid' => $result,
            'type' => 1,
            'filename' => $filename ?? 'unknown',
            'timestamp' => time(),
            'data' => serialize(['link' => $url]),
        ];
        $this->dbconn->save($data);
        return ['message' => $filename, 'result' => $result];
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            Helper::STATUS['PAUSED'] => $this->l10n->t('Paused'),
            Helper::STATUS['COMPLETE'] => $this->l10n->t('Complete'),
            Helper::STATUS['WAITING'] => $this->l10n->t('Adding to Nextcloud…'),
            Helper::STATUS['ERROR'] => $this->l10n->t('Error'),
            default => $this->l10n->t('Downloading…'),
        };
    }
}
