"""Generate → keep the raw file → post-process → log, for every asset variant."""
from __future__ import annotations

import hashlib
import json
import logging
import time
from datetime import datetime, timezone
from pathlib import Path

from . import postprocess
from .config import GENERATED_LOG, RAW_DIR, rel
from .manifest import Asset, KindSettings

LOG = logging.getLogger("audio.pipeline")


class GenerationError(RuntimeError):
    pass


def _load_log() -> dict:
    if GENERATED_LOG.exists():
        return json.loads(GENERATED_LOG.read_text(encoding="utf-8"))
    return {}


def _save_log(log: dict) -> None:
    GENERATED_LOG.write_text(json.dumps(dict(sorted(log.items())), indent=2, ensure_ascii=False) + "\n",
                             encoding="utf-8")


def _shape(asset: Asset, k: KindSettings) -> postprocess.Shape:
    return postprocess.Shape(kind=asset.kind, loop=asset.loop, lufs=k.lufs, seconds=asset.seconds, mono=k.mono,
                             bitrate=k.bitrate, fade_out=1.2 if asset.kind == "sting" else 0.0)


def _generate_raw(asset: Asset, k: KindSettings, prompt: str, clients: dict) -> tuple[bytes, dict]:
    if k.provider == "elevenlabs":
        return clients["elevenlabs"].generate(prompt, asset.seconds, k.prompt_influence)
    music = clients["lyria"]
    try:
        return music.generate(prompt, k.model)
    except Exception as exc:  # the model may be unavailable on this key: try the fallback once
        if not k.fallback_model:
            raise
        LOG.warning("%s failed on %s (%s), trying %s", asset.id, k.model, str(exc)[:200], k.fallback_model)
        return music.generate(prompt, k.fallback_model)


def run(style: dict[str, KindSettings], assets: list[Asset], clients: dict | None, only: set[str] | None,
        force: bool, dry_run: bool) -> tuple[int, dict]:
    """Returns (files made, spend {usd, credits})."""
    log = _load_log()
    made = 0
    spend = {"usd": 0.0, "credits": 0}
    for asset in assets:
        if only and asset.id not in only:
            continue
        k = style[asset.kind]
        prompt = asset.full_prompt(style)
        for out in asset.outputs(style):
            name = out.stem
            if out.exists() and not force:
                LOG.debug("skip %s (exists)", rel(out))
                continue
            if dry_run:
                LOG.info("would generate %s via %s/%s (%d chars): %s", rel(out), k.provider, k.model, len(prompt),
                         prompt[:160])
                made += 1
                continue
            started = time.monotonic()
            try:
                data, info = _generate_raw(asset, k, prompt, clients)
            except Exception as exc:
                raise GenerationError(f"{name}: {exc}") from exc
            RAW_DIR.mkdir(parents=True, exist_ok=True)
            raw = RAW_DIR / f"{name}.mp3"
            raw.write_bytes(data)
            try:
                stats = postprocess.process(raw, out, _shape(asset, k))
            except postprocess.ProcessError as exc:
                raise GenerationError(f"{name}: {exc}") from exc
            spend["usd"] += info.get("cost_usd") or 0
            spend["credits"] += info.get("credits") or 0 if isinstance(info.get("credits"), int) else 0
            log[name] = {
                "asset": asset.id, "kind": asset.kind, "provider": k.provider, **info, **stats,
                "out": rel(out), "prompt_sha256": hashlib.sha256(prompt.encode("utf-8")).hexdigest(),
                "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            }
            _save_log(log)
            made += 1
            LOG.info("generated %s in %.1fs (%s s, %s LUFS, %s)", rel(out), time.monotonic() - started,
                     stats["seconds"], stats["lufs"],
                     f"${info['cost_usd']}" if info.get("cost_usd") else f"{info.get('credits')} credits")
    return made, spend


def reprocess(style: dict[str, KindSettings], assets: list[Asset], only: set[str] | None) -> int:
    """Re-run the post-processing from out/raw (no API calls)."""
    log = _load_log()
    n = 0
    for asset in assets:
        if only and asset.id not in only:
            continue
        for out in asset.outputs(style):
            raw: Path = RAW_DIR / f"{out.stem}.mp3"
            if not raw.exists():
                LOG.warning("no raw file for %s, skipped", out.stem)
                continue
            stats = postprocess.process(raw, out, _shape(asset, style[asset.kind]))
            log.setdefault(out.stem, {}).update(stats)
            n += 1
    _save_log(log)
    return n
