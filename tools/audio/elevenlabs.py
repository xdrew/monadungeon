"""ElevenLabs Sound Effects (POST /v1/sound-generation) over urllib."""
from __future__ import annotations

import json
import logging
import time
import urllib.error
import urllib.request

LOG = logging.getLogger("audio.elevenlabs")

ENDPOINT = "https://api.elevenlabs.io/v1/sound-generation"
RETRY_STATUS = {429, 500, 502, 503, 504}


class GenerationError(RuntimeError):
    pass


class SfxClient:
    def __init__(self, api_key: str, model: str = "eleven_text_to_sound_v2", retries: int = 3):
        self._key = api_key
        self.model = model
        self.retries = retries

    def generate(self, text: str, seconds: float | None, influence: float, loop: bool = False) -> tuple[bytes, dict]:
        """One sound. Returns (mp3 bytes, info with the credits charged)."""
        body = {"text": text, "prompt_influence": influence, "model_id": self.model}
        if seconds is not None:
            body["duration_seconds"] = max(0.5, min(30.0, seconds))
        if loop:
            body["loop"] = True
        LOG.debug("request seconds=%s influence=%s loop=%s prompt_chars=%d", seconds, influence, loop, len(text))
        attempt = 0
        while True:
            attempt += 1
            started = time.monotonic()
            req = urllib.request.Request(f"{ENDPOINT}?output_format=mp3_44100_128", method="POST",
                                         data=json.dumps(body).encode("utf-8"),
                                         headers={"xi-api-key": self._key, "Content-Type": "application/json"})
            try:
                with urllib.request.urlopen(req, timeout=180) as resp:
                    data = resp.read()
                    credits = resp.headers.get("character-cost")
                    info = {"model": self.model, "call_s": round(time.monotonic() - started, 1),
                            "credits": int(credits) if credits and credits.isdigit() else credits,
                            "request_id": resp.headers.get("request-id")}
                    return data, info
            except urllib.error.HTTPError as exc:
                detail = exc.read()[:300].decode("utf-8", "replace")
                if exc.code in RETRY_STATUS and attempt <= self.retries:
                    wait = 5 * 2 ** (attempt - 1)
                    LOG.warning("ElevenLabs error %s on attempt %d/%d, retrying in %ds: %s", exc.code, attempt,
                                self.retries, wait, detail)
                    time.sleep(wait)
                    continue
                raise GenerationError(f"ElevenLabs error {exc.code}: {detail}") from exc
            except urllib.error.URLError as exc:
                if attempt <= self.retries:
                    LOG.warning("ElevenLabs network error on attempt %d/%d: %s", attempt, self.retries, exc.reason)
                    time.sleep(5)
                    continue
                raise GenerationError(f"ElevenLabs network error: {exc.reason}") from exc
