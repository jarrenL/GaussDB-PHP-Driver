$ErrorActionPreference = 'Stop'

$archive = 'C:\Windows\Temp\php-8.3.8-nts-Win32-vs16-x64.zip'
$phpHome = 'C:\GaussDBTest\php-8.3.8'
$statusFile = 'C:\GaussDBTest\php-setup.txt'

New-Item -ItemType Directory -Force -Path 'C:\GaussDBTest' | Out-Null
if (Test-Path $phpHome) {
    Remove-Item -Recurse -Force $phpHome
}

Expand-Archive -LiteralPath $archive -DestinationPath $phpHome
Copy-Item "$phpHome\php.ini-production" "$phpHome\php.ini"
Add-Content -Path "$phpHome\php.ini" -Value @'

extension_dir="ext"
extension=pdo_odbc
extension=odbc
'@

$output = & "$phpHome\php.exe" -v 2>&1
$modules = & "$phpHome\php.exe" -m 2>&1

@(
    $output,
    '--- modules ---',
    $modules
) | Set-Content -Encoding UTF8 $statusFile

