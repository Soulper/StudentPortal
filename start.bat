@echo off
REM Bind to 0.0.0.0 (not 127.0.0.1) so phones/tablets on the same WiFi can
REM reach this server. 0.0.0.0 also covers localhost, so desktop testing
REM is unchanged.
REM
REM Start the server, then open one of these on your tablet:
REM    http://<this-PC's-IP>:8000
REM The address is printed in the window below.
echo Starting Student Portal...
echo.
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr /c:"IPv4 Address"') do (
    set IP=%%a
)
echo   On this PC:   http://localhost:8000
echo.
echo   On your tablet, open:  http://%IP%:8000
echo   (both devices must be on the same WiFi)
echo.
"C:\Users\izenp\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" -d upload_max_filesize=25M -d post_max_size=26M -S 0.0.0.0:8000
