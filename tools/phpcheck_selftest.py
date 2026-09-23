"""反向自测：临时注入真实错误，确认 phpcheck 能抓到（防止检查器本身失效）。"""
import subprocess, sys
from pathlib import Path

api = Path("apps/api")
routes = api / "config" / "routes.php"
service = api / "src" / "Service"
backup_routes = routes.read_text(encoding="utf-8")


def run():
    r = subprocess.run([sys.executable, "tools/phpcheck.py"], capture_output=True, text=True, encoding="utf-8")
    return r.returncode, (r.stdout or "") + (r.stderr or "")


cases = []

# 1. PSR-4 不一致
t = service / "_SelfTest.php"
t.write_text("<?php\nnamespace App\\Wrong;\nclass SelfTest {}\n", encoding="utf-8")
cases.append(("psr4-mismatch", *run()))
t.unlink()

# 2. 括号不配平
t = service / "_SelfTest.php"
t.write_text("<?php\nnamespace App\\Service;\nclass SelfTest {\n  public function x() { return [1,2]; }\n", encoding="utf-8")
cases.append(("unbalanced-brace", *run()))
t.unlink()

# 3. new 表达式直接链式调用（PHP 语法错误）
t = service / "_SelfTest.php"
t.write_text("<?php\nnamespace App\\Service;\nuse App\\Support\\Validator;\nclass SelfTest {\n"
             "  public function x(): self {\n"
             "    return new Validator([])->required('x', 'X');\n"
             "  }\n}\n", encoding="utf-8")
cases.append(("new-chain-syntax", *run()))
t.unlink()

# 4. 模型 cast 引用了不存在的列
t = api / "src" / "Model" / "_SelfTest.php"
t.write_text("<?php\nnamespace App\\Model;\nuse Hyperf\\Database\\Model\\Model;\n"
             "class _SelfTest extends Model {\n  protected ?string $table = 'papers';\n"
             "  protected array $casts = ['does_not_exist' => 'array'];\n}\n", encoding="utf-8")
cases.append(("bad-cast-column", *run()))
t.unlink()

# 5. 路由指向不存在的控制器方法
routes.write_text(backup_routes + "\nRouter::get('/api/v1/_selftest', "
                  "[\\App\\Controller\\SubjectController::class, 'notARealMethod']);\n", encoding="utf-8")
cases.append(("route-missing-method", *run()))
routes.write_text(backup_routes, encoding="utf-8")

# 6. 未导入的类引用
t = service / "_SelfTest.php"
t.write_text("<?php\nnamespace App\\Service;\nclass SelfTest {\n"
             "  public function x(): TotallyUnknownClass { return new TotallyUnknownClass(); }\n}\n", encoding="utf-8")
cases.append(("unresolved-class", *run()))
t.unlink()

# 7. 干净树必须通过
cases.append(("clean-tree", *run()))

ok = True
for name, code, out in cases:
    expect_fail = name != "clean-tree"
    good = (code != 0) if expect_fail else (code == 0)
    ok = ok and good
    detail = ""
    if not good:
        detail = "  << " + " | ".join(l.strip() for l in out.splitlines() if l.strip().startswith("-"))[:200]
    print(f"{'PASS' if good else 'FAIL'}  {name:<22} exit={code}{detail}")

print("\nSELFTEST", "OK" if ok else "FAILED")
sys.exit(0 if ok else 1)
