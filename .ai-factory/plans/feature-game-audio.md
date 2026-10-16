# Implementation Plan: Music by situation and sounds for the interface and every game action

Branch: feature/game-audio (created from feature/online-google @ c5828a2; the chain is merged to main together)
Created: 2026-10-16

## Original Request
Еще бы сгенерить музыку получше и другие звуки в игре. Интерфейс и остальные действия в процессе игры (Делай план по музыке и звукам)

## Settings
- Testing: yes. The audio engine and the cue mapping are covered by e2e (a dev-only cue log, `window.__audioCues`); the implementer runs the new checks and every topic spec.
- Logging: standard.
  - Generator (Python): each call, its duration, the output path and the post-processing stats at INFO. Retries and refusals at WARNING. API keys are never logged.
  - Browser: `[audio]` unlock, track change, a failed decode at warn.
- Docs: yes (`tools/audio/README.md`, README, CLAUDE.md, `fe/e2e/README.md`).

User answers (2026-10-16):
- Music: **Google Lyria**.
- Sounds: **ElevenLabs Sound Effects**.
- Music by situation, with smooth transitions.
- The mood: **dark fantasy and light, tabletop**. The dungeon and torches (the Torchlight look) in a calm way that does not tire over a long game, darker in fights.
- **Separate volumes** for music and sounds.

## Requirements Reconciliation
Authority: user answers > CLAUDE.md (Torchlight, no emoji, debug only in dev, i18n) > existing code.

| Decision | Source | Verification |
|---|---|---|
| **Generator** `tools/audio/` mirrors `tools/art`. Parts: a style bible `style/style.json` + `STYLE.md` (the sound of the game, instruments, mood per situation, loudness targets); a manifest `assets.json` (`id`, `kind` music\|sting\|sfx, `prompt`, `seconds`, `loop`, `out`, `variants`); a log `generated.json` (model, prompt SHA-256, duration, loudness, timestamp). CLI: `python -m tools.audio list \| probe \| generate [--only ids] [--force] [--dry-run]`. Keys come from `tools/audio/.env` (gitignored) or the environment: `GEMINI_API_KEY`, `ELEVENLABS_API_KEY`. | `tools/art` pattern | `--dry-run`, `probe` |
| **Music: Lyria 3.5 through the Gemini API** (`lyria-3.5`, Interactions API, instrumental prompts, WAV out). `probe` checks the model id, the request shape, the cost per call and the length limits before anything else. Should it be unavailable on the key, the fallback is `lyria-3-clip-preview` (30 s clips, joined into loops). | user; Google docs (Sept 2026) | `probe` writes `out/probe.wav` |
| **Sounds: ElevenLabs Sound Effects API** (`POST /v1/sound-generation`, `duration_seconds`, `prompt_influence`). 2–3 variants are kept for frequent sounds (steps, clicks, the dice) and the game picks one at random, so repeats do not grate. | user | `probe` |
| **Post-processing with ffmpeg**: trim silence, fade in/out, loudness to −18 LUFS for music and −16 LUFS for sounds (so the sliders mean the same everywhere), music loops crossfaded at the seam. Encode to `.mp3` (music 160 kbps, sounds 128 kbps mono where possible) in `fe/public/audio/{music,sfx}/`. | ffmpeg present | the log has the measured loudness |
| **Tracks** (loops unless marked): `lobby` (warm, light tabletop, lute and soft strings), `explore` (dark fantasy ambient, low strings, soft drums, torches; long and calm; 2 variants alternating), `fight` (taut drums, low brass; short loop), `dragon` (the dragon's fight, bigger), and stings (not looped) `victory_sting`, `defeat_sting`, `game_over` (results), `lord_karak` (the reveal). | user: by situation | listening + the log |
| **Sounds (cues)**: | user: the interface and every action | e2e cue log |
| — interface | | `ui_click` (×3), `ui_toggle`, `ui_open` (a plaque or drawer), `ui_close`, `ui_refused` (a refused action), `notice` |
| — the field | | `tile_draw`, `tile_place`, `tile_rotate`, `step` (×3), `teleport`, `fountain_heal`, `chest_open`, `chest_locked`, `item_pickup`, `treasure`, `key`, `item_drop` |
| — the fight | | `dice_roll` (×2), `hit_win`, `hit_lose`, `draw`, `monster_roar`, `dragon_roar`, `stunned`, `reincarnate` |
| — spells and skills | | `spell_fireball`, `spell_teleport`, `dark_thorn`, `frost_fist`, `curse`, `curse_lifted`, `sneak` |
| — turns and tables | | `end_turn`, `your_turn`, `turn_tick` (the last 10 s of an online time limit), `table_join`, `game_start`, `duel_start` |
| **Engine** `fe/src/services/audio/`: one `AudioContext`, buses master → music / sfx (gain nodes); sounds decoded once, played as buffers; music as two `<audio>` elements through `MediaElementSource`, crossfading 1.5 s. Unlocked on the first user gesture (the current autoplay handling moves here). Settings in localStorage `audio.music`, `audio.sfx` (0–1), `audio.muted`; the old `musicEnabled` is read once for compatibility. The tab hidden → music fades down. `musicService` is replaced (its callers move to the engine). | existing `musicService` | e2e |
| **Situations** decide the music: start screen, rules and Hall of fame → `lobby`; a game → `explore`; the fight scene open → `fight` (`dragon` for the dragon); the fight ends → a sting, then back; the results → `game_over`; Lord Karak's reveal → `lord_karak`. One composable `useMusicSituation`. | user | e2e cue log |
| **Cues are hooked where things happen**, in one place each: `useGameSounds` in GameView (watchers on the state and the existing handlers: tile placed, moved, fight result, pickup, spell, curse, end turn, my turn, the countdown), the AI and online playback (`useAiTurnPlayback` actions → the same cues, so the opponent's moves sound too), and a delegated click listener for `.btn` / iron rings (`data-sound="none"` opts out). Nothing plays twice: a cue fired within 60 ms of the same cue is dropped. | — | e2e |
| **Settings UI**: the music ring becomes a sound ring. A small Torchlight popover holds Music and Sounds sliders and a mute, on every page (start screen, game, rules, Hall of fame, 404, table pages). Texts in en/ru/pl; a line icon (no emoji). The e2e keeps working: the old `.music-toggle` class stays on the ring. | user: separate volumes | e2e; screenshots |
| **Tests**: in dev builds the engine records cues in `window.__audioCues` (id + time). `fe/e2e/tests/audio.spec.ts`: the popover sets and keeps the volumes across a reload; muting stops the music; a test-mode game produces `tile_place`, `step`, `dice_roll`, `hit_win`, `item_pickup` and `end_turn` cues in order; the fight switches the situation to `fight` and back; the AI playback cues its actions. Headless Chromium runs with sound muted, so only cues are asserted, not audio output. | e2e convention | the implementer runs it and every topic spec |
| **Licensing**: ElevenLabs output is for commercial use on a paid plan only (Starter or higher); Lyria output carries SynthID watermarks. Both go into `tools/audio/README.md`, and every file's provenance into `generated.json`. | providers' terms | docs |
| **Weight**: about 4–6 MB of music and 1 MB of sounds; music loads lazily per situation, sounds are preloaded on the first gesture. | — | build size check |

## Commit Plan
- **Commit 1** (tasks 1–3): "feat(audio): tools/audio — Lyria music and ElevenLabs sound effects, loudness-matched, logged"
- **Commit 2** (tasks 4–6): "feat(fe): the audio engine — music by situation with crossfades, sound cues, separate volumes"
- **Commit 3** (tasks 7–9): "feat(fe): sounds for the interface and every game action; e2e and docs"

## Tasks

### Phase 1: Generate
- [x] Task 1: `tools/audio/` package. Contents:
  - `config.py` (keys, paths);
  - `lyria.py` and `elevenlabs.py` clients (retries with backoff, a refusal reported, never the key);
  - `postprocess.py` (ffmpeg trim / fade / loudnorm / loop crossfade / encode, measured LUFS returned);
  - `manifest.py`, `pipeline.py`, `__main__.py` (`list`, `probe`, `generate`);
  - `requirements.txt`, `.env.example`, `.gitignore` for `out/` and `.env`.
- [x] Task 2: The style bible (`style/style.json`, `STYLE.md`) and the manifest (`assets.json`) with every track and cue in the table above. Prompts in English, instrumental, the mood per situation.
- [x] Task 3: `probe` against both APIs (the costs printed). Then `generate` everything into `fe/public/audio/`, listen-check by loudness and length from the log, regenerate outliers. Commit the audio and `generated.json`.

### Phase 2: The engine
- [x] Task 4: `fe/src/services/audio/` (`audioEngine.js`: context, buses, unlock, cue playback with variants and the 60 ms dedupe, music crossfade, settings, the dev cue log; `cues.js`: the cue → files map and the situation → track map). Remove `musicService.js`; move its callers (HomeView, GameView, RulesView, MusicToggle).
- [x] Task 5: `useMusicSituation` (route + fight scene + results + Lord Karak) and the sound ring with the popover (`fe/src/components/ui/SoundControl.vue`, replacing `MusicToggle.vue` on every page; keeps the `.music-toggle` class); i18n en/ru/pl; `npm run i18n:check`.
- [x] Task 6: The delegated interface sounds (clicks on `.btn`, iron rings, toggles; opening/closing plaques and the Journal; refusals through `showActionNotice`).

### Phase 3: The game's sounds, e2e, docs
- [x] Task 7: `fe/src/composables/useGameSounds.js` hooked into GameView: the field, the fight scene (the dice when they are thrown, the result at the slam), pickups, spells, the curse, turns, the online countdown; the AI and online playback through the same cues.
- [ ] Task 8: `fe/e2e/tests/audio.spec.ts` (as in the table), then every topic spec; screenshots of the popover (desktop and phone) reviewed. (audio.spec.ts: 4/4 green; the other topic specs and the new pages lobby check wait: e2e paused by the user while they play)
- [x] Task 9: Docs:
  - `tools/audio/README.md` (setup, keys, commands, costs seen in `probe`, licensing);
  - README (the sound in the game);
  - CLAUDE.md (the engine, cues, how to add one);
  - `fe/e2e/README.md`.
