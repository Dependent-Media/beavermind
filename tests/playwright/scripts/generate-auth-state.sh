#!/usr/bin/env bash
# Generates a Playwright storageState JSON with a pre-authenticated WordPress
# session, bypassing any frontend login plugin (WPS Hide Login, 2FA, etc).
#
# Uses wp-cli on the remote host to mint an auth cookie for our test user,
# then writes it in Playwright's storageState format so test specs can skip
# the login UI.
#
# Usage:   bash scripts/generate-auth-state.sh
# Output:  .auth/state.json
set -euo pipefail

SSH_HOST="${BM_SSH_HOST:-testbeavermind}"
WP_USER_ID="${BM_WP_USER_ID:-6}"
SITE_HOST="${BM_SITE_HOST:-testbeavermind.dependentmedia.com}"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT_DIR="$HERE/../.auth"
OUT_FILE="$OUT_DIR/state.json"
mkdir -p "$OUT_DIR"

# shellcheck disable=SC2087  # heredoc intentionally expands locally
COOKIE_JSON="$(ssh "$SSH_HOST" bash -lc "'cd ~/httpdocs && wp eval '\''
\$user_id   = (int) ${WP_USER_ID};
\$expires   = time() + 14 * DAY_IN_SECONDS;
\$scheme    = \"secure_auth\";
\$token     = wp_generate_password( 43, false, false );
\$session   = \WP_Session_Tokens::get_instance( \$user_id );
\$session->update( \$token, array(
    \"expiration\" => \$expires,
    \"login\"      => time(),
    \"ua\"         => \"playwright-generated\",
    \"ip\"         => \"127.0.0.1\",
) );
\$cookie_name  = SECURE_AUTH_COOKIE;
\$cookie_value = wp_generate_auth_cookie( \$user_id, \$expires, \"secure_auth\", \$token );
\$domain = parse_url( home_url(), PHP_URL_HOST );
echo json_encode( array(
    \"name\"     => \$cookie_name,
    \"value\"    => \$cookie_value,
    \"domain\"   => \$domain,
    \"path\"     => \"/\",
    \"expires\"  => \$expires,
    \"httpOnly\" => true,
    \"secure\"   => true,
    \"sameSite\" => \"Lax\",
), JSON_UNESCAPED_SLASHES );
'\''
'")"

# Wrap the cookie in Playwright's storageState shape.
node -e "
const c = JSON.parse(process.argv[1]);
const state = { cookies: [c], origins: [] };
require('fs').writeFileSync(process.argv[2], JSON.stringify(state, null, 2));
console.log('Wrote auth state for domain', c.domain, '→', process.argv[2]);
" "$COOKIE_JSON" "$OUT_FILE"
