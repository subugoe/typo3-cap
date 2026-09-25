#!/usr/bin/env bash
set -euo pipefail

TAG="${1:-}"

if [[ -z "$TAG" ]]; then
    echo "Usage: ./release.sh v1.0.0"
    exit 1
fi

if [[ ! "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Version must look like v1.0.0"
    exit 1
fi

VERSION="${TAG#v}"

# Ensure working tree is clean
if [[ -n "$(git status --porcelain)" ]]; then
    echo "Working tree is not clean."
    exit 1
fi

# Update metadata
sed -i "s/'version' => '[^']*'/'version' => '${VERSION}'/" ext_emconf.php

git add ext_emconf.php
git commit -m "Release ${TAG}"

git tag -a "${TAG}" -m "Release ${TAG}"

git push origin main
git push origin "${TAG}"

echo "Released ${TAG}"