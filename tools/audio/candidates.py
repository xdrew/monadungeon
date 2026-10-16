"""Takes to choose from by ear: several ideas of one sound, two takes each, onto the sound bench (/dev/sounds).

`candidates <id>` reads tools/audio/candidates/<id>.json ({"seconds", "influence", "ideas": [{"key", "prompt",
"label": {en, ru, pl}}]}), generates the takes into fe/public/audio/candidates/<id>/ (gitignored, the bench lists
them from index.json), at the asset's loudness. `pick <id> <n,…>` puts the chosen takes into the game as the
asset's variants, writes their prompts into assets.json and logs them.
"""
from __future__ import annotations

import hashlib
import json
import logging
import shutil
from datetime import datetime, timezone

from . import postprocess
from .config import ASSETS_FILE, GENERATED_LOG, OUT_DIR, RAW_DIR, ROOT, TOOL_DIR, rel
from .manifest import load_assets, load_style

LOG = logging.getLogger("audio.candidates")

PUBLIC = ROOT / "fe" / "public" / "audio" / "candidates"
INDEX = PUBLIC / "index.json"
RAW = OUT_DIR / "candidates"


def _index() -> dict:
    return json.loads(INDEX.read_text(encoding="utf-8")) if INDEX.exists() else {}


def _save_index(index: dict) -> None:
    PUBLIC.mkdir(parents=True, exist_ok=True)
    INDEX.write_text(json.dumps(index, ensure_ascii=False, indent=1), encoding="utf-8")


def generate(asset_id: str, sfx_client) -> int:
    style = load_style()
    asset = {a.id: a for a in load_assets()}.get(asset_id)
    if asset is None or asset.kind != "sfx":
        raise SystemExit(f"{asset_id} is not a sound in assets.json")
    spec_file = TOOL_DIR / "candidates" / f"{asset_id}.json"
    if not spec_file.exists():
        raise SystemExit(f"no ideas for {asset_id}: write {rel(spec_file)}")
    spec = json.loads(spec_file.read_text(encoding="utf-8"))
    seconds = float(spec.get("seconds", asset.seconds or 1.0))
    influence = float(spec.get("influence", asset.influence or style["sfx"].prompt_influence))
    out_dir, raw_dir = PUBLIC / asset_id, RAW / asset_id
    for d in (out_dir, raw_dir):
        if d.exists():
            shutil.rmtree(d)
        d.mkdir(parents=True)
    k = style["sfx"]
    takes, credits = [], 0
    for idea in spec["ideas"]:
        for take in (1, 2):
            name = f"{idea['key']}-{take}"
            data, info = sfx_client.generate(idea["prompt"], seconds, influence)
            credits += info.get("credits") or 0
            (raw_dir / f"{name}.mp3").write_bytes(data)
            stats = postprocess.process(raw_dir / f"{name}.mp3", out_dir / f"{name}.mp3",
                                        postprocess.Shape("sfx", False, k.lufs, seconds, k.mono, k.bitrate))
            takes.append({"id": str(len(takes) + 1), "file": f"/audio/candidates/{asset_id}/{name}.mp3",
                          "raw": rel(raw_dir / f"{name}.mp3"), "label": idea["label"], "prompt": idea["prompt"],
                          "influence": influence, "seconds": stats["seconds"]})
    index = _index()
    index[asset_id] = takes
    _save_index(index)
    LOG.info("candidates %s: %d takes, %d credits — listen on /dev/sounds", asset_id, len(takes), credits)
    return credits


def pick(asset_id: str, numbers: list[str]) -> None:
    index = _index()
    takes = {t["id"]: t for t in index.get(asset_id, [])}
    chosen = [takes[n] for n in numbers if n in takes]
    if len(chosen) != len(numbers):
        raise SystemExit(f"unknown takes for {asset_id}: {', '.join(n for n in numbers if n not in takes)}")
    # the manifest: as many variants as takes, a prompt each
    data = json.loads(ASSETS_FILE.read_text(encoding="utf-8"))
    entry = next(a for a in data["assets"] if a["id"] == asset_id)
    entry["variants"] = len(chosen)
    entry["influence"] = chosen[0]["influence"]
    prompts = [t["prompt"] for t in chosen]
    entry["prompt"] = prompts[0]
    if len(set(prompts)) > 1:
        entry["prompts"] = prompts
    else:
        entry.pop("prompts", None)
    _replace_asset_line(asset_id, entry)
    # the files: raw into out/raw, then the usual post-processing
    style = load_style()
    asset = {a.id: a for a in load_assets()}[asset_id]
    log = json.loads(GENERATED_LOG.read_text(encoding="utf-8")) if GENERATED_LOG.exists() else {}
    for old in [n for n, v in log.items() if v.get("asset") == asset_id]:
        log.pop(old)
    out_dir = style["sfx"].out_dir
    for stale in out_dir.glob(f"{asset_id}*.mp3"):
        if stale.stem == asset_id or stale.stem.rsplit("-", 1)[0] == asset_id:
            stale.unlink()
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    for take, out in zip(chosen, asset.outputs(style)):
        raw = RAW_DIR / f"{out.stem}.mp3"
        shutil.copyfile(ROOT / take["raw"], raw)
        k = style["sfx"]
        stats = postprocess.process(raw, out, postprocess.Shape("sfx", False, k.lufs, asset.seconds, k.mono, k.bitrate))
        log[out.stem] = {"asset": asset_id, "kind": "sfx", "provider": "elevenlabs", "model": k.model,
                         "prompt_influence": take["influence"], **stats, "out": rel(out),
                         "prompt_sha256": hashlib.sha256(take["prompt"].encode("utf-8")).hexdigest(),
                         "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
                         "note": f"chosen by ear on the sound bench: take #{take['id']} ({take['label']['en']})"}
        LOG.info("picked #%s → %s", take["id"], rel(out))
    GENERATED_LOG.write_text(json.dumps(dict(sorted(log.items())), indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    index.pop(asset_id, None)
    if index:
        _save_index(index)
    elif PUBLIC.exists():
        shutil.rmtree(PUBLIC)
    shutil.rmtree(PUBLIC / asset_id, ignore_errors=True)


def _replace_asset_line(asset_id: str, entry: dict) -> None:
    """assets.json keeps one asset per line (and blank lines between groups): only that line changes."""
    lines = ASSETS_FILE.read_text(encoding="utf-8").split("\n")
    at = next(i for i, line in enumerate(lines) if f'"id": "{asset_id}"' in line)
    comma = "," if lines[at].rstrip().endswith(",") else ""
    lines[at] = "    " + json.dumps(entry, ensure_ascii=False) + comma
    ASSETS_FILE.write_text("\n".join(lines), encoding="utf-8")
