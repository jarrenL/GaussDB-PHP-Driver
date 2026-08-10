@echo off
if not exist C:\GaussDBTest mkdir C:\GaussDBTest
start /wait "" C:\Windows\Temp\gsqlodbc507x64.exe /S
echo %ERRORLEVEL%>C:\GaussDBTest\install-exit-code.txt

