$ErrorActionPreference = 'Stop'

$outputPath = 'C:\GaussDBTest\preflight.txt'
New-Item -ItemType Directory -Force -Path (Split-Path $outputPath) | Out-Null

$lines = @(
    "OS=$([Environment]::OSVersion.VersionString)",
    "OS64=$([Environment]::Is64BitOperatingSystem)",
    "Process64=$([Environment]::Is64BitProcess)",
    "Architecture=$env:PROCESSOR_ARCHITECTURE",
    "PowerShell=$($PSVersionTable.PSVersion)",
    "PHP=$((Get-Command php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source) -join ',')"
)

$lines += Get-OdbcDriver | ForEach-Object {
    "ODBC=$($_.Name)|$($_.Platform)"
}

$lines | Set-Content -Encoding UTF8 -Path $outputPath
