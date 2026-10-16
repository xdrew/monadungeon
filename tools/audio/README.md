# tools/audio — the game's music and sounds

Music and stings come from **Google Lyria** (Gemini API), sound effects from **ElevenLabs Sound Effects**.
Every file is trimmed, faded, loudness-matched and encoded by ffmpeg, then written to `fe/public/audio/`
(`music/` and `sfx/`), where the game's audio engine (`fe/src/services/audio/`) plays it.

## Setup (on the host, needs ffmpeg)

```bash
python3 -m venv tools/audio/.venv && tools/audio/.venv/bin/pip install -r tools/audio/requirements.txt
cp tools/audio/.env.example tools/audio/.env   # keys, gitignored; never into the root .env
```

- `GEMINI_API_KEY` — the paid tier (Lyria has no free tier). If it is not in `tools/audio/.env`, the one in `tools/art/.env` is used.
- `ELEVENLABS_API_KEY` — a key with the Sound Effects permission is enough.

## Commands (from the repo root)

```bash
tools/audio/.venv/bin/python -m tools.audio list                       # every asset, which files exist
tools/audio/.venv/bin/python -m tools.audio probe                      # one call to each API → tools/audio/out/
tools/audio/.venv/bin/python -m tools.audio generate --dry-run         # the prompts, no calls
tools/audio/.venv/bin/python -m tools.audio generate                   # the missing files
tools/audio/.venv/bin/python -m tools.audio generate --only step --force   # redo one (all its variants)
tools/audio/.venv/bin/python -m tools.audio reprocess --only lobby     # post-processing again from out/raw, no calls
```

## Files

- `style/style.json` — per kind (`music`, `sting`, `sfx`): provider, model, the prompt prefix/suffix, loudness (LUFS), bitrate, mono, output folder. `style/STYLE.md` — the sound of the game in words.
- `assets.json` — every track and cue: `id`, `kind`, `prompt`, `seconds` (stings and sounds are cut to it), `loop`, `variants`.
- `generated.json` — the log: model, cost (USD or ElevenLabs credits), prompt SHA-256, length, measured loudness and peak, time.
- `out/raw/` (gitignored) — what the APIs returned, for `reprocess`.

## Post-processing

Silence trimmed at both ends. A loop drops its last 6 s (Lyria tracks wind down) and crosses its end into its start over 4 s, so it has no seam. A sting or a sound is cut to its `seconds` with a fade-out. One gain brings it to the loudness target (music −18, stings −17, sounds −16 LUFS; a sound shorter than 1 s is measured looped, since EBU R128 gates on 400 ms blocks), a limiter keeps the peaks under −1 dBFS. Music is 112 kbps stereo mp3, sounds 96 kbps mono.

## Listening: the sound bench

In a dev build open `/dev/sounds` (also linked from the lobby's "Build: dev" line): every track, sting and sound with the game's picture it goes with, animated for as long as the sound lasts, its waveform with a playhead, each variant on its own button, where the game plays it, and "moments" that play several cues with the game's timing (a fight won or lost, the dragon, a turn, a chest). Music plays through the engine as in the game (crossfades, the sound ring's volumes). Not in production builds.

## Adding a sound

1. An entry in `assets.json`, then `generate --only <id>`.
2. The id in `fe/src/services/audio/cues.js` (`SFX`, and `SFX_VARIANTS` if it has several).
3. `audio.cue('<id>')` where it happens (see CLAUDE.md for the places).

## Costs seen (October 2026)

- Lyria 3.5 (`lyria-3.5`, full tracks of 1–3 minutes): $0.08 a track; Lyria 3 Clip (`lyria-3-clip-preview`, 30 s, for the stings): $0.04. The whole set: $0.48.
- ElevenLabs: about 10 credits per second of sound (5 for a 0.5 s click). The whole set (44 files): 434 credits of the free 10,000 a month.

## Licensing

- ElevenLabs: the free plan's sounds may not be used commercially; a public, commercial game needs a paid plan (Starter or higher) at the time of generation. Check the current terms before release.
- Lyria output carries a SynthID audio watermark.
- `generated.json` keeps each file's provenance.
