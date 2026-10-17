#!/usr/bin/env python3
"""
MediaBot Python Downloader Helper
Advanced yt-dlp integration with real-time progress reporting.
Used by PHP via proc_open() for non-blocking execution.
"""

import sys
import os
import json
import time
import subprocess
import argparse
import hashlib
import tempfile
from pathlib import Path
from typing import Optional, Callable


def parse_args():
    parser = argparse.ArgumentParser(description='MediaBot Downloader')
    parser.add_argument('url',         help='URL to download')
    parser.add_argument('--output',    required=True, help='Output file path template')
    parser.add_argument('--quality',   default='720', help='Video quality')
    parser.add_argument('--format',    default='video', choices=['video', 'audio'])
    parser.add_argument('--job-id',    default='', help='Job ID for logging')
    parser.add_argument('--ytdlp',     default='yt-dlp', help='Path to yt-dlp')
    parser.add_argument('--ffmpeg',    default='ffmpeg', help='Path to ffmpeg dir')
    parser.add_argument('--max-size',  default=str(2 * 1024**3), help='Max file size in bytes')
    parser.add_argument('--info-only', action='store_true', help='Only get media info')
    parser.add_argument('--cookies',   default='', help='Cookies file path')
    return parser.parse_args()


def build_format_string(format_type: str, quality: str) -> str:
    if format_type == 'audio':
        return 'bestaudio[ext=m4a]/bestaudio/best'

    q = int(quality) if quality.isdigit() else 720
    return (
        f'bestvideo[height<={q}][ext=mp4]+bestaudio[ext=m4a]/'
        f'bestvideo[height<={q}]+bestaudio/'
        f'best[height<={q}]/best'
    )


def get_media_info(url: str, ytdlp_path: str, cookies: str = '') -> dict:
    """Fetch media metadata without downloading."""
    cmd = [ytdlp_path,
           '--dump-json',
           '--no-playlist',
           '--no-warnings',
           '--socket-timeout', '20',
           '--skip-download']

    if cookies and os.path.exists(cookies):
        cmd += ['--cookies', cookies]

    cmd.append(url)

    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
        if result.returncode == 0:
            data = json.loads(result.stdout)
            return {
                'title':      data.get('title', 'Unknown'),
                'duration':   data.get('duration'),
                'uploader':   data.get('uploader', ''),
                'thumbnail':  data.get('thumbnail', ''),
                'platform':   data.get('extractor_key', 'unknown'),
                'view_count': data.get('view_count'),
                'like_count': data.get('like_count'),
                'formats':    extract_formats(data.get('formats', [])),
                'filesize':   data.get('filesize') or data.get('filesize_approx'),
            }
    except (subprocess.TimeoutExpired, json.JSONDecodeError, Exception) as e:
        print(f'INFO_ERROR:{e}', file=sys.stderr)

    return {}


def extract_formats(formats: list) -> list:
    """Extract available quality levels."""
    heights = set()
    for f in formats:
        h = f.get('height')
        if h and isinstance(h, int) and h > 0:
            heights.add(h)
    return sorted(heights, reverse=True)


def download(args) -> dict:
    """Run yt-dlp download with real-time progress to stdout."""
    fmt_str = build_format_string(args.format, args.quality)

    cmd = [
        args.ytdlp,
        '--no-playlist',
        '--no-warnings',
        '--newline',
        '--progress',
        '--merge-output-format', 'mp4',
        '--max-filesize', args.max_size,
        '--format', fmt_str,
        '--output', args.output,
        '--socket-timeout', '30',
        '--retries', '3',
        '--fragment-retries', '3',
        '--concurrent-fragments', '4',
        '--write-info-json',
        '--user-agent', 'Mozilla/5.0 (compatible; MediaBot/2.0)',
    ]

    if args.ffmpeg and os.path.exists(os.path.dirname(args.ffmpeg)):
        cmd += ['--ffmpeg-location', os.path.dirname(args.ffmpeg)]

    if args.cookies and os.path.exists(args.cookies):
        cmd += ['--cookies', args.cookies]

    cmd.append(args.url)

    process = subprocess.Popen(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        bufsize=1,
        universal_newlines=True
    )

    output_lines = []
    error_lines  = []
    last_progress = 0

    # Read stdout/stderr concurrently
    import select

    while True:
        reads = [process.stdout.fileno(), process.stderr.fileno()]
        try:
            ret = select.select(reads, [], [], 1.0)
        except (OSError, ValueError):
            break

        for fd in ret[0]:
            if fd == process.stdout.fileno():
                line = process.stdout.readline()
                if line:
                    output_lines.append(line)
                    progress = parse_progress(line)
                    if progress > last_progress:
                        last_progress = progress
                        # Print progress in PHP-parseable format
                        print(f'PROGRESS:{progress}', flush=True)
                        sys.stdout.flush()

            elif fd == process.stderr.fileno():
                line = process.stderr.readline()
                if line:
                    error_lines.append(line)

        if process.poll() is not None:
            # Drain remaining output
            remaining_out = process.stdout.read()
            remaining_err = process.stderr.read()
            if remaining_out: output_lines.append(remaining_out)
            if remaining_err: error_lines.append(remaining_err)
            break

    exit_code = process.wait()
    stderr_text = ''.join(error_lines)

    if exit_code != 0:
        error = parse_error(stderr_text)
        print(f'ERROR:{error}', flush=True)
        return {'success': False, 'error': error}

    # Find output file
    output_file = find_output_file(args.output)
    if not output_file:
        print('ERROR:Output file not found after download', flush=True)
        return {'success': False, 'error': 'Output file not found'}

    # Parse metadata from info.json
    meta = parse_info_json(args.output)
    meta['output_file'] = output_file
    meta['file_size']   = os.path.getsize(output_file)

    # Print final result as JSON
    result = {
        'success': True,
        'file':    output_file,
        'meta':    meta,
    }
    print(f'RESULT:{json.dumps(result, ensure_ascii=False)}', flush=True)
    return result


def parse_progress(line: str) -> int:
    """Parse yt-dlp progress line."""
    import re
    match = re.search(r'\[download\]\s+([\d.]+)%', line)
    if match:
        return int(float(match.group(1)))
    return 0


def parse_error(stderr: str) -> str:
    """Map yt-dlp error messages to user-friendly strings."""
    patterns = {
        'Private video':     'This video is private',
        'Video unavailable': 'Video unavailable',
        'age-restrict':      'Age-restricted content',
        'geo':               'Content geo-blocked',
        'not available':     'Content not available',
        'login required':    'Login required — private content',
        'copyright':         'Copyright restricted',
        'HTTP Error 404':    'Content not found',
        'HTTP Error 403':    'Access forbidden',
        'File is larger':    'File too large',
        'Unsupported URL':   'Unsupported platform',
    }

    for pattern, msg in patterns.items():
        if pattern.lower() in stderr.lower():
            return msg

    # Return last meaningful line
    lines = [l.strip() for l in stderr.strip().split('\n') if l.strip()]
    return lines[-1][:200] if lines else 'Unknown download error'


def find_output_file(template: str) -> Optional[str]:
    """Find the actual output file from the template."""
    base = template.replace('.%(ext)s', '')
    extensions = ['mp4', 'mkv', 'webm', 'mp3', 'm4a', 'ogg', 'wav', 'flac', 'mov', 'avi']

    for ext in extensions:
        path = f'{base}.{ext}'
        if os.path.exists(path) and os.path.getsize(path) > 0:
            return path

    # Glob fallback
    import glob
    files = glob.glob(f'{base}.*')
    for f in files:
        if not f.endswith('.json') and not f.endswith('.part') and os.path.getsize(f) > 0:
            return f

    return None


def parse_info_json(template: str) -> dict:
    """Parse the .info.json metadata file."""
    base    = template.replace('.%(ext)s', '')
    # yt-dlp puts info json at base.info.json
    candidates = [f'{base}.info.json']
    # also try glob
    import glob
    candidates += glob.glob(f'{base}*.info.json')

    for path in candidates:
        if os.path.exists(path):
            try:
                with open(path, 'r', encoding='utf-8') as f:
                    data = json.load(f)
                os.unlink(path)  # cleanup
                return {
                    'title':      data.get('title', 'Unknown'),
                    'duration':   data.get('duration'),
                    'uploader':   data.get('uploader', ''),
                    'thumbnail':  data.get('thumbnail', ''),
                    'platform':   data.get('extractor_key', 'unknown'),
                    'view_count': data.get('view_count'),
                }
            except Exception:
                pass
    return {}


def main():
    args = parse_args()

    if args.info_only:
        info = get_media_info(args.url, args.ytdlp, args.cookies)
        print(f'INFO:{json.dumps(info, ensure_ascii=False)}')
        return

    print(f'START:job={args.job_id},url={args.url[:60]}', flush=True)
    result = download(args)
    sys.exit(0 if result.get('success') else 1)


if __name__ == '__main__':
    main()
