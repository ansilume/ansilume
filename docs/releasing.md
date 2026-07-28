# Releasing

Ansilume uses a single `VERSION` file as the authoritative source for the current release version. The version follows [Semantic Versioning](https://semver.org/) (`MAJOR.MINOR.PATCH`).

## How it works

| Part | Role |
|---|---|
| `VERSION` | Plain text file containing the current version, e.g. `0.1.0` |
| `config/params.php` | Reads `VERSION` at runtime → `Yii::$app->params['version']` |
| Sidebar footer | Displays `v0.1.0` to logged-in users |
| `bin/release` | Script that bumps the version, commits, tags, and pushes |

## Making a release

Ensure your working tree is clean and you are on the `main` branch:

```bash
git checkout main
git pull
```

Then run:

```bash
./bin/release patch   # 0.1.0 → 0.1.1  (bug fixes)
./bin/release minor   # 0.1.0 → 0.2.0  (new features, backwards-compatible)
./bin/release major   # 0.1.0 → 1.0.0  (breaking changes)
```

The script will:

1. Validate that the working tree has no uncommitted changes
2. Read the current version from `VERSION`
3. Increment the appropriate component
4. Write the new version back to `VERSION`
5. Commit: `chore: release v0.1.1`
6. Create an annotated Git tag: `v0.1.1`
7. Push the commit and the tag to `origin main`

## Choosing the right bump

| Change type | Command |
|---|---|
| Bug fixes, security patches, dependency updates | `patch` |
| New features that do not break existing behaviour | `minor` |
| Incompatible API or schema changes, major rewrites | `major` |

## Accessing the version in code

```php
// Anywhere in PHP:
$version = \Yii::$app->params['version']; // e.g. "0.1.1"
```

If the `VERSION` file is missing (e.g. a fresh clone from a branch that predates versioning), the value falls back to `"dev"`.

## Container images

When a version tag (`v*`) is pushed, GitHub Actions automatically builds and publishes three Docker images to the GitHub Container Registry:

| Image | Purpose |
|---|---|
| `ghcr.io/ansilume/ansilume` | App (PHP-FPM), queue-worker, schedule-runner |
| `ghcr.io/ansilume/ansilume-nginx` | Nginx with baked-in config and static assets |
| `ghcr.io/ansilume/ansilume-runner` | Standalone runner (PHP CLI + Ansible, no DB/Redis) |

### Supported architectures

All images are published as multi-arch manifest lists for **`linux/amd64`** and
**`linux/arm64`**. `docker pull` selects the matching architecture
automatically — the same compose file works on x86 servers, AWS Graviton,
Raspberry Pi 4/5, and other arm64 hosts.

### Tags

Each image is tagged with:

- Full version: `0.2.0`
- Minor: `0.2`
- Major: `0`
- `latest`

### How it works

The release workflow (`.github/workflows/release.yml`) triggers on `v*` tags and runs three stages:

1. **Build** — each image is built once per architecture on a *native* runner (amd64 on `ubuntu-latest`, arm64 on `ubuntu-24.04-arm` — no QEMU emulation) and pushed to `ghcr.io` by digest only. The Buildx GHA cache is scoped per image and architecture.
2. **Merge** — per image, the two digests are combined into a multi-arch manifest list via `docker buildx imagetools create`, the semver + `latest` tags are applied, and the pushed manifest is verified to contain both `linux/amd64` and `linux/arm64`.
3. **Smoke test** — an arm64 runner pulls the freshly tagged images, boots the full `docker-compose.prebuilt.yml` stack, asserts the resolved images are arm64, and waits until app, nginx, and runner report healthy (including an HTTP `/health` check through nginx).

All pushes use the repository's `GITHUB_TOKEN`.

### Using prebuilt images in production

The Ansible deploy role supports pulling prebuilt images instead of building from source:

```yaml
# In your inventory or group_vars:
ansilume_use_prebuilt_images: true
ansilume_version: "0.2.0"   # image tag to pull — published without the v prefix
```

### Standalone runner

The runner image (`ghcr.io/ansilume/ansilume-runner`) is designed for independent deployment in separate networks. It communicates with the ansilume server exclusively via HTTP API and has no database or Redis dependencies.

```bash
docker run -d \
  -e RUNNER_NAME=remote-runner-1 \
  -e RUNNER_BOOTSTRAP_SECRET=your-secret \
  -e API_URL=https://ansilume.example.com \
  ghcr.io/ansilume/ansilume-runner:latest
```
