FROM mcr.microsoft.com/playwright:v1.52.0-noble

WORKDIR /e2e

COPY tests/e2e/package.json tests/e2e/package-lock.json* ./
RUN npm install --ignore-scripts && npx playwright install --with-deps chromium

COPY tests/e2e/ ./

CMD ["npx", "playwright", "test"]
