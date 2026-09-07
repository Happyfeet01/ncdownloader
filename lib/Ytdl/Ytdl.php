<?php

namespace OCA\NCDownloader\Ytdl;

use OCA\NCDownloader\Tools\Helper;
use OCA\NCDownloader\Ytdl\Helper as YtdHelper;
use Symfony\Component\Process\Process;

class Ytdl
{
    public $audioOnly = 0;
    public $audioFormat = 'm4a', $videoFormat = null;
    public $dbDlPath = null;
    private $format = 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best';
    private $options = [];
    private $downloadDir;
    private $timeout = 60 * 60 * 10;
    private $outTpl = "%(title).32s.%(ext)s";
    private $defaultDir = "/tmp/downloads";
    private $env = [];
    private $bin;
    private $cmd;
    private $completedFileHandler = null;
    private $afterMoveLog = null;
    private $afterMoveOffset = 0;
    public $helper;

    public function __construct(array $options)
    {
        $options += ['downloadDir' => '/tmp/downloads', 'settings' => []];
        $this->init($options);
    }

    public function init(array $options)
    {
        extract($options);
        if (!empty($binary)) {
            $this->bin = $binary;
        } else {
            $this->bin = __DIR__ . "/../../bin/yt-dlp";
        }
        if ($this->isInstalled() && !$this->isExecutable()) {
            chmod($this->bin, 0744);
        }
        $this->setDownloadDir($downloadDir);
        if (!empty($settings)) {
            foreach ($settings as $key => $value) {
                if (empty($value)) {
                    $this->addOption($key, true);
                } else {
                    $this->setOption($key, $value, true);
                }
            }
        }
        if (empty($lang = getenv('LANG')) || strpos(strtolower($lang), 'c.utf-8') === false) {
            $lang = 'C.UTF-8';
        }
        $this->setEnv('LANG', $lang);
        $this->addOption("--no-mtime");
        $this->addOption('--ignore-errors');

        if (($index = $this->hasOption('--output')) !== false) {
            $this->outTpl = $this->options[$index + 1];
            unset($this->options[$index]);
            unset($this->options[$index + 1]);
            $this->options = array_values($this->options);
        }
    }

    public function setEnv($key, $val)
    {
        $this->env[$key] = $val;
    }

    public function audioMode()
    {
        if (Helper::ffmpegInstalled()) {
            $this->addOption('--extract-audio');
        } else {
            $this->audioFormat = "m4a";
        }
        $this->setAudioFormat($this->audioFormat);
        return $this;
    }

    public function setAudioQuality($value = 0)
    {
        $this->setOption('--audio-quality', $value);
    }

    public function setAudioFormat($format)
    {
        $this->setOption('--audio-format', $format);
    }

    public function setVideoFormat($format)
    {
        $this->setOption('--recode-video', $format);
    }

    public function GetUrlOnly()
    {
        $this->addOption('--get-filename');
        $this->addOption('--get-url');
        return $this;
    }

    public static function create($options)
    {
        return new self($options);
    }

    public function setDownloadDir($dir)
    {
        $this->downloadDir = rtrim((string) $dir, '/');
        return $this;
    }

    public function getDownloadDir()
    {
        return $this->downloadDir;
    }

    public function setCompletedFileHandler(?callable $handler): self
    {
        $this->completedFileHandler = $handler;
        return $this;
    }

    public function prependOption(string $option)
    {
        array_unshift($this->options, $option);
    }

    public function download($url)
    {
        if ($this->audioOnly) {
            $this->audioMode();
        } else {
            if (Helper::ffmpegInstalled() && $this->videoFormat) {
                $this->setOption('--format', 'bestvideo+bestaudio/best');
                $this->setVideoFormat($this->videoFormat);
            } else {
                $this->setOption('--format', $this->format);
            }
        }

        $this->helper = YtdHelper::create();
        $this->downloadDir = $this->downloadDir ?: $this->defaultDir;
        $this->setOption("--output", $this->downloadDir . "/" . $this->outTpl);
        $this->configureAfterMoveTracking();
        $this->setUrl($url);
        $this->prependOption($this->bin);

        $data = ['link' => $url, 'path' => $this->dbDlPath];
        if ($this->audioOnly) {
            $data['ext'] = $this->audioFormat;
        } else {
            $data['ext'] = $this->videoFormat;
        }
        $this->helper->start($url, $data);

        $process = new Process($this->options, null, $this->env);
        $process->setTimeout($this->timeout);

        try {
            $process->run(function ($type, $buffer) use ($data, $process) {
                $this->drainCompletedFiles();

                $extra = $data;
                $extra['pid'] = $process->getPid();
                if (Process::ERR === $type) {
                    $this->onError($buffer, $extra);
                } else {
                    $this->onOutput($buffer, $extra);
                }

                $this->drainCompletedFiles();
            });

            $this->drainCompletedFiles();
        } finally {
            $this->cleanupAfterMoveTracking();
        }

        if ($process->isSuccessful()) {
            $this->helper->updateAllStatus(Helper::STATUS['WAITING'], true);
            return ['message' => $this->helper->file ?? 'Download finished'];
        }

        $this->helper->updateAllStatus(Helper::STATUS['ERROR'], true);
        return ['error' => $process->getErrorOutput() ?: 'yt-dlp failed'];
    }

    public function markCurrentImporting(string $source): void
    {
        if ($this->helper) {
            $this->helper->markImportingFile($source);
        }
    }

    public function markCurrentImported(string $source, string $filename): void
    {
        if ($this->helper) {
            $this->helper->markImportedFile($source, $filename);
        }
    }

    public function markImported(array $imported): void
    {
        if (!$this->helper) {
            return;
        }
        $this->helper->applyImportedNames($imported);
        $this->helper->updateAllStatus(Helper::STATUS['COMPLETE'], true);
    }

    public function markImportFailed(): void
    {
        if ($this->helper) {
            $this->helper->updateAllStatus(Helper::STATUS['ERROR'], true);
        }
    }

    private function configureAfterMoveTracking(): void
    {
        if (!$this->completedFileHandler || $this->downloadDir === '') {
            return;
        }

        $this->afterMoveLog = $this->downloadDir . '/.mediafetch-after-move';
        $this->afterMoveOffset = 0;
        @unlink($this->afterMoveLog);

        $this->addOption('--print-to-file');
        $this->addOption('after_move:%(filepath)s');
        $this->addOption($this->afterMoveLog);
    }

    private function drainCompletedFiles(): void
    {
        if (!$this->completedFileHandler || !$this->afterMoveLog || !is_file($this->afterMoveLog)) {
            return;
        }

        $handle = @fopen($this->afterMoveLog, 'rb');
        if (!is_resource($handle)) {
            return;
        }

        try {
            if ($this->afterMoveOffset > 0 && fseek($handle, $this->afterMoveOffset) !== 0) {
                $this->afterMoveOffset = 0;
                rewind($handle);
            }

            while (($line = fgets($handle)) !== false) {
                $path = rtrim($line, "\r\n");
                if ($path !== '') {
                    ($this->completedFileHandler)($path);
                }
            }

            $offset = ftell($handle);
            if ($offset !== false) {
                $this->afterMoveOffset = $offset;
            }
        } finally {
            fclose($handle);
        }
    }

    private function cleanupAfterMoveTracking(): void
    {
        if ($this->afterMoveLog) {
            @unlink($this->afterMoveLog);
        }
        $this->afterMoveLog = null;
        $this->afterMoveOffset = 0;
    }

    private function onError($buffer, array $extra)
    {
        $this->helper->log($buffer);
        $this->helper->run($buffer, $extra);
    }

    public function onOutput($buffer, $extra)
    {
        $this->helper->run($buffer, $extra);
    }

    public function getDownloadUrl($url)
    {
        $this->setUrl($url);
        $this->GetUrlOnly();
        $this->buildCMD();
        exec($this->cmd, $output, $returnCode);
        if (count($output) === 1) {
            return ['url' => reset($output)];
        }
        list($url, $filename) = $output;
        $filename = Helper::cleanString($filename);
        return ['url' => $url, 'filename' => Helper::clipFilename($filename)];
    }

    public function setUrl($url)
    {
        $this->prependOption($url);
    }

    public function setOption($key, $value, $hyphens = false)
    {
        $this->addOption($key, $hyphens);
        $this->addOption($value, false);
        return $this;
    }

    public function addOption(String $option, $hyphens = false)
    {
        if ($hyphens && substr($option, 0, 2) !== '--') {
            $option = "--" . $option;
        }
        array_push($this->options, $option);
    }

    protected function hasOption($name)
    {
        return array_search($name, $this->options, true);
    }

    public function forceIPV4()
    {
        $this->addOption('force-ipv4', true);
        return $this;
    }

    private function buildCMD()
    {
        $this->cmd = $this->bin;
        foreach ($this->options as $option) {
            $this->cmd .= " " . escapeshellarg((string) $option);
        }
    }

    public function isInstalled()
    {
        return @is_file($this->bin);
    }

    public function isExecutable()
    {
        return @is_executable($this->bin);
    }

    public function isReadable()
    {
        return @is_readable($this->bin);
    }

    public function getBin()
    {
        return $this->bin;
    }

    public function install()
    {
        $url = $this->installUrl();
        $file = __DIR__ . "/../../bin/yt-dlp2";
        try {
            Helper::Download($url, $file);
            chmod($file, 0744);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
        return false;
    }

    public function installUrl()
    {
        return "https://github.com/shiningw/ncdownloader-bin/raw/master/yt-dlp";
    }

    public function version()
    {
        $process = new Process([$this->bin, '--version']);
        $process->run();
        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }
        return false;
    }

    public function check()
    {
        if ($tagName = Helper::getLatestRelease('yt-dlp', 'yt-dlp')) {
            $tagName = Helper::removeLetters($tagName);
            $version = $this->version();
            if ($version && version_compare($version, $tagName, '<')) {
                return ['status' => true, 'message' => $tagName];
            }
        }
        return ['status' => false, 'message' => 'No update available'];
    }

    public function update()
    {
        $file = __DIR__ . "/../../bin/yt-dlp";
        try {
            Helper::downloadLatestRelease('yt-dlp', 'yt-dlp', $file);
            chmod($file, 0744);
        } catch (\Exception $e) {
            return ['status' => false,'message' => $e->getMessage()];
        }
        return ['status' => true, 'message' => 'Updated to latest version','data' => $this->version()];
    }
}
