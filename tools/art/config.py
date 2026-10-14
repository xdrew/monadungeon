"""Paths, API key and logging setup for the art generator."""
from __future__ import annotations

import logging
import os
from pathlib import Path

LOG = logging.getLogger("art")

TOOL_DIR = Path(__file__).resolve().parent
ROOT = TOOL_DIR.parents[1]  # repository root: tools/art -> tools -> repo
STYLE_FILE = TOOL_DIR / "style" / "style.json"
ASSETS_FILE = TOOL_DIR / "assets.json"
GENERATED_LOG = TOOL_DIR / "generated.json"
OUT_DIR = TOOL_DIR / "out"
RAW_DIR = OUT_DIR / "raw"
ENV_FILE = TOOL_DIR / ".env"

ENV_KEY = "GEMINI_API_KEY"


def setup_logging(verbose: bool) -> None:
    level = logging.DEBUG if verbose else logging.INFO
    logging.basicConfig(level=level, format="%(asctime)s %(levelname)s %(message)s", datefmt="%H:%M:%S")
    # the SDK's HTTP client is chatty at DEBUG
    logging.getLogger("httpx").setLevel(logging.WARNING)
    logging.getLogger("httpcore").setLevel(logging.WARNING)
    logging.getLogger("PIL").setLevel(logging.WARNING)
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
        value = value.strip().strip('"').strip("'")
        values[key.strip()] = value
    return values


def load_api_key() -> str:
    """GEMINI_API_KEY from the environment, else from tools/art/.env. Never logged."""
    key = os.environ.get(ENV_KEY, "").strip()
    source = "environment"
    if not key:
        key = _parse_env_file(ENV_FILE).get(ENV_KEY, "").strip()
        source = str(ENV_FILE.relative_to(ROOT))
    if not key:
        raise SystemExit(
            f"{ENV_KEY} is not set. Export it or put it into {ENV_FILE.relative_to(ROOT)} "
            f"(see {ENV_FILE.relative_to(ROOT)}.example). Do NOT put it into the root .env — that file is tracked by git."
        )
    LOG.debug("api key loaded from %s (%d chars)", source, len(key))
    return key


def rel(path: Path) -> str:
    """Repo-relative path for logs."""
    try:
        return str(path.resolve().relative_to(ROOT))
    except ValueError:
        return str(path)
