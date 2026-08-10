param(
    [string]$PhpPath = 'C:\GaussDBTest\php-8.3.8\php.exe',
    [string]$TestPath = 'C:\GaussDBTest\php_pdo_odbc_smoke.php',
    [string]$Dsn = 'GaussDB507',
    [string]$ConnectionString = $env:GAUSS_ODBC_CONNECTION_STRING,
    [string]$ConnectionStringFile = 'C:\Windows\Temp\gauss-connection-string.txt',
    [string]$User = 'gauss_php_test',
    [string]$Password = $env:GAUSS_PASSWORD,
    [string]$PasswordFile = 'C:\Windows\Temp\gauss-password.txt',
    [string]$OutputPath = 'C:\GaussDBTest\pdo-odbc-smoke.txt'
)

$ErrorActionPreference = 'Stop'
try {
    if ([string]::IsNullOrWhiteSpace($ConnectionString) -and (Test-Path $ConnectionStringFile)) {
        $ConnectionString = (Get-Content -Raw -Path $ConnectionStringFile).Trim()
    }
    if ([string]::IsNullOrWhiteSpace($Password) -and $PasswordFile) {
        $Password = (Get-Content -Raw -Path $PasswordFile).Trim()
    }
    if ([string]::IsNullOrWhiteSpace($Password)) {
        throw 'Password is required through -Password or GAUSS_PASSWORD'
    }
    $env:GAUSS_ODBC_DSN = $Dsn
    $env:GAUSS_ODBC_CONNECTION_STRING = $ConnectionString
    $env:GAUSS_USER = $User
    $env:GAUSS_PASSWORD = $Password

    $output = & $PhpPath $TestPath 2>&1
    $exitCode = $LASTEXITCODE
    @("exit_code=$exitCode"; $output) |
        Out-File -FilePath $OutputPath -Encoding utf8
    exit $exitCode
} catch {
    @('exit_code=1'; $_ | Out-String) |
        Out-File -FilePath $OutputPath -Encoding utf8
    exit 1
}
