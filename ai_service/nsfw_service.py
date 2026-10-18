from __future__ import annotations

import logging
import os
import time
from threading import Lock

import torch
from flask import Flask, jsonify, request
from PIL import Image, ImageOps, UnidentifiedImageError
from transformers import AutoImageProcessor, AutoModelForImageClassification

HOST = os.getenv("NSFW_SERVICE_HOST", "127.0.0.1")
PORT = int(os.getenv("NSFW_SERVICE_PORT", "5002"))
MODEL_NAME = os.getenv("NSFW_MODEL", "Falconsai/nsfw_image_detection")
THRESHOLD = min(0.99, max(0.01, float(os.getenv("NSFW_THRESHOLD", "0.70"))))
MAX_UPLOAD_MB = max(1, int(os.getenv("NSFW_MAX_UPLOAD_MB", "16")))
MAX_IMAGE_PIXELS = max(1_000_000, int(os.getenv("NSFW_MAX_IMAGE_PIXELS", "25000000")))
TORCH_THREADS = max(
    1,
    int(os.getenv("NSFW_TORCH_THREADS", str(min(4, os.cpu_count() or 1)))),
)
ALLOWED_FORMATS = {"JPEG", "PNG", "WEBP"}

logging.basicConfig(
    level=os.getenv("NSFW_LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
logger = logging.getLogger("lokalcraft.nsfw_service")

torch.set_num_threads(TORCH_THREADS)
device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
inference_lock = Lock()

logger.info("Loading NSFW model: %s", MODEL_NAME)
processor = AutoImageProcessor.from_pretrained(MODEL_NAME)
model = AutoModelForImageClassification.from_pretrained(MODEL_NAME)
model.to(device)
model.eval()

LABELS = {
    int(index): str(label).strip().lower()
    for index, label in model.config.id2label.items()
}
UNSAFE_TOKENS = (
    "nsfw",
    "porn",
    "sexual",
    "explicit",
    "hentai",
    "nude",
    "nudity",
)
UNSAFE_LABEL_IDS = [
    index
    for index, label in LABELS.items()
    if any(token in label for token in UNSAFE_TOKENS)
]
if not UNSAFE_LABEL_IDS:
    raise RuntimeError(
        "The configured NSFW model does not expose a recognized unsafe class."
    )

logger.info(
    "NSFW model loaded: model=%s device=%s labels=%s threshold=%.2f",
    MODEL_NAME,
    device,
    LABELS,
    THRESHOLD,
)

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = MAX_UPLOAD_MB * 1024 * 1024
Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS


def error_response(message: str, status: int):
    return jsonify({"success": False, "message": message}), status


def load_uploaded_image(field_name: str = "image") -> Image.Image:
    uploaded = request.files.get(field_name)
    if uploaded is None or uploaded.filename == "":
        raise ValueError("No image was uploaded.")

    try:
        image = Image.open(uploaded.stream)
        image_format = (image.format or "").upper()
        if image_format not in ALLOWED_FORMATS:
            raise ValueError("Only JPG, PNG, and WEBP images are supported.")

        image = ImageOps.exif_transpose(image)
        image.load()
        return image.convert("RGB")
    except Image.DecompressionBombError as exc:
        raise ValueError("The uploaded image is too large.") from exc
    except UnidentifiedImageError as exc:
        raise ValueError("The uploaded file is not a valid image.") from exc


def classify_image(image: Image.Image) -> tuple[bool, float]:
    inputs = processor(images=image, return_tensors="pt")
    pixel_values = inputs["pixel_values"].to(device)

    with inference_lock, torch.inference_mode():
        outputs = model(pixel_values=pixel_values)
        probabilities = torch.softmax(outputs.logits, dim=-1)[0].cpu()

    unsafe_probability = sum(
        float(probabilities[index].item())
        for index in UNSAFE_LABEL_IDS
        if 0 <= index < probabilities.numel()
    )
    unsafe_probability = max(0.0, min(1.0, unsafe_probability))
    safe = unsafe_probability < THRESHOLD
    return safe, unsafe_probability


@app.get("/health")
def health():
    return jsonify({
        "success": True,
        "service": "LokalCraft NSFW Moderation",
        "status": "ready",
        "model": MODEL_NAME,
        "device": str(device),
        "mode": "safe_unsafe",
        "threshold": THRESHOLD,
        "torch_threads": TORCH_THREADS,
        "max_upload_mb": MAX_UPLOAD_MB,
    })


@app.post("/moderate")
def moderate():
    started = time.perf_counter()

    try:
        image = load_uploaded_image("image")
        safe, unsafe_probability = classify_image(image)

        return jsonify({
            "success": True,
            "safe": safe,
            "classification": "safe" if safe else "unsafe",
            "score": round(unsafe_probability, 6),
            "threshold": THRESHOLD,
            "model": MODEL_NAME,
            "processing_ms": round((time.perf_counter() - started) * 1000, 2),
        })
    except ValueError as exc:
        return error_response(str(exc), 422)
    except Exception:
        logger.exception("Image moderation failed.")
        return error_response(
            "Image moderation could not be completed right now.",
            500,
        )


@app.errorhandler(413)
def upload_too_large(_error):
    return error_response(
        f"Image upload must not exceed {MAX_UPLOAD_MB} MB.",
        413,
    )


if __name__ == "__main__":
    app.run(host=HOST, port=PORT, debug=False, threaded=True)
