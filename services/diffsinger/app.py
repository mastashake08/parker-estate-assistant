"""A small HTTP service that sings DiffSinger scores for the audio studio.

The app writes the score (a DiffSinger .ds file) itself; this service
describes the installed voices, serves each voice's phoneme dictionary so
the app can spell lyrics in phonemes, and renders a score to a WAV file
with DiffSinger's own inference script: the variance model (when the voice
has one) predicts phoneme timing and pitch, then the acoustic model and
vocoder sing it.

Voices are DiffSinger experiments in the checkpoints folder, described in
checkpoints/voices.json:

    [{"name": "Alto", "acoustic": "alto_acoustic", "variance": "alto_variance",
      "language": "en", "speaker": null, "vowels": null}]

Without voices.json every acoustic experiment found there is offered on
its own, without a variance model.
"""

from __future__ import annotations

import json
import logging
import os
import re
import subprocess
import sys
import tempfile
import threading
from pathlib import Path
from typing import Any

import yaml
from fastapi import FastAPI, HTTPException
from fastapi.responses import Response
from pydantic import BaseModel, Field

log = logging.getLogger("diffsinger")

ROOT = Path(os.environ.get("DIFFSINGER_ROOT", "/opt/DiffSinger"))
CHECKPOINTS = Path(os.environ.get("DIFFSINGER_CHECKPOINTS", str(ROOT / "checkpoints")))
TIMEOUT = int(os.environ.get("DIFFSINGER_TIMEOUT", "900"))
STEPS = os.environ.get("DIFFSINGER_STEPS")

SPECIAL = {"SP", "AP"}
# Vowels of the phoneme sets DiffSinger voices commonly use (ARPAbet for
# English, Opencpop pinyin finals for Chinese, romaji for Japanese). A voice
# can list its own in voices.json instead.
VOWEL_LETTERS = re.compile(r"[aeiouAEIOU@]")
PINYIN_VOWELS = {"v", "vn", "ve", "van", "E", "En", "i0", "ir"}


class Voice(BaseModel):
    name: str
    acoustic: str
    variance: str | None = None
    language: str | None = None
    speaker: str | None = None
    vowels: list[str] | None = None


class RenderRequest(BaseModel):
    voice: str
    ds: list[dict[str, Any]] = Field(min_length=1)
    speaker: str | None = None
    key: int = Field(default=0, ge=-24, le=24)


def read_config(experiment: str) -> dict[str, Any]:
    path = CHECKPOINTS / experiment / "config.yaml"
    if not path.is_file():
        raise HTTPException(404, f"The DiffSinger experiment {experiment} has no config.yaml.")
    with path.open(encoding="utf-8") as file:
        return yaml.safe_load(file) or {}


def load_voices() -> list[Voice]:
    listing = CHECKPOINTS / "voices.json"
    if listing.is_file():
        with listing.open(encoding="utf-8") as file:
            return [Voice(**voice) for voice in json.load(file)]

    voices = []
    for config in sorted(CHECKPOINTS.glob("*/config.yaml")):
        with config.open(encoding="utf-8") as file:
            task = str((yaml.safe_load(file) or {}).get("task_cls", ""))
        if "acoustic" in task.lower():
            voices.append(Voice(name=config.parent.name, acoustic=config.parent.name))
    return voices


def find_voice(name: str) -> Voice:
    for voice in load_voices():
        if voice.name == name:
            return voice
    raise HTTPException(404, f"There is no DiffSinger voice named {name}.")


def dictionaries(config: dict[str, Any]) -> dict[str, Path]:
    """The voice's dictionaries by language, from its config (newer multi-language or older single form)."""
    found = config.get("dictionaries") or {}
    if not found and config.get("dictionary"):
        found = {config.get("main_language") or "default": config["dictionary"]}
    return {language: (ROOT / path if not Path(path).is_absolute() else Path(path)) for language, path in found.items()}


def read_dictionary(path: Path) -> dict[str, list[str]]:
    entries: dict[str, list[str]] = {}
    with path.open(encoding="utf-8") as file:
        for line in file:
            word, _, phonemes = line.rstrip("\n").partition("\t")
            if word and phonemes.strip():
                entries[word.strip()] = phonemes.split()
    return entries


def guess_vowels(phonemes: set[str], language: str | None) -> list[str]:
    vowels = {p for p in phonemes if VOWEL_LETTERS.search(p)}
    if language and language.startswith("zh"):
        vowels |= phonemes & PINYIN_VOWELS
    if language and language.startswith("ja"):
        vowels |= phonemes & {"N", "cl"}
    return sorted(vowels - SPECIAL)


def speakers(experiment: str) -> list[str]:
    path = CHECKPOINTS / experiment / "spk_map.json"
    if not path.is_file():
        return []
    with path.open(encoding="utf-8") as file:
        return sorted(json.load(file).keys())


def describe(voice: Voice) -> dict[str, Any]:
    config = read_config(voice.acoustic)
    languages = sorted(dictionaries(config))
    return {
        "name": voice.name,
        "language": voice.language or config.get("main_language") or (languages[0] if languages else None),
        "languages": languages,
        "speakers": speakers(voice.acoustic),
        "speaker": voice.speaker,
        "variance": voice.variance is not None,
    }


def run(arguments: list[str]) -> None:
    command = [sys.executable, "scripts/infer.py", *arguments]
    log.info("Running %s", " ".join(command))
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=TIMEOUT)
    if result.returncode != 0:
        output = (result.stderr or result.stdout).strip().splitlines()
        raise RuntimeError(output[-1] if output else f"DiffSinger exited with code {result.returncode}.")


def render(voice: Voice, ds: list[dict[str, Any]], speaker: str | None, key: int, runner=run) -> bytes:
    speaker = speaker or voice.speaker
    common = (["--spk", speaker] if speaker else []) + (["--steps", STEPS] if STEPS else [])

    with tempfile.TemporaryDirectory() as folder:
        work = Path(folder)
        score = work / "score.ds"
        score.write_text(json.dumps(ds, ensure_ascii=False), encoding="utf-8")

        if voice.variance:
            # Re-predict timing and pitch from the notes, over the app's estimates.
            runner(["variance", str(score), "--exp", voice.variance, "--predict", "dur", "--predict", "pitch",
                    "--out", str(work), "--title", "sung", *common, *(["--key", str(key)] if key else [])])
            score = work / "sung.ds"
            key = 0

        runner(["acoustic", str(score), "--exp", voice.acoustic, "--out", str(work), "--title", "song",
                *common, *(["--key", str(key)] if key else [])])

        wav = work / "song.wav"
        if not wav.is_file():
            raise RuntimeError("DiffSinger did not write any audio.")
        return wav.read_bytes()


def create_app(runner=run) -> FastAPI:
    app = FastAPI(title="DiffSinger")
    # One render at a time: each loads its models onto the device.
    lock = threading.Lock()

    @app.get("/health")
    def health() -> dict[str, str]:
        return {"status": "ok"}

    @app.get("/voices")
    def voices() -> dict[str, Any]:
        return {"voices": [describe(voice) for voice in load_voices()]}

    @app.get("/voices/{name}/dictionary")
    def dictionary(name: str, language: str | None = None) -> dict[str, Any]:
        voice = find_voice(name)
        found = dictionaries(read_config(voice.acoustic))
        language = language or voice.language or (sorted(found)[0] if found else None)

        if language not in found:
            raise HTTPException(404, f"The voice {name} has no {language} dictionary.")
        if not found[language].is_file():
            raise HTTPException(500, f"The dictionary {found[language]} is missing.")

        entries = read_dictionary(found[language])
        phonemes = {p for spelled in entries.values() for p in spelled}
        return {
            "language": language,
            "entries": entries,
            "vowels": voice.vowels or guess_vowels(phonemes, language),
        }

    @app.post("/render")
    def render_score(request: RenderRequest) -> Response:
        voice = find_voice(request.voice)
        try:
            with lock:
                wav = render(voice, request.ds, request.speaker, request.key, runner)
        except subprocess.TimeoutExpired as exception:
            raise HTTPException(504, "DiffSinger took too long to sing the score.") from exception
        except Exception as exception:  # noqa: BLE001 - the app shows the reason
            log.exception("Render failed")
            raise HTTPException(500, f"DiffSinger could not sing the score: {exception}") from exception
        return Response(content=wav, media_type="audio/wav")

    return app


app = create_app()
