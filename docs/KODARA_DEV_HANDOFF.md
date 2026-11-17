# Kodara export: instructions for the developer

Goal: one read-only export from the live database. It changes nothing (no database writes, no deploy, no R2 access, no downloads, no FFmpeg).

You need two files from Dustin: `export_transcription_inventory.php` and `kodara-r2-audio-listing.csv` (only filenames, sizes and dates).

## 1. Put the script on the server (do not deploy)

Copy it by hand into the site's `scripts/` folder, next to `transcribe_whisper.py`. It refuses to run from a web request, so being in the web root is safe. Delete it afterwards.

```bash
scp export_transcription_inventory.php kodara-r2-audio-listing.csv <user>@<host>:
ssh <user>@<host>
cp ~/export_transcription_inventory.php /path/to/site/public_html/scripts/
```

## 2. Run it (one command, from the site root)

```bash
cd /path/to/site/public_html
umask 077
php scripts/export_transcription_inventory.php --out-dir=$HOME/kodara-export --audio-manifest=$HOME/kodara-r2-audio-listing.csv
```

It prints a short summary and writes three files into `~/kodara-export`:

- `inventory_<time>.csv`: every video in the transcript channels, with an `eligible` yes/no and the reason. Full Zoom calls, private and unlisted videos are already marked `no`.
- `skipped_<time>.csv`: only the skipped ones, for spot-checking.
- `unverified_temp_copies_<time>.csv`: the cached WAV/MP4 copies seen in R2.

## 3. Send the CSVs back privately, then clean up

```bash
scp <user>@<host>:kodara-export/*.csv .
```

Then on the server:

```bash
rm -r ~/kodara-export ~/kodara-r2-audio-listing.csv ~/export_transcription_inventory.php /path/to/site/public_html/scripts/export_transcription_inventory.php
```

Do not email the CSVs or put them in the repository (it is public). They contain video titles.
