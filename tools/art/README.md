# tools/art — base-theme art generator (Gemini)

Generates the Karak (base theme) monsters, hero portraits and the lobby background with the Gemini image models, keeps the style consistent through a committed style bible and reference images, and records every generation for reproducibility.

## Setup

```bash
# from the repository root
python3 -m venv tools/art/.venv
tools/art/.venv/bin/pip install -r tools/art/requirements.txt

# API key (https://aistudio.google.com/apikey) — tools/art/.env is gitignored
cp tools/art/.env.example tools/art/.env   # then put the key into GEMINI_API_KEY=
```

`GEMINI_API_KEY` in the process environment also works. Never put the key into the root `.env`: that file is tracked by git. If the key has an IP restriction, the machine running the tool must be allowed.

## Commands

```bash
tools/art/.venv/bin/python -m tools.art list                      # manifest assets and whether their output exists
tools/art/.venv/bin/python -m tools.art probe                     # one test call: confirms the API contract, writes out/probe.jpg + out/probe.webp
tools/art/.venv/bin/python -m tools.art generate                  # every asset whose output is missing
tools/art/.venv/bin/python -m tools.art generate --only dragon,oracle --force   # regenerate by id
tools/art/.venv/bin/python -m tools.art -v generate --dry-run     # print prompts and references, no API calls
tools/art/.venv/bin/python -m tools.art --model gemini-3-pro-image generate --only lobby_bg --force
```

Outputs go straight into `fe/public/themes/base/` (the paths the theme expects, see `fe/src/theme/base.js`). Raw API images are kept in `tools/art/out/raw/` (gitignored) for debugging the chroma-key step.

## How it works

1. **Style bible** `style/style.json` (+ `style/STYLE.md` for humans): the style prompt, negative prompt, palette, per-kind prompt frames, the reference images (existing item icons, figures, a tile, the Monad lobby painting as composition reference) and per-kind output settings (size, aspect ratio, model).
2. **Manifest** `assets.json`: one entry per asset — `id` (= theme key), `kind` (`monster` | `hero` | `background`), `out`, `subject` prose, optional `character_refs` (ids of generated assets to attach).
3. **Generation** in manifest order. Each call sends: the style references for the kind → up to 5 of the most recently generated sprites of the same kind (or the `character_refs`) → the prompt (`style_prompt` + frame + subject + `negative_prompt`). At most 14 references per call.
4. **Post-processing**: sprites are generated on flat `#00FF00`, the green is keyed out (HSV mask, 2 px dilation, alpha snapped to 0/255), the subject is cropped, padded 6 % and resized (monsters 100×100, heroes 100×150) to a lossless RGBA webp. The background is cover-cropped to 1536×1024 and saved as webp q85.
5. **Log** `generated.json` (committed): model, style version, prompt SHA-256, ordered references with roles, raw file, output, timestamp, duration and post-processing stats per asset.

## Troubleshooting

- `WARN green residue` — fringe left after keying: regenerate (`--only <id> --force`) or lower `MIN_SAT` / raise `DILATE_PX` in `postprocess.py`.
- `WARN subject touches the image border` — the model cropped the subject: regenerate; the prompt already asks for padding.
- `API error 429` — rate limited: the client retries 3× with backoff; wait and rerun, finished assets are skipped.
- `403 … IP address restriction` — the key is restricted to other IPs: fix the key restriction in Google AI Studio / Cloud console.
- Looks off-style — check the `-v` output: the references listed are what the model saw. Add a reference in `style.json` or adjust `subject`.
