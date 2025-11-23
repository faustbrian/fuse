#!/bin/bash

set -e

# Parse command line arguments
package_name=""
package_description=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --name)
            package_name="$2"
            shift 2
            ;;
        --description)
            package_description="$2"
            shift 2
            ;;
        *)
            echo "❌ Unknown option: $1"
            echo "Usage: $0 [--name <package_name>] [--description <package_description>]"
            exit 1
            ;;
    esac
done

echo "🚀 Package Configuration Script"
echo "================================"
echo ""

# Prompt for package name if not provided
if [ -z "$package_name" ]; then
    read -p "Package name (e.g., my-awesome-package): " package_name
    if [ -z "$package_name" ]; then
        echo "❌ Error: Package name cannot be empty"
        exit 1
    fi
fi

# Prompt for package description if not provided
if [ -z "$package_description" ]; then
    read -p "Package description: " package_description
    if [ -z "$package_description" ]; then
        echo "❌ Error: Package description cannot be empty"
        exit 1
    fi
fi

echo ""
echo "📝 Configuration Summary:"
echo "  Package Name: $package_name"
echo "  Description: $package_description"
echo ""
read -p "Proceed with these values? (y/n): " confirm

if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
    echo "❌ Configuration cancelled"
    exit 0
fi

echo ""
echo "🔄 Replacing placeholders..."

# Find and replace in all files
files=$(rg -l ":package_" --type-not lock 2>/dev/null || echo "")

if [ -z "$files" ]; then
    echo "⚠️  No files found with placeholders"
    exit 0
fi

for file in $files; do
    echo "  ✓ $file"

    # Create backup
    cp "$file" "$file.bak"

    # Replace placeholders
    sed -i.tmp "s/:package_name/$package_name/g" "$file"
    sed -i.tmp "s/:package_description/$package_description/g" "$file"

    # Remove temp file
    rm -f "$file.tmp"
done

echo ""
echo "✅ Configuration complete!"
echo ""
echo "📋 Modified files:"
echo "$files"
echo ""
echo "💡 Backup files (.bak) have been created. Remove them when satisfied:"
echo "   rm **/*.bak"
echo ""
echo "🎉 Your package is ready!"
