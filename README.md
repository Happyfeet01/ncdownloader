# MediaFetch

MediaFetch is a download manager for Nextcloud built around **aria2** and **yt-dlp**.

It continues the NCDownloader codebase with support for current Nextcloud releases, per-user download settings, yt-dlp media downloads and a maintainable App Store release process.

## Features

- HTTP/HTTPS downloads through aria2
- BitTorrent and magnet downloads through aria2
- Media downloads through yt-dlp
- yt-dlp playlist handling with per-item import into Nextcloud after post-processing
- Live download status for active yt-dlp and aria2 jobs
- Complete and failed download history
- Cancel actions for active yt-dlp and aria2 downloads
- "Stop all downloads & reset" action
- Duplicate destination filenames are skipped instead of creating `(1)`, `(2)`, ... copies
- Per-user download and torrent folders
- Optional per-user aria2 options
- Optional per-user yt-dlp options
- Administrator-controlled binary paths and aria2 RPC settings
- Support for administrator-managed wrapper scripts, including VPN/network-namespace wrappers
- Existing NCDownloader user/app settings are imported lazily when MediaFetch first reads them

## Requirements

Current App Store compatibility:

- Nextcloud 32–34
- PHP 8.2–8.4
- aria2
- yt-dlp
- ffmpeg for media conversion, extraction and post-processing

> PHP 8.5 is not advertised as supported by MediaFetch yet. It should only be added to the compatibility range after the application and CI have been tested successfully with it.

## Installation

### Nextcloud App Store

The recommended way to install MediaFetch is through the Nextcloud App Store:

https://apps.nextcloud.com/apps/mediafetch

After installing the app, install the required external tools on the system or inside the Nextcloud container.

### Debian / Ubuntu

```bash
apt update
apt install aria2 yt-dlp ffmpeg
```

Verify the binaries afterwards:

```bash
aria2c --version
yt-dlp --version
ffmpeg -version
```

MediaFetch is normally executed by the web server / PHP user, so also verify that this user can execute the tools:

```bash
sudo -u www-data aria2c --version
sudo -u www-data yt-dlp --version
sudo -u www-data ffmpeg -version
```

If one of these commands fails, check the configured binary path and file permissions before troubleshooting MediaFetch itself.

## Docker

Installing packages interactively inside a running container is usually temporary. They disappear when the container is recreated.

For a normal Nextcloud Docker installation, use a custom image based on the official Nextcloud image.

### Docker Compose example

Create a `Dockerfile` next to your `compose.yaml`:

```dockerfile
FROM nextcloud:apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        aria2 \
        yt-dlp \
        ffmpeg \
    && rm -rf /var/lib/apt/lists/*
```

Use that Dockerfile in the Nextcloud service:

```yaml
services:
  nextcloud:
    build:
      context: .
      dockerfile: Dockerfile
    restart: unless-stopped

    # Keep the rest of your existing Nextcloud configuration here.
```

Rebuild and restart the container:

```bash
docker compose build --pull
docker compose up -d
```

Verify the tools inside the container:

```bash
docker compose exec nextcloud aria2c --version
docker compose exec nextcloud yt-dlp --version
docker compose exec nextcloud ffmpeg -version
```

And verify them as the Nextcloud web user:

```bash
docker compose exec -u www-data nextcloud aria2c --version
docker compose exec -u www-data nextcloud yt-dlp --version
docker compose exec -u www-data nextcloud ffmpeg -version
```

## Nextcloud AIO

Nextcloud AIO supports adding Alpine packages permanently to the Nextcloud container through `NEXTCLOUD_ADDITIONAL_APKS`.

The AIO default includes `imagemagick`. Because setting `NEXTCLOUD_ADDITIONAL_APKS` overrides the default value, keep `imagemagick` in the list if you still want it installed.

Example for the AIO mastercontainer environment:

```yaml
environment:
  NEXTCLOUD_ADDITIONAL_APKS: "imagemagick aria2 yt-dlp ffmpeg"
```

After recreating/restarting AIO, verify the binaries in the Nextcloud container:

```bash
docker exec nextcloud-aio-nextcloud aria2c --version
docker exec nextcloud-aio-nextcloud yt-dlp --version
docker exec nextcloud-aio-nextcloud ffmpeg -version
```

Also verify execution as `www-data`:

```bash
docker exec -u www-data nextcloud-aio-nextcloud aria2c --version
docker exec -u www-data nextcloud-aio-nextcloud yt-dlp --version
docker exec -u www-data nextcloud-aio-nextcloud ffmpeg -version
```

If the binary is visible as root but not executable as `www-data`, MediaFetch will not be able to use it.

## Configuration

MediaFetch exposes administrator settings and personal settings.

### General Settings

Users can choose their download and torrent folders here.

### Personal Aria2 Settings

These settings are **optional**.

An empty section is valid and means that MediaFetch uses its normal/default aria2 options. Users only need to add entries here when they intentionally want to override supported aria2 options for their own downloads.

### Personal yt-dlp Settings

These settings are **optional** as well.

An empty section is valid. Users only need to add entries when they intentionally want to override supported yt-dlp options.

## aria2 RPC

MediaFetch communicates with aria2 through its JSON-RPC interface.

Default values used by MediaFetch are:

- Host: `127.0.0.1`
- Port: `6800`
- RPC token fallback: `ncdownloader123`

Administrators can override the aria2 binary path, RPC host, RPC port and RPC token in the MediaFetch admin settings.

Installing `aria2c` alone does not necessarily mean that the RPC service is currently running.

Useful checks on a normal Linux installation:

```bash
which aria2c
aria2c --version
ss -lntp | grep 6800
ps aux | grep '[a]ria2c'
```

For Nextcloud AIO:

```bash
docker exec nextcloud-aio-nextcloud which aria2c
docker exec nextcloud-aio-nextcloud aria2c --version
docker exec nextcloud-aio-nextcloud sh -c 'ss -lntp 2>/dev/null | grep 6800 || echo "aria2 RPC not listening"'
```

If aria2 is started manually, its RPC host/port/token must match the values configured in MediaFetch.

## External binaries and VPN wrappers

Administrators can configure custom aria2 and yt-dlp binary paths. This makes it possible to point MediaFetch at administrator-managed wrapper scripts, for example when downloads must run in a dedicated VPN network namespace.

Always test the **exact configured path** as the web server user.

Example:

```bash
sudo -u www-data /path/to/aria2-wrapper --version
sudo -u www-data /path/to/yt-dlp-wrapper --version
```

A wrapper script may exist and be executable while the real binary behind it is missing or inaccessible. Testing the complete execution path avoids that ambiguity.

Users do not need direct access to the VPN configuration itself.

## Troubleshooting

### MediaFetch cannot find aria2 or yt-dlp

Check the binary directly and then as the PHP/web user:

```bash
which aria2c
which yt-dlp

sudo -u www-data aria2c --version
sudo -u www-data yt-dlp --version
```

For Docker or AIO, run the equivalent commands inside the Nextcloud container.

### Personal aria2 / yt-dlp settings are empty

This is normal. They are optional override sections and do not need to contain anything for MediaFetch to work.

### aria2 is installed but downloads do not start

Check whether the JSON-RPC service is listening on the configured host and port:

```bash
ss -lntp | grep 6800
```

Also verify that the configured RPC token matches the token used by the running aria2 process.

## Security

MediaFetch treats downloader options as untrusted user input. Only supported/safe options are intended to be available to unprivileged users.

Options capable of executing arbitrary commands, loading arbitrary local files or replacing the administrator-configured downloader are not intended to be exposed to normal users.

For VPN setups, prefer a narrowly scoped administrator-managed wrapper instead of granting the web server user broad Docker or root privileges.

## Development

Frontend dependencies and build:

```bash
npm install
npm run build
```

PHP dependencies:

```bash
composer install
```

The default development base is the `master` branch. Feature and bug-fix work should be done on separate branches and merged through pull requests.

## License and attribution

MediaFetch is licensed under the **GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later)**.

It is based on the original NCDownloader / Net loader project by Jiaxin Huang and subsequent contributors. The existing copyright and license history is retained.

## Links

- Nextcloud App Store: https://apps.nextcloud.com/apps/mediafetch
- GitHub repository: https://github.com/Happyfeet01/mediafetch
- Issue tracker: https://github.com/Happyfeet01/mediafetch/issues
