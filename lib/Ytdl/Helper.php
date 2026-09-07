<?php
namespace OCA\NCDownloader\Ytdl;

use OCA\NCDownloader\Db\Helper as DbHelper;
use OCA\NCDownloader\Tools\Helper as ToolsHelper;

class Helper
{
    public $file = null;
    protected $pid = 0;
    protected $dbconn;
    protected $tablename;
    protected $user;
    protected $tools;
    protected $gid;
    protected $ytdl;
    protected $status;
    protected $placeholderGid;
    protected $jobToken;
    protected $gids = [];
    protected $fileGids = [];

    public function __construct()
    {
        $this->dbconn = new DbHelper();
        $this->tablename = $this->dbconn->getTableName();
        $user = \OC::$server->get(\OCP\IUserSession::class)->getUser();
        $this->user = $user ? $user->getUID() : '';
    }

    public static function create()
    {
        return new static();
    }

    public function start(string $url, array $extra): string
    {
        $this->jobToken = ToolsHelper::generateGID($url . '|' . microtime(true) . '|' . mt_rand());
        $this->placeholderGid = $this->jobToken;
        $this->gid = $this->placeholderGid;

        $data = [
            'uid' => $this->user,
            'gid' => $this->placeholderGid,
            'type' => ToolsHelper::DOWNLOADTYPE['YOUTUBE-DL'],
            'filename' => 'Preparing download…',
            'status' => ToolsHelper::STATUS['ACTIVE'],
            'timestamp' => time(),
            'speed' => 'Starting',
            'progress' => '0%',
            'data' => $this->serializeExtra($extra),
        ];
        $this->dbconn->insert($data);

        return $this->placeholderGid;
    }

    public function getDownloadInfo(string $output): ?array
    {
        $rules = '#\[(?<module>(download|ExtractAudio|VideoConvertor|Merger|ffmpeg))\]((\s+|\s+Converting.*;\s+)Destination:\s+|\s+Merging formats into\s+\")' .
            '(?<filename>.*\.(?<ext>(mp4|mp3|aac|webm|m4a|ogg|3gp|mkv|wav|flv)))#i';

        if (preg_match($rules, $output, $matches)) {
            return $matches;
        }
        return null;
    }

    public function getSiteInfo(string $buffer): ?array
    {
        $regex = '/\[(?<site>.+)]\s(?<id>.+):\sDownloading\s.*/i';
        if (preg_match($regex, $buffer, $matches)) {
            return ["id" => $matches["id"], "site" => $matches["site"]];
        }
        return null;
    }

    public function getErrorInfo(string $buffer): ?array
    {
        $regex = '/ERROR:\s+\[(?<site>[^\]]+)]\s+(?<id>[^:]+):\s*(?<message>.*)/i';
        if (preg_match($regex, $buffer, $matches)) {
            return [
                'id' => trim((string) $matches['id']),
                'site' => trim((string) $matches['site']),
                'message' => trim((string) $matches['message']),
            ];
        }
        return null;
    }

    public function getProgress(string $buffer): ?array
    {
        $progressRegex = '#\[download\]\s+' .
        '(?<percentage>\d+(?:\.\d+)?%)' .
        '\s+of\s+[~]?\s*' .
        '(?<filesize>\d+(?:\.\d+)?(?:K|M|G)iB)' .
        '(?:\s+at\s+' .
        '(?<speed>(\d+(?:\.\d+)?(?:K|M|G)iB/s)|Unknown speed))' .
        '(?:\s+ETA\s+(?<eta>([\d:]{2,8}|Unknown ETA)))?' .
        '(\s+in\s+(?<totalTime>[\d:]{2,8}))?#i';

        if (preg_match_all($progressRegex, $buffer, $matches, PREG_SET_ORDER) !== false && count($matches) > 0) {
            return reset($matches);
        }
        return null;
    }

    protected function updateProgress(array $data)
    {
        extract($data);
        $sql = sprintf("UPDATE %s set filesize = ?,speed = ?,progress = ? WHERE gid = ?", $this->tablename);
        $this->dbconn->executeUpdate($sql, [$filesize, $speed, $percentage, $this->gid]);
    }

    public function log($message)
    {
        ToolsHelper::debug($message);
    }

    public function updateStatus($status = null)
    {
        if (isset($status)) {
            $this->status = trim((string) $status);
        }
        if ($this->gid) {
            $this->dbconn->updateStatus($this->gid, $this->status);
        }
    }

    public function markImportingFile(string $source): void
    {
        $gid = $this->findGidForFile($source);
        if ($gid) {
            $this->dbconn->updateStatus($gid, ToolsHelper::STATUS['WAITING']);
        }
    }

    public function markImportedFile(string $source, string $filename): void
    {
        $gid = $this->findGidForFile($source);
        if (!$gid) {
            return;
        }

        $this->dbconn->setFilename($gid, basename($filename));
        $this->dbconn->updateStatus($gid, ToolsHelper::STATUS['COMPLETE']);
        $this->setFinishedAt($gid);
        $this->fileGids[basename($filename)] = $gid;
    }

    public function setCurrentFilename(string $filename): void
    {
        if ($this->gid) {
            $this->dbconn->setFilename($this->gid, basename($filename));
            $this->rememberFileForCurrentGid($filename);
        }
    }

    public function updateAllStatus(int $status, bool $preserveTerminal = false): void
    {
        $this->status = $status;
        $gids = $this->gids;
        if ($gids === [] && $this->placeholderGid) {
            $gids[] = $this->placeholderGid;
        }

        foreach (array_unique($gids) as $gid) {
            if ($preserveTerminal) {
                $row = $this->dbconn->getByGid($gid);
                $current = (int) ($row['status'] ?? -1);
                if (in_array($current, [ToolsHelper::STATUS['COMPLETE'], ToolsHelper::STATUS['ERROR']], true)) {
                    continue;
                }
            }
            $this->dbconn->updateStatus($gid, $status);
            if ($status === ToolsHelper::STATUS['COMPLETE']) {
                $this->setFinishedAt($gid);
            }
        }
    }

    public function applyImportedNames(array $imported): void
    {
        if ($imported === []) {
            return;
        }

        $names = [];
        foreach ($imported as $item) {
            if (isset($item['source'], $item['name'])) {
                $names[basename((string) $item['source'])] = (string) $item['name'];
            }
        }

        foreach ($this->gids as $gid) {
            $row = $this->dbconn->getByGid($gid);
            if (!$row || !isset($row['filename'])) {
                continue;
            }
            $current = basename((string) $row['filename']);
            if (isset($names[$current]) && $names[$current] !== $current) {
                $this->dbconn->setFilename($gid, $names[$current]);
                $this->fileGids[basename($names[$current])] = $gid;
            }
        }
    }

    public function setPid($pid)
    {
        $this->pid = $pid;
    }

    public function run(string $buffer, array $extra)
    {
        if ($errorInfo = $this->getErrorInfo($buffer)) {
            $this->gid = ToolsHelper::generateGID($errorInfo['id'] . '|' . ($this->jobToken ?? 'mediafetch'));
            $extra['error'] = $errorInfo['message'];
            $this->ensureCurrentItem($extra, $errorInfo['id']);
            $this->dbconn->updateStatus($this->gid, ToolsHelper::STATUS['ERROR']);
            return;
        }

        $info = $this->getSiteInfo($buffer);
        if (isset($info["id"])) {
            $this->gid = ToolsHelper::generateGID($info["id"] . '|' . ($this->jobToken ?? 'mediafetch'));
            $this->ensureCurrentItem($extra, $info['id']);
        }
        if (!$this->gid || $this->gid === $this->placeholderGid) {
            $this->gid = ToolsHelper::generateGID($extra["link"] . '|' . ($this->jobToken ?? microtime(true)));
            $this->ensureCurrentItem($extra, 'Preparing download…');
        }

        $downloadInfo = $this->getDownloadInfo($buffer);
        if ($downloadInfo) {
            $file = $downloadInfo["filename"];
            $module = $downloadInfo["module"];
            $this->file = basename($file);
            if (strtolower($module) === "download") {
                $this->save($file, $extra);
            } else {
                $this->updateFilename($file);
            }
        }
        if ($progress = $this->getProgress($buffer)) {
            $this->updateProgress($progress);
        }
    }

    protected function save(string $file, array $extra)
    {
        $data = [
            'uid' => $this->user,
            'gid' => $this->gid,
            'type' => ToolsHelper::DOWNLOADTYPE['YOUTUBE-DL'],
            'filename' => basename($file),
            'status' => ToolsHelper::STATUS['ACTIVE'],
            'timestamp' => time(),
            'data' => $this->serializeExtra($extra),
        ];

        $this->dbconn->insert($data);
        $this->dbconn->setFilename($this->gid, basename($file));
        $this->dbconn->setData($this->gid, $this->serializeExtra($extra));
        $this->dbconn->updateStatus($this->gid, ToolsHelper::STATUS['ACTIVE']);
        $this->rememberFileForCurrentGid($file);

        if (!in_array($this->gid, $this->gids, true)) {
            $this->gids[] = $this->gid;
        }

        $this->removePlaceholder();
    }

    private function ensureCurrentItem(array $extra, string $label): void
    {
        if (!$this->gid) {
            return;
        }

        $data = [
            'uid' => $this->user,
            'gid' => $this->gid,
            'type' => ToolsHelper::DOWNLOADTYPE['YOUTUBE-DL'],
            'filename' => $label !== '' ? $label : 'Preparing download…',
            'status' => ToolsHelper::STATUS['ACTIVE'],
            'timestamp' => time(),
            'speed' => 'Starting',
            'progress' => '0%',
            'data' => $this->serializeExtra($extra),
        ];

        $this->dbconn->insert($data);
        $this->dbconn->setData($this->gid, $this->serializeExtra($extra));
        if (!in_array($this->gid, $this->gids, true)) {
            $this->gids[] = $this->gid;
        }

        $this->removePlaceholder();
    }

    private function removePlaceholder(): void
    {
        if ($this->placeholderGid && $this->placeholderGid !== $this->gid) {
            $this->dbconn->deleteByGid($this->placeholderGid);
            $this->placeholderGid = null;
        }
    }

    private function updateFilename(string $file)
    {
        $this->dbconn->setFilename($this->gid, basename($file));
        $this->rememberFileForCurrentGid($file);
    }

    private function rememberFileForCurrentGid(string $file): void
    {
        if ($this->gid) {
            $this->fileGids[basename($file)] = $this->gid;
        }
    }

    private function findGidForFile(string $file): ?string
    {
        $basename = basename($file);
        if (isset($this->fileGids[$basename])) {
            return $this->fileGids[$basename];
        }

        foreach ($this->gids as $gid) {
            $row = $this->dbconn->getByGid($gid);
            if ($row && basename((string) ($row['filename'] ?? '')) === $basename) {
                $this->fileGids[$basename] = $gid;
                return $gid;
            }
        }

        return $this->gid ?: null;
    }

    private function setFinishedAt(string $gid): void
    {
        $row = $this->dbconn->getByGid($gid);
        if (!$row || !isset($row['data'])) {
            return;
        }

        $extra = $this->dbconn->getExtra($row['data']);
        if (!is_array($extra)) {
            $extra = [];
        }
        $extra['finished_at'] = time();
        $this->dbconn->setData($gid, $this->serializeExtra($extra));
    }

    private function serializeExtra(array $extra)
    {
        $serialized = serialize($extra);
        if ($this->dbconn->getDBType() === "pgsql" && function_exists("pg_escape_bytea")) {
            return pg_escape_bytea($serialized);
        }
        return $serialized;
    }
}
