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

`--notes` says why the release exists; its GitHub release shows the notes
first. Give notes with every security release, and with every release whose
operators must do something:

```bash
./bin/release patch --notes "Images rebuilt with PHP 8.2.35 (CVE-2026-109618, CVE-2026-109619). Pull the new images."
```

The notes are Markdown and may span several lines and paragraphs.

The script will:

1. Validate that the working tree has no uncommitted changes
2. Read the current version from `VERSION`
3. Increment the appropriate component
4. Write the new version back to `VERSION`
5. Commit: `chore: release v0.1.1`
6. Create an annotated Git tag: `v0.1.1`, with the notes below its subject
   `Release v0.1.1`
7. Push the commit and the tag to `origin main`

The tag starts the release workflow: images first, then the GitHub release
(see below).

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

- Full version: `0.2.0`, as soon as its images are pushed
- Minor: `0.2`, major: `0`, and `latest`, only once the images passed the
  smoke test and the scan, and each only when the version is the highest
  release it covers: a fix of an older line, such as 0.1.5 after 0.2.0,
  gets `0.1` but neither `0` nor `latest`, and a re-run of an older release
  moves none of them back. That holds for the release runs of later tags:
  a run of v2.12.1 or older runs its own, older workflow, which moves the
  tags itself and scans nothing, so do not re-run those.

A release candidate (`v0.3.0-rc1`) gets its full version only: no floating
tag, and no GitHub release (that job is skipped, so its run ends green).

### How it works

The release workflow (`.github/workflows/release.yml`) triggers on `v*` tags and runs these stages:

1. **Build** — each image is built once per architecture on a *native* runner (amd64 on `ubuntu-latest`, arm64 on `ubuntu-24.04-arm` — no QEMU emulation) and pushed to `ghcr.io` by digest only. The Buildx GHA cache is scoped per image and architecture.
2. **Merge** — per image, the two digests are combined into a multi-arch manifest list via `docker buildx imagetools create`, tagged with the full version only, and the pushed manifest is verified to contain both `linux/amd64` and `linux/arm64`.
3. **Smoke test** — an arm64 runner pulls the images of the version (`ANSILUME_IMAGE_TAG` in its `.env`), boots the full `docker-compose.prebuilt.yml` stack, asserts the resolved images are arm64, and waits until app, nginx, and runner report healthy (including an HTTP `/health` check through nginx). It then checks that runner-1 can resolve nginx but none of the backend services, and that Redis refuses an unauthenticated `PING`.
   **Scan** — at the same time, grype scans both platforms of each image of the version (see [Vulnerability scan](#vulnerability-scan)).
4. **Promote** — once both passed, `latest` and the floating tags move to the images of the version (see [Tags](#tags)), and each is checked to name the same image index as the version.
5. **GitHub release** — only then does `.github/workflows/github-release.yml` create the GitHub release of the tag (see [GitHub releases](#github-releases)).

When the smoke test or the scan fails, the images keep their version tag,
but `latest`, the floating tags and the GitHub release stay with the
previous release: fix the cause and release the next version, or re-run the
failed jobs when the cause was passing, such as a registry error, as long
as no later version is released. The next release carries the notes and the
changes of the version that got no release (`bin/release-notes`). When the promote job fails for one image
only, the others have moved already, and `latest` names images of two
versions: re-run its failed jobs at once.

Only the jobs that push images (build, merge, promote) hold
`packages: write`. All pushes use the repository's `GITHUB_TOKEN`. Every
action is pinned to the commit of its release, see
[docs/ci.md](ci.md#pinned-actions).

### Vulnerability scan

The scan job runs grype, pinned to the digest of its image in `GRYPE_IMAGE`
of `release.yml`, for `linux/amd64` and `linux/arm64` of each image, such as
for version 2.12.2:

```bash
grype registry:ghcr.io/ansilume/ansilume:2.12.2 --platform linux/amd64 --only-fixed --fail-on high
```

A vulnerability of high or critical severity that has a fix fails the
release. Fix it in the image (a newer base image, package or dependency)
and release the next version. A finding that does not apply to Ansilume
goes into `.grype.yaml` in the repository root, with its reason, as grype
reads it from the checkout:

```yaml
ignore:
  # Why it does not apply, and when to look at it again.
  - vulnerability: CVE-2026-12345
    package:
      name: example-package
```

The same scan, before a release, of the current `latest`:

```bash
docker run --rm -v "$PWD:/src:ro" -w /src anchore/grype:v0.120.1 \
  registry:ghcr.io/ansilume/ansilume:latest --platform linux/amd64 --only-fixed --fail-on high
```

To update grype, set `GRYPE_IMAGE` to the new version and the digest of its
image index (`docker buildx imagetools inspect anchore/grype:<version>`).

### Release tags are protected

A ruleset of the repository, **Release tags** (Settings → Rules →
Rulesets), keeps every `v*` tag from being moved or deleted, with no
exceptions; pushing a new one stays allowed. A release that went wrong is
fixed with the next version, never by moving its tag. A tag pushed by
mistake starts its release run at once: cancel the run first. If its
promote job ran, point `latest` and the floating tags back at the previous
release (`docker buildx imagetools create -t ghcr.io/ansilume/<image>:latest
ghcr.io/ansilume/<image>:<previous version>`, for each image and tag). Then
an admin disables the ruleset for the moment of the deletion. Once its
GitHub release is published, the tag stays: releases are immutable, and
deleting one retires its version number, so release the next version
instead.

### Using prebuilt images in production

The Ansible deploy role supports pulling prebuilt images instead of building from source:

```yaml
# In your inventory or group_vars:
ansilume_use_prebuilt_images: true
ansilume_version: v0.2.0
```

The role pulls the images tagged `0.2.0`: `ansilume_image_tag` is
`ansilume_version` without its `v`, or `latest` for a branch such as `main`.

### Standalone runner

The runner image (`ghcr.io/ansilume/ansilume-runner`) is designed for independent deployment in separate networks. It communicates with the ansilume server exclusively via HTTP API and has no database or Redis dependencies.

```bash
docker run -d \
  -e RUNNER_NAME=remote-runner-1 \
  -e RUNNER_BOOTSTRAP_SECRET=your-secret \
  -e API_URL=https://ansilume.example.com \
  ghcr.io/ansilume/ansilume-runner:latest
```

## GitHub releases

Every version tag gets a GitHub release, made by
`.github/workflows/github-release.yml` once the images passed the smoke test
and the scan, and the promote job ran:

| Part | Content |
|---|---|
| Text | The notes of `bin/release --notes`, the notes of every version since the previous GitHub release that got no release of its own (its run failed), the commits since the previous GitHub release (without the version bumps), the three images with their digests, a link to `docs/updating.md` of the tag, and how to verify the source archive. `bin/release-notes <tag> [<images file> [<previous release>]]` writes it; it prints the same text locally when you name the previous GitHub release, the highest version below the tag in `gh release list --exclude-drafts --json tagName --jq '.[].tagName'`. |
| `ansilume-<version>.tar.gz` | The source of the tag (`git archive`) |
| `ansilume-<version>.tar.gz.sigstore.json` | Its build provenance, signed with a short-lived Sigstore certificate of the workflow (`actions/attest`) |

Only the highest version tag (`vX.Y.Z`, no release candidate) becomes the
latest release. `bin/quickstart` installs and updates prebuilt installations
with the compose file of the latest release and falls back to `main` only
when GitHub cannot name it; in source mode it runs the compose file of its
checkout of `main`. The promote job moves `:latest` right before the release is made, so
`:latest` and the latest release name the same version, but for the minutes
between the two jobs, or until a failed release job is run again.

Watching the repository with **Watch → Custom → Releases** notifies of every
release.

Verify the source archive of a release: the first command checks that the
release workflow built it from the tag, the second that it belongs to the
release.

```bash
gh attestation verify ansilume-<version>.tar.gz --repo ansilume/ansilume \
  --source-ref refs/tags/v<version> \
  --signer-workflow ansilume/ansilume/.github/workflows/github-release.yml
gh release verify-asset v<version> ansilume-<version>.tar.gz --repo ansilume/ansilume
```

### Permissions and triggers

The `github-release` job of `release.yml` is the only job that writes to the
repository (`contents: write`), and it holds `id-token: write` and
`attestations: write` for the provenance. `github-release.yml` runs only when
`release.yml` calls it for the pushed tag. It has no manual trigger: the
provenance of a run started from a branch would name the branch, not the tag
the archive is made of. The tag reaches its scripts only as the environment
variable `TAG`; the first step refuses anything but a version tag such as
`v2.12.1`, and a tag moved since the push fails the run
(`tests/unit/config/GithubReleaseWorkflowTest.php`).

### When the release job fails

Re-run the failed job of the tag's release workflow run (**Re-run failed
jobs**). Releases are immutable once published, a repository setting: a
published release takes no new assets, and deleting it does not free its tag
for a new release. So a re-run only completes a draft that an interrupted run
left behind, and stops with an error when the release is already published.

Tags up to v2.12.0 have no GitHub release. The release of v2.12.1 was made by
hand before this workflow existed and has no source archive.
