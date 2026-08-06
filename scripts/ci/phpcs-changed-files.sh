#!/usr/bin/env bash
set -euo pipefail

BASE_REF="${1:-}"

if [[ -z "$BASE_REF" ]]; then
	if [[ -n "${GITHUB_BASE_REF:-}" ]]; then
		BASE_REF="origin/${GITHUB_BASE_REF}"
	elif git rev-parse --verify origin/release/0.3.0 >/dev/null 2>&1; then
		BASE_REF="origin/release/0.3.0"
	else
		BASE_REF="HEAD~1"
	fi
fi

if [[ "$BASE_REF" == origin/* ]] && ! git rev-parse --verify "$BASE_REF" >/dev/null 2>&1; then
	git fetch --no-tags --depth=1 origin "${BASE_REF#origin/}:${BASE_REF}" >/dev/null 2>&1 || true
fi

if ! git rev-parse --verify "$BASE_REF" >/dev/null 2>&1; then
	echo "Unable to resolve PHPCS base ref: $BASE_REF" >&2
	exit 1
fi

MERGE_BASE="$(git merge-base "$BASE_REF" HEAD)"

PHP_FILES=()
while IFS= read -r php_file; do
	PHP_FILES+=("$php_file")
done < <(
	git diff --name-only --diff-filter=ACMRT "$MERGE_BASE"...HEAD -- '*.php' \
		| grep -Ev '^(vendor|node_modules|build|dist|coverage)/' \
		| sort
)

if [[ "${#PHP_FILES[@]}" -eq 0 ]]; then
	echo "No changed PHP files to lint against $BASE_REF."
	exit 0
fi

echo "Running PHPCS against changed PHP files relative to $BASE_REF:"
printf ' - %s\n' "${PHP_FILES[@]}"

LINT_FILES=()
SKIPPED_FILES=()

for php_file in "${PHP_FILES[@]}"; do
	if git cat-file -e "${MERGE_BASE}:${php_file}" 2>/dev/null; then
		if ! git show "${MERGE_BASE}:${php_file}" \
			| vendor/bin/phpcs --standard=phpcs.changed.xml.dist --runtime-set ignore_warnings_on_exit 1 --stdin-path="$php_file" - >/dev/null 2>&1; then
			SKIPPED_FILES+=("$php_file")
			continue
		fi
	fi

	LINT_FILES+=("$php_file")
done

if [[ "${#SKIPPED_FILES[@]}" -gt 0 ]]; then
	echo "Skipping changed PHP files that already fail the actionable PHPCS gate on $BASE_REF:"
	printf ' - %s\n' "${SKIPPED_FILES[@]}"
fi

if [[ "${#LINT_FILES[@]}" -eq 0 ]]; then
	echo "No changed PHP files are newly actionable against $BASE_REF."
	exit 0
fi

echo "Linting actionable changed PHP files:"
printf ' - %s\n' "${LINT_FILES[@]}"

vendor/bin/phpcs --standard=phpcs.changed.xml.dist --runtime-set ignore_warnings_on_exit 1 -- "${LINT_FILES[@]}"
