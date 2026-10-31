"""Tests for the MusicGen service, with a stand-in for the model."""

import io
import time
import wave

import numpy as np
import pytest
from fastapi.testclient import TestClient

import app as service


class FakeTensor:
    def __init__(self, value):
        self.value = value

    def to(self, device):
        return self


class FakeAudio:
    def __init__(self, samples):
        self.samples = samples

    def __getitem__(self, index):
        return self

    def detach(self):
        return self

    def cpu(self):
        return self

    def float(self):
        return self

    def numpy(self):
        return self.samples


class FakeModel:
    class config:
        class audio_encoder:
            sampling_rate = 32000

    def __init__(self, channels=1):
        self.channels = channels
        self.calls = []

    def generate(self, **kwargs):
        self.calls.append(kwargs)
        samples = int(kwargs["max_new_tokens"] / service.TOKENS_PER_SECOND * 32000)
        return FakeAudio(np.full((self.channels, samples), 0.5, dtype=np.float32))


def fake_processor(text, padding, return_tensors):
    return {"input_ids": FakeTensor(text)}


@pytest.fixture
def loads():
    return []


@pytest.fixture
def client(loads):
    def loader(name, device):
        loads.append(name)
        return fake_processor, FakeModel(channels=2 if "stereo" in name else 1)

    engine = service.Engine(loader=loader)
    return TestClient(service.create_app(engine, preload=False))


def read_wav(data):
    with wave.open(io.BytesIO(data)) as file:
        return file.getnchannels(), file.getframerate(), file.getnframes()


def test_generate_loads_the_model_and_answers_a_wav(client, loads):
    response = client.post("/generate", json={"prompt": "lo-fi piano", "model": "facebook/musicgen-small", "duration": 2})

    assert response.status_code == 200
    assert response.headers["content-type"] == "audio/wav"
    assert read_wav(response.content) == (1, 32000, 64000)
    assert loads == ["facebook/musicgen-small"]
    assert client.get("/models").json()["loaded"] == "facebook/musicgen-small"


def test_stereo_models_give_two_channels(client):
    response = client.post("/generate", json={"prompt": "synthwave", "model": "facebook/musicgen-stereo-small", "duration": 1})

    assert read_wav(response.content)[0] == 2


def test_the_loaded_model_is_reused(client, loads):
    client.post("/generate", json={"prompt": "a", "model": "facebook/musicgen-small", "duration": 1})
    client.post("/generate", json={"prompt": "b", "model": "facebook/musicgen-small", "duration": 1})

    assert loads == ["facebook/musicgen-small"]


def test_the_duration_is_capped(client):
    response = client.post("/generate", json={"prompt": "a", "model": "facebook/musicgen-small", "duration": 600})

    assert read_wav(response.content)[2] == service.MAX_SECONDS * 32000


def test_unknown_models_are_refused(client):
    assert client.post("/generate", json={"prompt": "a", "model": "someone/else"}).status_code == 422
    assert client.post("/models/load", json={"model": "someone/else"}).status_code == 422


def test_loading_a_model_runs_in_the_background(client, loads):
    response = client.post("/models/load", json={"model": "facebook/musicgen-medium"})

    assert response.status_code == 202
    for _ in range(50):
        if client.get("/models").json()["loaded"] == "facebook/musicgen-medium":
            break
        time.sleep(0.02)

    status = client.get("/models").json()
    assert status["loaded"] == "facebook/musicgen-medium"
    assert status["loading"] is None
    assert loads == ["facebook/musicgen-medium"]


def test_a_failed_load_is_reported():
    def loader(name, device):
        raise RuntimeError("out of memory")

    client = TestClient(service.create_app(service.Engine(loader=loader), preload=False))
    client.post("/models/load", json={"model": "facebook/musicgen-large"})
    for _ in range(50):
        if client.get("/models").json()["error"]:
            break
        time.sleep(0.02)

    status = client.get("/models").json()
    assert status["loaded"] is None
    assert "out of memory" in status["error"]


def test_to_wav_normalizes_loud_audio():
    data = service.to_wav(np.array([0.0, 2.0, -4.0]), 8000)

    with wave.open(io.BytesIO(data)) as file:
        frames = np.frombuffer(file.readframes(3), dtype="<i2")

    assert frames.tolist() == [0, 16383, -32767]
