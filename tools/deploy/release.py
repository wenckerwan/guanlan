"""Prepare, activate and roll back prebuilt Guanlan images without rebuilding or restoring data."""
import argparse
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import subprocess
import time
import urllib.error
import urllib.request

SERVICES = ('api', 'web', 'gateway')
UNCHANGED = ('mysql', 'redis', 'mayuan-api', 'mayuan-web')
CONFIGS = ('compose.production.yml', 'compose.ui-release.yml', 'docker/nginx/production.conf')
IMMUTABLE_INPUTS = ('storage/dataset', 'storage/dataset-manifest.json', 'storage/import-manifest.json', 'storage/history')
IMAGE_REFERENCE = re.compile(r'[A-Za-z0-9][A-Za-z0-9._/@:-]{0,254}\Z')


class Release:
    def __init__(self, root, backups, env, runner=None):
        self.root, self.backups, self.env = Path(root).resolve(), Path(backups).resolve(), Path(env).resolve()
        self.run = runner or self.command
        self.overlay = self.root / 'compose.release-images.yml'

    def command(self, args, output=None):
        result = subprocess.run(args, cwd=self.root, stdout=output or subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode:
            # Docker/config/SQL stderr may contain connection details; retain only the operation.
            raise RuntimeError(f'{args[0]} operation failed (exit {result.returncode})')
        return '' if output else result.stdout.decode().strip()

    def compose(self, *args, output=None):
        command = ['docker', 'compose', '--env-file', str(self.env), '-f', str(self.root / 'compose.production.yml')]
        for name in ('compose.ui-release.yml', 'compose.release-images.yml'):
            if (self.root / name).exists():
                command += ['-f', str(self.root / name)]
        return self.run(command + list(args), output=output)

    def folder(self, release_id):
        if not re.fullmatch(r'[a-z0-9][a-z0-9-]{0,62}', release_id):
            raise ValueError('release id must be lowercase letters, digits and hyphens')
        folder = (self.backups / release_id).resolve()
        if folder.parent != self.backups:
            raise ValueError('release directory escapes backup root')
        return folder

    def read(self, folder):
        return json.loads((folder / 'release.json').read_text())

    def write(self, folder, data):
        temp = folder / 'release.json.tmp'
        temp.write_text(json.dumps(data, indent=2) + '\n')
        temp.replace(folder / 'release.json')

    def fingerprints(self):
        result = {}
        for name in CONFIGS:
            path = self.root / name
            result[name] = hashlib.sha256(path.read_bytes()).hexdigest() if path.exists() else None
        for name in IMMUTABLE_INPUTS:
            path = self.root / name
            if path.is_dir():
                for child in sorted(p for p in path.rglob('*') if p.is_file()):
                    result[str(child.relative_to(self.root))] = hashlib.sha256(child.read_bytes()).hexdigest()
            elif path.is_file():
                result[name] = hashlib.sha256(path.read_bytes()).hexdigest()
            else:
                result[name] = None
        return result

    def container(self, service):
        value = self.compose('ps', '-q', service).strip()
        if not value or '\n' in value:
            raise RuntimeError(f'expected one running {service} container')
        return value

    def image(self, reference):
        return self.run(['docker', 'image', 'inspect', '--format', '{{.Id}}', reference]).strip()

    def image_reference(self, reference):
        if not isinstance(reference, str) or not IMAGE_REFERENCE.fullmatch(reference):
            raise ValueError('image reference contains unsupported characters')
        return reference

    def prepare(self, release_id, api_image, web_image, mayuan_web_image=None):
        folder = self.folder(release_id)
        if folder.exists():
            raise FileExistsError('release backup already exists')
        self.image_reference(api_image); self.image_reference(web_image)
        candidates = {'api': self.image(api_image), 'web': self.image(web_image)}
        if mayuan_web_image is not None:
            self.image_reference(mayuan_web_image)
            candidates['mayuan-web'] = self.image(mayuan_web_image)
        if not self.env.is_file():
            raise RuntimeError('production environment file is missing')
        self.compose('config', '--quiet')
        folder.mkdir(parents=True, mode=0o700)
        os.chmod(folder, 0o700)
        managed = SERVICES + (('mayuan-web',) if mayuan_web_image is not None else ())
        data = {'status': 'PREPARING', 'id': release_id, 'root': str(self.root), 'env': str(self.env),
                'env_sha256': hashlib.sha256(self.env.read_bytes()).hexdigest(),
                'sha': self.run(['git', 'rev-parse', 'HEAD']), 'configs': self.fingerprints(),
                'old': {}, 'new': {}, 'image_ids': {},
                'unchanged': {s: self.container(s) for s in UNCHANGED if s not in candidates}}
        self.write(folder, data)
        for service in managed:
            previous = self.run(['docker', 'inspect', '--format', '{{.Image}}', self.container(service)])
            old_tag = f'guanlan-{service}:rollback-{release_id}'
            new_tag = f'guanlan-{service}:release-{release_id}'
            self.run(['docker', 'tag', previous, old_tag])
            self.run(['docker', 'tag', candidates.get(service, previous), new_tag])
            data['old'][service], data['new'][service] = old_tag, new_tag
            data['image_ids'][old_tag], data['image_ids'][new_tag] = previous, candidates.get(service, previous)
        for name in CONFIGS:
            if (self.root / name).exists():
                target = folder / name
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes((self.root / name).read_bytes())
        if self.overlay.exists():
            (folder / 'previous-images.yml').write_bytes(self.overlay.read_bytes())
        with (folder / 'database.sql').open('wb') as output:
            self.compose('exec', '-T', 'mysql', 'sh', '-c',
                         'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --routines --triggers --databases "$MYSQL_DATABASE" mayuan', output=output)
        dump = folder / 'database.sql'
        if dump.stat().st_size == 0 or b'CREATE TABLE' not in dump.read_bytes():
            raise RuntimeError('database backup is empty or invalid')
        data['database_sha256'] = hashlib.sha256(dump.read_bytes()).hexdigest()
        data['content'] = self.content_digest()
        data['status'] = 'PREPARED'
        self.write(folder, data)
        return folder

    def validate(self, data, images):
        if data['root'] != str(self.root) or data['env'] != str(self.env):
            raise RuntimeError('release belongs to another checkout/environment')
        if hashlib.sha256(self.env.read_bytes()).hexdigest() != data['env_sha256']:
            raise RuntimeError('environment changed after preparation')
        current = self.fingerprints()
        if any(current.get(name) != data['configs'].get(name) for name in CONFIGS):
            raise RuntimeError('production configuration changed after preparation')
        if current != data['configs']:
            raise RuntimeError('dataset or history inputs changed after preparation')
        for reference in images.values():
            if self.image(reference) != data['image_ids'][reference]:
                raise RuntimeError('prepared image is missing or changed')

    def write_images(self, images, ip):
        ipaddress.IPv4Address(ip)
        text = ('services:\n  api:\n    image: ' + images['api'] + '\n'
                '    command: ["sh", "-c", "php bin/verify-dataset.php && exec php bin/hyperf.php start"]\n'
                '    environment:\n      TRUSTED_PROXY_IPS: "' + ip + '"\n'
                '  web:\n    image: ' + images['web'] + '\n  gateway:\n    image: ' + images['gateway'] + '\n')
        if 'mayuan-web' in images:
            text += '  mayuan-web:\n    image: ' + images['mayuan-web'] + '\n'
        temp = self.overlay.with_suffix('.tmp')
        temp.write_text(text)
        temp.replace(self.overlay)

    def gateway_ip(self):
        values = self.run(['docker', 'inspect', '--format', '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}', self.container('gateway')]).split()
        if len(values) != 1:
            raise RuntimeError('gateway must have exactly one application network')
        return str(ipaddress.IPv4Address(values[0]))

    def switch(self, images):
        managed = tuple(s for s in ('api', 'web') if s in images)
        if 'mayuan-web' in images:
            managed += ('mayuan-web',)
        self.write_images(images, self.gateway_ip())
        self.compose('config', '--quiet')
        self.compose('up', '-d', '--no-build', '--pull', 'never', '--no-deps', *managed)
        self.compose('up', '-d', '--no-build', '--pull', 'never', '--no-deps', '--force-recreate', 'gateway')
        self.write_images(images, self.gateway_ip())
        self.compose('up', '-d', '--no-build', '--pull', 'never', '--no-deps', 'api')
        # API recreation may change its address too; refresh nginx's resolved upstreams.
        self.compose('exec', '-T', 'gateway', 'nginx', '-t')
        self.compose('exec', '-T', 'gateway', 'nginx', '-s', 'reload')
        self.verify()

    def activate(self, release_id):
        folder = self.folder(release_id)
        data = self.read(folder)
        if data['status'] not in ('PREPARED', 'ROLLED_BACK'):
            raise RuntimeError('release is not prepared for activation')
        self.validate(data, {**{s + '-old': v for s, v in data['old'].items()}, **data['new']})
        for service in data['old']:
            current = self.run(['docker', 'inspect', '--format', '{{.Image}}', self.container(service)])
            if current != data['image_ids'][data['old'][service]]:
                raise RuntimeError('running images changed after preparation')
        if self.run(['git', 'rev-parse', 'HEAD']) != data['sha']:
            raise RuntimeError('checkout changed after preparation')
        if hashlib.sha256((folder / 'database.sql').read_bytes()).hexdigest() != data['database_sha256']:
            raise RuntimeError('database backup checksum mismatch')
        self.check_unchanged(data)
        try:
            data['status'] = 'ACTIVATING'; self.write(folder, data)
            self.switch(data['new'])
            self.check_unchanged(data)
            if self.content_digest() != data['content']:
                raise RuntimeError('content changed during release; inspect before accepting')
            data['status'] = 'ACTIVE'; self.write(folder, data)
        except Exception as error:
            try:
                self.rollback(release_id)
            except Exception as rollback_error:
                data['status'] = 'ROLLBACK_FAILED'; self.write(folder, data)
                raise RuntimeError('activation and rollback failed; inspect retained release backup') from rollback_error
            raise RuntimeError('activation failed; previous images restored') from error

    def check_unchanged(self, data):
        if {s: self.container(s) for s in UNCHANGED if s not in data['old']} != data['unchanged']:
            raise RuntimeError('database/Redis/Mayuan containers changed unexpectedly')

    def rollback(self, release_id):
        folder = self.folder(release_id); data = self.read(folder)
        if data['status'] not in ('ACTIVE', 'ACTIVATING', 'ROLLBACK_FAILED', 'ROLLED_BACK'):
            raise RuntimeError('release has not been activated')
        self.validate(data, data['old'])
        if self.run(['git', 'rev-parse', 'HEAD']) != data['sha']:
            raise RuntimeError('checkout changed after preparation')
        if hashlib.sha256((folder / 'database.sql').read_bytes()).hexdigest() != data['database_sha256']:
            raise RuntimeError('database backup checksum mismatch')
        if data['status'] == 'ACTIVE':
            for service in data['new']:
                current = self.run(['docker', 'inspect', '--format', '{{.Image}}', self.container(service)])
                if current != data['image_ids'][data['new'][service]]:
                    raise RuntimeError('running images changed after activation')
        self.switch(data['old'])
        self.check_unchanged(data)
        data['status'] = 'ROLLED_BACK'; self.write(folder, data)

    def content_digest(self):
        code = '$p=new PDO("mysql:host=".getenv("DB_HOST").";dbname=".getenv("DB_DATABASE"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$o=[];foreach(["analysis_articles","hotspots","predictions","questions","papers","practice_banks","practice_questions","history_datasets"] as $t){$r=$p->query("SELECT * FROM $t ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);$o[$t]=[count($r),hash("sha256",json_encode($r))];}echo json_encode($o);'
        return self.compose('exec', '-T', 'api', 'php', '-r', code)

    def verify(self):
        for attempt in range(30):
            states = [self.run(['docker', 'inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}', self.container(s)]) for s in SERVICES + UNCHANGED]
            if all(state == 'healthy' for state in states):
                break
            if attempt == 29:
                raise RuntimeError('services did not become healthy within 60 seconds')
            time.sleep(2)
        match = re.search(r'^\s*APP_PORT\s*=\s*(\d+)\s*$', self.env.read_text(), re.M)
        port = int(match[1]) if match else 8080
        for path, expected in [('/', 200), ('/api/v1/health', 200), ('/api/v1/auth/me', 401), ('/api/v1/history/events', 401), ('/api/v1/stats/leaderboard', 401), ('/api/v2/content', 401)]:
            try:
                with urllib.request.urlopen(f'http://127.0.0.1:{port}{path}', timeout=10) as response:
                    status = response.status
            except urllib.error.HTTPError as error:
                status = error.code
            if status != expected:
                raise RuntimeError(f'{path}: expected {expected}, received {status}')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['prepare', 'activate', 'rollback'])
    parser.add_argument('release_id')
    parser.add_argument('--api-image'); parser.add_argument('--web-image'); parser.add_argument('--mayuan-web-image')
    parser.add_argument('--env-file', default='.env.production')
    parser.add_argument('--backup-root', default='/www/backup/guanlan/releases')
    args = parser.parse_args()
    if args.action == 'prepare' and not (args.api_image and args.web_image):
        parser.error('prepare requires --api-image and --web-image (already built locally)')
    if os.name != 'posix':
        parser.error('production execution requires Linux; unit tests are cross-platform')
    import fcntl
    os.umask(0o077)
    manager = Release(Path(__file__).resolve().parents[2], args.backup_root, args.env_file)
    manager.backups.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (manager.backups / '.release.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.action == 'prepare':
            manager.prepare(args.release_id, args.api_image, args.web_image, args.mayuan_web_image)
        else:
            getattr(manager, args.action)(args.release_id)
    print(f'{args.action}: {args.release_id} complete')


if __name__ == '__main__':
    main()
