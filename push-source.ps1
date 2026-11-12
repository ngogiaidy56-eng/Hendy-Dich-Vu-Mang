param(
    [Parameter(Mandatory = $true)]
    [string]$ZipPath
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$resolvedZip = (Resolve-Path -LiteralPath $ZipPath).Path
$tempRoot = Join-Path $env:TEMP ("hendy-source-" + [guid]::NewGuid().ToString("N"))

try {
    New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
    Expand-Archive -LiteralPath $resolvedZip -DestinationPath $tempRoot -Force

    $sourceRoot = Join-Path $tempRoot "hendydichvumang"
    if (-not (Test-Path -LiteralPath $sourceRoot -PathType Container)) {
        $candidate = Get-ChildItem -LiteralPath $tempRoot -Directory -Force |
            Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName "index.php") } |
            Select-Object -First 1
        if (-not $candidate) {
            throw "Không tìm thấy thư mục mã nguồn chứa index.php trong ZIP."
        }
        $sourceRoot = $candidate.FullName
    }

    Get-ChildItem -LiteralPath $sourceRoot -Force |
        Copy-Item -Destination $repoRoot -Recurse -Force

    git -C $repoRoot add -A
    if ($LASTEXITCODE -ne 0) { throw "git add thất bại." }

    git -C $repoRoot status --short
    git -C $repoRoot diff --cached --quiet
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Không có thay đổi mới để commit."
        exit 0
    }

    git -C $repoRoot commit -m "Add Hendy Dịch Vụ Mạng Neon Edition source"
    if ($LASTEXITCODE -ne 0) { throw "git commit thất bại." }

    git -C $repoRoot push origin main
    if ($LASTEXITCODE -ne 0) { throw "git push thất bại. Kiểm tra đăng nhập GitHub và nhánh main." }

    Write-Host "Đã push mã nguồn lên origin/main."
}
finally {
    if (Test-Path -LiteralPath $tempRoot) {
        Remove-Item -LiteralPath $tempRoot -Recurse -Force
    }
}
