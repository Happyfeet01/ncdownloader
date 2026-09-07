# Changelog

All notable changes to MediaFetch will be documented in this file.

## [Unreleased]

### Fixed

- Removed the deprecated yt-dlp `--prefer-ffmpeg` option; audio extraction continues through yt-dlp's normal ffmpeg post-processing flow.
- Completed yt-dlp playlist items are now imported into Nextcloud immediately after yt-dlp's `after_move` stage instead of waiting for the entire playlist job to finish.
- Already imported playlist items keep their completed state while later entries continue downloading or fail.
- A final workspace pass still imports sidecar files and recovers completed files if an immediate per-item import was not possible.
- yt-dlp staging now defaults to `/var/tmp/mediafetch` instead of `/tmp/mediafetch` so large downloads do not consume a tmpfs-backed `/tmp`; administrators can override the work directory with the `mediafetch` app setting `work_directory`.
- The yt-dlp Downloads view now shows only running/importing jobs, so completed and failed rows no longer remain in the live queue indefinitely.
- Completed yt-dlp items now briefly show a `✅` state after `Adding to Nextcloud…` before leaving the live queue, and `after_move` completion is matched to the correct playlist item instead of relying on whichever item is currently active.
- Completed and failed yt-dlp items now appear together with aria2/HTTP/magnet history in the existing Complete Downloads and Failed Downloads views.
- Playlist entries that fail before a media file is created are now recorded as failed yt-dlp items, including the extractor error when available.
- Running yt-dlp jobs now expose a cancel action once their process ID is known; cancelled jobs are retained as failed history instead of being silently deleted.
- Active aria2/HTTP/magnet downloads now expose a cancel action in addition to pause.
- Corrected the remaining aria2 action route to use the `mediafetch` route namespace.

## [1.0.1] - 2026-09-03

### Changed

- yt-dlp downloads are now imported through Nextcloud's Files API instead of relying on internal file scanning.
- Added visible preparing, downloading, importing and completed states for yt-dlp jobs.
- Updated project links to point to the MediaFetch repository and issue tracker.

### Fixed

- Fixed yt-dlp jobs appearing inactive immediately after submitting a download.
- Avoided false import errors while cleaning up temporary yt-dlp workspaces.
- Made completed HTTP, magnet and torrent downloads appear in Nextcloud without requiring a manual `occ files:scan`.
- Corrected the aria2 hook argument order (`GID`, file count, file path) and preserved paths containing spaces.
- Added a BitTorrent completion hook so multi-file torrents are scanned before seeding begins.
- Removed the remaining call to the deleted legacy `folderScan` class.

## [1.0.0] - 2026-08-26

### Added

- Rebranded the application as **MediaFetch** with the new app ID `mediafetch`.
- Added compatibility metadata for Nextcloud 32, 33 and 34.
- Added a MediaFetch navigation/settings icon.
- Added GitHub Actions checks for PHP syntax, app metadata and frontend builds.
- Added support for administrator-managed downloader wrapper binaries, including VPN wrapper setups.
- Added safe per-user allowlists for aria2 and yt-dlp options.
- Added a Nextcloud 34 compatible mobile navigation drawer.
- Added visible yt-dlp lifecycle states for preparing, downloading, importing and completed downloads.

### Changed

- Updated frontend routes and translation domains from `ncdownloader` to `mediafetch`.
- Updated the folder selector to use the maintained `@nextcloud/dialogs` file picker.
- Updated Nextcloud frontend libraries for current Nextcloud releases.
- Renamed user-facing youtube-dl references to yt-dlp where applicable.
- Restricted the supported Nextcloud range to currently maintained releases (32–34) for the first MediaFetch release.
- Updated bundled PHP dependencies for PHP 8.2–8.4 and Nextcloud 34 compatibility.
- yt-dlp now downloads into a private temporary workspace and imports completed files through Nextcloud's public Files API.
- Removed the old forced folder scan after yt-dlp downloads and before opening the destination folder.

### Migration

- Existing NCDownloader app and user settings are read through a legacy fallback and copied to the `mediafetch` configuration namespace when accessed.
- Existing per-user download folders, torrent folders, aria2 settings and yt-dlp settings remain available after migration.
- Existing NCDownloader download records are imported into the MediaFetch download table without deleting the legacy table during the first release.

### Security

- Removed user-configurable VPN start/stop shell commands. VPN routing should instead be implemented by an administrator-controlled downloader binary or wrapper.
- Removed arbitrary command-execution options from personal yt-dlp settings.
- Removed unsafe filesystem, credential and RPC-related options from personal aria2 settings.
- Restricted personal yt-dlp output templates to filenames so users cannot escape the configured download directory.
- Removed the legacy direct use of Nextcloud's internal file scanner from the download workflow.

### Tested

- Runtime tested successfully on Nextcloud 34.0.2 with PHP 8.4.
- Main app view, mobile navigation, admin settings, personal settings, aria2 and yt-dlp workflows verified on the target installation.
