"""Music (Lyria) and sound effects (ElevenLabs). Run from the repo root: tools/audio/.venv/bin/python -m tools.audio <command>."""
from __future__ import annotations

import argparse
import logging
import sys

from . import pipeline, postprocess
from .config import ELEVENLABS_KEY, GEMINI_KEY, OUT_DIR, load_key, rel, setup_logging
from .manifest import describe, load_assets, load_style, write_variants

LOG = logging.getLogger("audio")


def _clients(style, assets, only) -> dict:
    wanted = {style[a.kind].provider for a in assets if not only or a.id in only}
    clients = {}
    if "lyria" in wanted:
        from .lyria import MusicClient
        clients["lyria"] = MusicClient(load_key(GEMINI_KEY))
    if "elevenlabs" in wanted:
        from .elevenlabs import SfxClient
        clients["elevenlabs"] = SfxClient(load_key(ELEVENLABS_KEY), style["sfx"].model)
    return clients


def cmd_list(args) -> int:
    print(describe(load_assets(), load_style()))
    return 0


def cmd_probe(args) -> int:
    """One short call to each API, post-processed into out/: confirms both contracts and shows the cost."""
    from .elevenlabs import SfxClient
    from .lyria import MusicClient

    style = load_style()
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    data, info = SfxClient(load_key(ELEVENLABS_KEY), style["sfx"].model).generate(
        "Two wooden dice rolled onto a wooden table, a short clatter.", 1.5, style["sfx"].prompt_influence)
    (OUT_DIR / "probe-sfx-raw.mp3").write_bytes(data)
    stats = postprocess.process(OUT_DIR / "probe-sfx-raw.mp3", OUT_DIR / "probe-sfx.mp3",
                                postprocess.Shape("sfx", False, style["sfx"].lufs, 1.5, True, style["sfx"].bitrate))
    LOG.info("ElevenLabs: %s credits, %s → %s", info.get("credits"), stats, rel(OUT_DIR / "probe-sfx.mp3"))
    model = args.model or style["sting"].model
    data, info = MusicClient(load_key(GEMINI_KEY)).generate(
        style["sting"].prefix + " A five-second calm lute phrase.", model)
    (OUT_DIR / "probe-music-raw.mp3").write_bytes(data)
    stats = postprocess.process(OUT_DIR / "probe-music-raw.mp3", OUT_DIR / "probe-music.mp3",
                                postprocess.Shape("sting", False, style["sting"].lufs, 5, False, style["sting"].bitrate,
                                                  fade_out=1.2))
    LOG.info("Lyria %s: $%s, %s → %s", model, info.get("cost_usd"), stats, rel(OUT_DIR / "probe-music.mp3"))
    return 0


def cmd_generate(args) -> int:
    style = load_style()
    assets = load_assets()
    only = set(args.only.split(",")) if args.only else None
    if only:
        unknown = only - {a.id for a in assets}
        if unknown:
            raise SystemExit(f"unknown asset ids: {', '.join(sorted(unknown))}")
    clients = None if args.dry_run else _clients(style, assets, only)
    if not args.dry_run:
        write_variants(assets)
    try:
        n, spend = pipeline.run(style, assets, clients, only, args.force, args.dry_run)
    except pipeline.GenerationError as exc:
        LOG.error("generation failed: %s", exc)
        return 1
    if args.dry_run:
        LOG.info("dry-run: %d file(s) would be generated", n)
    else:
        LOG.info("generate: %d file(s), spent $%.2f (Lyria) and %d ElevenLabs credits", n, spend["usd"],
                 spend["credits"])
    return 0


def cmd_reprocess(args) -> int:
    only = set(args.only.split(",")) if args.only else None
    n = pipeline.reprocess(load_style(), load_assets(), only)
    LOG.info("reprocess: %d file(s)", n)
    return 0


def cmd_candidates(args) -> int:
    from . import candidates
    style = load_style()
    assets = load_assets()
    candidates.generate(args.id, _clients(style, assets, {args.id}))
    return 0


def cmd_pick(args) -> int:
    from . import candidates
    candidates.pick(args.id, args.takes.split(","))
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="tools.audio", description="Generate game music (Lyria) and sounds (ElevenLabs).")
    parser.add_argument("--verbose", "-v", action="store_true", help="DEBUG logging (prompts, ffmpeg)")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("list", help="show manifest assets and which outputs exist").set_defaults(func=cmd_list)
    probe = sub.add_parser("probe", help="one call to each API, written to tools/audio/out/")
    probe.add_argument("--model", help="the Lyria model to try (default: the sting model)")
    probe.set_defaults(func=cmd_probe)
    gen = sub.add_parser("generate", help="generate missing outputs from assets.json")
    gen.add_argument("--only", help="comma-separated asset ids")
    gen.add_argument("--force", action="store_true", help="regenerate even if the output exists")
    gen.add_argument("--dry-run", action="store_true", help="show the prompts, no API calls")
    gen.set_defaults(func=cmd_generate)
    rep = sub.add_parser("reprocess", help="re-run the post-processing from out/raw, no API calls")
    rep.add_argument("--only", help="comma-separated asset ids")
    rep.set_defaults(func=cmd_reprocess)
    cand = sub.add_parser("candidates", help="takes of several ideas for one sound, onto the sound bench")
    cand.add_argument("id", help="a sound id; the ideas are in tools/audio/candidates/<id>.json")
    cand.set_defaults(func=cmd_candidates)
    pk = sub.add_parser("pick", help="put the chosen takes into the game")
    pk.add_argument("id")
    pk.add_argument("takes", help="comma-separated take numbers from the bench, e.g. 15,16")
    pk.set_defaults(func=cmd_pick)
    args = parser.parse_args(argv)
    setup_logging(args.verbose)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
