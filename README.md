# Lidarr Music Video Sync

This directory contains the starter implementation of the Lidarr → YouTube → Music Video sync helper you and Zood have been iterating on.

Key pieces:

- `Dockerfile` – PHP + ffmpeg + yt-dlp base image
- `entrypoint.sh` – runs the PHP dev server for the Web UI and loops `sync.php`
- `public/index.php` – simple status/log UI with a button to trigger fragment cleanup
- `sync.php` – core sync loop:
  - talks to Lidarr API
  - searches YouTube via yt-dlp
  - downloads videos into `VIDEO_ROOT`
  - supports DRY_RUN, MAX_DOWNLOADS_PER_RUN, rename-only stub, Unknown Artist skip, etc.
- `cleanup_fragments.php` – one-shot or on-demand cleanup/merge of `.f137` / `.f251` fragments via ffmpeg
- `docker-compose.yml` – Unraid-friendly compose file with example paths and env vars

You can:

1. Build locally:

   ```bash
   docker compose build
   docker compose up -d
   ```

2. Or upload this whole folder to GitHub and let Codespaces/Copilot refine and extend the internals.
