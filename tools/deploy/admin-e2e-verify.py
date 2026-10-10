import json
import re
from pathlib import Path

backup = Path('/www/backup/guanlan/admin-e2e-20261010-01')
before = json.loads((backup / 'content-before.json').read_text())
after = json.loads((backup / 'content-after.json').read_text())
for name in before:
    if name != 'content_maintenance':
        assert before[name] == after[name], name
print('FIVE_ORIGINAL_CONTENT_TABLES_UNCHANGED')
sql = (backup / 'database.sql').read_text()
match = re.search(r'INSERT INTO `content_maintenance` VALUES (.*);', sql)
assert match is not None
prior = {name: (int(flag), timestamp) for name, flag, timestamp in re.findall(r"\('([^']+)',([01]),'([^']*)'\)", match.group(1))}
current = json.loads((backup / 'maintenance-after.json').read_text())
assert set(prior) == {row['table_name'] for row in current}
for row in current:
    name = row['table_name']
    assert prior[name][0] == int(row['maintained']) == 1, name
    if name != 'hotspots':
        assert prior[name][1] == row['updated_at'], name
print('MAINTENANCE_FLAGS_UNCHANGED_ONLY_HOTSPOT_TIMESTAMP_UPDATED')
