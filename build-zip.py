import zipfile, os, sys

version = open('VERSION').read().strip()
base = 'photojob-organizer'
out = 'releases/photojob-organizer-%s.zip' % version
os.makedirs('releases', exist_ok=True)
if os.path.exists(out):
    os.remove(out)

files = []
for f in ['photojob-organizer.php', 'README.md', 'CHANGELOG.md', 'VERSION']:
    if os.path.isfile(f):
        files.append(f)
for d in ['includes', 'admin']:
    for root, _, fs in os.walk(d):
        for fn in fs:
            files.append(os.path.join(root, fn))

with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for f in files:
        arc = base + '/' + f.replace(os.sep, '/')
        z.write(f, arc)

with zipfile.ZipFile(out) as z:
    names = z.namelist()
    bad = [n for n in names if '\\' in n]
    print('out:', out)
    print('entries:', len(names), 'backslash:', len(bad))
    for n in names:
        print('  ', n)
