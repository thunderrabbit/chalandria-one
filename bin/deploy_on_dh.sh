#!/bin/bash
# Deploy current branch to lab.robnugen.com (DreamHost) via git push + ssh pull
set -e

cd "$(dirname "$0")/.."
BRANCH=$(git rev-parse --abbrev-ref HEAD)
PLUGIN_DIR="lab.robnugen.com/wwwroot/wp/wp-content/plugins/chalandria-one"

echo "Pushing $BRANCH to origin..."
git push -u origin "$BRANCH"

echo "Pulling $BRANCH on lab..."
ssh lab "cd $PLUGIN_DIR && git pull origin $BRANCH"

echo "Deployed $BRANCH to lab."
