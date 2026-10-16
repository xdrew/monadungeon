"""ffmpeg post-processing: trim silence, cut, fades, seamless loop seam, loudness match, mp3 encode."""
from __future__ import annotations

import logging
import re
import shutil
import subprocess
import tempfile
from dataclasses import dataclass
from pathlib import Path

LOG = logging.getLogger("audio.post")

TRUE_PEAK_LIMIT = 0.89  # ≈ −1 dBFS, keeps the mp3 from clipping after the gain


class ProcessError(RuntimeError):
    pass


@dataclass(frozen=True)
class Shape:
    kind: str  # music | sting | sfx
    loop: bool
    lufs: float
    seconds: float | None  # cut to this length (stings, sounds); None keeps everything
    mono: bool
    bitrate: str
    seam: float = 4.0  # loop crossfade, seconds
    outro: float = 6.0  # cut from a music loop's end before the seam (Lyria tracks wind down)
    fade_out: float = 0.0


def _ffmpeg() -> str:
    exe = shutil.which("ffmpeg")
    if not exe:
        raise ProcessError("ffmpeg not found on PATH")
    return exe


def _run(args: list[str]) -> str:
    LOG.debug("ffmpeg %s", " ".join(args))
    proc = subprocess.run([_ffmpeg(), "-hide_banner", "-nostdin", "-y", *args], capture_output=True, text=True)
    if proc.returncode != 0:
        raise ProcessError(f"ffmpeg failed: {proc.stderr[-500:]}")
    return proc.stderr


def duration(path: Path) -> float:
    proc = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(path)],
                          capture_output=True, text=True)
    try:
        return float(proc.stdout.strip())
    except ValueError as exc:
        raise ProcessError(f"cannot read the duration of {path.name}") from exc


def loudness(path: Path) -> tuple[float, float]:
    """Integrated loudness (LUFS) and true peak (dBTP) by EBU R128."""
    # EBU R128 gates on 400 ms blocks: a shorter sound is measured looped over 3 s (same loudness, a valid gate)
    looped = ["-stream_loop", "-1", "-i", str(path), "-t", "3"] if duration(path) < 1.0 else ["-i", str(path)]
    out = _run([*looped, "-af", "ebur128=peak=true", "-f", "null", "-"])
    summary = out[out.rfind("Summary:"):]
    i = re.search(r"I:\s+(-?[\d.]+|-inf) LUFS", summary)
    p = re.search(r"Peak:\s+(-?[\d.]+|-inf) dBFS", summary)
    if not i:
        raise ProcessError(f"no loudness measured for {path.name}")
    lufs = float(i.group(1)) if i.group(1) != "-inf" else -70.0
    peak = float(p.group(1)) if p and p.group(1) != "-inf" else -70.0
    return lufs, peak


def process(raw: Path, out: Path, shape: Shape) -> dict:
    """raw (any format) → out (.mp3). Returns stats for the log."""
    raw_seconds = duration(raw)
    channels = "1" if shape.mono else "2"
    with tempfile.TemporaryDirectory(prefix="audio-") as tmp:
        t = Path(tmp)
        # 1. decode and trim the silence at both ends (the reversed pass trims the tail)
        trim = ("silenceremove=start_periods=1:start_threshold=-55dB:start_silence=0.02,"
                "areverse,silenceremove=start_periods=1:start_threshold=-55dB:start_silence=0.05,areverse")
        _run(["-i", str(raw), "-ac", channels, "-ar", "44100", "-af", trim, str(t / "a.wav")])
        current = t / "a.wav"
        length = duration(current)
        if length < 0.1:
            raise ProcessError(f"{raw.name}: nothing left after trimming silence")

        if shape.loop:
            # 2a. drop the outro, then cross the end into the start so the loop has no seam
            body = max(shape.seam * 3, length - shape.outro)
            seam = min(shape.seam, body / 4)
            # the file is read twice: asplit into acrossfade stalls and writes nothing
            _run(["-i", str(current), "-i", str(current), "-filter_complex",
                  f"[0]atrim={seam:.3f}:{body:.3f},asetpts=N/SR/TB[a];[1]atrim=0:{seam:.3f},asetpts=N/SR/TB[b];"
                  f"[a][b]acrossfade=d={seam:.3f}:c1=qsin:c2=qsin", str(t / "b.wav")])
            current = t / "b.wav"
        else:
            # 2b. a sting or a sound: cut to length, short fade in, fade out at the end
            end = min(length, shape.seconds) if shape.seconds else length
            fade = shape.fade_out or min(0.08, end / 4)
            _run(["-i", str(current), "-af",
                  f"atrim=0:{end:.3f},afade=t=in:d=0.004,afade=t=out:st={max(0.0, end - fade):.3f}:d={fade:.3f}",
                  str(t / "b.wav")])
            current = t / "b.wav"

        # 3. loudness: one gain to the target, a limiter for the peaks
        before, _ = loudness(current)
        gain = shape.lufs - before
        _run(["-i", str(current), "-af", f"volume={gain:.2f}dB,alimiter=limit={TRUE_PEAK_LIMIT}:level=false",
              str(t / "c.wav")])
        current = t / "c.wav"
        if duration(current) < 0.1:
            raise ProcessError(f"{raw.name}: the processed audio is empty")

        # 4. encode
        out.parent.mkdir(parents=True, exist_ok=True)
        _run(["-i", str(current), "-c:a", "libmp3lame", "-b:a", shape.bitrate, "-ac", channels, str(out)])
    after, peak = loudness(out)
    stats = {"raw_s": round(raw_seconds, 2), "seconds": round(duration(out), 2), "lufs_before": round(before, 1),
             "lufs": round(after, 1), "peak_dbtp": round(peak, 1), "loop": shape.loop}
    LOG.info("post %s: %s", out.name, stats)
    return stats
