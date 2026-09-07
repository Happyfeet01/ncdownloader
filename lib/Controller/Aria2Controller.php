<?php
namespace OCA\NCDownloader\Controller;

use OCA\NCDownloader\Aria2\Aria2;
use OCA\NCDownloader\Tools\Counters;
use OCA\NCDownloader\Db\Helper as DbHelper;
use OCA\NCDownloader\Tools\Helper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use \OC\Files\Filesystem;

class Aria2Controller extends Controller
{
    private $uid;
    private $settings = null;
    //@config OC\AppConfig
    private $config;
    private $l10n;
    private $minmax = [-1, 999];
    private $aria2;
    private $dbconn;
    private $counters;
    private $rootFolder;
    private $downloadDir;
    private $urlGenerator;

    public function __construct(
        $appName,
        IRequest $request,
        $UserId,
        IL10N $IL10N,
        IRootFolder $rootFolder,
        Aria2 $aria2,
        IURLGenerator $urlGenerator
    ) {
        parent::__construct($appName, $request);
        $this->uid = $UserId;
        $this->l10n = $IL10N;
        $this->rootFolder = $rootFolder;
        $this->urlGenerator = $urlGenerator;
        $this->downloadDir = Helper::getDownloadDir();
        \OC_Util::setupFS();
        $this->aria2 = $aria2;
        $this->aria2->init();
        $this->dbconn = new DbHelper();
        $this->counters = new Counters($aria2, $this->dbconn, $UserId);
    }

    /**
     * @NoAdminRequired
     */
    public function Action($path)
    {
        $path = strtolower(trim($path));
        $resp = [];

        if (!in_array($path, ['start', 'check']) && !($gid = $this->request->getParam('gid'))) {
            return new JSONResponse(['error' => "no gid value is received!"]);
        }
        switch (strtolower($path)) {
            case "check":
                $resp = $this->aria2->isRunning();
                break;
            case "start":
                $resp = $this->Start();
                break;
            case "unpause":
            case "remove":
            case "pause":
                $resp = $this->doAction($path, $gid);
                if ($path === "remove" && isset($resp['status']) && $resp['status']) {
                    $this->dbconn->deleteByGid($gid);
                }
                break;
            case "get":
                $resp = $this->doAction('tellStatus', $gid);
                break;
            case 'purge':
                $resp = $this->doAction('removeDownloadResult', $gid);
                if (isset($resp['status']) && $resp['status']) {
                    $this->dbconn->deleteByGid($gid);
                }
        }
        return new JSONResponse($resp);
    }

    private function doAction($action, $gid)
    {
        if (!$action || !$gid) {
            return [];
        }
        $resp = $this->aria2->{$action}($gid);
        if (isset($resp['result'])) {
            switch ($action) {
                case "pause":
                    $this->dbconn->updateStatus($gid, Helper::STATUS['PAUSED']);
                    break;
                case "unpause":
                    $this->dbconn->updateStatus($gid);
                    break;
            }
        }
        if (in_array($action, ['removeDownloadResult', 'remove'])) {
            $result = $resp['result'] ?? null;
            if (isset($result)) {
                if (strtolower($result) === 'ok') {
                    return ['message' => $this->l10n->t("DONE!"), 'status' => 1];
                }
                if (is_string($result)) {
                    return ['message' => $this->l10n->t("DONE!"), 'status' => 1];
                }
            } else {
                return ['error' => $this->l10n->t("FAILED!"), 'status' => 0];
            }
        }
        return $resp;
    }

    private function Start()
    {
        if ($this->aria2->isRunning()) {
            $data = ['status' => (bool) $this->aria2->stop()];
            return $data;
        }
        $data = $this->aria2->start();
        return $data;
    }

    private function createActionItem($name, $path)
    {
        return array(
            'name' => $name,
            'path' => $this->urlGenerator->linkToRoute('mediafetch.Aria2.Action', ['path' => $path]),
        );
    }

    /**
     * @NoAdminRequired
     */
    public function getStatus($path)
    {
        $counter = $this->counters->getCounters();
        $normalizedPath = strtolower($path);
        switch ($normalizedPath) {
            case "active":
                $resp = $this->aria2->tellActive();
                break;
            case "waiting":
                $resp = $this->aria2->tellWaiting($this->minmax);
                break;
            case "complete":
                $resp = $this->aria2->tellStopped($this->minmax);
                break;
            case "fail":
                $resp = $this->aria2->tellFail($this->minmax);
                break;
            default:
                $resp = $this->aria2->tellActive();
        }
        if (isset($resp['error'])) {
            return new JSONResponse($resp);
        }

        $data = $this->transformResp($resp);
        if (in_array($normalizedPath, ['complete', 'fail'], true)) {
            $status = $normalizedPath === 'complete' ? Helper::STATUS['COMPLETE'] : Helper::STATUS['ERROR'];
            $ytdlRows = $this->dbconn->getYtdlByUidAndStatus($this->uid, [$status]);
            $historyRows = $this->transformYtdlHistoryRows($ytdlRows, $status);
            if ($historyRows !== []) {
                $data['row'] = array_merge($data['row'] ?? [], $historyRows);
                $data['title'] = Helper::getTableTitles();
            }
        }

        $data['counter'] = $counter;
        return new JSONResponse($data);
    }

    private function transformResp($resp)
    {
        $data = [];
        if (empty($resp)) {
            return $data;
        }
        $data['row'] = [];
        $resp = $this->filterData($resp);
        foreach ($resp as $value) {
            $gid = $value['following'] ?? $value['gid'];
            $extra = [];
            $timestamp = 0;
            if ($row = $this->dbconn->getByGid($gid)) {
                $filename = $row['filename'];
                $timestamp = $row['timestamp'];
                $extra = $this->dbconn->getExtra(($row['data']));
                if (!is_array($extra)) {
                    $extra = [];
                }
            } else if (isset($value['files'][0]['path'])) {
                $parts = explode("/", ($path = $value['files'][0]['path']));
                if (count($parts) > 1) {
                    $filename = basename(dirname($path));
                } else {
                    $filename = basename($path);
                }
            } else {
                $filename = "Unknown";
            }
            if (!isset($value['completedLength'])) {
                continue;
            }

            $dlDir = $extra['path'] ?? $this->downloadDir;
            $file = $dlDir . "/" . $filename;
            $params = ['dir' => $dlDir];
            $fileInfo = Filesystem::getFileInfo($file);
            if ($fileInfo) {
                $fileType = $fileInfo->getType();
                if ($fileType === "dir") {
                    $params = ['dir' => $file];
                }
            }
            $folderLink = $this->urlGenerator->linkToRoute('files.view.index', $params);
            $completed = Helper::formatBytes($value['completedLength']);
            $percentage = $value['completedLength'] ? 100 * ($value['completedLength'] / $value['totalLength']) : 0;
            $completed = Helper::formatBytes($value['completedLength']);
            $total = Helper::formatBytes($value['totalLength']);

            $remaining = (int) $value['totalLength'] - (int) $value['completedLength'];
            $remaining = ($value['downloadSpeed'] > 0) ? ($remaining / $value['downloadSpeed']) : 0;
            $left = Helper::formatInterval($remaining);

            $numSeeders = $value['numSeeders'] ?? 0;
            $upload = $value['uploadLength'] ?? 0;
            $upload = Helper::formatBytes($upload);
            $extraInfo = "Seeders: $numSeeders|Up:$upload";
            $value['progress'] = array(sprintf("%s(%.2f%%)", $completed, $percentage), $extraInfo);
            $tmp = [];
            $actions = [];
            $filename = sprintf('<a class="download-file-folder" href="%s">%s</a>', $folderLink, htmlspecialchars((string) $filename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $fileInfo = sprintf('<button id="icon-clipboard" class="icon-clipboard" data-text="%s"></button> %s | %s', htmlspecialchars((string) ($extra["link"] ?? 'nolink'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $total, date("Y-m-d H:i:s", (int) $timestamp));
            $tmp['filename'] = array($filename, $fileInfo);

            if ($this->aria2->methodName === "tellStopped") {
                $actions[] = $this->createActionItem('purge', 'purge');
            }
            if ($this->aria2->methodName === "tellWaiting") {
                $actions[] = $this->createActionItem('unpause', 'unpause');
                $actions[] = $this->createActionItem('delete', 'remove');
            }
            if ($this->aria2->methodName === "tellActive") {
                $speed = [Helper::formatBytes($value['downloadSpeed']), $left];
                $tmp['speed'] = $speed;
                $tmp['progress'] = $value['progress'];
                $actions[] = $this->createActionItem('pause', 'pause');
                $actions[] = $this->createActionItem('cancel', 'remove');
            }
            if (strtolower($value['status']) === 'error') {
                $tmp['status'] = $value['errorMessage'];
            } else if ($this->aria2->methodName !== "tellActive") {
                $tmp['status'] = $value['status'];
            }
            $tmp['data_gid'] = $value['gid'] ?? 0;
            $tmp['actions'] = $actions;
            array_push($data['row'], $tmp);
        }
        if ($this->aria2->methodName === "tellActive") {
            $data['title'] = Helper::getTableTitles('active');
        } else {
            $data['title'] = Helper::getTableTitles();
        }
        return $data;
    }

    private function transformYtdlHistoryRows(array $rows, int $status): array
    {
        $result = [];
        foreach ($rows as $row) {
            $extra = isset($row['data']) ? $this->dbconn->getExtra($row['data']) : [];
            if (!is_array($extra)) {
                $extra = [];
            }

            $folder = (string) ($extra['path'] ?? $this->downloadDir);
            $folderLink = $this->urlGenerator->linkToRoute('files.view.index', ['dir' => $folder]);
            $safeFilename = htmlspecialchars((string) ($row['filename'] ?? 'unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $filename = sprintf('<a class="download-file-folder" href="%s">%s</a>', htmlspecialchars($folderLink, ENT_QUOTES, 'UTF-8'), $safeFilename);
            $link = htmlspecialchars((string) ($extra['link'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $timestamp = isset($row['timestamp']) ? date('Y-m-d H:i:s', (int) $row['timestamp']) : '';
            $fileInfo = sprintf('<button id="icon-clipboard" class="icon-clipboard" data-text="%s"></button> yt-dlp | %s', $link, $timestamp);

            $statusText = $status === Helper::STATUS['COMPLETE'] ? $this->l10n->t('Complete') : $this->l10n->t('Error');
            if ($status === Helper::STATUS['ERROR'] && !empty($extra['error'])) {
                $error = trim((string) $extra['error']);
                $error = function_exists('mb_strimwidth') ? mb_strimwidth($error, 0, 180, '…') : substr($error, 0, 180);
                $statusText .= ': ' . $error;
            }

            $actions = [[
                'name' => 'delete',
                'path' => $this->urlGenerator->linkToRoute('mediafetch.Ytdl.Delete'),
            ]];
            if (!empty($extra['link'])) {
                $actions[] = [
                    'name' => 'refresh',
                    'path' => $this->urlGenerator->linkToRoute('mediafetch.Ytdl.Redownload'),
                ];
            }

            $result[] = [
                'filename' => [$filename, $fileInfo],
                'status' => $statusText,
                'actions' => $actions,
                'data_gid' => (string) ($row['gid'] ?? ''),
            ];
        }

        return $result;
    }

    private function filterData($resp)
    {
        $data = [];
        if (empty($resp)) {
            return $data;
        }
        if (isset($resp['error'])) {
            return $resp;
        }

        $data = array_filter($resp, function ($value) {
            $gid = $value['following'] ?? $value['gid'];
            return (bool) ($this->dbconn->getUidByGid($gid) === $this->uid);
        });

        return $data;
    }
}
