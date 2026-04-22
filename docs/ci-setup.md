# CI setup

The `E2E` workflow needs SSH access to the test WordPress site (default: `testbeavermind.dependentmedia.com` on Dependent Media's Plesk server) so it can mint a Playwright auth cookie at run time via `wp-cli`. Four repository secrets drive this:

| Secret | Value |
|---|---|
| `SSH_HOST` | Hostname of the SSH server, e.g. `server-02.dependentmedia.com` |
| `SSH_USER` | Webspace system user, e.g. `testbeavermind.depen_xxxxxxxx` |
| `SSH_PRIVATE_KEY` | PEM contents of the CI-only private key |
| `SSH_KNOWN_HOSTS` | `ssh-keyscan -t ed25519 <SSH_HOST>` output |

The key pair is **scoped to a single webspace user on Plesk** — not root, not the Plesk admin. Access is sandboxed to one subscription.

## Rotating / regenerating the CI key

Do this if the key is exposed, when the testbeavermind user changes, or periodically as hygiene.

```bash
# 1. Generate a fresh keypair locally
mkdir -p /tmp/bm-ci-key
ssh-keygen -t ed25519 -N "" \
  -C "beavermind-github-actions@dependentmedia" \
  -f /tmp/bm-ci-key/id_ed25519

# 2. Install the public key on testbeavermind
#    (run from a host that already has SSH access to the target)
PUB=$(cat /tmp/bm-ci-key/id_ed25519.pub)
ssh testbeavermind "grep -qxF '$PUB' ~/.ssh/authorized_keys \
  || echo '$PUB' >> ~/.ssh/authorized_keys"

# 3. Capture the server's host key
ssh-keyscan -t ed25519 server-02.dependentmedia.com \
  > /tmp/bm-ci-key/known_hosts

# 4. Push to GitHub Secrets
gh secret set SSH_PRIVATE_KEY --repo Dependent-Media/beavermind \
  < /tmp/bm-ci-key/id_ed25519
gh secret set SSH_KNOWN_HOSTS --repo Dependent-Media/beavermind \
  < /tmp/bm-ci-key/known_hosts
gh secret set SSH_HOST --repo Dependent-Media/beavermind \
  --body 'server-02.dependentmedia.com'
gh secret set SSH_USER --repo Dependent-Media/beavermind \
  --body 'testbeavermind.depen_xxxxxxxx'   # replace with current user

# 5. Clean up the keypair on disk
rm -rf /tmp/bm-ci-key

# 6. (Optional) Revoke the old key
ssh testbeavermind 'nano ~/.ssh/authorized_keys'
# — remove the old `beavermind-github-actions@dependentmedia` line
```

## What the E2E workflow does with these secrets

1. Writes `~/.ssh/id_ed25519`, `~/.ssh/known_hosts`, and a matching `~/.ssh/config` entry aliased `testbeavermind`.
2. **Rsyncs the PR's plugin code to `testbeavermind:httpdocs/wp-content/plugins/beavermind/`** so the test exercises the actual PR changes — not whatever was last manually deployed. Uses `--delete` so stale files don't survive. Excludes `_TestRunner/`, `dist/`, `.git/`, `.github/`, and Playwright's `node_modules` / `test-results` / `.auth` / `.env`.
3. Runs `tests/playwright/scripts/generate-auth-state.sh`, which SSHes to the webspace user and uses `wp-cli` to mint a 14-day WP auth cookie for the pre-created test user (`bm_playwright_test`).
4. Stores the cookie as `tests/playwright/.auth/state.json` so Playwright can skip the WP login UI (which is brittle under WPS Hide Login + Limit Login Attempts + maintenance mode).
5. Runs the Playwright spec. Videos, screenshots, and traces are uploaded as workflow artifacts (retained 14 days).

**Concurrency:** the workflow uses `concurrency: { group: e2e-testbeavermind }` so only one E2E run touches the site at a time. Concurrent PR runs queue rather than racing.

**Fork safety:** the `pull_request` trigger doesn't expose secrets to forks (GitHub default), so a fork PR's E2E run will fail at the secret-validation step before any rsync to testbeavermind. Internal PRs (own repo) get full access and deploy as expected.

## Making the test site reachable from a different webspace

The workflow assumes `testbeavermind.dependentmedia.com` as the target. To point the E2E at a different install, change:

- `SSH_HOST` / `SSH_USER` secrets (point at the new webspace)
- `BM_WP_USER_ID` in `tests/playwright/scripts/generate-auth-state.sh` (defaults to `6` — the `bm_playwright_test` user ID on testbeavermind)
- `TEST_SITE_URL` and `TEST_REFERENCE_URL` in `.github/workflows/e2e.yml` (baseline URLs) or the `.env` for local runs.

The new webspace also needs the `beavermind` plugin installed + activated, the Anthropic API key configured in its `beavermind_options`, an administrator user matching the wp-cli script's `BM_WP_USER_ID`, and a copy of `tests/fixtures/example-landing.html` at `/beavermind-fixtures/example-landing.html`.

## Debugging a failing E2E run

- **"Missing required secrets"** — one of the four values above isn't set on the repo. Run `gh secret list --repo Dependent-Media/beavermind` to confirm.
- **SSH connection fails** — the `SSH_KNOWN_HOSTS` value is stale (host rotated its key) or the CI pubkey was removed from the webspace user's `~/.ssh/authorized_keys`. Re-run steps 2–4 above.
- **Playwright can't see `.fl-builder-content`** — the generated page was created but Beaver Builder isn't rendering it, usually because the target site is in a maintenance mode that also blocks authenticated admin views. Log in manually and confirm admins bypass.
- **Flaky timeouts at the Claude step** — Anthropic API was overloaded (HTTP 529). The SDK already retries; if the workflow still times out, re-run the job.

## Cost model

Every PR run invokes the Claude API once (`Planner::plan` with a reference). On the current ~4-fragment catalog that's ~3K tokens on Opus 4.7 ≈ $0.03 per run. At scale, PR-triggered runs dominate cost — consider moving to `workflow_dispatch` + nightly scheduled runs if PR volume spikes.
