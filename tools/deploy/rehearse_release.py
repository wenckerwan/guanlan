"""Linux Docker rehearsal in a unique temporary project; synthetic SQL/content fixtures only.

Exercises real image switches, health checks and both rollback paths. Does not verify
MySQL restoration or production business endpoints. No production project is opened.
"""
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import uuid
from release import Release, SERVICES, UNCHANGED


def main():
    if os.name != 'posix':
        raise RuntimeError('run on a Linux Docker host')
    project = 'dev29-drill-' + uuid.uuid4().hex[:10]
    with tempfile.TemporaryDirectory(prefix=project) as directory:
        root = Path(directory)
        def run(args):
            return subprocess.check_output(args, cwd=root, stderr=subprocess.STDOUT).decode().strip()
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0)); port = listener.getsockname()[1]
        (root / '.env.production').write_text(f'APP_PORT={port}\n')
        (root / 'docker/nginx').mkdir(parents=True)
        config = 'worker_processes 1; events { worker_connections 32; } http { server { listen 80; location = / { return 200 "fixture"; } location = /api/v1/health { return 200 "ok"; } location / { return 401; } } }'
        (root / 'docker/nginx/production.conf').write_text(config)
        # Minimal stand-ins keep memory low; mysqldump and content hashing return fixture data.
        (root / 'php').write_text('#!/bin/sh\nif [ "$1" = "-r" ]; then echo "fixture-content"; elif [ "$1" = "bin/verify-dataset.php" ]; then printf "dataset verified\\n"; exit 0; else exec nginx -g "daemon off;"; fi\n')
        (root / 'mysqldump').write_text('#!/bin/sh\nprintf "CREATE TABLE fixture (id int);\\nINSERT INTO fixture VALUES (1);\\n"\n')
        (root / 'Dockerfile').write_text('FROM nginx:1.30.6-alpine\nCOPY php mysqldump /usr/local/bin/\nRUN chmod 755 /usr/local/bin/php /usr/local/bin/mysqldump\nENTRYPOINT []\nCMD ["nginx", "-g", "daemon off;"]\n')
        image = project + ':old'
        run(['docker', 'build', '--pull=false', '-t', image, '.'])
        (root / 'Dockerfile').write_text((root / 'Dockerfile').read_text() + 'LABEL dev29.candidate="true"\n')
        candidate = project + ':new'
        run(['docker', 'build', '--pull=false', '-t', candidate, '.'])
        services = {}
        for service in SERVICES + UNCHANGED:
            services[service] = {'image': image, 'mem_limit': '24m',
                'volumes': ['./docker/nginx/production.conf:/etc/nginx/nginx.conf:ro'],
                'healthcheck': {'test': ['CMD', 'wget', '-qO-', 'http://127.0.0.1/'], 'interval': '1s', 'timeout': '1s', 'retries': 5}}
        services['gateway']['ports'] = [f'127.0.0.1:{port}:80']
        (root / 'compose.production.yml').write_text(json.dumps({'name': project, 'services': services}))
        run(['git', 'init', '-q']); run(['git', 'add', '.'])
        run(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture'])
        manager = Release(root, root / 'backups', root / '.env.production')
        release_id = project
        try:
            manager.compose('up', '-d', '--pull', 'never')
            manager.verify()
            folder = manager.prepare(release_id, candidate, candidate)
            original = manager.read(folder)
            manager.activate(release_id)
            assert manager.read(folder)['status'] == 'ACTIVE'
            assert manager.run(['docker', 'inspect', '--format', '{{.Image}}', manager.container('api')]) == manager.image(candidate)
            manager.rollback(release_id)
            assert manager.read(folder)['status'] == 'ROLLED_BACK'
            real_verify = manager.verify
            calls = 0
            def fail_candidate_once():
                nonlocal calls
                calls += 1
                if calls == 1:
                    raise RuntimeError('injected candidate verification failure')
                real_verify()
            manager.verify = fail_candidate_once
            try:
                manager.activate(release_id)
                raise AssertionError('activation must fail')
            except RuntimeError as error:
                assert 'previous images restored' in str(error), str(error)
            assert manager.read(folder)['status'] == 'ROLLED_BACK'
            assert manager.run(['docker', 'inspect', '--format', '{{.Image}}', manager.container('api')]) == original['image_ids'][original['old']['api']]
            manager.check_unchanged(original)
            print('PASS real Docker: activate, explicit rollback, failed activation auto rollback; four unchanged containers; synthetic backup preserved')
        finally:
            # Only this generated Compose project is removed; no volumes are deleted.
            manager.compose('down')
            tags = [image, candidate] + [f'guanlan-{s}:{kind}-{release_id}' for s in SERVICES for kind in ('release', 'rollback')]
            run(['docker', 'image', 'rm', *tags])


if __name__ == '__main__':
    main()
