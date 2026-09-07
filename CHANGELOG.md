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
- Existing yt-dlp destination files are now skipped during the Nextcloud import instead of being renamed into duplicate copies such as `(1)`, `(2)` and so on.
- Completed and failed yt-dlp items now appear together with aria2/HTTP/magnet history in the existing Complete Downloads and Failed Downloads views.
- Playlist entries that fail before a media file is created are now recorded as failed yt-dlp items, including the extractor error when available.
- Running yt-dlp jobs now expose a cancel action once their process ID is known; cancelled jobs are retained as failed history instead of being silently deleted.
- Active aria2/HTTP/magnet downloads now expose a cancel action in addition to pause.
- `Stop all downloads & reset` now keeps a live yt-dlp row visible when its process tree could not actually be terminated instead of falsely showing an empty queue, and it clears failed yt-dlp/aria2 history while preserving completed history.
- Reset now handles privileged VPN wrappers correctly by terminating the www-data-owned yt-dlp/ffmpeg descendants first and allowing the root sudo/unshare/nsenter wrapper chain to unwind naturally.
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
