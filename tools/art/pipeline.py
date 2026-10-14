"""Manifest-driven generation with rolling character references and a persistent log."""
from __future__ import annotations

import hashlib
import json
import logging
from datetime import datetime, timezone
from pathlib import Path

from . import postprocess
from .config import GENERATED_LOG, RAW_DIR, rel
from .gemini import GenerationError, ImageClient
from .manifest import Asset, Style

LOG = logging.getLogger("art.pipeline")


def build_prompt(style: Style, asset: Asset) -> str:
    frame = style.frames[asset.kind].format(subject=asset.subject)
    return "\n\n".join([style.style_prompts[asset.kind], frame, style.negative_prompts[asset.kind]])


def collect_references(style: Style, asset: Asset, assets_by_id: dict[str, Asset],
                       recent: dict[str, list[Path]]) -> list[tuple[Path, str]]:
    """Ordered (path, role) list: style/composition refs for the kind, then character refs."""
    refs: list[tuple[Path, str]] = [(r.path, r.role) for r in style.references if asset.kind in r.kinds]
    chars: list[Path] = []
    if asset.character_refs:
        for ref_id in asset.character_refs:
            out = assets_by_id[ref_id].out
            if out.exists():
                chars.append(out)
            else:
                LOG.warning("%s: character_ref '%s' has no output yet, skipped", asset.id, ref_id)
    else:
        chars = list(recent.get(asset.kind, []))
    chars = chars[-style.max_character_references:]
    refs.extend((p, "character") for p in chars)
    if len(refs) > style.max_references:
        LOG.warning("%s: %d references exceed the limit %d, trimming style refs", asset.id, len(refs), style.max_references)
        overflow = len(refs) - style.max_references
        refs = refs[overflow:]
    return refs


def _load_log() -> dict:
    if GENERATED_LOG.exists():
        return json.loads(GENERATED_LOG.read_text(encoding="utf-8"))
    return {"assets": {}}


def _save_log(log: dict) -> None:
    GENERATED_LOG.write_text(json.dumps(log, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def run(style: Style, assets: list[Asset], client: ImageClient | None, only: set[str] | None, force: bool,
        dry_run: bool, model_override: str | None) -> int:
    """Returns the number of assets generated."""
    assets_by_id = {a.id: a for a in assets}
    recent: dict[str, list[Path]] = {}
    # existing outputs of earlier assets still serve as character references for later ones
    log = _load_log()
    generated = 0
    for asset in assets:
        selected = only is None or asset.id in only
        exists = asset.out.exists()
        if not selected:
            if exists:
                recent.setdefault(asset.kind, []).append(asset.out)
            continue
        if exists and not force:
            LOG.info("skip %s: %s exists (use --force to regenerate)", asset.id, rel(asset.out))
            recent.setdefault(asset.kind, []).append(asset.out)
            continue
        settings = style.kinds[asset.kind]
        model = model_override or settings.model or style.default_model
        prompt = build_prompt(style, asset)
        refs = collect_references(style, asset, assets_by_id, recent)
        prompt_hash = hashlib.sha256(prompt.encode("utf-8")).hexdigest()
        LOG.info("generate %s (%s) model=%s refs=%d → %s", asset.id, asset.kind, model, len(refs), rel(asset.out))
        LOG.debug("prompt for %s:\n%s", asset.id, prompt)
        for p, role in refs:
            LOG.debug("  ref [%s] %s", role, rel(p))
        if dry_run:
            generated += 1
            continue
        if client is None:
            raise SystemExit("no client (dry-run expected)")
        raw, mime, info = client.generate(prompt, [p for p, _ in refs], settings.aspect_ratio, settings.image_size,
                                          model=model)
        RAW_DIR.mkdir(parents=True, exist_ok=True)
        ext = "jpg" if mime == "image/jpeg" else "png"
        raw_path = RAW_DIR / f"{asset.id}.{ext}"
        raw_path.write_bytes(raw)
        if asset.kind == "background":
            image, post = postprocess.process_background(raw, settings.width, settings.height)
            postprocess.save_background(image, asset.out, settings.quality or 85)
        else:
            image, post = postprocess.process_sprite(raw, settings.width, settings.height)
            postprocess.save_sprite(image, asset.out)
        LOG.info("done %s in %.1fs → %s %dx%d", asset.id, info["duration_s"], rel(asset.out), *image.size)
        recent.setdefault(asset.kind, []).append(asset.out)
        log["assets"][asset.id] = {
            "kind": asset.kind,
            "model": info["model"],
            "style_version": style.version,
            "prompt_sha256": prompt_hash,
            "references": [{"path": rel(p), "role": role} for p, role in refs],
            "raw": rel(raw_path),
            "output": rel(asset.out),
            "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "duration_s": info["duration_s"],
            "interaction_id": info.get("interaction_id"),
            "post": post,
        }
        _save_log(log)
        generated += 1
    return generated


def reprocess(style: Style, assets: list[Asset], only: set[str] | None) -> int:
    """Re-run the post-processing from out/raw without calling the API (after tuning postprocess.py)."""
    log = _load_log()
    done = 0
    for asset in assets:
        if only is not None and asset.id not in only:
            continue
        entry = log["assets"].get(asset.id)
        raw_path = RAW_DIR / Path(entry["raw"]).name if entry else None
        if raw_path is None or not raw_path.exists():
            LOG.warning("reprocess %s: no raw file in %s, skipped", asset.id, rel(RAW_DIR))
            continue
        settings = style.kinds[asset.kind]
        raw = raw_path.read_bytes()
        if asset.kind == "background":
            image, post = postprocess.process_background(raw, settings.width, settings.height)
            postprocess.save_background(image, asset.out, settings.quality or 85)
        else:
            image, post = postprocess.process_sprite(raw, settings.width, settings.height)
            postprocess.save_sprite(image, asset.out)
        entry["post"] = post
        entry["reprocessed_at"] = datetime.now(timezone.utc).isoformat(timespec="seconds")
        LOG.info("reprocess %s → %s %s", asset.id, rel(asset.out), post)
        done += 1
    _save_log(log)
    return done
