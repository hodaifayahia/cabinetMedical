#!/usr/bin/env bash
cd "$(dirname "$0")"
f="vendor/filament/support/src/Icons/Heroicon.php"
echo "file: $f"
grep -oE 'OutlinedUsers|OutlinedUserGroup|OutlinedIdentification|OutlinedShieldCheck|OutlinedClipboardDocumentList|OutlinedClipboardDocumentCheck|OutlinedClipboardDocument|OutlinedRectangleStack|OutlinedBeaker|OutlinedBanknotes|OutlinedCreditCard' "$f" | sort -u
