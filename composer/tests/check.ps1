# Runs an installed bonsai-lint/bonsai-lint through Composer's .bat proxy, from PowerShell and from
# cmd, which is how Windows users start it: pwsh check.ps1 <project> (after check.sh made busy.ts)
param([Parameter(Mandatory)] [string] $Project)
$ErrorActionPreference = 'Stop'
Set-Location $Project

function Assert-ExitCode([int] $Code, [string] $Label) {
    if ($LASTEXITCODE -ne $Code) { throw "${Label}: exit $LASTEXITCODE, expected $Code" }
}

& .\vendor\bin\bonsai-lint.bat --over 1 busy.ts | Out-Null
Assert-ExitCode 1 'findings through pwsh'
& .\vendor\bin\bonsai-lint.bat --no-such-flag 2>$null | Out-Null
Assert-ExitCode 2 'a usage error through pwsh'
cmd /d /c 'vendor\bin\bonsai-lint.bat --over 1 busy.ts > NUL'
Assert-ExitCode 1 'findings through cmd'
cmd /d /c 'vendor\bin\bonsai-lint.bat --no-such-flag > NUL 2>&1'
Assert-ExitCode 2 'a usage error through cmd'

$path = 'we ird!dir/a&b 100%.ts'
$json = Get-Content busy.ts -Raw | & .\vendor\bin\bonsai-lint.bat --stdin --stdin-path $path --over 1 --format json
Assert-ExitCode 1 'stdin through pwsh'
$found = ($json | Out-String | ConvertFrom-Json).findings[0].path
if ($found -ne $path) { throw "the stdin path came back as $found" }
Write-Output 'check: the .bat proxy passes exit codes, stdin and arguments through'
