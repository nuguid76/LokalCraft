from __future__ import annotations

import logging
import os
import time
from threading import Lock

import torch
import torch.nn.functional as F
from flask import Flask, jsonify, request
from PIL import Image, ImageOps, UnidentifiedImageError
from transformers import CLIPModel, CLIPProcessor

HOST = os.getenv("IMAGE_SERVICE_HOST", "127.0.0.1")
PORT = int(os.getenv("IMAGE_SERVICE_PORT", "5001"))
MODEL_NAME = os.getenv("CLIP_MODEL", "openai/clip-vit-base-patch32")
MAX_UPLOAD_MB = max(1, int(os.getenv("IMAGE_SERVICE_MAX_UPLOAD_MB", "6")))
MAX_IMAGE_PIXELS = max(1_000_000, int(os.getenv("IMAGE_SERVICE_MAX_PIXELS", "25000000")))
TORCH_THREADS = max(
    1,
    int(os.getenv("IMAGE_SERVICE_TORCH_THREADS", str(min(4, os.cpu_count() or 1)))),
)
ALLOWED_FORMATS = {"JPEG", "PNG", "WEBP"}

logging.basicConfig(
    level=os.getenv("IMAGE_SERVICE_LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
logger = logging.getLogger("lokalcraft.image_service")

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = MAX_UPLOAD_MB * 1024 * 1024

Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS
torch.set_num_threads(TORCH_THREADS)

device = torch.device("cpu")
inference_lock = Lock()

logger.info("Loading CLIP model: %s", MODEL_NAME)
processor = CLIPProcessor.from_pretrained(MODEL_NAME)
model = CLIPModel.from_pretrained(MODEL_NAME)
model.to(device)
model.eval()
EMBEDDING_DIMENSIONS = int(getattr(model.config, "projection_dim", 0) or 0)
logger.info(
    "CLIP model loaded: model=%s device=%s dimensions=%s torch_threads=%s",
    MODEL_NAME,
    device,
    EMBEDDING_DIMENSIONS,
    TORCH_THREADS,
)


def error_response(message: str, status_code: int):
    return jsonify({
        "success": False,
        "message": message,
    }), status_code


def load_uploaded_image(field_name: str) -> Image.Image:
    uploaded = request.files.get(field_name)
    if uploaded is None or uploaded.filename == "":
        raise ValueError(f"{field_name} is required.")

    try:
        uploaded.stream.seek(0)
        with Image.open(uploaded.stream) as probe:
            image_format = (probe.format or "").upper()
            probe.verify()

        if image_format not in ALLOWED_FORMATS:
            raise ValueError(
                f"{field_name} must be a valid JPG, PNG, or WEBP image."
            )

        uploaded.stream.seek(0)
        with Image.open(uploaded.stream) as source:
            corrected = ImageOps.exif_transpose(source)
            width, height = corrected.size

            if width <= 0 or height <= 0:
                raise ValueError(f"{field_name} has invalid image dimensions.")

            if width * height > MAX_IMAGE_PIXELS:
                raise ValueError(
                    f"{field_name} is too large in resolution. "
                    f"Maximum allowed is {MAX_IMAGE_PIXELS:,} pixels."
                )

            return corrected.convert("RGB").copy()

    except ValueError:
        raise
    except Image.DecompressionBombError as exc:
        raise ValueError(
            f"{field_name} image resolution is too large."
        ) from exc
    except (Image.DecompressionBombWarning, UnidentifiedImageError, OSError) as exc:
        raise ValueError(
            f"{field_name} must be a valid JPG, PNG, or WEBP image."
        ) from exc


def image_embedding(image: Image.Image) -> torch.Tensor:
    inputs = processor(images=image, return_tensors="pt")
    pixel_values = inputs["pixel_values"].to(device)

    with inference_lock, torch.inference_mode():
        vision_outputs = model.vision_model(pixel_values=pixel_values)
        pooled_output = vision_outputs.pooler_output
        features = model.visual_projection(pooled_output)
        features = F.normalize(features, p=2, dim=-1)

    return features[0].cpu()


def similarity_between(first: torch.Tensor, second: torch.Tensor) -> float:
    score = float(torch.dot(first, second).item())
    return max(-1.0, min(1.0, score))


@app.get("/health")
def health():
    return jsonify({
        "success": True,
        "service": "LokalCraft Image Similarity",
        "status": "ready",
        "model": MODEL_NAME,
        "device": str(device),
        "dimensions": EMBEDDING_DIMENSIONS,
        "torch_threads": TORCH_THREADS,
        "max_upload_mb": MAX_UPLOAD_MB,
    })


@app.post("/embed")
def embed():
    started = time.perf_counter()

    try:
        image = load_uploaded_image("image")
        embedding = image_embedding(image)

        return jsonify({
            "success": True,
            "model": MODEL_NAME,
            "dimensions": int(embedding.numel()),
            "embedding": embedding.tolist(),
            "processing_ms": round((time.perf_counter() - started) * 1000, 2),
        })
    except ValueError as exc:
        return error_response(str(exc), 422)
    except Exception:
        logger.exception("Image embedding failed.")
        return error_response(
            "Image embedding could not be completed right now.",
            500,
        )


@app.post("/similarity")
def similarity():
    started = time.perf_counter()

    try:
        image_a = load_uploaded_image("image_a")
        image_b = load_uploaded_image("image_b")

        embedding_a = image_embedding(image_a)
        embedding_b = image_embedding(image_b)
        score = similarity_between(embedding_a, embedding_b)

        return jsonify({
            "success": True,
            "model": MODEL_NAME,
            "similarity": round(score, 6),
            "processing_ms": round((time.perf_counter() - started) * 1000, 2),
        })
    except ValueError as exc:
        return error_response(str(exc), 422)
    except Exception:
        logger.exception("Image comparison failed.")
        return error_response(
            "Image comparison could not be completed right now.",
            500,
        )


@app.errorhandler(413)
def too_large(_error):
    return error_response(
        f"Image upload is too large. Maximum request size is {MAX_UPLOAD_MB} MB.",
        413,
    )


@app.errorhandler(404)
def not_found(_error):
    return error_response("Endpoint not found.", 404)


@app.errorhandler(405)
def method_not_allowed(_error):
    return error_response("Method not allowed.", 405)


if __name__ == "__main__":
    app.run(host=HOST, port=PORT, debug=False)
