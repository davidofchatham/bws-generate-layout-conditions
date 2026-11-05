#!/usr/bin/env bash
#
# Prove the two guards still work: green on a clean tree, red on a seeded violation.
#
# A guard nobody has watched fail is a guard nobody knows still works. Runs in a
# throwaway git repo, so the real tree is never touched. Each probe must make the
# guard exit exactly 1: exit 2 is a crashed guard, and a crash is not a pass.
# Every PII rule gets its own probe, so one rule cannot rot behind another's red.
#
# Probes are assembled from pieces so this file does not trip the guards itself.
#
# Usage: bash tools/guards/self-test-guards.sh

set -uo pipefail

here=$(cd "$(dirname "$0")" && pwd)
work=$(mktemp -d)
remote=$(mktemp -d)
trap 'rm -rf "$work" "$remote"' EXIT

mkdir -p "$work/tools/guards/" "$work/docs"
cp "$here/check-private-citations.sh" "$here/check-tracked-pii.sh" "$here/pre-push" "$work/tools/guards/"
cd "$work" || exit 2
git init -q

failed=0
# expect <want-exit> <label> <guard>
expect() {
	bash "tools/guards/$3" > /dev/null 2>&1
	got=$?
	if [ "$got" -eq "$1" ]; then
		echo "ok    $2 (exit $got)"
	else
		echo "FAIL  $2: wanted exit $1, got $got"
		failed=1
	fi
}

# Clean tree. README.md names a placeholder path, which is the convention, not a citation.
# docs/future-work.md holds a literal private path: the tracker is the
# one exempt file, so this also proves the exemption.
printf '# Clean\n\nLayout: .scratch/<slug>/spec.md\n' > README.md
printf 'Detail home: `.scratch/plans/%s.md`\n' x > docs/future-work.md
expect 0 "citation guard, clean tree" check-private-citations.sh
expect 0 "pii guard, clean tree" check-tracked-pii.sh

# probe <label> <guard> <file> <printf-format> <arg>
probe() {
	printf "$4\n" "$5" > "$3"
	expect 1 "$1" "$2"
	rm "$3"
}
probe "citation guard, literal private path" check-private-citations.sh docs/probe.md 'See `.scratch/plans/%s.md`' x
probe "citation guard, literal .claude path" check-private-citations.sh docs/probe.md 'See `.claude/plans/%s.md`' x
probe "pii guard, absolute local path" check-tracked-pii.sh probe.md 'C:/Us%s' ers/x
probe "pii guard, email address" check-tracked-pii.sh probe.md 'probe@wordpress.%s' org
probe "pii guard, unlisted domain" check-tracked-pii.sh probe.md 'guard-probe.%s' io

# Rule 4, client names. The dummy name is assembled from pieces; CLIENT_TERMS is
# unset for everything above, so the unset-skips case is proven by the same tree.
dummy="dummy-cl""ient"
terms="$dummy|other-dum""my"
printf 'ran bin/wp.sh %s eval-file x.php\n' "$dummy" > probe.md
expect 0 "pii guard, client name present but CLIENT_TERMS unset (rule skipped)" check-tracked-pii.sh
CLIENT_TERMS="$terms" expect 1 "pii guard, client name as a bare word" check-tracked-pii.sh
out=$(CLIENT_TERMS="$terms" bash tools/guards/check-tracked-pii.sh 2>&1)
if printf '%s' "$out" | grep -qiF "$dummy"; then
	echo "FAIL  pii guard, client name: the matched text leaked into the output"
	failed=1
else
	echo "ok    pii guard, client name: output carries file:line only"
fi
printf 'Ran bin/wp.sh %s eval-file x.php\n' "$(printf '%s' "$dummy" | tr a-z A-Z)" > probe.md
CLIENT_TERMS="$terms" expect 1 "pii guard, client name in another case" check-tracked-pii.sh
printf 'the %sle and file%s\n' "$dummy" "$dummy" > probe.md
CLIENT_TERMS="$terms" expect 0 "pii guard, client name inside a longer word is not a hit" check-tracked-pii.sh
rm probe.md
touch "$dummy.md"
CLIENT_TERMS="$terms" expect 1 "pii guard, client name in a file path" check-tracked-pii.sh
rm "$dummy.md"
CLIENT_TERMS='(' expect 2 "pii guard, invalid CLIENT_TERMS is an error, not a pass" check-tracked-pii.sh

# The pre-push hook: feeds git's stdin line by hand. No remote exists in the
# throwaway repo, so every commit counts as unpushed.
hook() { # hook <want-exit> <label> <local sha>, CLIENT_TERMS from the caller
	printf 'refs/heads/main %s refs/heads/main %s\n' "$3" "0000000000000000000000000000000000000000" \
		| bash tools/guards/pre-push origin url > /dev/null 2>&1
	got=$?
	if [ "$got" -eq "$1" ]; then
		echo "ok    $2 (exit $got)"
	else
		echo "FAIL  $2: wanted exit $1, got $got"
		failed=1
	fi
}
git add -A
git -c user.name=t -c user.email=t@example.test commit -q -m "clean message"
clean=$(git rev-parse HEAD)
git -c user.name=t -c user.email=t@example.test commit -q --allow-empty -m "Fix for $dummy export"
dirty=$(git rev-parse HEAD)
CLIENT_TERMS="$terms" hook 0 "pre-push, clean message" "$clean"
CLIENT_TERMS="$terms" hook 1 "pre-push, client name in a commit message" "$dirty"
CLIENT_TERMS="" hook 0 "pre-push, terms unset (message scan skipped)" "$dirty"
CLIENT_TERMS="$terms" hook 0 "pre-push, branch delete publishes nothing" "0000000000000000000000000000000000000000"
CLIENT_TERMS='(' hook 2 "pre-push, invalid CLIENT_TERMS is an error, not a pass" "$clean"
printf 'ran bin/wp.sh %s eval-file x.php\n' "$dummy" > probe.md
CLIENT_TERMS="$terms" hook 1 "pre-push, tree leak the pre-commit missed (--no-verify)" "$clean"
rm probe.md
# Already-pushed commits are not rescanned. A clean child of the dirty commit
# still publishes it until the dirty one reaches a remote; after that it does not.
git -c user.name=t -c user.email=t@example.test commit -q --allow-empty -m "later clean work"
child=$(git rev-parse HEAD)
CLIENT_TERMS="$terms" hook 1 "pre-push, dirty ancestor not yet on a remote" "$child"
git init -q --bare "$remote"
git remote add origin "$remote"
git push -q origin HEAD:refs/heads/main
CLIENT_TERMS="$terms" hook 0 "pre-push, already-pushed commit is not rescanned" "$child"

# Seeding then removing must leave the tree green again, or a probe leaked state.
expect 0 "citation guard, clean again" check-private-citations.sh
expect 0 "pii guard, clean again" check-tracked-pii.sh

[ "$failed" -eq 0 ] && echo "Guards fire on violations and pass on a clean tree."
exit "$failed"
