$ErrorActionPreference = 'Stop'

$outputPath = 'C:\Windows\Temp\driver-inspection.txt'
New-Item -ItemType Directory -Force -Path (Split-Path $outputPath) | Out-Null

$lines = @()
try {
  foreach ($registryPath in @(
    'HKLM:\SOFTWARE\ODBC\ODBCINST.INI\GaussDB Unicode',
    'HKLM:\SOFTWARE\WOW6432Node\ODBC\ODBCINST.INI\GaussDB Unicode'
  )) {
    if (-not (Test-Path $registryPath)) {
        $lines += "MISSING=$registryPath"
        continue
    }

    $item = Get-ItemProperty $registryPath
    $lines += "REGISTRY=$registryPath"
    $lines += "DRIVER=$($item.Driver)"
    $lines += "SETUP=$($item.Setup)"

    foreach ($path in @($item.Driver, $item.Setup) | Select-Object -Unique) {
        if ($path -and (Test-Path -LiteralPath $path)) {
            $file = Get-Item -LiteralPath $path
            $lines += "FILE=$path"
            $lines += "VERSION=$($file.VersionInfo.FileVersion)"
            $lines += "SHA256=$((Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant())"
        }
    }
  }
} catch {
    $lines += "ERROR=$($_.Exception.Message)"
    $lines += "STACK=$($_.ScriptStackTrace)"
}

$lines | Set-Content -Encoding UTF8 -Path $outputPath
