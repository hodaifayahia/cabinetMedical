; Drclick installer migration for the two historical per-user product names.
;
; This intentionally performs a surgical uninstall. In particular, it does not
; execute a legacy uninstaller and never removes an installation directory. The
; original MediSmart bundle stored its Laravel/PHP runtime and cabinet data below
; $LOCALAPPDATA\MediSmart, while all generations share Tauri application data at
; $LOCALAPPDATA\dz.click.medismart. Both locations must survive the rebrand.

!macro DRCLICK_REMOVE_LEGACY_INSTALL PRODUCT_NAME MAIN_BINARY_NAME
  ; Tauri's current-user process helper only stops a matching process owned by
  ; the user running this installer. It prompts in interactive mode and aborts
  ; safely if Windows cannot stop the process.
  !insertmacro CheckIfAppIsRunning "${MAIN_BINARY_NAME}.exe" "${PRODUCT_NAME}"

  ; Only remove executable installer artifacts from Tauri's exact historical
  ; default per-user location. Never derive a deletion target from registry data.
  Delete "$LOCALAPPDATA\${PRODUCT_NAME}\${MAIN_BINARY_NAME}.exe"
  Delete "$LOCALAPPDATA\${PRODUCT_NAME}\uninstall.exe"

  ; Remove only shortcuts created under the historical product name.
  !insertmacro UnpinShortcut "$SMPROGRAMS\${PRODUCT_NAME}.lnk"
  Delete "$SMPROGRAMS\${PRODUCT_NAME}.lnk"
  !insertmacro UnpinShortcut "$DESKTOP\${PRODUCT_NAME}.lnk"
  Delete "$DESKTOP\${PRODUCT_NAME}.lnk"
  !insertmacro UnpinShortcut "$QUICKLAUNCH\User Pinned\TaskBar\${PRODUCT_NAME}.lnk"
  Delete "$QUICKLAUNCH\User Pinned\TaskBar\${PRODUCT_NAME}.lnk"

  ; Remove only the historical NSIS registration and autostart metadata. The
  ; stable dz.click.medismart application-data identity is deliberately retained.
  DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "${PRODUCT_NAME}"
  DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${PRODUCT_NAME}"
  DeleteRegKey HKCU "Software\click\${PRODUCT_NAME}"
!macroend

!macro NSIS_HOOK_PREINSTALL
  SetShellVarContext current
  !insertmacro DRCLICK_REMOVE_LEGACY_INSTALL "MediSmart" "medismart-desktop"
  !insertmacro DRCLICK_REMOVE_LEGACY_INSTALL "DrClickDz" "DrClickDz"
!macroend
