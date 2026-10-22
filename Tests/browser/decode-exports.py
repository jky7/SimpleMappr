#!/usr/bin/env python3
import json
from pathlib import Path
import struct
import sys
import xml.etree.ElementTree as ET
import zipfile

folder = Path(sys.argv[1])
checks = []
for browser in ['chromium', 'firefox']:
    for suffix, main in [('docx', 'word/document.xml'), ('pptx', 'ppt/presentation.xml')]:
        with zipfile.ZipFile(folder / f'{browser}-download.{suffix}') as archive:
            if archive.testzip() is not None:
                raise SystemExit('Office archive CRC verification failed.')
            root = ET.fromstring(archive.read(main))
            images = []
            for member in archive.namelist():
                if '/media/' in member and member.lower().endswith('.png'):
                    data = archive.read(member)
                    if not data.startswith(b'\x89PNG\r\n\x1a\n'):
                        raise SystemExit('Office media is not a PNG.')
                    images.append({'member': member, 'dimensions': struct.unpack('>II', data[16:24])})
            if not images or not any(image['dimensions'] == (900, 450) for image in images):
                raise SystemExit('Office output does not contain the expected map image.')
            checks.append({'browser': browser, 'format': suffix, 'status': 'PASS', 'root': root.tag, 'images': images})
    for suffix, namespace in [('svg', 'http://www.w3.org/2000/svg'), ('kml', 'http://www.opengis.net/kml/2.2')]:
        root = ET.parse(folder / f'{browser}-download.{suffix}').getroot()
        if not root.tag.startswith('{' + namespace + '}'):
            raise SystemExit('The exported XML has an unexpected namespace.')
        checks.append({'browser': browser, 'format': suffix, 'status': 'PASS', 'root': root.tag})
    transparent = (folder / f'{browser}-download.pnga').read_bytes()
    if transparent[:8] != b'\x89PNG\r\n\x1a\n' or transparent[25] != 6:
        raise SystemExit('Transparent PNG output must carry an alpha channel.')
    checks.append({'browser': browser, 'format': 'pnga', 'status': 'PASS', 'pngColorType': transparent[25]})
(folder / 'decoded-exports.json').write_text(json.dumps(checks, indent=2) + '\n')
print('PASS: Office CRC, XML and map media; SVG and KML XML; transparent PNG alpha.')
