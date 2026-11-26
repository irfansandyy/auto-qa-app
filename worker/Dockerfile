# syntax=docker/dockerfile:1

FROM node:24-bookworm-slim
ENV NODE_ENV=production \
    HOME=/tmp \
    XDG_CACHE_HOME=/tmp/autoqa-browser-cache \
    XDG_CONFIG_HOME=/tmp/autoqa-browser-config \
    PLAYWRIGHT_BROWSERS_PATH=/opt/playwright-browsers \
    PLAYWRIGHT_EXECUTABLE_PATH=/usr/local/bin/autoqa-chromium \
    BROWSER_EVIDENCE_DIR=/var/www/html/storage/app/private/evidence
WORKDIR /opt/autoqa/worker
COPY worker/package.json worker/package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund \
    && npx --no-install playwright install-deps chromium \
    && rm -rf /var/lib/apt/lists/*
RUN npx --no-install playwright install --only-shell chromium \
    && find "$PLAYWRIGHT_BROWSERS_PATH" -type f -name chrome-headless-shell -exec ln -s {} "$PLAYWRIGHT_EXECUTABLE_PATH" \; \
    && test -x "$PLAYWRIGHT_EXECUTABLE_PATH" \
    && install -d -o 1000 -g 1000 -m 0700 /var/www/html/storage/app/private/evidence
COPY worker/*.mjs ./
USER 1000:1000
CMD ["node", "runner.mjs"]
