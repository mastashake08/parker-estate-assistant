FROM python:3.11-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    DIFFSINGER_ROOT=/opt/DiffSinger

RUN apt-get update && apt-get install -y --no-install-recommends git libsndfile1 \
    && rm -rf /var/lib/apt/lists/*

# CPU PyTorch by default. For an NVIDIA GPU build with
#   --build-arg TORCH_INDEX=https://download.pytorch.org/whl/cu124
ARG TORCH_INDEX=https://download.pytorch.org/whl/cpu
RUN pip install --index-url "$TORCH_INDEX" torch==2.7.0

# DiffSinger itself, pinned for repeatable builds.
ARG DIFFSINGER_REF=a679987cff87a92eceba3b9041e5bf33740cd168
RUN git clone https://github.com/openvpi/DiffSinger.git "$DIFFSINGER_ROOT" \
    && git -C "$DIFFSINGER_ROOT" checkout "$DIFFSINGER_REF" \
    && pip install -r "$DIFFSINGER_ROOT/requirements.txt"

WORKDIR /service
COPY requirements.txt .
RUN pip install -r requirements.txt
COPY app.py .

# Mount your voices (experiments, vocoder and voices.json) here.
VOLUME ["/opt/DiffSinger/checkpoints"]

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
