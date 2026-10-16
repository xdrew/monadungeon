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

from . import pipeline, postprocess
from .config import ASSETS_FILE, GENERATED_LOG, OUT_DIR, RAW_DIR, ROOT, TOOL_DIR, rel
from .manifest import load_assets, load_style, write_variants

LOG = logging.getLogger("audio.candidates")

PUBLIC = ROOT / "fe" / "public" / "audio" / "candidates"
INDEX = PUBLIC / "index.json"
RAW = OUT_DIR / "candidates"
LIBRARY = TOOL_DIR / "library"  # takes not chosen but kept (committed): <asset>/<key>.mp3 + library.json


def _index() -> dict:
    return json.loads(INDEX.read_text(encoding="utf-8")) if INDEX.exists() else {}


def _save_index(index: dict) -> None:
    PUBLIC.mkdir(parents=True, exist_ok=True)
    INDEX.write_text(json.dumps(index, ensure_ascii=False, indent=1), encoding="utf-8")


def generate(asset_id: str, clients: dict) -> float:
    """Returns the spend: ElevenLabs credits for a sound, US dollars for music."""
    style = load_style()
    asset = {a.id: a for a in load_assets()}.get(asset_id)
    if asset is None:
        raise SystemExit(f"{asset_id} is not in assets.json")
    spec_file = TOOL_DIR / "candidates" / f"{asset_id}.json"
    if not spec_file.exists():
        raise SystemExit(f"no ideas for {asset_id}: write {rel(spec_file)}")
    spec = json.loads(spec_file.read_text(encoding="utf-8"))
    k = style[asset.kind]
    music = k.provider == "lyria"
    seconds = float(spec.get("seconds", asset.seconds or 1.0)) if not music else asset.seconds
    influence = float(spec.get("influence", asset.influence or k.prompt_influence))
    takes_per_idea = int(spec.get("takes", 1 if music else 2))
    out_dir, raw_dir = PUBLIC / asset_id, RAW / asset_id
    # the files go, the folders stay: Vite's dev server misses files in a folder removed and made again
    for d in (out_dir, raw_dir):
        d.mkdir(parents=True, exist_ok=True)
        for old in d.glob("*.mp3"):
            old.unlink()
    takes, spend = [], 0.0
    for idea in spec["ideas"]:
        for take in range(1, takes_per_idea + 1):
            name = f"{idea['key']}-{take}"
            try:
                if music:  # the kind's prefix (instrumental, the game's mood) goes first, as in generate
                    full = " ".join(p for p in (k.prefix, idea["prompt"]) if p)
                    data, info = clients["lyria"].generate(full, k.model)
                    spend += info.get("cost_usd") or 0
                else:
                    data, info = clients["elevenlabs"].generate(idea["prompt"], seconds, influence)
                    spend += info.get("credits") or 0
            except Exception as exc:  # one refused or failed take does not lose the others
                LOG.warning("take %s skipped: %s", name, str(exc)[:300])
                continue
            (raw_dir / f"{name}.mp3").write_bytes(data)
            stats = postprocess.process(raw_dir / f"{name}.mp3", out_dir / f"{name}.mp3", pipeline._shape(asset, k))
            takes.append({"id": str(len(takes) + 1), "file": f"/audio/candidates/{asset_id}/{name}.mp3",
                          "raw": rel(raw_dir / f"{name}.mp3"), "label": idea["label"], "prompt": idea["prompt"],
                          "influence": influence, "seconds": stats["seconds"], "requested_s": seconds,
                          "bus": "music" if music else "sfx", "model": info.get("model")})
    index = _index()
    index[asset_id] = takes
    _save_index(index)
    LOG.info("candidates %s: %d takes, %s — listen on /dev/sounds", asset_id, len(takes),
             f"${spend:.2f}" if music else f"{int(spend)} credits")
    return spend


def pick(asset_id: str, numbers: list[str], keep_rest: bool = False) -> None:
    index = _index()
    takes = {t["id"]: t for t in index.get(asset_id, [])}
    chosen = [takes[n] for n in numbers if n in takes]
    if len(chosen) != len(numbers):
        raise SystemExit(f"unknown takes for {asset_id}: {', '.join(n for n in numbers if n not in takes)}")
    # the manifest: as many variants as takes, a prompt each
    data = json.loads(ASSETS_FILE.read_text(encoding="utf-8"))
    entry = next(a for a in data["assets"] if a["id"] == asset_id)
    if len(chosen) > 1:
        entry["variants"] = len(chosen)
    else:
        entry.pop("variants", None)
    if chosen[0].get("bus", "sfx") == "sfx":
        entry["influence"] = chosen[0]["influence"]
        # the length the takes were made and heard at on the bench
        if chosen[0].get("requested_s"):
            entry["seconds"] = chosen[0]["requested_s"]
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
    k = style[asset.kind]
    out_dir = k.out_dir
    for stale in out_dir.glob(f"{asset_id}*.mp3"):
        if stale.stem == asset_id or stale.stem.rsplit("-", 1)[0] == asset_id:
            stale.unlink()
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    for take, out in zip(chosen, asset.outputs(style)):
        raw = RAW_DIR / f"{out.stem}.mp3"
        shutil.copyfile(ROOT / take["raw"], raw)
        stats = postprocess.process(raw, out, pipeline._shape(asset, k))
        log[out.stem] = {"asset": asset_id, "kind": asset.kind, "provider": k.provider, "model": take.get("model") or k.model,
                         **({"prompt_influence": take["influence"]} if k.provider == "elevenlabs" else {}),
                         **stats, "out": rel(out),
                         "prompt_sha256": hashlib.sha256(take["prompt"].encode("utf-8")).hexdigest(),
                         "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
                         "note": f"chosen by ear on the sound bench: take #{take['id']} ({take['label']['en']})"}
        LOG.info("picked #%s → %s", take["id"], rel(out))
    GENERATED_LOG.write_text(json.dumps(dict(sorted(log.items())), indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    write_variants(load_assets())
    if keep_rest:
        _keep(asset_id, [t for t in takes.values() if t["id"] not in numbers])
    index.pop(asset_id, None)
    if index:
        _save_index(index)
    elif PUBLIC.exists():
        shutil.rmtree(PUBLIC)
    shutil.rmtree(PUBLIC / asset_id, ignore_errors=True)


def _keep(asset_id: str, rest: list[dict]) -> None:
    """The takes not chosen go to the library, processed as for the game, with their prompts."""
    shelf = LIBRARY / asset_id
    shelf.mkdir(parents=True, exist_ok=True)
    book_file = LIBRARY / "library.json"
    book = json.loads(book_file.read_text(encoding="utf-8")) if book_file.exists() else {}
    for take in rest:
        name = take["file"].rsplit("/", 1)[-1]
        shutil.copyfile(PUBLIC / asset_id / name, shelf / name)
        book.setdefault(asset_id, {})[name.removesuffix(".mp3")] = {
            "file": rel(shelf / name), "label": take["label"], "prompt": take["prompt"],
            "model": take.get("model"), "seconds": take["seconds"],
            "kept_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        }
        LOG.info("kept #%s in %s", take["id"], rel(shelf / name))
    book_file.write_text(json.dumps(book, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def _replace_asset_line(asset_id: str, entry: dict) -> None:
    """assets.json keeps one asset per line (and blank lines between groups): only that line changes."""
    lines = ASSETS_FILE.read_text(encoding="utf-8").split("\n")
    at = next(i for i, line in enumerate(lines) if f'"id": "{asset_id}"' in line)
    comma = "," if lines[at].rstrip().endswith(",") else ""
    lines[at] = "    " + json.dumps(entry, ensure_ascii=False) + comma
    ASSETS_FILE.write_text("\n".join(lines), encoding="utf-8")
