import base64
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
import urllib.parse
import zipfile

class TraceSecurityTest(unittest.TestCase):
    def test_credentials_are_absent_from_every_published_member(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            secret = 'Fixture only: été & + = never-publish'
            credentials = root/'credentials.json'
            credentials.write_text(json.dumps({'password':secret}))
            credentials.chmod(0o600)
            trace = root/'trace.zip'
            with zipfile.ZipFile(trace,'w') as archive:
                archive.writestr('trace.trace',json.dumps({'params':{'value':secret}}))
                archive.writestr('trace.network',json.dumps({'postData':{'text':'username=fixture&password='+urllib.parse.quote_plus(secret)},'cookies':[{'name':'PHPSESSID','value':'fixture-session'}]}))
                archive.writestr('resources/body',base64.b64encode(secret.encode()))
            subprocess.run(['python3',str(Path(__file__).with_name('sanitize-trace.py')),'--secret-file',str(credentials),str(trace)],check=True)
            with zipfile.ZipFile(trace) as archive:
                for member in archive.namelist():
                    data=archive.read(member)
                    for candidate in [secret,urllib.parse.quote_plus(secret),base64.b64encode(secret.encode()).decode(),'fixture-session']:
                        self.assertNotIn(candidate.encode(),data)
                self.assertEqual(json.loads(archive.read('trace.trace'))['params']['value'],'[redacted]')

if __name__ == '__main__': unittest.main()
