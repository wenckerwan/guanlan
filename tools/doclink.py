"""检查 docs/ 与 README 中的相对 Markdown 链接是否指向存在的文件。"""
import io, os, re, sys
from pathlib import Path

root = Path(".")
targets = [Path("README.md"), Path("CHANGELOG.md")] + sorted(Path("docs").glob("*.md"))
bad = []
total = 0

for doc in targets:
    if not doc.exists():
        continue
    text = io.open(doc, encoding="utf-8").read()
    for m in re.finditer(r"\[([^\]]*)\]\(([^)]+)\)", text):
        link = m.group(2).strip()
        if link.startswith(("http://", "https://", "mailto:", "#")):
            continue
        path = link.split("#")[0]
        if not path:
            continue
        total += 1
        resolved = (doc.parent / path).resolve()
        if not resolved.exists():
            bad.append(f"{doc}: [{m.group(1)}]({link})")

print(f"checked {total} relative links in {len([t for t in targets if t.exists()])} files")
if bad:
    print("BROKEN:")
    for b in bad:
        print("  -", b)
    sys.exit(1)
print("OK")