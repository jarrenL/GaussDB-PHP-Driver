param(
    [string]$PhpPath = 'C:\GaussDBTest\php-8.3.8-x64\php.exe',
    [string]$RepositoryPath = 'C:\GaussDBTest\GaussDB-PHP-Driver',
    [Parameter(Mandatory = $true)]
    [string]$Server,
    [int]$Port = 5432,
    [string]$MDatabase = 'gdbdrv_m_test',
    [string]$ODatabase = 'gdbdrv_a_test',
    [string]$User = 'gauss_php_test',
    [string]$Password = $env:GAUSS_PASSWORD,
    [string]$OutputDirectory = 'C:\GaussDBTest\compat-results'
)

$ErrorActionPreference = 'Stop'
if ([string]::IsNullOrWhiteSpace($Password)) { throw 'GAUSS_PASSWORD or -Password is required' }
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw "PHP not found: $PhpPath" }

$testPath = Join-Path $RepositoryPath 'tests\php_compat_integration.php'
if (-not (Test-Path -LiteralPath $testPath -PathType Leaf)) { throw "Compatibility test not found: $testPath" }
New-Item -ItemType Directory -Force -Path $OutputDirectory | Out-Null

$env:GAUSS_HOST = $Server
$env:GAUSS_PORT = [string]$Port
$env:GAUSS_USER = $User
$env:GAUSS_PASSWORD = $Password
$failed = $false

try {
    foreach ($target in @(
        @{ Mode = 'M'; Database = $MDatabase },
        @{ Mode = 'O'; Database = $ODatabase }
    )) {
        $env:GAUSS_MODE = $target.Mode
        $env:GAUSS_DATABASE = $target.Database
        $outputPath = Join-Path $OutputDirectory "windows-$($target.Mode.ToLowerInvariant()).json"
        $output = & $PhpPath $testPath 2>&1
        $exitCode = $LASTEXITCODE
        $output | Set-Content -Encoding UTF8 -Path $outputPath
        if ($exitCode -ne 0) {
            $failed = $true
            Write-Warning "Compatibility test failed for mode $($target.Mode); see $outputPath"
        }
    }
} finally {
    Remove-Item Env:GAUSS_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:GAUSS_MODE -ErrorAction SilentlyContinue
    Remove-Item Env:GAUSS_DATABASE -ErrorAction SilentlyContinue
}

if ($failed) { exit 1 }
exit 0
