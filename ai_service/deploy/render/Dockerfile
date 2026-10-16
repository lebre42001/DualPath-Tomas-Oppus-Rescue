FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONPATH=/app

WORKDIR /app

COPY requirements.txt requirements-text.txt requirements-advisory.txt ./

# Keep the free manual-only demo small; set this build arg for a reviewed text release.
ARG INSTALL_TEXT_AI=false
ARG INSTALL_TEXT_BASELINE=false

RUN pip install --upgrade pip \
    && pip install --no-cache-dir -r requirements.txt \
    && if [ "$INSTALL_TEXT_AI" = "true" ]; then pip install --no-cache-dir -r requirements-text.txt; fi \
    && if [ "$INSTALL_TEXT_BASELINE" = "true" ]; then pip install --no-cache-dir -r requirements-advisory.txt; fi

COPY . .

# Verify the bytes after Docker COPY, before an unusable model can be deployed.
RUN if [ "$INSTALL_TEXT_BASELINE" = "true" ]; then \
    python -c "import hashlib,json; from pathlib import Path; models=[(p.parent,json.loads(p.read_text())) for p in Path('releases').glob('*/metadata.json')]; models=[(p,m) for p,m in models if m.get('model_type')=='tfidf_logistic']; assert models, 'No text artifact packaged'; assert all(hashlib.sha256((p/'baseline.json').read_bytes()).hexdigest()==m['artifact_sha256'] for p,m in models), 'Text artifact fingerprint mismatch'"; \
    fi

EXPOSE 10000

CMD ["python", "serve_api.py"]
