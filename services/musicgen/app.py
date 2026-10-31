"""A small HTTP service that runs Meta's MusicGen for the audio studio.

It keeps one MusicGen model in memory. The app can ask which model is
loaded (GET /models), load another (POST /models/load, which downloads it
from Hugging Face on first use and runs in the background), and generate
music from a text description (POST /generate, which answers a WAV file).
"""

from __future__ import annotations

import io
import logging
import os
import threading
import wave
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel, Field

log = logging.getLogger("musicgen")

DEFAULT_MODELS = (
    "facebook/musicgen-small,facebook/musicgen-medium,facebook/musicgen-large,"
    "facebook/musicgen-stereo-small,facebook/musicgen-stereo-medium"
)
MODELS = [name.strip() for name in os.environ.get("MUSICGEN_MODELS", DEFAULT_MODELS).split(",") if name.strip()]
DEFAULT_MODEL = os.environ.get("MUSICGEN_MODEL", MODELS[0] if MODELS else "facebook/musicgen-small")
MAX_SECONDS = int(os.environ.get("MUSICGEN_MAX_SECONDS", "30"))
PRELOAD = os.environ.get("MUSICGEN_PRELOAD", "true").lower() in ("1", "true", "yes")

# MusicGen's audio codec runs at 50 frames (tokens) per second of audio.
TOKENS_PER_SECOND = 50


class Engine:
    """The loaded model, and the one being loaded, shared between requests."""

    def __init__(self, loader=None) -> None:
        self.loader = loader or load_pretrained
        self.name: str | None = None
        self.model: Any = None
        self.processor: Any = None
        self.loading: str | None = None
        self.error: str | None = None
        self.device = os.environ.get("MUSICGEN_DEVICE") or default_device()
        # Held while a model loads or generates, so one never runs during the other.
        self.lock = threading.Lock()

    def status(self) -> dict[str, Any]:
        return {
            "loaded": self.name,
            "loading": self.loading,
            "device": self.device,
            "models": MODELS,
            "error": self.error,
        }

    def load(self, name: str) -> None:
        """Load a model now, replacing the one in memory."""
        with self.lock:
            self._load(name)

    def load_in_background(self, name: str) -> None:
        self.loading = name
        self.error = None
        threading.Thread(target=self._load_quietly, args=(name,), daemon=True).start()

    def _load_quietly(self, name: str) -> None:
        try:
            self.load(name)
        except Exception as exception:  # noqa: BLE001 - reported through /models
            log.exception("Could not load %s", name)
            self.error = f"Could not load {name}: {exception}"
        finally:
            if self.loading == name:
                self.loading = None

    def _load(self, name: str) -> None:
        if self.name == name and self.model is not None:
            return

        log.info("Loading %s on %s", name, self.device)
        # Free the old model first; the large ones need several GB.
        self.model = self.processor = self.name = None
        self.processor, self.model = self.loader(name, self.device)
        self.name = name
        self.error = None

    def generate(self, prompt: str, name: str, seconds: int, guidance_scale: float) -> bytes:
        with self.lock:
            self._load(name)

            inputs = self.processor(text=[prompt], padding=True, return_tensors="pt")
            inputs = {key: value.to(self.device) for key, value in inputs.items()}

            audio = self.model.generate(
                **inputs,
                do_sample=True,
                guidance_scale=guidance_scale,
                max_new_tokens=int(seconds * TOKENS_PER_SECOND),
            )
            rate = int(self.model.config.audio_encoder.sampling_rate)

        # (batch, channels, samples) -> (samples, channels)
        samples = audio[0].detach().cpu().float().numpy().T
        return to_wav(samples, rate)


def default_device() -> str:
    try:
        import torch

        if torch.cuda.is_available():
            return "cuda"
        if getattr(torch.backends, "mps", None) and torch.backends.mps.is_available():
            return "mps"
    except ImportError:
        pass
    return "cpu"


def load_pretrained(name: str, device: str):
    from transformers import AutoProcessor, MusicgenForConditionalGeneration

    processor = AutoProcessor.from_pretrained(name)
    model = MusicgenForConditionalGeneration.from_pretrained(name).to(device)
    model.eval()
    return processor, model


def to_wav(samples, rate: int) -> bytes:
    """Write float samples in [-1, 1], shaped (samples, channels), as 16-bit PCM WAV."""
    import numpy as np

    samples = np.asarray(samples, dtype=np.float32)
    if samples.ndim == 1:
        samples = samples[:, None]

    peak = float(np.max(np.abs(samples))) if samples.size else 0.0
    if peak > 1.0:
        samples = samples / peak

    pcm = (np.clip(samples, -1.0, 1.0) * 32767).astype("<i2")

    buffer = io.BytesIO()
    with wave.open(buffer, "wb") as file:
        file.setnchannels(pcm.shape[1])
        file.setsampwidth(2)
        file.setframerate(rate)
        file.writeframes(pcm.tobytes())
    return buffer.getvalue()


class LoadRequest(BaseModel):
    model: str


class GenerateRequest(BaseModel):
    prompt: str = Field(min_length=1, max_length=2000)
    model: str | None = None
    duration: int = Field(default=10, ge=1)
    guidance_scale: float = Field(default=3.0, ge=1.0, le=10.0)


def create_app(engine: Engine | None = None, preload: bool = PRELOAD) -> FastAPI:
    engine = engine or Engine()

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        if preload and DEFAULT_MODEL in MODELS:
            engine.load_in_background(DEFAULT_MODEL)
        yield

    app = FastAPI(title="MusicGen", lifespan=lifespan)
    app.state.engine = engine

    def check(name: str) -> None:
        if name not in MODELS:
            raise HTTPException(422, f"Unknown model {name}. Choose one of: {', '.join(MODELS)}.")

    @app.get("/health")
    def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.get("/models")
    def models() -> dict[str, Any]:
        return engine.status()

    @app.post("/models/load", status_code=202)
    def load(request: LoadRequest) -> dict[str, Any]:
        check(request.model)
        if engine.loading:
            raise HTTPException(409, f"{engine.loading} is still loading. Try again when it is ready.")
        engine.load_in_background(request.model)
        return engine.status()

    @app.post("/generate")
    def generate(request: GenerateRequest) -> Response:
        name = request.model or engine.name or DEFAULT_MODEL
        check(name)
        seconds = min(request.duration, MAX_SECONDS)

        try:
            wav = engine.generate(request.prompt, name, seconds, request.guidance_scale)
        except Exception as exception:  # noqa: BLE001 - the app shows the reason
            log.exception("Generation failed")
            raise HTTPException(500, f"Generation failed: {exception}") from exception

        return Response(content=wav, media_type="audio/wav")

    return app


app = create_app()
