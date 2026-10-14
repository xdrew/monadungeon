"""Thin wrapper over the Gemini Interactions API for image generation with reference images."""
from __future__ import annotations

import base64
import logging
import mimetypes
import time
from pathlib import Path

from google import genai
from google.genai import errors

LOG = logging.getLogger("art.gemini")

RETRY_STATUS = {429, 500, 502, 503, 504}


class GenerationError(RuntimeError):
    pass


def _mime(path: Path) -> str:
    mime, _ = mimetypes.guess_type(path.name)
    if mime not in ("image/png", "image/jpeg", "image/webp"):
        raise GenerationError(f"unsupported reference type for {path.name}: {mime}")
    return mime


def _image_block(path: Path) -> dict:
    data = base64.b64encode(path.read_bytes()).decode("ascii")
    return {"type": "image", "data": data, "mime_type": _mime(path)}


def _extract_image(interaction) -> tuple[bytes, str]:
    """Return (bytes, mime) from interaction.output_image or the last image step."""
    img = getattr(interaction, "output_image", None)
    if img is not None and getattr(img, "data", None):
        return base64.b64decode(img.data), img.mime_type or "image/png"
    # fall back: scan outputs / steps for the last image content
    for attr in ("outputs", "steps"):
        items = getattr(interaction, attr, None) or []
        found = None
        for item in items:
            if getattr(item, "type", None) == "image" and getattr(item, "data", None):
                found = item
            for sub in getattr(item, "content", None) or []:
                if getattr(sub, "type", None) == "image" and getattr(sub, "data", None):
                    found = sub
        if found is not None:
            return base64.b64decode(found.data), getattr(found, "mime_type", None) or "image/png"
    status = getattr(interaction, "status", None)
    errs = getattr(interaction, "errors", None)
    text = (getattr(interaction, "output_text", None) or "")[:300]
    raise GenerationError(f"no image in response (status={status}, errors={errs}, text={text!r})")


class ImageClient:
    def __init__(self, api_key: str, model: str, retries: int = 3):
        self._client = genai.Client(api_key=api_key)
        self.model = model
        self.retries = retries

    def generate(self, prompt: str, references: list[Path], aspect_ratio: str, image_size: str = "1K",
                 model: str | None = None) -> tuple[bytes, str, dict]:
        """Generate one image. Returns (bytes, mime, info) where info has model/duration/response attrs."""
        model = model or self.model
        blocks = [_image_block(p) for p in references]
        blocks.append({"type": "text", "text": prompt})
        LOG.debug("request model=%s aspect=%s size=%s refs=%d prompt_chars=%d", model, aspect_ratio, image_size,
                  len(references), len(prompt))
        attempt = 0
        while True:
            attempt += 1
            started = time.monotonic()
            try:
                interaction = self._client.interactions.create(
                    model=model,
                    input=blocks,
                    # the API only offers image/jpeg for the output; the chroma-key mask tolerates JPEG noise
                    response_format={"type": "image", "aspect_ratio": aspect_ratio, "image_size": image_size},
                )
                data, mime = _extract_image(interaction)
                duration = time.monotonic() - started
                usage = getattr(interaction, "usage", None)
                info = {"model": model, "duration_s": round(duration, 1), "interaction_id": getattr(interaction, "id", None),
                        "usage": usage.model_dump() if hasattr(usage, "model_dump") else None}
                LOG.debug("response id=%s mime=%s bytes=%d usage=%s", info["interaction_id"], mime, len(data), info["usage"])
                return data, mime, info
            except Exception as exc:  # the SDK raises google.genai.errors.APIError or compat_errors.APIStatusError
                code = getattr(exc, "code", None) or getattr(exc, "status_code", None)
                if code is None and not isinstance(exc, errors.APIError):
                    raise
                if code in RETRY_STATUS and attempt <= self.retries:
                    wait = 5 * 2 ** (attempt - 1)
                    LOG.warning("API error %s on attempt %d/%d, retrying in %ds: %s", code, attempt, self.retries, wait,
                                str(exc)[:200])
                    time.sleep(wait)
                    continue
                raise GenerationError(f"API error {code}: {exc}") from exc

    def describe_response_shape(self, prompt: str, references: list[Path]) -> str:
        """Used by `probe`: returns the attribute names present on the response for the log."""
        blocks = [_image_block(p) for p in references] + [{"type": "text", "text": prompt}]
        interaction = self._client.interactions.create(
            model=self.model, input=blocks,
            response_format={"type": "image", "aspect_ratio": "1:1", "image_size": "1K"},
        )
        present = [k for k, v in (interaction.model_dump() if hasattr(interaction, "model_dump") else {}).items() if v]
        data, mime = _extract_image(interaction)
        return f"status={getattr(interaction, 'status', None)} fields={present} image={mime} {len(data)} bytes", data
