FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONPATH=/app

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends libgomp1 libjpeg62-turbo libpng16-16 \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt requirements-text.txt ./

# Text inference is opt-in; the default image profile retains image-only dependencies.
ARG INSTALL_TEXT_AI=false

RUN pip install --upgrade pip \
    && pip install --no-cache-dir -r requirements.txt \
    && if [ "$INSTALL_TEXT_AI" = "true" ]; then pip install --no-cache-dir -r requirements-text.txt; fi

COPY . .

EXPOSE 10000

CMD ["python", "serve_api.py"]
