param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $ArtisanArguments
)

$ErrorActionPreference = 'Stop'
$php = 'C:\wamp64\bin\php\php8.4.15\php.exe'
if (!(Test-Path -LiteralPath $php)) {
    throw 'Wamp PHP 8.4.15 was not found. Update the PHP path in start-local.ps1 to a PHP 8.4+ runtime with SOAP enabled.'
}
if (!$ArtisanArguments) {
    $ArtisanArguments = @('serve', '--host=127.0.0.1', '--port=8000', '--no-reload')
}

$previousXdebugMode = $env:XDEBUG_MODE
Push-Location $PSScriptRoot
try {
    $env:XDEBUG_MODE = 'off'
    & $php artisan @ArtisanArguments
    $result = $LASTEXITCODE
} finally {
    $env:XDEBUG_MODE = $previousXdebugMode
    Pop-Location
}
exit $result
