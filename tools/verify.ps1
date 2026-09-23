# 本机（Windows，无 Docker / 无 PHP）可跑的离线验证。
# 容器内验证请用 WSL 里的 lint.sh / smoke.sh。
$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$failed = @()

function Step {
    param([string]$Name, [scriptblock]$Action)
    Write-Host ""
    Write-Host "== $Name"
    & $Action
    if ($LASTEXITCODE -eq 0) {
        Write-Host "   OK"
    } else {
        Write-Host "   FAILED (exit $LASTEXITCODE)"
        $script:failed += $Name
    }
}

Write-Host "观澜｜考研政治知识库 离线验证"
Write-Host "工作目录: $(Get-Location)"

Step "Python 语法检查（tools）"        { python -m compileall -q tools }
Step "PHP 结构检查（PSR-4 / 模型列 / 路由）" { python tools\phpcheck.py }
Step "PHP 检查器反向自测（5 类错误必须被抓到）" { python tools\phpcheck_selftest.py }
Step "数据集重新生成"                  { python tools\ingest\build_all.py }

Write-Host ""
Write-Host "== 前端测试（node --test）"
Push-Location apps\web
npm test
$webExit = $LASTEXITCODE
Pop-Location
if ($webExit -eq 0) { Write-Host "   OK" } else { Write-Host "   FAILED"; $failed += "前端测试" }

Write-Host ""
if ($failed.Count -eq 0) {
    Write-Host "全部离线验证通过。容器验证请另跑 README 里的 compose 流程。"
    exit 0
}
Write-Host "失败项：$($failed -join '、')"
exit 1