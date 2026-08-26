@echo off
if not exist C:\GaussDBTest mkdir C:\GaussDBTest
set "INSTALLER=%~1"
if "%INSTALLER%"=="" set "INSTALLER=C:\Windows\Temp\gsqlodbc-x64.exe"
start /wait "" "%INSTALLER%" /S
echo %ERRORLEVEL%>C:\GaussDBTest\install-exit-code.txt
