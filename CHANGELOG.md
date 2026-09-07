# Changelog

All notable changes to MediaFetch will be documented in this file.

## [Unreleased]

## [1.1.0] - 2026-09-07

### Changed

- Completed yt-dlp playlist items are imported into Nextcloud immediately after yt-dlp's `after_move` stage instead of waiting for the entire playlist job to finish.
- yt-dlp staging now defaults to `/var/tmp/mediafetch` instead of `/tmp/mediafetch`; administrators can override the work directory with the `mediafetch` app setting `work_directory`.
- Completed and failed yt-dlp items now appear together with aria2/HTTP/magnet history in the existing Complete Downloads and Failed Downloads views.
- The yt-dlp Downloads view now contains only running/importing jobs; completed and failed rows leave the live queue automatically.

### Added

- Completed yt-dlp items briefly show `✅` after `Adding to Nextcloud…` before leaving the live queue.
- Running yt-dlp jobs can be cancelled once their process ID is known.
- Active aria2/HTTP/magnet downloads now expose a cancel action in addition to pause.
- Added `Stop all downloads & reset` to stop the current user's tracked yt-dlp/ffmpeg processes, remove active/waiting aria2 jobs and clear failed history while preserving completed history.
- Playlist entries that fail before a media file is created are recorded as failed yt-dlp items, including extractor errors when available.

### Fixed

- Removed the deprecated yt-dlp `--prefer-ffmpeg` option; audio extraction continues through yt-dlp's normal ffmpeg post-processing flow.
- Already imported playlist items keep their completed state while later entries continue downloading or fail.
- A final workspace pass still imports sidecar files and recovers completed files if an immediate per-item import was not possible.
- Existing yt-dlp destination files are skipped during the Nextcloud import instead of being renamed into duplicate copies such as `(1)`, `(2)` and so on.
- `Stop all downloads & reset` now keeps a live yt-dlp row visible when its process tree could not actually be terminated instead of falsely showing an empty queue.
- Reset correctly handles privileged VPN wrappers by terminating the www-data-owned yt-dlp/ffmpeg descendants first and allowing the root sudo/unshare/nsenter wrapper chain to unwind naturally.
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
