"""Paths, API keys and logging setup for the audio generator."""
from __future__ import annotations

import logging
import os
from pathlib import Path

LOG = logging.getLogger("audio")

TOOL_DIR = Path(__file__).resolve().parent
ROOT = TOOL_DIR.parents[1]  # repository root: tools/audio -> tools -> repo
STYLE_FILE = TOOL_DIR / "style" / "style.json"
ASSETS_FILE = TOOL_DIR / "assets.json"
GENERATED_LOG = TOOL_DIR / "generated.json"
OUT_DIR = TOOL_DIR / "out"
RAW_DIR = OUT_DIR / "raw"
ENV_FILE = TOOL_DIR / ".env"
ART_ENV_FILE = ROOT / "tools" / "art" / ".env"  # the art generator's Gemini key serves the music too

GEMINI_KEY = "GEMINI_API_KEY"
ELEVENLABS_KEY = "ELEVENLABS_API_KEY"


def setup_logging(verbose: bool) -> None:
    level = logging.DEBUG if verbose else logging.INFO
    logging.basicConfig(level=level, format="%(asctime)s %(levelname)s %(message)s", datefmt="%H:%M:%S")
    logging.getLogger("httpx").setLevel(logging.WARNING)
    logging.getLogger("httpcore").setLevel(logging.WARNING)
    LOG.debug("root=%s tool_dir=%s", ROOT, TOOL_DIR)


def _parse_env_file(path: Path) -> dict[str, str]:
    """Minimal KEY=value parser (no python-dotenv dependency)."""
    values: dict[str, str] = {}
    if not path.exists():
        return values
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def load_key(name: str) -> str:
    """A key from the environment, else tools/audio/.env (else tools/art/.env for Gemini). Never logged."""
    sources = [("environment", os.environ.get(name, ""))]
    sources.append((rel(ENV_FILE), _parse_env_file(ENV_FILE).get(name, "")))
    if name == GEMINI_KEY:
        sources.append((rel(ART_ENV_FILE), _parse_env_file(ART_ENV_FILE).get(name, "")))
    for source, value in sources:
        if value.strip():
            LOG.debug("%s loaded from %s (%d chars)", name, source, len(value.strip()))
            return value.strip()
    raise SystemExit(
        f"{name} is not set. Export it or put it into {rel(ENV_FILE)} (see {rel(ENV_FILE)}.example). "
        "Do NOT put it into the root .env — that file is tracked by git."
    )


def rel(path: Path) -> str:
    """Repo-relative path for logs."""
    try:
        return str(path.resolve().relative_to(ROOT))
    except ValueError:
        return str(path)
