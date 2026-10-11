#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Merges the heads of pull requests, in the order given, into the branch that
# is checked out: what that branch will hold once they have all landed in that
# order. Stops at the first pull request that does not merge, naming it, the
# files in conflict and which of the pull requests merged before it touched
# them. Used by .github/workflows/stack-check.yml.
#
#   bash tests/Stack/merge.sh 2601 2602 2603
#
# Every pull request here ends with a version bump and a rebuilt js/, so two
# that started from the same commit always conflict there. Conflicts confined
# to the files in TAKE_LATER are settled in favour of the later pull request,
# as merging them by hand would, and reported as warnings; a conflict anywhere
# else stops the merge. TAKE_LATER='' turns that off.
#
# REMOTE (default: origin) is where refs/pull/<n>/head is fetched from.

set -euo pipefail

REMOTE=${REMOTE:-origin}
TAKE_LATER=${TAKE_LATER-appinfo/info.xml composer.json composer.lock package.json package-lock.json docs/Architecture.md js/}
SUMMARY=${GITHUB_STEP_SUMMARY:-/dev/null}

if [ $# -eq 0 ]; then
	echo "usage: $0 PR-NUMBER..." >&2
	exit 2
fi

git_merge() {
	git -c user.name='Stack check' -c user.email='stack-check@invalid' "$@"
}

# whether every path given is covered by TAKE_LATER (a file, or a directory
# ending in a slash)
all_take_later() {
	local path entry covered
	for path in "$@"; do
		covered=no
		for entry in $TAKE_LATER; do
			case "$entry" in
				*/) [[ "$path" == "$entry"* ]] && covered=yes ;;
				*) [ "$path" = "$entry" ] && covered=yes ;;
			esac
		done
		[ "$covered" = yes ] || return 1
	done
}

for pr in "$@"; do
	if ! [[ "$pr" =~ ^[0-9]+$ ]]; then
		echo "::error::'$pr' is not a pull request number"
		exit 2
	fi
done

base=$(git rev-parse HEAD)
{
	echo "### Stack on $(git rev-parse --abbrev-ref HEAD) at ${base:0:9}"
	echo
} >> "$SUMMARY"

merged=()
for pr in "$@"; do
	ref="refs/stack/pr-$pr"
	if ! git fetch --quiet --no-tags "$REMOTE" "+refs/pull/$pr/head:$ref"; then
		echo "::error::#$pr could not be fetched from $REMOTE"
		exit 2
	fi
	head=$(git rev-parse "$ref")

	if git merge-base --is-ancestor "$head" HEAD; then
		echo "#$pr (${head:0:9}) is already contained"
		echo "- #$pr \`${head:0:9}\`: already contained" >> "$SUMMARY"
		merged+=("$pr")
		continue
	fi

	if git_merge merge --quiet --no-ff --no-edit -m "Merge #$pr into the stack" "$ref" > /dev/null 2>&1; then
		echo "#$pr (${head:0:9}) merged"
		echo "- #$pr \`${head:0:9}\`: merged" >> "$SUMMARY"
		merged+=("$pr")
		continue
	fi

	conflicted=()
	while IFS= read -r file; do
		conflicted+=("$file")
	done < <(git diff --name-only --diff-filter=U)
	if [ ${#conflicted[@]} -gt 0 ] && [ -n "$TAKE_LATER" ] && all_take_later "${conflicted[@]}"; then
		for file in "${conflicted[@]}"; do
			if git cat-file -e "MERGE_HEAD:$file" 2> /dev/null; then
				git checkout MERGE_HEAD -- "$file"
			else
				git rm --quiet -- "$file"
			fi
		done
		git_merge commit --quiet --no-edit
		echo "::warning title=#$pr merged with conflicts settled::took #$pr's side of ${conflicted[*]}"
		echo "- #$pr \`${head:0:9}\`: merged, taking its side of ${conflicted[*]}" >> "$SUMMARY"
		merged+=("$pr")
		continue
	fi

	echo "::error title=#$pr does not merge::#$pr (${head:0:9}) conflicts with the stack below it in ${conflicted[*]:-(no file: see the log)}"
	echo "- #$pr \`${head:0:9}\`: **conflicts**" >> "$SUMMARY"
	for file in ${conflicted[@]+"${conflicted[@]}"}; do
		touched=()
		for earlier in ${merged[@]+"${merged[@]}"}; do
			if [ -n "$(git diff --name-only "$base...refs/stack/pr-$earlier" -- "$file")" ]; then
				touched+=("#$earlier")
			fi
		done
		echo "  $file: changed by ${touched[*]:-the base branch since #$pr branched off}"
		echo "  - \`$file\`: also changed by ${touched[*]:-the base branch since #$pr branched off}" >> "$SUMMARY"
	done
	git merge --abort
	exit 1
done

echo "Merged ${#merged[@]} pull request(s) onto ${base:0:9}"
