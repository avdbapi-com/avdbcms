# Portable FFmpeg

AVDBCMS checks `bin/` before falling back to a server-installed `ffmpeg` or
`ffprobe`.

Supported layouts:

- `bin/windows/ffmpeg.exe` and `bin/windows/ffprobe.exe`
- `bin/linux-x64/ffmpeg` and `bin/linux-x64/ffprobe`
- `bin/linux-arm64/ffmpeg` and `bin/linux-arm64/ffprobe`

On Linux, the resolver attempts `chmod(0755)` when a bundled binary is not
executable. If the host blocks `chmod`, set permissions manually:

```sh
chmod +x bin/linux-x64/ffmpeg bin/linux-x64/ffprobe
chmod +x bin/linux-arm64/ffmpeg bin/linux-arm64/ffprobe
```

Production preference:

1. Use panel or OS packages when available.
2. Use bundled binaries when the host does not provide FFmpeg.
3. Set `FFMPEG_PATH` and `FFPROBE_PATH` in `.env` only when auto-detection
   chooses the wrong binary.

Bundled builds are GPL FFmpeg builds. Keep the included license files when
redistributing this source or release package.
