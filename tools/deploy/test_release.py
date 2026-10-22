"""Behavioral release and rollback rehearsal with a stateful Docker adapter; no daemon needed."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec=importlib.util.spec_from_file_location('release',Path(__file__).with_name('release.py'))
release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)

class Fake:
    def __init__(self):
        self.calls=[];self.fail=False;self.tags={};self.current={s:'old-'+s for s in release.SERVICES};self.container_ids={s:s for s in release.SERVICES + release.UNCHANGED}
    def __call__(self,args,output=None):
        self.calls.append(args)
        if 'mysqldump' in ' '.join(args):
            if output:output.write(b'CREATE TABLE fixture (id int);\n')
            return ''
        if args[:3]==['git','rev-parse','HEAD']:return 'a'*40
        if args[:3]==['docker','image','inspect']:
            name=args[-1]
            if name=='missing':raise RuntimeError('missing image')
            return self.tags.get(name,'sha256:'+('1' if name=='new-api' else '2')*64)
        if args[:2]==['docker','tag']:self.tags[args[-1]]=args[-2];return ''
        if args[:2]==['docker','inspect']:
            field=args[3];name=args[-1]
            if 'IPAddress' in field:return '172.21.0.3 '
            return self.current.get(name,'old-'+name)
        if 'ps' in args and '-q' in args:return self.container_ids[args[-1]]
        if 'up' in args:
            if self.fail and 'api' in args:
                self.fail=False;raise RuntimeError('simulated activation failure')
            return ''
        return ''

class Tests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name)/'repo';self.root.mkdir()
        (self.root/'docker/nginx').mkdir(parents=True)
        (self.root/'compose.production.yml').write_text('services: {}')
        (self.root/'docker/nginx/production.conf').write_text('server {}')
        (self.root/'.env.production').write_text('APP_KEY=private-fixture')
        self.fake=Fake();self.manager=release.Release(self.root,Path(self.tmp.name)/'backups',self.root/'.env.production',self.fake)
        self.manager.verify=lambda:None
        self.manager.content_digest=lambda:'stable-content'
    def tearDown(self):self.tmp.cleanup()
    def prepare(self):return self.manager.prepare('dev29-test','new-api','new-web')
    def test_success_and_explicit_rollback_preserve_data(self):
        folder=self.prepare();self.manager.activate('dev29-test')
        data=self.manager.read(folder)
        for service in release.SERVICES:
            self.fake.current[service]=data['image_ids'][data['new'][service]]
        self.manager.rollback('dev29-test')
        self.assertEqual(self.manager.read(folder)['status'],'ROLLED_BACK')
        self.assertIn('rollback-dev29-test',(self.root/'compose.release-images.yml').read_text())
        joined=' '.join(' '.join(c) for c in self.fake.calls)
        for forbidden in [' build',' pull','reset --hard',' clean','db:seed','migrate','mysql <','down -v']:
            self.assertNotIn(forbidden,joined)
        self.assertTrue((folder/'database.sql').exists())
        self.assertNotIn('private-fixture',''.join(p.read_text() for p in folder.iterdir() if p.is_file()))
    def test_failed_activation_automatically_restores_old_images(self):
        folder=self.prepare();self.fake.fail=True
        with self.assertRaises(RuntimeError):self.manager.activate('dev29-test')
        self.assertEqual(self.manager.read(folder)['status'],'ROLLED_BACK')
        self.assertIn('rollback-dev29-test',(self.root/'compose.release-images.yml').read_text())
    def test_missing_candidate_does_not_touch_running_services(self):
        with self.assertRaises(RuntimeError):self.manager.prepare('dev29-test','missing','new-web')
        self.assertFalse(any('up' in c for c in self.fake.calls))
    def test_config_drift_refuses_activation(self):
        self.prepare();(self.root/'compose.production.yml').write_text('changed')
        with self.assertRaises(RuntimeError):self.manager.activate('dev29-test')
        self.assertFalse(any('up' in c for c in self.fake.calls))
    def test_environment_drift_refuses_activation(self):
        self.prepare();(self.root/'.env.production').write_text('APP_KEY=changed')
        with self.assertRaises(RuntimeError):self.manager.activate('dev29-test')
        self.assertFalse(any('up' in c for c in self.fake.calls))
    def test_dataset_drift_refuses_activation_before_switch(self):
        dataset = self.root/'storage'/'dataset'; dataset.mkdir(parents=True)
        paths = [dataset/'fixture.json', self.root/'storage'/'import-manifest.json']
        for path in paths: path.write_text('{"version":1}\n')
        self.prepare()
        for path in paths:
            with self.subTest(path=path.name):
                path.write_text('{"version":2}\n')
                with self.assertRaisesRegex(RuntimeError, 'dataset'):
                    self.manager.activate('dev29-test')
                self.assertFalse(any('up' in c for c in self.fake.calls))
                path.write_text('{"version":1}\n')
    def test_duplicate_and_unsafe_ids_refused(self):
        self.prepare()
        with self.assertRaises((RuntimeError,FileExistsError)):self.prepare()
        for value in ['../outside','/tmp/x','UPPER','']:
            with self.assertRaises(ValueError):self.manager.prepare(value,'new-api','new-web')
    def test_rollback_failure_is_reported(self):
        folder=self.prepare();self.manager.verify=lambda:(_ for _ in ()).throw(RuntimeError('unhealthy'))
        with self.assertRaisesRegex(RuntimeError,'rollback failed'):self.manager.activate('dev29-test')
        self.assertEqual(self.manager.read(folder)['status'],'ROLLBACK_FAILED')
    def test_activation_refuses_changed_unchanged_containers_before_switch(self):
        self.prepare()
        self.fake.container_ids['mysql']='unexpected-mysql'
        with self.assertRaisesRegex(RuntimeError,'containers changed'):
            self.manager.activate('dev29-test')
        self.assertFalse(any(' up ' in (' ' + ' '.join(c) + ' ') for c in self.fake.calls))
    def test_rollback_refuses_checkout_drift(self):
        folder=self.prepare();self.manager.activate('dev29-test')
        self.manager.run=lambda args,output=None: 'b'*40 if args[:3]==['git','rev-parse','HEAD'] else self.fake(args,output)
        with self.assertRaisesRegex(RuntimeError,'checkout changed'):
            self.manager.rollback('dev29-test')
        self.assertEqual(self.manager.read(folder)['status'],'ACTIVE')
    def test_api_command_verifies_dataset_before_start(self):
        self.manager.write_images({'api':'api-image','web':'web-image','gateway':'gateway-image'}, '172.21.0.3')
        overlay=(self.root/'compose.release-images.yml').read_text()
        self.assertIn('php bin/verify-dataset.php', overlay)
        self.assertLess(overlay.index('php bin/verify-dataset.php'), overlay.index('php bin/hyperf.php start'))

if __name__=='__main__':unittest.main()
