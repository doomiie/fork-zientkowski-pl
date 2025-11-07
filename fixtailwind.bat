@echo off
setlocal

echo -----------------------------------------
echo 🔧 USTAWIANIE ŚCIEŻKI DLA GLOBALNYCH PAKIETÓW NPM
echo -----------------------------------------

REM === Pobierz ścieżkę prefixu npm ===
for /f "delims=" %%A in ('npm config get prefix') do set NPM_PREFIX=%%A
set NPM_BIN=%NPM_PREFIX%\

echo 📁 Globalny katalog npm: %NPM_BIN%

REM === Dodaj do PATH (tylko jeśli jeszcze go nie ma) ===
echo.
echo 🧩 Sprawdzam, czy PATH zawiera %NPM_BIN%
echo.

for /f "tokens=*" %%P in ('powershell -NoProfile -Command "[Environment]::GetEnvironmentVariable('Path', 'User')"') do set USER_PATH=%%P

echo %USER_PATH% | find "%NPM_BIN%" >nul
if %errorlevel%==0 (
    echo ✅ Folder juz jest w PATH.
) else (
    echo 🛠️ Dodaję %NPM_BIN% do PATH...
    powershell -NoProfile -Command "[Environment]::SetEnvironmentVariable('Path', '%USER_PATH%;%NPM_BIN%', 'User')"
    echo ✅ PATH został zaktualizowany.
)

echo.
echo 🔄 Otwórz nowy terminal (CMD / PowerShell), aby zmiana zadziałała.
echo -----------------------------------------
echo Sprawdzanie Tailwinda:
echo -----------------------------------------
echo.

pause
tailwindcss -v

echo.
echo ✅ Jeśli powyżej widzisz wersję (np. 3.x.x), wszystko działa poprawnie!
echo -----------------------------------------
pause
