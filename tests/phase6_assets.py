"""Verify local page assets exist; never fetch external/CDN resources."""
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urlsplit,unquote
import re
ROOT=Path(__file__).resolve().parents[1]/'php'
class References(HTMLParser):
    def __init__(self):super().__init__();self.references=[]
    def handle_starttag(self,tag,attributes):
        a=dict(attributes)
        if tag in ('script','img') and a.get('src'):self.references.append(a['src'])
        if tag=='link' and a.get('href') and set(a.get('rel','').split()) & {'stylesheet','icon'}:self.references.append(a['href'])
def target(reference,parent):
    u=urlsplit(reference)
    if u.scheme or u.netloc or not u.path:return None
    p=((ROOT if u.path.startswith('/') else parent)/unquote(u.path).lstrip('/')).resolve()
    assert p.is_relative_to(ROOT.resolve()),'Asset escapes public root'
    return p
count=0
for page in ROOT.glob('*.html'):
    parser=References();parser.feed(page.read_text(encoding='utf-8-sig'))
    for reference in parser.references:
        p=target(reference,page.parent)
        if p is None:continue
        assert p.is_file() and p.stat().st_size>0,f'Missing asset in {page.name}: {reference}'
        count+=1
        if p.suffix=='.css':
            for ref in re.findall(r'url\(\s*[\'\"]?([^\)\'\"]+)',p.read_text(encoding='utf-8')):
                child=target(ref,p.parent)
                if child is not None:assert child.is_file(),f'Missing CSS asset: {ref}'
print(f'PASS {count} local page asset references, including security styles; external assets not fetched.')
