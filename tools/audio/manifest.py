"""Style bible (style/style.json) and asset manifest (assets.json) loaders with validation."""
from __future__ import annotations

import json
import logging
from dataclasses import dataclass
from pathlib import Path

from .config import ASSETS_FILE, ROOT, STYLE_FILE

LOG = logging.getLogger("audio.manifest")

KINDS = ("music", "sting", "sfx")
PROVIDERS = ("lyria", "elevenlabs")


@dataclass(frozen=True)
class KindSettings:
    provider: str
    model: str
    lufs: float
    bitrate: str
    mono: bool
    prefix: str
    out_dir: Path
    suffix: str = ""
    fallback_model: str | None = None
    prompt_influence: float = 0.5


@dataclass(frozen=True)
class Asset:
    id: str
    kind: str
    prompt: str
    loop: bool
    seconds: float | None
    variants: int
    influence: float | None = None  # ElevenLabs prompt_influence for this asset (else the kind's)
    prompts: tuple[str, ...] = ()  # one prompt per variant (else `prompt` for all)
    lufs: float | None = None  # this asset's loudness target (else the kind's): quieter for frequent sounds

    def outputs(self, style: dict[str, KindSettings]) -> list[Path]:
        """One file per variant: <id>.mp3, or <id>-1.mp3 … <id>-n.mp3."""
        out_dir = style[self.kind].out_dir
        if self.variants == 1:
            return [out_dir / f"{self.id}.mp3"]
        return [out_dir / f"{self.id}-{n}.mp3" for n in range(1, self.variants + 1)]

    def full_prompt(self, style: dict[str, KindSettings], variant: int = 0) -> str:
        k = style[self.kind]
        own = self.prompts[variant] if variant < len(self.prompts) else self.prompt
        return " ".join(p for p in (k.prefix, own, k.suffix) if p)


def _fail(msg: str) -> None:
    raise SystemExit(f"manifest error: {msg}")


def load_style(path: Path = STYLE_FILE) -> dict[str, KindSettings]:
    data = json.loads(path.read_text(encoding="utf-8"))
    kinds: dict[str, KindSettings] = {}
    for name, cfg in data["kinds"].items():
        if name not in KINDS:
            _fail(f"unknown kind '{name}' in style.kinds")
        if cfg["provider"] not in PROVIDERS:
            _fail(f"unknown provider '{cfg['provider']}' for {name}")
        kinds[name] = KindSettings(
            provider=cfg["provider"], model=cfg["model"], lufs=float(cfg["lufs"]), bitrate=cfg["bitrate"],
            mono=bool(cfg["mono"]), prefix=cfg.get("prefix", ""), suffix=cfg.get("suffix", ""),
            out_dir=ROOT / cfg["out_dir"], fallback_model=cfg.get("fallback_model"),
            prompt_influence=float(cfg.get("prompt_influence", 0.5)),
        )
    missing = set(KINDS) - set(kinds)
    if missing:
        _fail(f"style.kinds lacks {', '.join(sorted(missing))}")
    return kinds


def load_assets(path: Path = ASSETS_FILE) -> list[Asset]:
    data = json.loads(path.read_text(encoding="utf-8"))
    assets: list[Asset] = []
    seen: set[str] = set()
    for entry in data["assets"]:
        aid = entry["id"]
        if aid in seen:
            _fail(f"duplicate id '{aid}'")
        seen.add(aid)
        if entry["kind"] not in KINDS:
            _fail(f"unknown kind '{entry['kind']}' for {aid}")
        if entry.get("prompts") and len(entry["prompts"]) != int(entry.get("variants", 1)):
            _fail(f"{aid}: one prompt per variant")
        if not entry.get("prompt"):
            _fail(f"{aid} has no prompt")
        seconds = entry.get("seconds")
        if entry["kind"] == "sfx" and seconds is not None and not 0.5 <= seconds <= 30:
            _fail(f"{aid}: a sound lasts 0.5–30 s")
        assets.append(Asset(id=aid, kind=entry["kind"], prompt=entry["prompt"], loop=bool(entry.get("loop", False)),
                            seconds=float(seconds) if seconds is not None else None,
                            variants=int(entry.get("variants", 1)),
                            influence=float(entry["influence"]) if "influence" in entry else None,
                            prompts=tuple(entry.get("prompts", ())),
                            lufs=float(entry["lufs"]) if "lufs" in entry else None))
    LOG.debug("loaded %d assets", len(assets))
    return assets


def describe(assets: list[Asset], style: dict[str, KindSettings]) -> str:
    lines = []
    for a in assets:
        outs = a.outputs(style)
        have = sum(1 for p in outs if p.exists())
        extra = f" {a.seconds:g}s" if a.seconds else ""
        lines.append(f"{'ok ' if have == len(outs) else '-- '} {a.kind:5} {a.id:16} {have}/{len(outs)}"
                     f"{' loop' if a.loop else ''}{extra}")
    return "\n".join(lines)


VARIANTS_FILE = ROOT / "fe" / "src" / "services" / "audio" / "variants.json"


def write_variants(assets: list[Asset]) -> None:
    """The game's cue map reads how many files each sound has from here (fe/src/services/audio/cues.js)."""
    data = {a.id: a.variants for a in assets if a.kind == "sfx" and a.variants > 1}
    VARIANTS_FILE.write_text(json.dumps(dict(sorted(data.items())), indent=2) + "\n", encoding="utf-8")
