"""Style bible (style/style.json) and asset manifest (assets.json) loaders with validation."""
from __future__ import annotations

import json
import logging
from dataclasses import dataclass, field
from pathlib import Path

from .config import ASSETS_FILE, ROOT, STYLE_FILE, rel

LOG = logging.getLogger("art.manifest")

KINDS = ("monster", "hero", "background", "icon")
REF_ROLES = ("style", "composition", "character")


@dataclass(frozen=True)
class Reference:
    path: Path
    role: str
    kinds: tuple[str, ...]  # asset kinds this reference is sent for


@dataclass(frozen=True)
class KindSettings:
    width: int
    height: int
    aspect_ratio: str
    image_size: str = "1K"
    model: str | None = None
    quality: int | None = None  # webp quality for opaque outputs
    mirror: bool = False  # flip horizontally after keying (monsters face the hero)


@dataclass(frozen=True)
class Style:
    version: int
    style_prompts: dict[str, str]  # kind -> style prompt
    negative_prompts: dict[str, str]  # kind -> negative prompt
    palette: tuple[str, ...]
    frames: dict[str, str]  # kind -> prompt frame with {subject}
    references: tuple[Reference, ...]
    kinds: dict[str, KindSettings]
    max_references: int
    max_character_references: int
    default_model: str


@dataclass(frozen=True)
class Asset:
    id: str
    kind: str
    out: Path
    subject: str
    character_refs: tuple[str, ...] = field(default_factory=tuple)  # ids of generated assets to attach


def _fail(msg: str) -> None:
    raise SystemExit(f"manifest error: {msg}")


def load_style(path: Path = STYLE_FILE) -> Style:
    data = json.loads(path.read_text(encoding="utf-8"))
    refs: list[Reference] = []
    for entry in data["references"]:
        p = ROOT / entry["path"]
        if not p.exists():
            _fail(f"reference file missing: {entry['path']}")
        role = entry.get("role", "style")
        if role not in REF_ROLES:
            _fail(f"unknown reference role '{role}' for {entry['path']}")
        kinds = tuple(entry.get("kinds", KINDS))
        for k in kinds:
            if k not in KINDS:
                _fail(f"unknown kind '{k}' in reference {entry['path']}")
        refs.append(Reference(path=p, role=role, kinds=kinds))
    kinds: dict[str, KindSettings] = {}
    for name, cfg in data["kinds"].items():
        if name not in KINDS:
            _fail(f"unknown kind '{name}' in style.kinds")
        kinds[name] = KindSettings(
            width=int(cfg["width"]), height=int(cfg["height"]), aspect_ratio=cfg["aspect_ratio"],
            image_size=cfg.get("image_size", "1K"), model=cfg.get("model"), quality=cfg.get("quality"),
            mirror=bool(cfg.get("mirror", False)),
        )
    for k in KINDS:
        if k not in kinds:
            _fail(f"style.kinds lacks '{k}'")
        if k not in data["frames"]:
            _fail(f"style.frames lacks '{k}'")
        if "{subject}" not in data["frames"][k]:
            _fail(f"style.frames.{k} must contain {{subject}}")
    style = Style(
        version=int(data.get("style_version", 1)),
        style_prompts={k: (data["background_style_prompt"] if k == "background" else data["style_prompt"]).strip()
                       for k in KINDS},
        negative_prompts={k: (data["background_negative_prompt"] if k == "background" else data["negative_prompt"]).strip()
                          for k in KINDS},
        palette=tuple(data.get("palette", [])),
        frames={k: v.strip() for k, v in data["frames"].items()},
        references=tuple(refs),
        kinds=kinds,
        max_references=int(data["limits"]["max_references"]),
        max_character_references=int(data["limits"]["max_character_references"]),
        default_model=data["default_model"],
    )
    LOG.debug("style v%d: %d references, default model %s", style.version, len(refs), style.default_model)
    return style


def load_assets(path: Path = ASSETS_FILE) -> list[Asset]:
    data = json.loads(path.read_text(encoding="utf-8"))
    assets: list[Asset] = []
    seen: set[str] = set()
    for entry in data["assets"]:
        aid = entry["id"]
        if aid in seen:
            _fail(f"duplicate asset id '{aid}'")
        seen.add(aid)
        if entry["kind"] not in KINDS:
            _fail(f"asset '{aid}': unknown kind '{entry['kind']}'")
        if not entry.get("subject", "").strip():
            _fail(f"asset '{aid}': empty subject")
        assets.append(Asset(
            id=aid, kind=entry["kind"], out=ROOT / entry["out"], subject=entry["subject"].strip(),
            character_refs=tuple(entry.get("character_refs", [])),
        ))
    ids = {a.id for a in assets}
    for a in assets:
        for ref in a.character_refs:
            if ref not in ids:
                _fail(f"asset '{a.id}': character_ref '{ref}' is not an asset id")
    LOG.debug("manifest: %d assets (%s)", len(assets), ", ".join(a.id for a in assets))
    return assets


def describe(assets: list[Asset]) -> str:
    lines = []
    for a in assets:
        state = "done" if a.out.exists() else "todo"
        lines.append(f"{a.id:18} {a.kind:10} {state:5} {rel(a.out)}")
    return "\n".join(lines)
