import json, sys
from pathlib import Path
from urllib.parse import urlsplit
origin = sys.argv[1].rstrip('/')
url = urlsplit(origin)
if url.scheme != 'https' or not url.hostname or url.path or url.query or url.fragment:
    raise SystemExit('Pass the HTTPS Render origin without a path')
config = {'$schema': 'https://openapi.vercel.sh/vercel.json', 'framework': None, 'outputDirectory': 'public',
          'rewrites': [{'source': '/:path*', 'destination': origin + '/:path*'}]}
Path(__file__).with_name('vercel.json').write_text(json.dumps(config, indent=2) + '\n')
