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

; Before Drclick 0.4.4 the bundled PHP server, queue worker, scheduler and
; cloudflared could outlive a force-closed app and keep the bundled PHP DLLs
; locked, so copying the new php folder failed ("Erreur lors de l'ouverture du
; fichier en écriture"). Close Drclick first (so it cannot restart them), then
; stop only those helpers running from this installation folder. Any other PHP
; on the computer is left alone. The folder reaches PowerShell through the
; environment so no path character can break the command. The installer is a
; 32-bit program, so the PowerShell it starts is too, and Get-Process cannot
; read a 64-bit process's path from there; Win32_Process can. If PowerShell is
; unavailable this does nothing and NSIS shows its usual Retry dialog.
!macro DRCLICK_STOP_LEFTOVER_HELPERS
  !insertmacro CheckIfAppIsRunning "${MAINBINARYNAME}.exe" "${PRODUCTNAME}"
  Push $0
  System::Call 'Kernel32::SetEnvironmentVariable(t "DRCLICK_INSTDIR", t "$INSTDIR\")'
  nsExec::Exec `powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "Get-CimInstance Win32_Process | Where-Object { @('php.exe','php-cgi.exe','cloudflared.exe') -contains $$_.Name -and $$_.ExecutablePath -and $$_.ExecutablePath.StartsWith($$env:DRCLICK_INSTDIR, [StringComparison]::OrdinalIgnoreCase) } | ForEach-Object { Stop-Process -Id $$_.ProcessId -Force -ErrorAction SilentlyContinue }"`
  Pop $0 ; nsExec's exit code, not needed
  Pop $0
  ; Windows releases the DLL locks shortly after the processes end.
  Sleep 1000
!macroend

!macro NSIS_HOOK_PREINSTALL
  SetShellVarContext current
  !insertmacro DRCLICK_STOP_LEFTOVER_HELPERS
  !insertmacro DRCLICK_REMOVE_LEGACY_INSTALL "MediSmart" "medismart-desktop"
  !insertmacro DRCLICK_REMOVE_LEGACY_INSTALL "DrClickDz" "DrClickDz"
!macroend
