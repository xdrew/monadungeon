"""Lyria music through the Gemini Interactions API."""
from __future__ import annotations

import base64
import logging
import time

from google import genai
from google.genai import errors

LOG = logging.getLogger("audio.lyria")

RETRY_STATUS = {429, 500, 502, 503, 504}

# Gemini API prices (ai.google.dev/gemini-api/docs/pricing, Oct 2026); paid tier only
PRICE_USD = {"lyria-3.5": 0.08, "lyria-3-pro-preview": 0.08, "lyria-3-clip-preview": 0.04}


class GenerationError(RuntimeError):
    pass


class MusicClient:
    def __init__(self, api_key: str, retries: int = 3):
        self._client = genai.Client(api_key=api_key)
        self.retries = retries

    def generate(self, prompt: str, model: str) -> tuple[bytes, dict]:
        """One track. Returns (mp3 bytes, info with model / duration / cost)."""
        LOG.debug("request model=%s prompt_chars=%d", model, len(prompt))
        attempt = 0
        while True:
            attempt += 1
            started = time.monotonic()
            try:
                interaction = self._client.interactions.create(model=model, input=prompt)
                audio = getattr(interaction, "output_audio", None)
                if audio is None or not getattr(audio, "data", None):
                    text = (getattr(interaction, "output_text", None) or "")[:300]
                    raise GenerationError(f"no audio in response (status={getattr(interaction, 'status', None)}, "
                                          f"text={text!r})")
                data = base64.b64decode(audio.data)
                info = {"model": model, "call_s": round(time.monotonic() - started, 1),
                        "interaction_id": getattr(interaction, "id", None), "cost_usd": PRICE_USD.get(model),
                        "mime": getattr(audio, "mime_type", None)}
                LOG.debug("response id=%s bytes=%d", info["interaction_id"], len(data))
                return data, info
            except GenerationError:
                raise
            except Exception as exc:  # google.genai.errors.APIError or compat_errors.APIStatusError
                code = getattr(exc, "code", None) or getattr(exc, "status_code", None)
                if code is None and not isinstance(exc, errors.APIError):
                    raise
                if code in RETRY_STATUS and attempt <= self.retries:
                    wait = 5 * 2 ** (attempt - 1)
                    LOG.warning("Lyria error %s on attempt %d/%d, retrying in %ds: %s", code, attempt, self.retries,
                                wait, str(exc)[:200])
                    time.sleep(wait)
                    continue
                raise GenerationError(f"Lyria error {code}: {str(exc)[:300]}") from exc
