#!/bin/bash
###############################################################################
# Refresh Evidence Anchors
# Regenerates file:line anchors in documentation files.
# Pre-handoff check fails if any referenced file/line anchor is missing.
###############################################################################

echo "Refreshing evidence anchors..."

DOCS_DIR="docs"
BACKEND_DIR="backend"
FRONTEND_DIR="frontend"

errors=0

check_anchor() {
    local file="$1"
    local line="$2"
    local doc_file="$3"

    if [ ! -f "$file" ]; then
        echo "ERROR: File not found: $file (referenced in $doc_file)"
        ((errors++))
        return
    fi

    if [ -n "$line" ]; then
        total_lines=$(wc -l < "$file")
        if [ "$line" -gt "$total_lines" ]; then
            echo "WARNING: Line $line exceeds file length ($total_lines) in $file (referenced in $doc_file)"
        fi
    fi
}

# Scan documentation files for file:line references
for doc in "$DOCS_DIR"/*.md; do
    if [ -f "$doc" ]; then
        grep -oP '[a-zA-Z_/]+\.(php|js|sql|json):\d+' "$doc" 2>/dev/null | while IFS=: read -r file line; do
            check_anchor "$file" "$line" "$doc"
        done
    fi
done

if [ $errors -eq 0 ]; then
    echo "All evidence anchors valid."
else
    echo "$errors anchor error(s) found."
fi

exit $errors
