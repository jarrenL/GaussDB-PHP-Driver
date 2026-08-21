param(
    [string]$Archive = 'C:\Windows\Temp\php-8.3.8-nts-Win32-vs16-x64.zip',
    [string]$PhpHome = 'C:\GaussDBTest\php-8.3.8-x64',
    [string]$StatusFile = 'C:\GaussDBTest\php-setup-x64.txt',
    [ValidateSet('x64', 'x86')]
    [string]$ExpectedArchitecture = 'x64'
)

$ErrorActionPreference = 'Stop'
trap {
    @('setup_status=failed'; ($_ | Out-String)) |
        Set-Content -Encoding UTF8 -Path $StatusFile
    throw
}

New-Item -ItemType Directory -Force -Path 'C:\GaussDBTest' | Out-Null
if (Test-Path $PhpHome) {
    Remove-Item -Recurse -Force $PhpHome
}

Expand-Archive -LiteralPath $Archive -DestinationPath $PhpHome
Copy-Item "$PhpHome\php.ini-production" "$PhpHome\php.ini"
Add-Content -Path "$PhpHome\php.ini" -Value @'

extension_dir="ext"
extension=pdo_odbc
extension=odbc
'@

$output = & "$PhpHome\php.exe" -v 2>&1
if ($LASTEXITCODE -ne 0) { throw "php.exe -v failed: $($output -join [Environment]::NewLine)" }
$modules = & "$PhpHome\php.exe" -m 2>&1
if ($LASTEXITCODE -ne 0) { throw "php.exe -m failed: $($modules -join [Environment]::NewLine)" }
if ($modules -notcontains 'PDO' -or $modules -notcontains 'PDO_ODBC') {
    throw 'PDO and PDO_ODBC must both be loaded'
}
$phpInfo = & "$PhpHome\php.exe" -i 2>&1
if ($LASTEXITCODE -ne 0) { throw 'PHP information probe failed' }
$architecture = ($phpInfo | Select-String -Pattern '^Architecture =>').Line
if ([string]::IsNullOrWhiteSpace($architecture)) { throw 'PHP architecture was not reported' }
$architecturePattern = if ($ExpectedArchitecture -eq 'x64') { 'x64|AMD64' } else { 'x86|i[3-6]86' }
if ($architecture -notmatch $architecturePattern) {
    throw "Expected $ExpectedArchitecture PHP, got: $architecture"
}

@(
    $output,
    "architecture=$architecture",
    '--- modules ---',
    $modules
) | Set-Content -Encoding UTF8 $StatusFile
