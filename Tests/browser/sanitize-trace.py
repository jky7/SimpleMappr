#!/usr/bin/env python3
import argparse
import base64
import json
from pathlib import Path
import re
import tempfile
import urllib.parse
import zipfile

parser = argparse.ArgumentParser()
parser.add_argument('--secret-file', type=Path)
parser.add_argument('traces', nargs='+', type=Path)
args = parser.parse_args()
SENSITIVE = {'cookie','set-cookie','authorization','proxy-authorization','x-csrf-token','password','passwd','pwd','_csrf','csrftoken'}
secrets = []
if args.secret_file:
    credentials = json.loads(args.secret_file.read_text())
    for name,value in credentials.items():
        if isinstance(value,str) and re.search(r'password|secret|token',name,re.I) and value:
            secrets.extend([value,json.dumps(value)[1:-1],urllib.parse.quote(value,safe=''),urllib.parse.quote_plus(value),base64.b64encode(value.encode()).decode()])

def redact(text):
    for secret in secrets:
        text = text.replace(secret,'[redacted]')
    text = re.sub(r'(?i)(\b(?:password|passwd|pwd|secret|token|_csrf)=)[^&\s]*',r'\1[redacted]',text)
    text = re.sub(r'(?i)(preview-|/_preview/|/shared/)[0-9a-f]{32,64}',r'\1[redacted]',text)
    return re.sub(r'\b[0-9a-f]{64}\b','[redacted]',text)

def clean(value):
    if isinstance(value,dict):
        if str(value.get('name','')).lower() in SENSITIVE and 'value' in value:
            return dict(value,value='[redacted]')
        return {key:[] if key.lower()=='cookies' else '[redacted]' if key.lower() in SENSITIVE or key.lower()=='value' else clean(item) for key,item in value.items()}
    if isinstance(value,list):
        return [clean(item) for item in value]
    return redact(value) if isinstance(value,str) else value

for source in args.traces:
    with tempfile.NamedTemporaryFile(prefix='.trace-',dir=source.parent,delete=False) as staged:
        target=Path(staged.name)
    try:
        with zipfile.ZipFile(source) as original,zipfile.ZipFile(target,'w',compression=zipfile.ZIP_DEFLATED) as output:
            for item in original.infolist():
                data=original.read(item)
                try:
                    text=data.decode('utf-8');lines=[]
                    for line in text.splitlines():
                        try:lines.append(json.dumps(clean(json.loads(line)),ensure_ascii=False,separators=(',',':')))
                        except json.JSONDecodeError:lines.append(redact(line))
                    data=('\n'.join(lines)+('\n' if text.endswith('\n') else '')).encode()
                except UnicodeDecodeError:
                    pass
                if any(secret.encode() in data for secret in secrets):
                    raise SystemExit('Trace contains fixture credentials. Nothing published.')
                output.writestr(item,data)
        target.chmod(0o644);target.replace(source)
    finally:
        target.unlink(missing_ok=True)
