FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    HF_HOME=/cache/huggingface

WORKDIR /app

# CPU PyTorch by default. For an NVIDIA GPU build with
#   --build-arg TORCH_INDEX=https://download.pytorch.org/whl/cu124
ARG TORCH_INDEX=https://download.pytorch.org/whl/cpu
RUN pip install --index-url "$TORCH_INDEX" torch==2.7.0

COPY requirements.txt .
RUN pip install -r requirements.txt

COPY app.py .

RUN useradd --create-home musicgen && mkdir -p /cache/huggingface && chown -R musicgen /cache
USER musicgen

EXPOSE 8000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
