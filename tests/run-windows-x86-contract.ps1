param(
    [string]$PhpPath = 'C:\GaussDBTest\php-8.3.8-x86\php.exe',
    [string]$TestPath = 'C:\GaussDBTest\php_pdo_contract.php',
    [string]$OutputPath = 'C:\GaussDBTest\windows-x86.json',
    [string]$ConnectionString = $env:GAUSS_ODBC_CONNECTION_STRING,
    [string]$User = 'gauss_php_test',
    [string]$Password = $env:GAUSS_PASSWORD
)

& "$PSScriptRoot\run-windows-driver-contract.ps1" `
    -PhpPath $PhpPath `
    -TestPath $TestPath `
    -OutputPath $OutputPath `
    -Profile windows-x86 `
    -ConnectionString $ConnectionString `
    -User $User `
    -Password $Password
exit $LASTEXITCODE
