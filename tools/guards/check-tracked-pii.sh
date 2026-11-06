#!/usr/bin/env bash
#
# Fail if tracked files carry personal or client-identifying content.
#
# WHY THIS EXISTS: committed prose accretes over time. Visibility is a setting, not a
# property of the repo: a private repo that flips public turns every past commit
# public at once, a public repo has done so already, and git history cannot be
# un-leaked by a later delete. So the guard runs at push, when the content is still
# one revert away, rather than later when it is not. Client work contributes foreign
# facts only (README.md, "What does not go here"); this guard is the net under that
# rule.
#
# WHAT IT CANNOT SEE: the tracked tree only. Not commit messages, not older commits,
# not issues or PR text. Rules 1-3 match shapes (paths, emails, hostnames); a
# client's NAME written as a bare word has no shape, so rule 4 matches a private
# list instead.
#
# Scope is the tracked tree plus untracked files that are not gitignored. .scratch/
# and .claude/ are working surfaces and are out of scope ONLY where .gitignore
# lists them -- their whole purpose is to hold what must not be committed. A repo
# that does not ignore .claude/ has it scanned, and risks committing it; ignore it.
#
# Convention to converge on: every fixture value uses a reserved domain, preferably
# @example.test (the allowlist below also admits example.com, .org and .net).
#
# Copy-in template. Adapted from a private project's scripts/check-tracked-pii.sh (read 2026-10-02);
# the adaptations are listed in the body of the commit that added this file (git log -- tools/guards/).
# Why each rule looks the way it does: the bws-reference practice committed-content-guards.md.
# Usage: bash tools/guards/check-tracked-pii.sh
# Rule 4 is opt-in: CLIENT_TERMS='name-one|name-two' bash tools/guards/check-tracked-pii.sh
# CI proves it still fails on a hit (tools/guards/self-test-guards.sh).

set -uo pipefail

# Paths never scanned.
#   vendor, libs, composer.lock -- third-party; their domains are not ours
#   this script                 -- it necessarily contains every pattern below
EXCLUDES='^(vendor/|libs/|composer\.lock$|tools/guards/check-tracked-pii\.sh$)'

# Domains that may legitimately appear: upstream projects, specs, and the
# reserved test domains (RFC 2606 / RFC 6761). Matched with an optional
# subdomain prefix, so www.w3.org clears on the w3.org entry.
ALLOWED_DOMAINS='wordpress\.org|wp\.org|github\.com|gnu\.org|w3\.org|example\.(com|org|net)|[a-z0-9-]+\.(test|invalid|localhost)'

# Emails get their own, much smaller list -- see rule 2.
ALLOWED_EMAIL_DOMAINS='example\.(com|org|net)|[a-z0-9-]+\.(test|invalid|localhost)'

# --others surfaces files not yet committed; --exclude-standard keeps .gitignore
# honored, so .scratch/ stays out. Without it a brand-new file's PII would pass
# locally and only fail once committed -- after the value is already in history.
FILES=$( { git ls-files; git ls-files --others --exclude-standard; } | sort -u | grep -Ev "$EXCLUDES")
[ -z "$FILES" ] && { echo "No files to scan."; exit 0; }

fail=0
report() {
	# $1 = what was found, $2 = grep output
	[ -z "$2" ] && return 0
	fail=1
	echo "::error::$1"
	printf '%s\n' "$2" | sed 's/^/    /'
	echo
}

# 1. Absolute paths into the author's own checkout or home directory.
#
#    NARROWED DELIBERATELY to paths bearing an identifying segment. A bare drive
#    letter is not one: docs may write 'U:\' or 'X:\path\to\plugin' as
#    placeholders, and those name nobody. Matching every "<letter>:\" also
#    collides with PHP's own escapes -- "exists:\n" is a drive path by that
#    rule -- so the pattern would have taught people to ignore it, which is the
#    failure mode a hard-fail guard cannot survive.
hits=$(printf '%s\n' "$FILES" | xargs -d '\n' grep -nIE \
	'([A-Za-z]:[\\/](Users|Dev|Resources|Repos|Environments)[\\/]|/home/[a-z][a-z0-9_-]*/|/Users/[A-Za-z])' 2>/dev/null || true)
report "Absolute local path in a tracked file" "$hits"

# 2. Email addresses. Only RESERVED test domains pass -- deliberately NOT the
#    hostname allowlist. Reusing that list here would exempt the author's own mail
#    domain, so a real mailbox would sail through while a stranger's gmail was
#    caught: precisely backwards. Upstream project hostnames are legitimate to cite;
#    addresses AT them are not.
hits=$(printf '%s\n' "$FILES" | xargs -d '\n' grep -nIEo \
	'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' 2>/dev/null \
	| grep -Ev "@([a-z0-9-]+\.)*($ALLOWED_EMAIL_DOMAINS)$" || true)
report "Email address in a tracked file" "$hits"

# 3. Domains outside the allowlist. This catches a client's HOSTNAME -- a real one
#    in a config example reads as documentation and is indistinguishable from a
#    placeholder until someone knows the client. It does not catch a client's name
#    or site slug written as a bare word: that is rule 4.
hits=$(printf '%s\n' "$FILES" | xargs -d '\n' grep -nIEo \
	'\b[a-z0-9][a-z0-9-]*(\.[a-z0-9-]+)*\.(com|org|net|io|co|us|dev|app|cloud|site|online|xyz)\b' 2>/dev/null \
	| grep -Ev ":([a-z0-9-]+\.)*($ALLOWED_DOMAINS)$" || true)
report "Domain outside the allowlist in a tracked file" "$hits"

# 4. Client names, from a list that must never be committed. OPT-IN: CLIENT_TERMS is
#    an extended-regex alternation ('name-one|name-two'), read from the environment
#    (an Actions secret in CI, an env var locally) and skipped when unset or empty.
#    The list cannot live in the repo, because a committed denylist publishes the
#    names it protects.
#
#    - Case-insensitive; a hit needs a non-alphanumeric (or line edge) on both
#      sides, so an underscore or hyphen still separates and 'name' does not match
#      inside 'filename'.
#    - Prints file:line and NEVER the matched text. Actions logs are public on a
#      public repo, and secret masking redacts only the whole secret string, not a
#      name a regex pulled out of a file. A file PATH that matches is counted, not
#      listed, for the same reason.
#    - A silent skip is a hole: secrets are not passed to workflows triggered from
#      a fork, so there the rule is off. The skip prints a notice so the log says so.
#    - An invalid pattern is an error (exit 2), never "no matches".
if [ -n "${CLIENT_TERMS:-}" ]; then
	pat="(^|[^[:alnum:]])($CLIENT_TERMS)([^[:alnum:]]|\$)"
	echo x | grep -E "$pat" > /dev/null 2>&1
	[ "$?" -gt 1 ] && { echo "::error::CLIENT_TERMS is not a valid extended regex."; exit 2; }
	hits=$(printf '%s\n' "$FILES" | xargs -d '\n' grep -nIiHE "$pat" 2>/dev/null | cut -d: -f1,2 || true)
	report "Client name in a tracked file (matched text withheld)" "$hits"
	pathhits=$(printf '%s\n' "$FILES" | grep -ciE "$pat" || true)
	if [ "${pathhits:-0}" -gt 0 ]; then
		fail=1
		echo "::error::$pathhits tracked path(s) contain a client name (paths withheld)"
		echo
	fi
	names=ran
else
	echo "::notice::CLIENT_TERMS unset: client-name rule skipped."
	names=skipped
fi

if [ "$fail" -ne 0 ]; then
	cat <<-'MSG'
	Tracked files must not name the author's machine, mailbox, or a client's
	infrastructure or name. Replace with a reserved placeholder (example.com, *.test,
	"Site A"), or -- if the value is genuinely public and belongs here -- add it to
	ALLOWED_DOMAINS in this script, in the same commit, with a reason. Client-name
	hits show file:line only; open the line to see what matched.
	MSG
	exit 1
fi

echo "Tracked tree clean: no local paths, emails, or unlisted domains (client-name rule: $names)."
