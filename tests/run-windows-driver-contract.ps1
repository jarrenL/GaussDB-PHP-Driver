param(
    [string]$PhpPath = 'C:\GaussDBTest\php-8.3.8\php.exe',
    [string]$TestPath = 'C:\GaussDBTest\php_pdo_contract.php',
    [string]$OutputPath = 'C:\GaussDBTest\windows-x64.json',
    [string]$ConnectionString = $env:GAUSS_ODBC_CONNECTION_STRING,
    [string]$ConnectionStringFile = 'C:\Windows\Temp\gauss-connection-string.txt',
    [string]$User = 'gauss_php_test',
    [string]$Password = $env:GAUSS_PASSWORD,
    [string]$PasswordFile = 'C:\Windows\Temp\gauss-password.txt'
)

$ErrorActionPreference = 'Stop'
try {
    if ([string]::IsNullOrWhiteSpace($ConnectionString) -and (Test-Path $ConnectionStringFile)) {
        $ConnectionString = (Get-Content -Raw -Path $ConnectionStringFile).Trim()
    }
    if ([string]::IsNullOrWhiteSpace($Password) -and (Test-Path $PasswordFile)) {
        $Password = (Get-Content -Raw -Path $PasswordFile).Trim()
    }
    if ([string]::IsNullOrWhiteSpace($ConnectionString)) { throw 'GAUSS_ODBC_CONNECTION_STRING is required' }
    if ([string]::IsNullOrWhiteSpace($Password)) { throw 'GAUSS_PASSWORD is required' }

    $env:GAUSS_TEST_PROFILE = 'windows-x64'
    $env:GAUSS_TEST_DRIVER = 'odbc'
    $env:GAUSS_ODBC_CONNECTION_STRING = $ConnectionString
    $env:GAUSS_USER = $User
    $env:GAUSS_PASSWORD = $Password

    $output = & $PhpPath $TestPath 2>&1
    $exitCode = $LASTEXITCODE
    $output | Out-File -FilePath $OutputPath -Encoding utf8
    exit $exitCode
} catch {
    @('{"runner_error":true}'; $_ | Out-String) |
        Out-File -FilePath $OutputPath -Encoding utf8
    exit 1
}
