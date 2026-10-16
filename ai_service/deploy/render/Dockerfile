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

EXPOSE 10000

CMD ["python", "serve_api.py"]
