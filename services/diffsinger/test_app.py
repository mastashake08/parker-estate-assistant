"""Tests for the DiffSinger service, with a stand-in for DiffSinger's inference script."""

import json
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

import app as service


@pytest.fixture
def checkpoints(tmp_path, monkeypatch):
    root = tmp_path / "DiffSinger"
    (root / "dictionaries").mkdir(parents=True)
    (root / "dictionaries" / "en.txt").write_text("hello\thh ah l ow\nworld\tw er l d\nah\taa\n", encoding="utf-8")
    (root / "dictionaries" / "zh.txt").write_text("ni\tn i\nhao\th ao\nlv\tl v\n", encoding="utf-8")

    checkpoints = root / "checkpoints"
    for name, task in [("alto_acoustic", "training.acoustic_task.AcousticTask"), ("alto_variance", "training.variance_task.VarianceTask")]:
        (checkpoints / name).mkdir(parents=True)
        (checkpoints / name / "config.yaml").write_text(
            f"task_cls: {task}\ndictionaries:\n  en: dictionaries/en.txt\n  zh: dictionaries/zh.txt\n", encoding="utf-8"
        )
    (checkpoints / "alto_acoustic" / "spk_map.json").write_text('{"alto": 0, "soprano": 1}', encoding="utf-8")

    monkeypatch.setattr(service, "ROOT", root)
    monkeypatch.setattr(service, "CHECKPOINTS", checkpoints)
    return checkpoints


def voices_file(checkpoints, voices):
    (checkpoints / "voices.json").write_text(json.dumps(voices), encoding="utf-8")


class FakeInfer:
    """Records each run and writes the file the real script would."""

    def __init__(self):
        self.runs = []

    def __call__(self, arguments):
        self.runs.append(arguments)
        out = Path(arguments[arguments.index("--out") + 1])
        title = arguments[arguments.index("--title") + 1]
        if arguments[0] == "variance":
            (out / f"{title}.ds").write_text(Path(arguments[1]).read_text(encoding="utf-8"), encoding="utf-8")
        else:
            (out / f"{title}.wav").write_bytes(b"RIFF fake wav")


def test_acoustic_experiments_are_offered_without_a_voices_file(checkpoints):
    client = TestClient(service.create_app())

    voices = client.get("/voices").json()["voices"]

    assert [voice["name"] for voice in voices] == ["alto_acoustic"]
    assert voices[0]["speakers"] == ["alto", "soprano"]
    assert voices[0]["languages"] == ["en", "zh"]
    assert voices[0]["variance"] is False


def test_the_dictionary_and_its_vowels_are_served(checkpoints):
    voices_file(checkpoints, [{"name": "Alto", "acoustic": "alto_acoustic", "language": "en"}])
    client = TestClient(service.create_app())

    dictionary = client.get("/voices/Alto/dictionary").json()

    assert dictionary["language"] == "en"
    assert dictionary["entries"]["hello"] == ["hh", "ah", "l", "ow"]
    assert dictionary["vowels"] == ["aa", "ah", "er", "ow"]


def test_pinyin_vowels_include_u_umlaut(checkpoints):
    voices_file(checkpoints, [{"name": "Alto", "acoustic": "alto_acoustic", "language": "zh"}])
    client = TestClient(service.create_app())

    assert client.get("/voices/Alto/dictionary").json()["vowels"] == ["ao", "i", "v"]


def test_a_voice_can_list_its_own_vowels(checkpoints):
    voices_file(checkpoints, [{"name": "Alto", "acoustic": "alto_acoustic", "language": "en", "vowels": ["ah", "ow"]}])
    client = TestClient(service.create_app())

    assert client.get("/voices/Alto/dictionary").json()["vowels"] == ["ah", "ow"]


def test_rendering_runs_the_variance_model_then_the_acoustic_model(checkpoints):
    voices_file(checkpoints, [{"name": "Alto", "acoustic": "alto_acoustic", "variance": "alto_variance", "speaker": "alto"}])
    infer = FakeInfer()
    client = TestClient(service.create_app(runner=infer))

    response = client.post("/render", json={"voice": "Alto", "ds": [{"text": "SP hello SP"}], "key": 2})

    assert response.status_code == 200
    assert response.content == b"RIFF fake wav"
    assert [run[0] for run in infer.runs] == ["variance", "acoustic"]
    assert infer.runs[0][infer.runs[0].index("--exp") + 1] == "alto_variance"
    assert "--predict" in infer.runs[0] and "--key" in infer.runs[0]
    assert infer.runs[1][1].endswith("sung.ds")
    assert infer.runs[1][infer.runs[1].index("--spk") + 1] == "alto"
    # The key is moved once, by the variance model.
    assert "--key" not in infer.runs[1]


def test_a_voice_without_a_variance_model_is_sung_from_the_score_as_written(checkpoints):
    infer = FakeInfer()
    client = TestClient(service.create_app(runner=infer))

    client.post("/render", json={"voice": "alto_acoustic", "ds": [{"text": "SP hello SP"}]})

    assert [run[0] for run in infer.runs] == ["acoustic"]
    assert infer.runs[0][1].endswith("score.ds")


def test_failures_are_reported(checkpoints):
    def failing(arguments):
        raise RuntimeError("Vocoder ckpt not found.")

    client = TestClient(service.create_app(runner=failing))
    response = client.post("/render", json={"voice": "alto_acoustic", "ds": [{"text": "SP"}]})

    assert response.status_code == 500
    assert "Vocoder ckpt not found." in response.json()["detail"]


def test_unknown_voices_are_not_found(checkpoints):
    client = TestClient(service.create_app())

    assert client.post("/render", json={"voice": "Bass", "ds": [{}]}).status_code == 404
    assert client.get("/voices/Bass/dictionary").status_code == 404
