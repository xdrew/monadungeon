"""Turn raw Gemini output into theme assets: chroma-key removal, trim, fit, webp."""
from __future__ import annotations

import io
import logging
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter

LOG = logging.getLogger("art.post")

# chroma key #00FF00 → hue 120°; thresholds from the sticker workflow (philschmid.de/generate-stickers)
HUE_CENTER = 120.0
HUE_RANGE = 25.0
MIN_SAT = 0.75
MIN_VAL = 0.70
DILATE_PX = 2
ALPHA_SNAP = 64


def load(data: bytes) -> Image.Image:
    return Image.open(io.BytesIO(data)).convert("RGBA")


def _hsv(rgb: np.ndarray) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    """Vectorised RGB(0..1) → H(deg), S, V."""
    r, g, b = rgb[..., 0], rgb[..., 1], rgb[..., 2]
    mx = rgb.max(axis=-1)
    mn = rgb.min(axis=-1)
    delta = mx - mn
    h = np.zeros_like(mx)
    nz = delta > 1e-6
    rc = np.where(nz, (mx - r) / np.where(nz, delta, 1), 0)
    gc = np.where(nz, (mx - g) / np.where(nz, delta, 1), 0)
    bc = np.where(nz, (mx - b) / np.where(nz, delta, 1), 0)
    h = np.where(mx == r, bc - gc, np.where(mx == g, 2.0 + rc - bc, 4.0 + gc - rc))
    h = (h / 6.0) % 1.0 * 360.0
    h = np.where(nz, h, 0.0)
    s = np.where(mx > 1e-6, delta / np.where(mx > 1e-6, mx, 1), 0.0)
    return h, s, mx


def green_mask(img: Image.Image) -> np.ndarray:
    rgb = np.asarray(img.convert("RGB"), dtype=np.float32) / 255.0
    h, s, v = _hsv(rgb)
    hue_diff = np.abs(h - HUE_CENTER)
    hue_diff = np.minimum(hue_diff, 360.0 - hue_diff)
    return (hue_diff < HUE_RANGE) & (s > MIN_SAT) & (v > MIN_VAL)


def remove_chroma_key(img: Image.Image) -> Image.Image:
    """Make the chroma-key green transparent; alpha is snapped to 0/255."""
    mask = green_mask(img)
    before = float(mask.mean()) * 100
    mask_img = Image.fromarray((mask * 255).astype(np.uint8), mode="L")
    if DILATE_PX > 0:
        mask_img = mask_img.filter(ImageFilter.MaxFilter(2 * DILATE_PX + 1))
    mask = np.asarray(mask_img) > 0
    data = np.array(img, dtype=np.uint8)
    alpha = data[..., 3].copy()
    alpha[mask] = 0
    alpha = np.where(alpha < ALPHA_SNAP, 0, 255).astype(np.uint8)
    data[..., 3] = alpha
    out = Image.fromarray(data, mode="RGBA")
    LOG.debug("chroma key: %.1f%% of pixels were green, %.1f%% opaque after", before, float((alpha > 0).mean()) * 100)
    return out


def green_residue_pct(img: Image.Image) -> float:
    """Share of opaque pixels that are still green-dominant (fringe left after keying)."""
    data = np.asarray(img.convert("RGBA"), dtype=np.int16)
    opaque = data[..., 3] > 0
    if not opaque.any():
        return 0.0
    r, g, b = data[..., 0], data[..., 1], data[..., 2]
    greenish = (g > 140) & (g > r + 50) & (g > b + 50)
    return float((greenish & opaque).sum() / opaque.sum() * 100)


def trim_and_fit(img: Image.Image, width: int, height: int, padding: float = 0.06) -> tuple[Image.Image, dict]:
    """Crop to the alpha bbox, pad to the target aspect, resize with LANCZOS."""
    bbox = img.getbbox()
    if bbox is None:
        raise ValueError("image is fully transparent after keying")
    w, h = img.size
    touches = bbox[0] == 0 or bbox[1] == 0 or bbox[2] == w or bbox[3] == h
    cropped = img.crop(bbox)
    cw, ch = cropped.size
    target_ratio = width / height
    # canvas large enough for the content plus padding, in the target aspect
    pad_w = int(round(cw * padding))
    pad_h = int(round(ch * padding))
    canvas_w = cw + 2 * pad_w
    canvas_h = ch + 2 * pad_h
    if canvas_w / canvas_h > target_ratio:
        canvas_h = int(round(canvas_w / target_ratio))
    else:
        canvas_w = int(round(canvas_h * target_ratio))
    canvas = Image.new("RGBA", (canvas_w, canvas_h), (0, 0, 0, 0))
    canvas.paste(cropped, ((canvas_w - cw) // 2, (canvas_h - ch) // 2))
    fitted = canvas.resize((width, height), Image.LANCZOS)
    # keep edges hard after resampling
    data = np.array(fitted)
    data[..., 3] = np.where(data[..., 3] < 96, 0, data[..., 3])
    fitted = Image.fromarray(data, mode="RGBA")
    info = {"bbox": list(bbox), "source_size": [w, h], "touches_border": touches}
    if touches:
        LOG.warning("subject touches the image border (bbox=%s of %sx%s) — it may be cropped by the model", bbox, w, h)
    return fitted, info


def process_sprite(raw: bytes, width: int, height: int) -> tuple[Image.Image, dict]:
    img = load(raw)
    keyed = remove_chroma_key(img)
    fitted, info = trim_and_fit(keyed, width, height)
    residue = green_residue_pct(fitted)
    info["green_residue_pct"] = round(residue, 2)
    if residue > 0.5:
        LOG.warning("green residue %.2f%% of opaque pixels — consider regenerating", residue)
    corners = [fitted.getpixel((0, 0))[3], fitted.getpixel((width - 1, height - 1))[3]]
    info["corner_alpha"] = corners
    return fitted, info


def save_sprite(img: Image.Image, out: Path) -> None:
    out.parent.mkdir(parents=True, exist_ok=True)
    img.save(out, "WEBP", lossless=True)
    LOG.debug("saved sprite %s (%dx%d)", out, *img.size)


def process_background(raw: bytes, width: int, height: int) -> tuple[Image.Image, dict]:
    img = Image.open(io.BytesIO(raw)).convert("RGB")
    w, h = img.size
    target_ratio = width / height
    if w / h > target_ratio:  # too wide → crop sides
        new_w = int(round(h * target_ratio))
        left = (w - new_w) // 2
        img = img.crop((left, 0, left + new_w, h))
    else:  # too tall → crop top/bottom
        new_h = int(round(w / target_ratio))
        top = (h - new_h) // 2
        img = img.crop((0, top, w, top + new_h))
    fitted = img.resize((width, height), Image.LANCZOS)
    return fitted, {"source_size": [w, h]}


def save_background(img: Image.Image, out: Path, quality: int = 85) -> None:
    out.parent.mkdir(parents=True, exist_ok=True)
    img.save(out, "WEBP", quality=quality, method=6)
    LOG.debug("saved background %s (%dx%d, q=%d)", out, *img.size, quality)
