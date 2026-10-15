# Base theme style bible

`style.json` next to this file is what the generator reads; this page explains the choices so the style stays consistent when assets are added or regenerated.

## Setting

Karak: a dark stone dungeon lit by wall torches, treasure chests full of gold, undead and vermin guarding the halls, heroes from the board game (Horan the Warrior, Aderyn the Thief, Victorius the Swordsman, Lord Xanros the Warlock, Argentus the Wizard, Taia the Oracle). Nothing modern, no mascots, no text.

## What the existing art looks like

- **Item icons** (`fe/public/images/key.webp`, `axe.webp`, `sword.webp`, `dagger.webp`, `chest-closed.webp`): 100×100 RGBA webp, a single object centred with padding, thin dark outline, soft painterly shading with a slightly pixel-art feel, warm highlight from the upper left. These are the **style references** for every sprite.
- **Figures** (`player.webp`, `ai.webp`): 100×150 full-body characters, feet visible, same shading. Style references for heroes.
- **Tiles** (`fe/public/assets/tiles/*.webp`): worn stone, moss, dark grout. Reference for the dungeon material.
- **Lobby background** (`fe/public/themes/monad/lobby-bg.webp`, 1536×1024): painterly dungeon hall, open chest with glowing gold at the centre, torches, monsters gathered around. The base lobby art keeps this composition with Karak monsters instead of mascots.

## Palette

| Hex | Use |
|---|---|
| `#c9a227` aged gold | coins, crowns, keys |
| `#8a6a3a` / `#5a3b21` leather / dark wood | straps, chests, hafts |
| `#e8dcc3` bone white | skeletons, bandages, highlights |
| `#6f8f4f` mossy green | moss, slime (never chroma green) |
| `#6b6b70` stone grey | armour, stone |
| `#e07a2a` ember orange | torch light, glowing eyes |
| `#1a1226` shadow violet-black | deepest shadows, outlines |

## Sprite rules

- One subject, centred, ~6 % padding after cropping; silhouette must read at 32 px.
- Generated on flat chroma-key green `#00FF00` with a 2–3 px white outline; the tool removes the green and snaps alpha to 0/255. The subject must not contain pure green.
- Monsters: 256×256 (shown at 32–128 px), 3/4 front view, menacing; the file is mirrored in post so monsters face left, towards the hero (`kinds.monster.mirror`). Heroes: 256×384, full body, standing.
- Icons (`kind: icon`): 100×100 RGBA, one object (a spell scroll, a tile marker), not mirrored, like the existing item icons; their style references are the five item icons plus `fireball.webp`, `hf-teleport.webp` and `hf.webp`. Used for the Karak: Regent spells (`dark-thorn.webp`, `frost-fist.webp`) and tile markers (`arena.webp`, `cursed-room.webp`) in `fe/public/images/`.
- No scene, no floor, no cast shadow on the background, no text.

## Consistency mechanics

Every call sends the style references first, then up to five of the most recently generated sprites of the same kind as "character" references, then the prompt (`style_prompt` + kind frame + subject + `negative_prompt`). The lobby background gets the composition reference plus five generated monsters. `generated.json` records the exact references and the prompt hash per asset, so a regeneration can reproduce the inputs.

## Extending

- New asset: add an entry to `assets.json` (`id` = theme key, `kind`, `out`, `subject`).
- Style change: edit `style.json`, bump `style_version`, regenerate with `--force`.
- New reference: drop the file under `fe/public` and add it to `references` with a role (`style`, `composition`) and the kinds it applies to.
