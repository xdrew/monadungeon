"""Base-theme art generator. Run from the repo root: tools/art/.venv/bin/python -m tools.art <command>."""
from __future__ import annotations

import argparse
import logging
import sys

from . import pipeline
from .config import OUT_DIR, RAW_DIR, load_api_key, rel, setup_logging
from .manifest import describe, load_assets, load_style

LOG = logging.getLogger("art")


def cmd_list(args) -> int:
    assets = load_assets()
    print(describe(assets))
    return 0


def cmd_probe(args) -> int:
    from .gemini import ImageClient
    from .config import ROOT

    style = load_style()
    client = ImageClient(load_api_key(), args.model or style.default_model)
    ref = ROOT / "fe/public/images/key.webp"
    prompt = "\n\n".join([style.style_prompts["monster"],
                          style.frames["monster"].format(subject="a small rusty iron padlock with a brass keyhole"),
                          style.negative_prompts["monster"]])
    LOG.info("probe model=%s ref=%s", client.model, rel(ref))
    shape, data = client.describe_response_shape(prompt, [ref])
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    out = OUT_DIR / "probe.jpg"
    out.write_bytes(data)
    LOG.info("response: %s", shape)
    LOG.info("wrote %s", rel(out))
    # run the sprite post-processing on it so the whole chain is exercised
    from . import postprocess
    image, info = postprocess.process_sprite(data, 100, 100)
    probe_sprite = OUT_DIR / "probe.webp"
    postprocess.save_sprite(image, probe_sprite)
    LOG.info("post-processed %s: %s", rel(probe_sprite), info)
    return 0


def cmd_generate(args) -> int:
    style = load_style()
    assets = load_assets()
    only = set(args.only.split(",")) if args.only else None
    if only:
        unknown = only - {a.id for a in assets}
        if unknown:
            raise SystemExit(f"unknown asset ids: {', '.join(sorted(unknown))}")
    client = None
    if not args.dry_run:
        from .gemini import ImageClient
        client = ImageClient(load_api_key(), args.model or style.default_model)
    try:
        n = pipeline.run(style, assets, client, only, args.force, args.dry_run, args.model)
    except pipeline.GenerationError as exc:
        LOG.error("generation failed: %s", exc)
        return 1
    LOG.info("%s: %d asset(s) %s", "dry-run" if args.dry_run else "generate", n,
             "would be generated" if args.dry_run else "generated")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="tools.art", description="Generate base-theme art with Gemini.")
    parser.add_argument("--verbose", "-v", action="store_true", help="DEBUG logging (prompts, references)")
    parser.add_argument("--model", help="override the model id for every call")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("list", help="show manifest assets and whether their output exists").set_defaults(func=cmd_list)
    sub.add_parser("probe", help="one test call: confirms the API contract and writes out/probe.jpg").set_defaults(func=cmd_probe)
    gen = sub.add_parser("generate", help="generate missing assets from assets.json")
    gen.add_argument("--only", help="comma-separated asset ids")
    gen.add_argument("--force", action="store_true", help="regenerate even if the output exists")
    gen.add_argument("--dry-run", action="store_true", help="build prompts and references, no API calls")
    gen.set_defaults(func=cmd_generate)
    args = parser.parse_args(argv)
    setup_logging(args.verbose)
    LOG.debug("raw dir %s", rel(RAW_DIR))
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
